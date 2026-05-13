<?php

/**
 * Oblio Integration Module - WHMCS Hooks
 *
 * Hook: InvoiceCreated -> Creates an invoice in Oblio as soon as any invoice is generated
 * Hook: InvoicePaid    -> Adds Incasare (payment collection) to the existing Oblio invoice
 *
 * @see https://developers.whmcs.com/addon-modules/hooks/
 * @see https://developers.whmcs.com/hooks-reference/invoices-and-quotes/
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Module\Addon\Oblio\OblioApi;
use WHMCS\Module\Addon\Oblio\WhmcsHelper;

require_once __DIR__ . '/lib/OblioApi.php';
require_once __DIR__ . '/lib/WhmcsHelper.php';

/**
 * Retrieve the Oblio addon module settings.
 *
 * @return array Module settings or empty array on failure
 */
function oblio_get_module_settings()
{
    try {
        if (class_exists('\\WHMCS\\Database\\Capsule')) {
            $settings = \WHMCS\Database\Capsule::table('tbladdonmodules')
                ->where('module', 'oblio')
                ->pluck('value', 'setting')
                ->toArray();
            return $settings;
        }
    } catch (\Exception $e) {
        logActivity('Oblio: Failed to load module settings: ' . $e->getMessage());
    }
    return [];
}

/**
 * Attempt to send an invoice to SPV (e-Factura) if enabled.
 *
 * @param OblioApi $api         Authenticated API client
 * @param array    $settings    Module settings
 * @param int      $invoiceId   WHMCS invoice ID (for logging)
 * @param string   $oblioSeries Oblio document series
 * @param string   $oblioNumber Oblio document number
 */
function oblio_try_send_spv($api, array $settings, $invoiceId, $oblioSeries, $oblioNumber)
{
    if (empty($settings['enable_spv']) || $settings['enable_spv'] !== 'on') {
        return;
    }
    if (empty($oblioSeries) || empty($oblioNumber)) {
        return;
    }

    try {
        $spvResponse = $api->sendToSPV($settings['company_cif'], $oblioSeries, $oblioNumber);
        $spvSent = isset($spvResponse['data']['sent']) && $spvResponse['data']['sent'];
        $spvText = isset($spvResponse['data']['text']) ? $spvResponse['data']['text'] : '';
        logActivity('Oblio: e-Factura SPV for invoice #' . $invoiceId . ': ' . ($spvSent ? 'sent' : 'not sent') . ' - ' . $spvText);
    } catch (\Exception $e) {
        logActivity('Oblio: Failed to send e-Factura to SPV for invoice #' . $invoiceId . ': ' . $e->getMessage());
    }
}

/**
 * Call Oblio createDocument, retrying once with a uniqueness suffix on every
 * line item if Oblio rejects with a duplicate-product-name error.
 *
 * Oblio adds each line item to its product nomenclator (keyed by Denumire +
 * Tip) and refuses to issue a document if a row with the same name already
 * exists. The API exposes no DELETE on /api/nomenclature/products, so the only
 * way to recover is to make the names unique on retry by appending the WHMCS
 * invoice ID. Clean descriptions stay clean for non-colliding invoices.
 */
function oblio_create_document_with_dedup_retry($api, $docType, array $payload, $invoiceId)
{
    try {
        return $api->createDocument($docType, $payload);
    } catch (\Exception $e) {
        if (!oblio_is_duplicate_name_error($e->getMessage())) {
            throw $e;
        }

        $suffix = ' #INV-' . $invoiceId;
        if (!empty($payload['products']) && is_array($payload['products'])) {
            foreach ($payload['products'] as $i => $product) {
                if (isset($product['name']) && strpos($product['name'], $suffix) === false) {
                    $payload['products'][$i]['name'] = $product['name'] . $suffix;
                }
            }
        }

        logActivity('Oblio: Duplicate product name on invoice #' . $invoiceId . '. Retrying with unique suffix.');
        return $api->createDocument($docType, $payload);
    }
}

/**
 * Detect Oblio's duplicate-product-name 400 from its (Romanian) error message.
 */
