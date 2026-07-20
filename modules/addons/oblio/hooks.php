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
 * Record one Incasare on Oblio for a specific WHMCS transaction.
 *
 * One Oblio Incasare = one WHMCS tblaccounts row. Multiple partial payments on the same
 * invoice produce multiple Incasari, each tracked by transaction_id in mod_oblio_invoices.
 * SPV is intentionally not fired here - InvoicePaid handles that once per Paid-status
 * transition so partial-payment invoices don't fire SPV repeatedly.
 *
 * @param int   $invoiceId   WHMCS invoice ID
 * @param array $transaction WHMCS transaction row (id, transid, gateway, amountin, date)
 * @param array $settings    Module settings
 * @return bool true if Incasare was sent (or already existed), false on configuration/error
 */
function oblio_collect_document($invoiceId, array $transaction, array $settings)
{
    $transactionId = (int)($transaction['id'] ?? 0);

    try {
        if (empty($settings['api_email']) || empty($settings['api_secret'])) {
            logActivity('Oblio: API credentials not configured. Skipping Incasare for invoice #' . $invoiceId);
            return false;
        }
        if (empty($settings['company_cif'])) {
            logActivity('Oblio: Company CIF not configured. Skipping Incasare for invoice #' . $invoiceId);
            return false;
        }
        if ($transactionId === 0) {
            logActivity('Oblio: Cannot record Incasare without a transaction id for invoice #' . $invoiceId);
            return false;
        }

        $syncedInvoice = WhmcsHelper::getSyncedInvoice($invoiceId);
        if (!$syncedInvoice) {
            logActivity('Oblio: No synced invoice found for WHMCS invoice #' . $invoiceId . '. Cannot add Incasare for transaction #' . $transactionId . '.');
            return false;
        }

        if (WhmcsHelper::isTransactionSynced($invoiceId, $transactionId)) {
            logActivity('Oblio: Transaction #' . $transactionId . ' already collected for invoice #' . $invoiceId . '. Skipping.');
            return true;
        }

        $defaultCollectType = !empty($settings['collect_type']) ? $settings['collect_type'] : 'Ordin de plata';

        $collect = [
            'type'           => WhmcsHelper::mapGatewayToCollectType(
                $transaction['gateway'] ?? '',
                $defaultCollectType
            ),
            'issueDate'      => !empty($transaction['date'])
                ? date('Y-m-d', strtotime($transaction['date']))
                : date('Y-m-d'),
            'documentNumber' => !empty($transaction['transid'])
                ? $transaction['transid']
                : (string)$transactionId,
        ];
        if (!empty($transaction['amountin']) && (float)$transaction['amountin'] > 0) {
            $collect['value'] = round((float)$transaction['amountin'], 2);
        }

        $api = new OblioApi($settings['api_email'], $settings['api_secret']);
        $api->collectInvoice(
            $settings['company_cif'],
            $syncedInvoice->oblio_series,
            $syncedInvoice->oblio_number,
            $collect
        );

        WhmcsHelper::logSync(
            $invoiceId,
            'collect',
            $syncedInvoice->oblio_series,
            $syncedInvoice->oblio_number,
            'success',
            $collect['type'] . ' / value=' . ($collect['value'] ?? 'auto'),
            $transactionId
        );
        logActivity('Oblio: Incasare (' . $collect['type'] . ') added for invoice #' . $invoiceId
            . ' txn #' . $transactionId . ': ' . $syncedInvoice->oblio_series . ' #' . $syncedInvoice->oblio_number);

        return true;

    } catch (\Exception $e) {
        $series = isset($syncedInvoice) ? $syncedInvoice->oblio_series : '';
        $number = isset($syncedInvoice) ? $syncedInvoice->oblio_number : '';
        WhmcsHelper::logSync($invoiceId, 'collect', $series, $number, 'error', $e->getMessage(), $transactionId);
        logActivity('Oblio: Failed to add Incasare for invoice #' . $invoiceId . ' txn #' . $transactionId . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * Collect every not-yet-collected positive transaction on an invoice.
 *
 * Used by InvoicePaid and the admin Manual Sync UI. Idempotent: re-running it after
 * any number of payments only sends Incasari for transactions that don't already
 * have a successful collect row.
 *
 * @return int Number of new Incasari sent
 */
function oblio_collect_all_transactions($invoiceId, array $settings)
{
    $transactions = WhmcsHelper::getTransactions($invoiceId);
    $sent = 0;
    foreach ($transactions as $t) {
        $tid = (int)($t['id'] ?? 0);
        if ($tid === 0 || WhmcsHelper::isTransactionSynced($invoiceId, $tid)) {
            continue;
        }
        if (oblio_collect_document($invoiceId, $t, $settings)) {
            $sent++;
        }
    }
    return $sent;
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
 * Triggered when an invoice's status flips to Paid in WHMCS (last payment posted).
 * Ensures the invoice exists in Oblio (creates it if it was missed at generation),
 * collects every transaction that doesn't already have an Incasare in Oblio, then
 * fires SPV once if enabled.
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
        $sent = oblio_collect_all_transactions($invoiceId, $settings);
        if ($sent > 0) {
            logActivity('Oblio: InvoicePaid #' . $invoiceId . ' - sent ' . $sent . ' new Incasare(s).');
        }
    } else {
        logActivity('Oblio: InvoicePaid #' . $invoiceId . ' - Incasare disabled in module settings; not collecting.');
    }

    // SPV submission: fire once now that the invoice is fully Paid. Inside the helper
    // it bails if enable_spv is off; this hook only runs on the Paid-status transition
    // so we don't risk re-submitting on every partial-payment Incasare.
    if (!empty($settings['enable_spv']) && $settings['enable_spv'] === 'on'
        && !empty($settings['api_email']) && !empty($settings['api_secret'])
    ) {
        $synced = WhmcsHelper::getSyncedInvoice($invoiceId);
        if ($synced) {
            try {
                $api = new OblioApi($settings['api_email'], $settings['api_secret']);
                oblio_try_send_spv($api, $settings, $invoiceId, $synced->oblio_series, $synced->oblio_number);
            } catch (\Exception $e) {
                logActivity('Oblio: InvoicePaid #' . $invoiceId . ' - SPV submission failed: ' . $e->getMessage());
            }
        }
    }
});

/**
 * Hook: AddTransaction
 *
 * Fires when ANY transaction is added in WHMCS - including partial payments that
 * leave the invoice Unpaid. We send one Incasare for this specific transaction.
 * isTransactionSynced() inside oblio_collect_document() blocks duplicates if the
 * same transaction ever fires twice (e.g. AddTransaction + a manual sync race).
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
    if (empty($settings['enable_collect']) || $settings['enable_collect'] !== 'on') {
        return;
    }

    $transactionId = !empty($vars['id']) ? (int)$vars['id'] : 0;
    if ($transactionId === 0) {
        logActivity('Oblio: AddTransaction for invoice #' . $invoiceId . ' has no transaction id; cannot record Incasare per-transaction.');
        return;
    }

    // Build the transaction record from $vars rather than calling getTransactionById -
    // the DB row may not be visible yet inside the hook depending on transaction ordering.
    $transaction = [
        'id'        => $transactionId,
        'transid'   => $vars['transid']  ?? '',
        'gateway'   => $vars['gateway']  ?? '',
        'amountin'  => $amountIn,
        'date'      => $vars['date']     ?? date('Y-m-d'),
    ];

    logActivity('Oblio: AddTransaction fired for invoice #' . $invoiceId . ' txn #' . $transactionId . ' (amountin=' . $amountIn . '); attempting Incasare.');
    oblio_collect_document($invoiceId, $transaction, $settings);
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
 *
 * If the Oblio storno succeeded we also detach the WHMCS transactions from the cancelled
 * invoice (set invoiceid=0) and clear the addon's collect-sync rows. The fiscal record is
 * already reversed on the Oblio side; the WHMCS payment becomes a free-floating credit
 * the admin can reattach to a replacement invoice and re-collect via Manual Sync. Without
 * clearing the collect rows, isTransactionSynced() would block the re-collect.
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

    // Only detach transactions if the Oblio storno actually succeeded - we don't want
    // to orphan WHMCS payments when the storno attempt failed and the original invoice
    // is still live on the Oblio side.
    if ($oblioStorno !== null) {
        $detached = WhmcsHelper::detachTransactionsFromInvoice($invoiceId);
        $cleared  = WhmcsHelper::clearCollectRowsForInvoice($invoiceId);
        if ($detached > 0 || $cleared > 0) {
            logActivity('Oblio: Storno cleanup for invoice #' . $invoiceId
                . ' - detached ' . $detached . ' transaction(s), cleared ' . $cleared . ' collect row(s).');
        }
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

/**
 * Storno-and-reissue an Oblio-synced invoice that WHMCS just bolted a late fee onto.
 *
 * Romanian fiscal invoices, once issued (and especially once submitted to SPV), cannot be
 * edited. WHMCS's automated late-fee routine edits the invoice in place anyway. To stay
 * compliant we instead: reissue the whole invoice (original items + the new late fee) as a
 * fresh Oblio document, storno the original, and cancel the original WHMCS invoice so it
 * stops dunning. The misleading "modified invoice" / overdue emails WHMCS sends for the now
 * defunct original are suppressed in the EmailPreSend hook below.
 *
 * Returns a result array so the admin "Storno + Reissue" button can surface the outcome;
 * the AddInvoiceLateFee hook ignores the return value (everything is also logged).
 *
 * @param int   $invoiceId Original WHMCS invoice the late fee was added to
 * @param array $settings  Module settings
 * @return array ['success' => bool, 'message' => string, 'newInvoiceId' => int|null]
 */
function oblio_handle_late_fee_amendment($invoiceId, array $settings)
{
    try {
        // The amendment storno's the original via the InvoiceCancelled path, which only acts
        // when Oblio Storno is enabled. Without it we'd cancel + reissue but leave the original
        // live in Oblio - a broken half-state. Refuse up front instead.
        if (empty($settings['enable_storno']) || $settings['enable_storno'] !== 'on') {
            $msg = 'Enable Oblio Storno on Cancel/Refund must be turned on for late-fee amendment to reverse the original.';
            logActivity('Oblio: Late fee amendment - ' . $msg);
            return ['success' => false, 'message' => $msg, 'newInvoiceId' => null];
        }

        // Must be a fiscal invoice we actually issued in Oblio - otherwise nothing to reissue.
        $synced = WhmcsHelper::getSyncedInvoice($invoiceId);
        if (!$synced) {
            $msg = 'Invoice #' . $invoiceId . ' is not synced to Oblio; nothing to reissue.';
            logActivity('Oblio: Late fee amendment - ' . $msg);
            return ['success' => false, 'message' => $msg, 'newInvoiceId' => null];
        }

        // Re-entrancy / double-fire guard.
        if (WhmcsHelper::isAmended($invoiceId)) {
            $msg = 'Invoice #' . $invoiceId . ' was already stornoed and replaced; skipping.';
            logActivity('Oblio: Late fee amendment - ' . $msg);
            return ['success' => false, 'message' => $msg, 'newInvoiceId' => null];
        }

        $invoice = WhmcsHelper::getInvoice($invoiceId);
        if (empty($invoice)) {
            $msg = 'Invoice #' . $invoiceId . ' not found.';
            logActivity('Oblio: Late fee amendment - ' . $msg);
            return ['success' => false, 'message' => $msg, 'newInvoiceId' => null];
        }

        // Only amend invoices that are still open. A Paid/Cancelled/Refunded invoice
        // shouldn't be receiving a late fee in the first place; don't touch it.
        $status = $invoice['status'] ?? '';
        if (!in_array($status, ['Unpaid', 'Collections'], true)) {
            $msg = 'Invoice #' . $invoiceId . ' has status "' . $status . '", not open; refusing to amend.';
            logActivity('Oblio: Late fee amendment - ' . $msg);
            return ['success' => false, 'message' => $msg, 'newInvoiceId' => null];
        }

        // Snapshot any payments on the original BEFORE we touch it (rare: a partially-paid
        // invoice that still went overdue on its balance). We move these to the replacement
        // so the customer's payment follows them to the new document.
        $oldTransactions = WhmcsHelper::getTransactions($invoiceId);
        $hasPayments = !empty($oldTransactions);

        // 1. Reissue: copy original items + the new late fee into a fresh invoice. If the
        //    original carried payments, defer the customer email (sendEmail=false) until
        //    after we've moved those payments across, so the email shows the real remaining
        //    balance instead of the full amount.
        $newInvoiceId = WhmcsHelper::createReplacementInvoice($invoiceId, 7, !$hasPayments);

        // 2. Record the old -> new link. Marks the amendment complete (isAmended guard) and
        //    lets EmailPreSend keep suppressing the original's dunning emails for a short
        //    window after the replacement is issued.
        WhmcsHelper::logAmendment($invoiceId, $newInvoiceId);

        // 3. Sync the replacement to Oblio before moving any payments onto it, so the
        //    Incasari in step 4 have a fiscal invoice to attach to. Idempotent - if
        //    sendEmail was true, InvoiceCreationPreEmail usually already synced it.
        oblio_send_document($newInvoiceId, 'invoice', $settings);

        // 4. Partially-paid edge: move the snapshotted payments to the replacement, record
        //    them as Incasari against the new Oblio invoice, then send the deferred invoice
        //    email now that the balance is correct. (Skipped entirely for the common
        //    fully-unpaid overdue case, where the email already went out in step 1.)
        if ($hasPayments) {
            foreach ($oldTransactions as $t) {
                $tid = (int)($t['id'] ?? 0);
                if ($tid === 0) {
                    continue;
                }
                $reattach = localAPI('UpdateTransaction', ['transactionid' => $tid, 'invoiceid' => $newInvoiceId]);
                if (($reattach['result'] ?? '') !== 'success') {
                    logActivity('Oblio: Late fee amendment - failed to move transaction #' . $tid . ' to invoice #' . $newInvoiceId . ': ' . ($reattach['message'] ?? 'unknown'));
                }
            }
            $recollected = oblio_collect_all_transactions($newInvoiceId, $settings);

            $mail = localAPI('SendEmail', ['messagename' => 'Invoice Created', 'id' => $newInvoiceId]);
            if (($mail['result'] ?? '') !== 'success') {
                logActivity('Oblio: Late fee amendment - replacement #' . $newInvoiceId . ' created but its invoice email was not sent: ' . ($mail['message'] ?? 'unknown'));
            }
            logActivity('Oblio: Late fee amendment - moved ' . count($oldTransactions) . ' payment(s) to invoice #' . $newInvoiceId . ', recorded ' . $recollected . ' Incasare(s).');
        }

        // 5. Cancel the original. This fires InvoiceCancelled -> oblio_handle_storno_event,
        //    which storno's the original in Oblio, optionally mirrors it in WHMCS, detaches
        //    any remaining transactions and clears its collect rows. By now the payments
        //    (if any) have already been moved to the replacement.
        $cancel = localAPI('UpdateInvoice', ['invoiceid' => $invoiceId, 'status' => 'Cancelled']);
        if (($cancel['result'] ?? '') !== 'success') {
            logActivity('Oblio: Late fee amendment - failed to cancel original invoice #' . $invoiceId . ': ' . ($cancel['message'] ?? 'unknown'));
        }

        $msg = 'Invoice #' . $invoiceId . ' (' . $synced->oblio_series . '-' . $synced->oblio_number
            . ') stornoed and reissued as WHMCS invoice #' . $newInvoiceId . '.';
        logActivity('Oblio: Late fee amendment - ' . $msg);
        return ['success' => true, 'message' => $msg, 'newInvoiceId' => $newInvoiceId];

    } catch (\Exception $e) {
        logActivity('Oblio: Late fee amendment failed for invoice #' . $invoiceId . ': ' . $e->getMessage());
        return ['success' => false, 'message' => 'Late fee amendment failed: ' . $e->getMessage(), 'newInvoiceId' => null];
    }
}

/**
 * Hook: AddInvoiceLateFee
 *
 * Fires when WHMCS adds a late fee to an overdue invoice - but BEFORE the fee line item is
 * actually written (confirmed from the cron activity log: the "Late Invoice Fees added"
 * entry lands after this hook returns). Snapshotting the invoice here therefore misses the
 * fee. So we only QUEUE the invoice for amendment; the real storno+reissue runs in
 * DailyCronJob, by which point the fee is committed and the snapshot is complete.
 *
 * Writing the pending marker now also arms EmailPreSend to suppress the original's dunning
 * emails for the rest of this cron run (the original stays Unpaid until DailyCronJob).
 */
add_hook('AddInvoiceLateFee', 1, function ($vars) {
    if (empty($vars['invoiceid'])) {
        return;
    }
    $invoiceId = (int)$vars['invoiceid'];

    $settings = oblio_get_module_settings();
    if (empty($settings['enable_late_fee_amendment']) || $settings['enable_late_fee_amendment'] !== 'on') {
        return;
    }
    // Cheap pre-checks so we only mark (and later suppress emails for) real candidates.
    if (empty($settings['enable_storno']) || $settings['enable_storno'] !== 'on') {
        return;
    }
    if (!WhmcsHelper::getSyncedInvoice($invoiceId) || WhmcsHelper::isAmended($invoiceId)) {
        return;
    }

    WhmcsHelper::logPendingAmendment($invoiceId);
    logActivity('Oblio: Late fee on invoice #' . $invoiceId . ' - queued for storno+reissue at end of cron (fee not yet committed at hook time).');
});

/**
 * Hook: DailyCronJob
 *
 * Runs at the very end of the daily automation, after late fees are committed and reminder
 * emails have been sent. Processes every invoice queued by AddInvoiceLateFee: now the fee
 * line item exists, so oblio_handle_late_fee_amendment() snapshots it correctly.
 */
add_hook('DailyCronJob', 1, function () {
    $settings = oblio_get_module_settings();
    if (empty($settings['enable_late_fee_amendment']) || $settings['enable_late_fee_amendment'] !== 'on') {
        return;
    }

    $pending = WhmcsHelper::getPendingAmendmentInvoiceIds();
    foreach ($pending as $invoiceId) {
        $result = oblio_handle_late_fee_amendment($invoiceId, $settings);

        if (!empty($result['success'])) {
            WhmcsHelper::clearPendingAmendment($invoiceId);
            continue;
        }

        // Amendment didn't complete. Only keep the marker (for a retry on the next cron) if
        // the invoice is still open and worth amending; otherwise drop it so we don't retry
        // or suppress its emails forever.
        $invoice = WhmcsHelper::getInvoice($invoiceId);
        $status  = $invoice['status'] ?? '';
        if (empty($invoice) || !in_array($status, ['Unpaid', 'Collections'], true) || WhmcsHelper::isAmended($invoiceId)) {
            WhmcsHelper::clearPendingAmendment($invoiceId);
        } else {
            logActivity('Oblio: Late fee amendment for invoice #' . $invoiceId . ' did not complete; will retry next cron. Reason: ' . ($result['message'] ?? 'unknown'));
        }
    }
});

/**
 * Hook: EmailPreSend
 *
 * Suppresses the emails WHMCS would otherwise send about an invoice that is queued for, or has
 * just undergone, the late-fee storno+reissue:
 *
 *   - "Invoice Modified" for any Oblio-synced invoice: an issued fiscal invoice must never
 *     be edited in place, so this email is always misleading for synced invoices. Aborting
 *     it is order-independent.
 *   - Overdue notices / payment reminders for an invoice that is pending amendment (its
 *     replacement hasn't been issued yet this cron) or was amended in the last few minutes:
 *     the original is being replaced, so its dunning emails are stale.
 *
 * Only active when late-fee amendment is enabled.
 */
add_hook('EmailPreSend', 1, function ($vars) {
    $settings = oblio_get_module_settings();
    if (empty($settings['enable_late_fee_amendment']) || $settings['enable_late_fee_amendment'] !== 'on') {
        return;
    }

    $messagename = (string)($vars['messagename'] ?? '');
    $relid       = (int)($vars['relid'] ?? 0);
    if ($relid === 0 || $messagename === '') {
        return;
    }

    // Rule A: never send "Invoice Modified" for a fiscal invoice that lives in Oblio.
    if (stripos($messagename, 'Modified') !== false && WhmcsHelper::getSyncedInvoice($relid)) {
        logActivity('Oblio: Suppressed "' . $messagename . '" email for Oblio-synced invoice #' . $relid . ' (fiscal invoices are reissued, not modified).');
        return ['abortsend' => true];
    }

    // Rule B: suppress dunning emails for an invoice pending amendment or freshly amended.
    $isDunning = stripos($messagename, 'Overdue') !== false
        || stripos($messagename, 'Reminder') !== false;
    if ($isDunning && (WhmcsHelper::hasPendingAmendment($relid) || WhmcsHelper::wasRecentlyAmended($relid, 600))) {
        logActivity('Oblio: Suppressed "' . $messagename . '" email for invoice #' . $relid . ' (queued for / undergoing late-fee storno+reissue).');
        return ['abortsend' => true];
    }
});