function oblio_is_duplicate_name_error($message)
{
    $message = (string)$message;
    if (stripos($message, 'Denumiri duplicate') !== false) {
        return true;
    }
    if (stripos($message, 'aceeasi Denumire') !== false && stripos($message, 'exista deja') !== false) {
        return true;
    }
    return false;
}

/**
 * Send a document to Oblio.
 *
 * @param int    $invoiceId WHMCS invoice ID
 * @param string $docType   'invoice'
 * @param array  $settings  Module settings
 */
function oblio_send_document($invoiceId, $docType, array $settings)
{
    try {
        if (empty($settings['api_email']) || empty($settings['api_secret'])) {
            logActivity('Oblio: API credentials not configured. Skipping ' . $docType . ' for invoice #' . $invoiceId);
            return;
        }
        if (empty($settings['company_cif'])) {
            logActivity('Oblio: Company CIF not configured. Skipping ' . $docType . ' for invoice #' . $invoiceId);
            return;
        }

        $seriesName = $settings['invoice_series'] ?? '';
        if (empty($seriesName)) {
            logActivity('Oblio: Invoice series not configured. Skipping invoice #' . $invoiceId);
            return;
        }

        if (WhmcsHelper::isSynced($invoiceId, $docType)) {
            logActivity('Oblio: Invoice #' . $invoiceId . ' already synced as ' . $docType . '. Skipping.');
            return;
        }

        $vatPercentage = !empty($settings['vat_percentage']) ? (int)$settings['vat_percentage'] : 21;
        $vatExemptName = !empty($settings['vat_exempt_name']) ? $settings['vat_exempt_name'] : 'Scutita';

        $payload = WhmcsHelper::buildDocumentPayload(
            $invoiceId,
            $settings['company_cif'],
            $seriesName,
            $settings['cui_field'] ?? '',
            $settings['doc_language'] ?? 'RO',
            $vatPercentage,
            $vatExemptName
        );

        $api = new OblioApi($settings['api_email'], $settings['api_secret']);
        $response = oblio_create_document_with_dedup_retry($api, $docType, $payload, $invoiceId);

        $oblioSeries = isset($response['data']['seriesName']) ? $response['data']['seriesName'] : $seriesName;
        $oblioNumber = isset($response['data']['number']) ? $response['data']['number'] : '';

        WhmcsHelper::logSync($invoiceId, $docType, $oblioSeries, $oblioNumber, 'success');
        logActivity('Oblio: ' . ucfirst($docType) . ' created for invoice #' . $invoiceId . ': ' . $oblioSeries . ' #' . $oblioNumber);

    } catch (\Exception $e) {
        WhmcsHelper::logSync($invoiceId, $docType, '', '', 'error', $e->getMessage());
        logActivity('Oblio: Failed to create ' . $docType . ' for invoice #' . $invoiceId . ': ' . $e->getMessage());
    }
}

/**
 * Record payment (Incasare) on an already-created Oblio invoice.
 *
 * Looks up the Oblio invoice that was created when the WHMCS invoice was generated,
 * then calls the collect endpoint with the payment details from the WHMCS transaction.
 *
 * @param int   $invoiceId WHMCS invoice ID
 * @param array $settings  Module settings
 */
function oblio_collect_document($invoiceId, array $settings)
{
    try {
        if (empty($settings['api_email']) || empty($settings['api_secret'])) {
            logActivity('Oblio: API credentials not configured. Skipping Incasare for invoice #' . $invoiceId);
            return;
        }
        if (empty($settings['company_cif'])) {
            logActivity('Oblio: Company CIF not configured. Skipping Incasare for invoice #' . $invoiceId);
            return;
        }

        $syncedInvoice = WhmcsHelper::getSyncedInvoice($invoiceId);
        if (!$syncedInvoice) {
            logActivity('Oblio: No synced invoice found for WHMCS invoice #' . $invoiceId . '. Cannot add Incasare.');
            return;
        }

        if (WhmcsHelper::isSynced($invoiceId, 'collect')) {
            logActivity('Oblio: Incasare already recorded for invoice #' . $invoiceId . '. Skipping. (To re-collect, delete the payment in WHMCS and re-add it - InvoiceUnpaid will clear this state.)');
            return;
        }

        $defaultCollectType = !empty($settings['collect_type']) ? $settings['collect_type'] : 'Ordin de plata';

        $collect = [
            'type'           => $defaultCollectType,
            'issueDate'      => date('Y-m-d'),
            'documentNumber' => 'WHMCS-' . $invoiceId,
        ];

        // Use actual transaction details where available
        $transaction = WhmcsHelper::getLastTransaction($invoiceId);
        if ($transaction) {
            $collect['type'] = WhmcsHelper::mapGatewayToCollectType(
                $transaction['gateway'],
                $defaultCollectType
            );
            if (!empty($transaction['amountin']) && (float)$transaction['amountin'] > 0) {
                $collect['value'] = round((float)$transaction['amountin'], 2);
            }
            if (!empty($transaction['date'])) {
                $collect['issueDate'] = date('Y-m-d', strtotime($transaction['date']));
            }
            if (!empty($transaction['transid'])) {
                $collect['documentNumber'] = $transaction['transid'];
            } elseif (!empty($transaction['id'])) {
                $collect['documentNumber'] = (string)$transaction['id'];
            }
        }

        $api = new OblioApi($settings['api_email'], $settings['api_secret']);
        $api->collectInvoice(
            $settings['company_cif'],
            $syncedInvoice->oblio_series,
            $syncedInvoice->oblio_number,
            $collect
        );

        WhmcsHelper::logSync($invoiceId, 'collect', $syncedInvoice->oblio_series, $syncedInvoice->oblio_number, 'success', $collect['type']);
        logActivity('Oblio: Incasare (' . $collect['type'] . ') added for invoice #' . $invoiceId . ': ' . $syncedInvoice->oblio_series . ' #' . $syncedInvoice->oblio_number);

        // SPV is sent after payment is confirmed, not at invoice creation
        oblio_try_send_spv($api, $settings, $invoiceId, $syncedInvoice->oblio_series, $syncedInvoice->oblio_number);

    } catch (\Exception $e) {
        $series = isset($syncedInvoice) ? $syncedInvoice->oblio_series : '';
        $number = isset($syncedInvoice) ? $syncedInvoice->oblio_number : '';
        WhmcsHelper::logSync($invoiceId, 'collect', $series, $number, 'error', $e->getMessage());
        logActivity('Oblio: Failed to add Incasare for invoice #' . $invoiceId . ': ' . $e->getMessage());
    }
}

/**
 * Sync a WHMCS invoice to Oblio when it first becomes available.
 *
 * Shared by every "invoice became real" hook (InvoiceCreated for automated
 * billing, InvoiceCreationPreEmail for admin-created invoices being published).
 * Double-firing is harmless - oblio_send_document() checks isSynced() first.
 *
 * @param int    $invoiceId    WHMCS invoice ID
 * @param string $hookName     Hook label for the activity log
 */
function oblio_try_initial_invoice_sync($invoiceId, $hookName)
{
    $settings = oblio_get_module_settings();

    if (empty($settings['enable_invoice']) || $settings['enable_invoice'] !== 'on') {
        logActivity('Oblio: ' . $hookName . ' #' . $invoiceId . ' - Invoice Sync disabled in module settings; skipping.');
        return;
    }

    $invoice = WhmcsHelper::getInvoice($invoiceId);
    if (empty($invoice) || !in_array($invoice['status'], ['Unpaid', 'Draft'])) {
        $status = !empty($invoice['status']) ? $invoice['status'] : 'unknown';
        logActivity('Oblio: ' . $hookName . ' #' . $invoiceId . ' - status is "' . $status . '" (not Unpaid/Draft); skipping. InvoicePaid will handle it on payment.');
        return;
    }
    if (empty($invoice['items']['item'])) {
        logActivity('Oblio: ' . $hookName . ' #' . $invoiceId . ' - invoice has no line items; skipping.');
        return;
    }

    oblio_send_document($invoiceId, 'invoice', $settings);
}

/**
 * Hook: InvoiceCreated
 *
 * Fires when an automated/scheduled invoice is created and leaves Draft.
 * Does NOT fire reliably for invoices published manually from the admin area -
 * that case is covered by InvoiceCreationPreEmail below.
 */
add_hook('InvoiceCreated', 1, function ($vars) {
    oblio_try_initial_invoice_sync($vars['invoiceid'], 'InvoiceCreated');
});

/**
 * Hook: InvoiceCreationPreEmail
 *
 * Fires when an admin creates/publishes an invoice in the admin area, just before
 * the customer email goes out. This is the hook that actually triggers for the
 * "Created Manual Invoice" → "Publish" workflow (WHMCS's InvoiceCreated only
 * fires for automated billing in practice).
 */
add_hook('InvoiceCreationPreEmail', 1, function ($vars) {
    oblio_try_initial_invoice_sync($vars['invoiceid'], 'InvoiceCreationPreEmail');
});

/**
 * Hook: InvoicePaid
 *
 * Triggered when an invoice is paid in WHMCS.
 * Ensures the invoice exists in Oblio (creates it if it was missed at generation),
 * then records the payment via Incasare.
 */
add_hook('InvoicePaid', 1, function ($vars) {
    $invoiceId = $vars['invoiceid'];

    $settings = oblio_get_module_settings();

    $invoiceSyncOn = !empty($settings['enable_invoice']) && $settings['enable_invoice'] === 'on';
    $collectOn     = !empty($settings['enable_collect']) && $settings['enable_collect'] === 'on';

    if (!$invoiceSyncOn && !$collectOn) {
        logActivity('Oblio: InvoicePaid #' . $invoiceId . ' - both Invoice Sync and Incasare disabled; skipping.');
        return;
    }

    // If the invoice wasn't sent to Oblio at creation (e.g. created directly as paid), send it now
    if ($invoiceSyncOn && !WhmcsHelper::isSynced($invoiceId, 'invoice')) {
        $invoice = WhmcsHelper::getInvoice($invoiceId);
        if (!empty($invoice) && !empty($invoice['items']['item'])) {
            oblio_send_document($invoiceId, 'invoice', $settings);
        }
    }

    if ($collectOn) {
        oblio_collect_document($invoiceId, $settings);
    } else {
        logActivity('Oblio: InvoicePaid #' . $invoiceId . ' - Incasare disabled in module settings; not collecting.');
    }
});

/**
 * Hook: InvoiceUnpaid
 *
 * When an admin deletes a payment in WHMCS, the invoice transitions back to Unpaid.
 * Clear our 'collect' sync row so the next AddPayment will trigger a fresh Incasare
 * instead of being blocked by isSynced().
 */
add_hook('InvoiceUnpaid', 1, function ($vars) {
    $invoiceId = $vars['invoiceid'];
    try {
        if (class_exists('\\WHMCS\\Database\\Capsule')) {
            $deleted = \WHMCS\Database\Capsule::table('mod_oblio_invoices')
                ->where('invoice_id', $invoiceId)
                ->where('oblio_type', 'collect')
                ->delete();
            if ($deleted) {
                logActivity('Oblio: InvoiceUnpaid #' . $invoiceId . ' - cleared ' . $deleted . ' collect sync row(s); next payment will re-trigger Incasare.');
            }
        }
    } catch (\Exception $e) {
        logActivity('Oblio: InvoiceUnpaid #' . $invoiceId . ' - failed to clear collect state: ' . $e->getMessage());
    }
});

/**
 * Hook: AddTransaction
 *
 * Safety net for the case where an admin deletes and re-adds a payment in WHMCS:
 * deleting a transaction does NOT revert the invoice status from Paid to Unpaid
 * (datepaid stays set), so the subsequent AddTransaction does NOT re-fire InvoicePaid.
 * Without this hook, the re-add would silently do nothing in Oblio.
 *
 * This hook fires for every transaction added. We attempt the Incasare if:
 *   - The transaction has a positive amountin (it's an incoming payment)
 *   - It's tied to an invoice
 *   - That invoice is currently Paid
 */
add_hook('AddTransaction', 1, function ($vars) {
    if (empty($vars['invoiceid'])) {
        return;
    }
    $invoiceId = (int)$vars['invoiceid'];
    $amountIn  = isset($vars['amountin']) ? (float)$vars['amountin'] : 0;
    if ($amountIn <= 0) {
        return;
    }

    $settings = oblio_get_module_settings();
    $collectOn = !empty($settings['enable_collect']) && $settings['enable_collect'] === 'on';
    if (!$collectOn) {
        return;
    }

    // Only attempt if invoice is currently Paid
    $invoice = WhmcsHelper::getInvoice($invoiceId);
    if (empty($invoice) || $invoice['status'] !== 'Paid') {
        return;
    }

    // If a previous successful collect exists, this is a duplicate transaction
    // for an already-collected invoice. Clear it so the new payment gets recorded.
    try {
        if (class_exists('\WHMCS\Database\Capsule')) {
            \WHMCS\Database\Capsule::table('mod_oblio_invoices')
                ->where('invoice_id', $invoiceId)
                ->where('oblio_type', 'collect')
                ->delete();
        }
    } catch (\Exception $e) {
        logActivity('Oblio: AddTransaction #' . $invoiceId . ' - failed to clear previous collect rows: ' . $e->getMessage());
    }

    logActivity('Oblio: AddTransaction fired for invoice #' . $invoiceId . ' (amountin=' . $amountIn . '); attempting Incasare.');
    oblio_collect_document($invoiceId, $settings);
});


/**
 * Create a storno (total invoice reversal) in Oblio for a cancelled or refunded invoice.
 *
 * Issues a new negative invoice in Oblio referencing the original, which is the
 * correct Romanian accounting method for reversing a fiscal document (stornare totala).
 *
 * @param int   $invoiceId WHMCS invoice ID
 * @param array $settings  Module settings
 * @return array|null      ['origSeries','origNumber','stornoSeries','stornoNumber'] on success, null otherwise
 */
function oblio_storno_document($invoiceId, array $settings)
{
    try {
        if (empty($settings['api_email']) || empty($settings['api_secret'])) {
            logActivity('Oblio: API credentials not configured. Skipping storno for invoice #' . $invoiceId);
            return null;
        }
        if (empty($settings['company_cif'])) {
            logActivity('Oblio: Company CIF not configured. Skipping storno for invoice #' . $invoiceId);
            return null;
        }

        $syncedInvoice = WhmcsHelper::getSyncedInvoice($invoiceId);
        if (!$syncedInvoice) {
            logActivity('Oblio: No synced invoice found for WHMCS invoice #' . $invoiceId . '. Cannot create storno.');
            return null;
        }

        if (WhmcsHelper::isSynced($invoiceId, 'storno')) {
            logActivity('Oblio: Storno already created for invoice #' . $invoiceId . '. Skipping.');
            return null;
        }

        $seriesName = !empty($settings['invoice_series']) ? $settings['invoice_series'] : $syncedInvoice->oblio_series;

        $api = new OblioApi($settings['api_email'], $settings['api_secret']);
        $response = $api->stornoInvoice(
            $settings['company_cif'],
            $seriesName,
            $syncedInvoice->oblio_series,
            $syncedInvoice->oblio_number
        );

        $stornoSeries = isset($response['data']['seriesName']) ? $response['data']['seriesName'] : $seriesName;
        $stornoNumber = isset($response['data']['number']) ? $response['data']['number'] : '';

        WhmcsHelper::logSync($invoiceId, 'storno', $stornoSeries, $stornoNumber, 'success',
            'Storno for ' . $syncedInvoice->oblio_series . ' #' . $syncedInvoice->oblio_number);
        logActivity('Oblio: Storno created for invoice #' . $invoiceId . ': ' . $stornoSeries . ' #' . $stornoNumber
            . ' (reverses ' . $syncedInvoice->oblio_series . ' #' . $syncedInvoice->oblio_number . ')');

        return [
            'origSeries'   => $syncedInvoice->oblio_series,
            'origNumber'   => $syncedInvoice->oblio_number,
            'stornoSeries' => $stornoSeries,
            'stornoNumber' => $stornoNumber,
        ];

    } catch (\Exception $e) {
        $series = isset($syncedInvoice) ? $syncedInvoice->oblio_series : '';
        $number = isset($syncedInvoice) ? $syncedInvoice->oblio_number : '';
        WhmcsHelper::logSync($invoiceId, 'storno', $series, $number, 'error', $e->getMessage());
        logActivity('Oblio: Failed to create storno for invoice #' . $invoiceId . ': ' . $e->getMessage());
        return null;
    }
}


/**
 * Create a storno invoice inside WHMCS itself (negative amounts, status = Storno).
 *
 * If $oblioStorno is supplied, the WHMCS storno is labelled with the same fiscal number
 * Oblio just assigned (e.g. invoicenum = "CRK-0026") and the WHMCS sequential counter
 * is advanced. Otherwise the WHMCS storno is created without an invoicenum.
 *
 * @param int        $invoiceId   Original WHMCS invoice ID
 * @param array|null $oblioStorno Output of oblio_storno_document(), or null
 */
function oblio_create_whmcs_storno($invoiceId, $oblioStorno = null)
{
    try {
        $origSeries   = $oblioStorno['origSeries']   ?? '';
        $origNumber   = $oblioStorno['origNumber']   ?? '';
        $stornoSeries = $oblioStorno['stornoSeries'] ?? '';
        $stornoNumber = $oblioStorno['stornoNumber'] ?? '';

        // Fall back to the previously-synced Oblio invoice for the original reference,
        // so notes still read "Storno for Invoice CRK-0022" even when Oblio storno is disabled.
        if ($origSeries === '' || $origNumber === '') {
            $synced = WhmcsHelper::getSyncedInvoice($invoiceId);
            if ($synced) {
                $origSeries = $synced->oblio_series;
                $origNumber = $synced->oblio_number;
            }
        }

        $stornoId = WhmcsHelper::createStornoInvoice(
            $invoiceId,
            $origSeries,
            $origNumber,
            $stornoSeries,
            $stornoNumber
        );
        $label = ($stornoSeries !== '' && $stornoNumber !== '')
            ? ' (' . $stornoSeries . '-' . $stornoNumber . ')'
            : '';
        logActivity('Oblio: WHMCS storno invoice #' . $stornoId . $label . ' created for invoice #' . $invoiceId);
    } catch (\Exception $e) {
        logActivity('Oblio: Failed to create WHMCS storno invoice for invoice #' . $invoiceId . ': ' . $e->getMessage());
    }
}

/**
 * Shared handler for InvoiceCancelled / InvoiceRefunded. Runs the Oblio storno first so the
 * WHMCS storno can adopt the same fiscal number, keeping the two systems in sync.
 */
function oblio_handle_storno_event($invoiceId, array $settings)
{
    $oblioStorno = null;
    if (!empty($settings['enable_storno']) && $settings['enable_storno'] === 'on') {
        $oblioStorno = oblio_storno_document($invoiceId, $settings);
    }

    if (!empty($settings['enable_whmcs_storno']) && $settings['enable_whmcs_storno'] === 'on') {
        oblio_create_whmcs_storno($invoiceId, $oblioStorno);
    }
}

/**
 * Hook: InvoiceCancelled
 *
 * Triggered when an invoice is cancelled in WHMCS.
 * Creates a storno (total reversal) invoice in Oblio if the invoice was previously synced.
 */
add_hook('InvoiceCancelled', 1, function ($vars) {
    oblio_handle_storno_event($vars['invoiceid'], oblio_get_module_settings());
});

/**
 * Hook: InvoiceRefunded
 *
 * Triggered when an invoice is refunded in WHMCS.
 * Creates a storno (total reversal) invoice in Oblio if the invoice was previously synced.
 */
add_hook('InvoiceRefunded', 1, function ($vars) {
    oblio_handle_storno_event($vars['invoiceid'], oblio_get_module_settings());
});
