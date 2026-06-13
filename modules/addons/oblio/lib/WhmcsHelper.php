<?php

namespace WHMCS\Module\Addon\Oblio;

/**
 * Helper class for extracting WHMCS invoice/client data
 * and transforming it into Oblio API format.
 */
class WhmcsHelper
{
    /**
     * Build the Oblio document payload from a WHMCS invoice.
     *
     * @param int    $invoiceId     WHMCS invoice ID
     * @param string $companyCif    Company CIF configured in module settings
     * @param string $seriesName    Document series name
     * @param string $cuiFieldId    Custom field ID that holds the client CUI/CIF
     * @param string $docLanguage   Document language (default: RO)
     * @param int    $vatPercentage VAT rate to apply to taxed items (0 = always send untaxed)
     * @param string $vatExemptName Oblio VAT-rate name for 0% items (must exist in Oblio settings)
     * @return array Oblio document payload
     * @throws \Exception
     */
    public static function buildDocumentPayload($invoiceId, $companyCif, $seriesName, $cuiFieldId = '', $docLanguage = 'RO', $vatPercentage = 0, $vatExemptName = 'Scutita')
    {
        $invoice = self::getInvoice($invoiceId);
        if (empty($invoice)) {
            throw new \Exception('Invoice #' . $invoiceId . ' not found in WHMCS.');
        }

        $client = self::getClient($invoice['userid']);
        if (empty($client)) {
            throw new \Exception('Client #' . $invoice['userid'] . ' not found in WHMCS.');
        }

        $clientCui = '';
        if (!empty($cuiFieldId)) {
            $clientCui = self::getCustomFieldValue($client['id'], $cuiFieldId);
        }

        // Resolve currency code from client details (GetInvoice does not return currency info)
        $currencyCode = self::getClientCurrencyCode($client);

        // Tax-exempt clients (tblclients.taxexempt = 1) get every line item zero-rated,
        // matching WHMCS's behavior of not adding tax to their invoices.
        $clientTaxExempt = !empty($client['taxexempt']);

        $clientData = [
            'name'         => trim($client['companyname'] ?: ($client['firstname'] . ' ' . $client['lastname'])),
            'cif'          => $clientCui,
            'code'         => (string)$client['id'],
            'address'      => trim($client['address1'] . ' ' . $client['address2']),
            'state'        => $client['state'],
            'city'         => $client['city'],
            'country'      => $client['country'],
            'email'        => $client['email'],
            'phone'        => self::formatPhoneWithPrefix($client['phonenumber'], $client['country']),
            'contact'      => trim($client['firstname'] . ' ' . $client['lastname']),
            'vatPayer'     => !empty($clientCui) ? 1 : 0,
            'save'         => 0,
        ];

        $products = [];
        if (!empty($invoice['items']['item'])) {
            $items = $invoice['items']['item'];
            // Normalize single item to array of items (WHMCS may return a flat
            // associative array instead of an indexed array when there is only one item)
            if (isset($items['id'])) {
                $items = [$items];
            }
            foreach ($items as $item) {
                if ((float)$item['amount'] == 0) {
                    continue;
                }
                // Apply VAT only if the WHMCS item is taxed AND the client isn't tax-exempt.
                $itemTaxed = !empty($item['taxed']);
                $itemVat = ($clientTaxExempt || !$itemTaxed) ? 0 : (int)$vatPercentage;
                // Oblio requires the vatName to match a rate that actually exists in the
                // company's VAT settings; "Normala" typically isn't configured at 0%.
                $itemVatName = ($itemVat === 0) ? $vatExemptName : 'Normala';

                $products[] = [
                    'name'            => $item['description'],
                    'price'           => round((float)$item['amount'], 2),
                    'measuringUnit'   => 'buc',
                    'currency'        => $currencyCode,
                    'vatName'         => $itemVatName,
                    'vatPercentage'   => $itemVat,
                    'vatIncluded'     => 0,
                    'quantity'        => 1,
                    'save'            => 0,
                ];
            }
        }

        if (empty($products)) {
            throw new \Exception('Invoice #' . $invoiceId . ' has no line items.');
        }

        // Use the admin-editable WHMCS invoice notes for Oblio's "mentions" field;
        // fall back to a generic reference so the Oblio document always carries something useful.
        $mentions = isset($invoice['notes']) ? trim((string)$invoice['notes']) : '';
        if ($mentions === '') {
            $mentions = 'WHMCS Invoice #' . $invoiceId;
        }

        $payload = [
            'cif'            => $companyCif,
            'client'         => $clientData,
            'issueDate'      => date('Y-m-d', strtotime($invoice['date'])),
            'dueDate'        => date('Y-m-d', strtotime($invoice['duedate'])),
            'seriesName'     => $seriesName,
            'language'       => $docLanguage,
            'precision'      => 2,
            'currency'       => $currencyCode,
            'products'       => $products,
            'mentions'       => $mentions,
            'useStock'       => 0,
        ];

        return $payload;
    }

    /**
     * Get a WHMCS invoice using the local API.
     *
     * @param int $invoiceId
     * @return array
     */
    public static function getInvoice($invoiceId)
    {
        $result = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);
        if ($result['result'] !== 'success') {
            return [];
        }
        return $result;
    }

    /**
     * Get a WHMCS client using the local API.
     *
     * @param int $clientId
     * @return array
     */
    public static function getClient($clientId)
    {
        $result = localAPI('GetClientsDetails', ['clientid' => $clientId, 'stats' => false]);
        if ($result['result'] !== 'success') {
            return [];
        }
        return $result;
    }

    /**
     * Resolve the currency code (e.g. EUR, RON) for a client.
     *
     * GetClientsDetails returns 'currency_code' directly in newer WHMCS versions.
     * Falls back to looking up the currency ID via GetCurrencies.
     *
     * @param array $client Client data from getClient()
     * @return string 3-letter currency code (defaults to RON)
     */
    public static function getClientCurrencyCode(array $client)
    {
        // Prefer the currency_code field from GetClientsDetails (WHMCS 7.x+)
        if (!empty($client['currency_code'])) {
            return $client['currency_code'];
        }

        // Fall back to looking up the currency by ID
        $currencyId = isset($client['currency']) ? (int)$client['currency'] : 0;
        if ($currencyId > 0) {
            $result = localAPI('GetCurrencies', []);
            if (isset($result['result']) && $result['result'] === 'success'
                && !empty($result['currencies']['currency'])) {
                foreach ($result['currencies']['currency'] as $currency) {
                    if ((int)$currency['id'] === $currencyId) {
                        return $currency['code'];
                    }
                }
            }
        }

        return 'RON';
    }

    /**
     * Get a custom field value for a specific client.
     *
     * @param int    $clientId
     * @param string $fieldId  The custom field ID
     * @return string
     */
    public static function getCustomFieldValue($clientId, $fieldId)
    {
        $result = localAPI('GetClientsDetails', ['clientid' => $clientId, 'stats' => false]);
        if ($result['result'] !== 'success' || empty($result['customfields'])) {
            return '';
        }

        foreach ($result['customfields'] as $field) {
            if ((string)$field['id'] === (string)$fieldId) {
                return $field['value'];
            }
        }

        return '';
    }

    /**
     * Get all custom client fields from WHMCS.
     *
     * @return array Array of ['id' => ..., 'fieldname' => ...]
     */
    public static function getCustomClientFields()
    {
        try {
            if (class_exists('\\WHMCS\\Database\\Capsule')) {
                return \WHMCS\Database\Capsule::table('tblcustomfields')
                    ->where('type', 'client')
                    ->select(['id', 'fieldname'])
                    ->get()
                    ->map(function ($row) {
                        return ['id' => $row->id, 'fieldname' => $row->fieldname];
                    })
                    ->toArray();
            }
        } catch (\Exception $e) {
            // Fall through to empty
        }
        return [];
    }

    /**
     * Log an Oblio sync event to the module table.
     *
     * @param int    $invoiceId   WHMCS invoice ID
     * @param string $oblioType   'invoice', 'collect', or 'storno'
     * @param string $oblioSeries Document series in Oblio
     * @param string $oblioNumber Document number in Oblio
     * @param string $status      'success' or 'error'
     * @param string $message     Additional message/error
     */
    public static function logSync($invoiceId, $oblioType, $oblioSeries, $oblioNumber, $status, $message = '', $transactionId = null)
    {
        try {
            if (class_exists('\\WHMCS\\Database\\Capsule')) {
                \WHMCS\Database\Capsule::table('mod_oblio_invoices')->insert([
                    'invoice_id'     => $invoiceId,
                    'oblio_type'     => $oblioType,
                    'transaction_id' => $transactionId,
                    'oblio_series'   => $oblioSeries,
                    'oblio_number'   => $oblioNumber,
                    'status'         => $status,
                    'message'        => mb_substr($message, 0, 500),
                    'created_at'     => date('Y-m-d H:i:s'),
                ]); // Message truncated to 500 chars to fit the database text column safely
            }
        } catch (\Exception $e) {
            logActivity('Oblio: Failed to log sync: ' . $e->getMessage());
        }
    }

    /**
     * Check if an invoice has already been synced as a specific document type.
     *
     * For 'collect' rows this answers "has ANY Incasare been recorded for this invoice?",
     * which is what callers other than the per-transaction path want (e.g. legacy code
     * or "was an invoice ever collected at all"). Use isTransactionSynced() to test for
     * a specific WHMCS transaction.
     *
     * @param int    $invoiceId
     * @param string $oblioType 'invoice', 'collect', or 'storno'
     * @return bool
     */
    public static function isSynced($invoiceId, $oblioType)
    {
        try {
            if (class_exists('\\WHMCS\\Database\\Capsule')) {
                return \WHMCS\Database\Capsule::table('mod_oblio_invoices')
                    ->where('invoice_id', $invoiceId)
                    ->where('oblio_type', $oblioType)
                    ->where('status', 'success')
                    ->exists();
            }
        } catch (\Exception $e) {
            // Fall through
        }
        return false;
    }

    /**
     * Check if a specific WHMCS transaction has already been sent as an Incasare to Oblio.
     *
     * Per-transaction tracking lets partial payments produce one Incasare each instead of
     * the addon blocking all subsequent payments once one collect is recorded.
     *
     * @param int $invoiceId
     * @param int $transactionId tblaccounts.id
     * @return bool
     */
    public static function isTransactionSynced($invoiceId, $transactionId)
    {
        try {
            if (class_exists('\\WHMCS\\Database\\Capsule')) {
                return \WHMCS\Database\Capsule::table('mod_oblio_invoices')
                    ->where('invoice_id', $invoiceId)
                    ->where('oblio_type', 'collect')
                    ->where('transaction_id', $transactionId)
                    ->where('status', 'success')
                    ->exists();
            }
        } catch (\Exception $e) {
            // Fall through
        }
        return false;
    }

    /**
     * Get all positive-amount transactions attached to a WHMCS invoice.
     *
     * @param int $invoiceId
     * @return array List of transaction records (each with at least: id, transid, gateway, amountin, date)
     */
    public static function getTransactions($invoiceId)
    {
        $result = localAPI('GetTransactions', ['invoiceid' => $invoiceId]);
        if (empty($result['transactions']['transaction'])) {
            return [];
        }
        $transactions = $result['transactions']['transaction'];
        // localAPI returns a flat array for a single result, list-of-arrays for multiple
        if (isset($transactions['id'])) {
            $transactions = [$transactions];
        }
        $positive = [];
        foreach ($transactions as $t) {
            if ((float)($t['amountin'] ?? 0) > 0) {
                $positive[] = $t;
            }
        }
        return $positive;
    }

    /**
     * Get a single transaction by its tblaccounts.id.
     *
     * GetTransactions doesn't accept an id filter, so we look up by invoice_id then match.
     * Falls back to a direct DB lookup if the API can't find it (e.g. the transaction was
     * just detached from its invoice via detachTransactionsFromInvoice).
     *
     * @param int $transactionId tblaccounts.id
     * @return array|null
     */
    public static function getTransactionById($transactionId)
    {
        try {
            if (class_exists('\\WHMCS\\Database\\Capsule')) {
                $row = \WHMCS\Database\Capsule::table('tblaccounts')
                    ->where('id', $transactionId)
                    ->first();
                if ($row) {
                    return (array)$row;
                }
            }
        } catch (\Exception $e) {
            // Fall through
        }
        return null;
    }

    /**
     * Detach every transaction attached to a WHMCS invoice (set invoiceid=0).
     *
     * Used after a storno: the Oblio fiscal storno already reverses the invoice, so the
     * WHMCS payment record stops being a debit against the cancelled invoice and becomes
     * a free-floating credit the admin can reattach to a replacement invoice.
     *
     * Note: WHMCS doesn't fire AddTransaction or InvoicePaid when an admin edits an
     * existing transaction's invoiceid. After reattaching, use the addon's Manual Sync
     * panel to send the Incasare to Oblio.
     *
     * @param int $invoiceId
     * @return int Number of transactions detached
     */
    public static function detachTransactionsFromInvoice($invoiceId)
    {
        try {
            if (class_exists('\\WHMCS\\Database\\Capsule')) {
                return \WHMCS\Database\Capsule::table('tblaccounts')
                    ->where('invoiceid', $invoiceId)
                    ->update(['invoiceid' => 0]);
            }
        } catch (\Exception $e) {
            logActivity('Oblio: Failed to detach transactions from invoice #' . $invoiceId . ': ' . $e->getMessage());
        }
        return 0;
    }

    /**
     * Delete every 'collect' sync row for an invoice.
     *
     * Used after a storno so transactions detached and later reattached to a replacement
     * invoice can be re-collected fresh, without isTransactionSynced() blocking them on
     * stale rows that point to the now-stornoed invoice.
     *
     * @param int $invoiceId
     * @return int Number of rows deleted
     */
    public static function clearCollectRowsForInvoice($invoiceId)
    {
        try {
            if (class_exists('\\WHMCS\\Database\\Capsule')) {
                return \WHMCS\Database\Capsule::table('mod_oblio_invoices')
                    ->where('invoice_id', $invoiceId)
                    ->where('oblio_type', 'collect')
                    ->delete();
            }
        } catch (\Exception $e) {
            logActivity('Oblio: Failed to clear collect rows for invoice #' . $invoiceId . ': ' . $e->getMessage());
        }
        return 0;
    }

    /**
     * Format a phone number with country dial prefix.
     *
     * If the phone number already starts with '+' or '00', it is returned as-is.
     * Otherwise, the country dial code is prepended based on the ISO 2-letter country code.
     *
     * @param string $phone   Raw phone number
     * @param string $country ISO 3166-1 alpha-2 country code (e.g., 'RO', 'DE')
     * @return string Phone number with country prefix
     */
    public static function formatPhoneWithPrefix($phone, $country)
    {
        $phone = trim($phone);
        if (empty($phone)) {
            return '';
        }

        // Already has international prefix
        if (strpos($phone, '+') === 0 || strpos($phone, '00') === 0) {
            return $phone;
        }

        $prefix = self::getCountryDialCode($country);
        if (!empty($prefix)) {
            // Strip single leading zero from local numbers before adding prefix
            if (substr($phone, 0, 1) === '0') {
                $phone = substr($phone, 1);
            }
            return $prefix . $phone;
        }

        return $phone;
    }

    /**
     * Get the dial code for a given ISO 2-letter country code.
     *
     * @param string $countryCode ISO 3166-1 alpha-2 country code
     * @return string Dial code with '+' prefix, or empty string if unknown
     */
    public static function getCountryDialCode($countryCode)
    {
        $codes = [
            'AD' => '+376', 'AE' => '+971', 'AF' => '+93',  'AG' => '+1',
            'AL' => '+355', 'AM' => '+374', 'AO' => '+244', 'AR' => '+54',
            'AT' => '+43',  'AU' => '+61',  'AZ' => '+994', 'BA' => '+387',
            'BB' => '+1',   'BD' => '+880', 'BE' => '+32',  'BF' => '+226',
            'BG' => '+359', 'BH' => '+973', 'BI' => '+257', 'BJ' => '+229',
            'BN' => '+673', 'BO' => '+591', 'BR' => '+55',  'BS' => '+1',
            'BT' => '+975', 'BW' => '+267', 'BY' => '+375', 'BZ' => '+501',
            'CA' => '+1',   'CD' => '+243', 'CF' => '+236', 'CG' => '+242',
            'CH' => '+41',  'CI' => '+225', 'CL' => '+56',  'CM' => '+237',
            'CN' => '+86',  'CO' => '+57',  'CR' => '+506', 'CU' => '+53',
            'CV' => '+238', 'CY' => '+357', 'CZ' => '+420', 'DE' => '+49',
            'DJ' => '+253', 'DK' => '+45',  'DM' => '+1',   'DO' => '+1',
            'DZ' => '+213', 'EC' => '+593', 'EE' => '+372', 'EG' => '+20',
            'ER' => '+291', 'ES' => '+34',  'ET' => '+251', 'FI' => '+358',
            'FJ' => '+679', 'FR' => '+33',  'GA' => '+241', 'GB' => '+44',
            'GD' => '+1',   'GE' => '+995', 'GH' => '+233', 'GM' => '+220',
            'GN' => '+224', 'GQ' => '+240', 'GR' => '+30',  'GT' => '+502',
            'GW' => '+245', 'GY' => '+592', 'HK' => '+852', 'HN' => '+504',
            'HR' => '+385', 'HT' => '+509', 'HU' => '+36',  'ID' => '+62',
            'IE' => '+353', 'IL' => '+972', 'IN' => '+91',   'IQ' => '+964',
            'IR' => '+98',  'IS' => '+354', 'IT' => '+39',   'JM' => '+1',
            'JO' => '+962', 'JP' => '+81',  'KE' => '+254',  'KG' => '+996',
            'KH' => '+855', 'KI' => '+686', 'KM' => '+269',  'KN' => '+1',
            'KP' => '+850', 'KR' => '+82',  'KW' => '+965',  'KZ' => '+7',
            'LA' => '+856', 'LB' => '+961', 'LC' => '+1',    'LI' => '+423',
            'LK' => '+94',  'LR' => '+231', 'LS' => '+266',  'LT' => '+370',
            'LU' => '+352', 'LV' => '+371', 'LY' => '+218',  'MA' => '+212',
            'MC' => '+377', 'MD' => '+373', 'ME' => '+382',  'MG' => '+261',
            'MK' => '+389', 'ML' => '+223', 'MM' => '+95',   'MN' => '+976',
            'MO' => '+853', 'MR' => '+222', 'MT' => '+356',  'MU' => '+230',
            'MV' => '+960', 'MW' => '+265', 'MX' => '+52',   'MY' => '+60',
            'MZ' => '+258', 'NA' => '+264', 'NE' => '+227',  'NG' => '+234',
            'NI' => '+505', 'NL' => '+31',  'NO' => '+47',   'NP' => '+977',
            'NR' => '+674', 'NZ' => '+64',  'OM' => '+968',  'PA' => '+507',
            'PE' => '+51',  'PG' => '+675', 'PH' => '+63',   'PK' => '+92',
            'PL' => '+48',  'PT' => '+351', 'PY' => '+595',  'QA' => '+974',
            'RO' => '+40',  'RS' => '+381', 'RU' => '+7',    'RW' => '+250',
            'SA' => '+966', 'SB' => '+677', 'SC' => '+248',  'SD' => '+249',
            'SE' => '+46',  'SG' => '+65',  'SI' => '+386',  'SK' => '+421',
            'SL' => '+232', 'SM' => '+378', 'SN' => '+221',  'SO' => '+252',
            'SR' => '+597', 'SS' => '+211', 'ST' => '+239',  'SV' => '+503',
            'SY' => '+963', 'SZ' => '+268', 'TD' => '+235',  'TG' => '+228',
            'TH' => '+66',  'TJ' => '+992', 'TL' => '+670',  'TM' => '+993',
            'TN' => '+216', 'TO' => '+676', 'TR' => '+90',   'TT' => '+1',
            'TV' => '+688', 'TW' => '+886', 'TZ' => '+255',  'UA' => '+380',
            'UG' => '+256', 'US' => '+1',   'UY' => '+598',  'UZ' => '+998',
            'VA' => '+379', 'VC' => '+1',   'VE' => '+58',   'VN' => '+84',
            'VU' => '+678', 'WS' => '+685', 'XK' => '+383',  'YE' => '+967',
            'ZA' => '+27',  'ZM' => '+260', 'ZW' => '+263',
        ];

        $countryCode = strtoupper(trim($countryCode));
        return isset($codes[$countryCode]) ? $codes[$countryCode] : '';
    }


    /**
     * Get the successful invoice sync record for a WHMCS invoice.
     *
     * @param int $invoiceId
     * @return object|null
     */
    public static function getSyncedInvoice($invoiceId)
    {
        try {
            if (class_exists('\\WHMCS\\Database\\Capsule')) {
                return \WHMCS\Database\Capsule::table('mod_oblio_invoices')
                    ->where('invoice_id', $invoiceId)
                    ->where('oblio_type', 'invoice')
                    ->where('status', 'success')
                    ->first();
            }
        } catch (\Exception $e) {
            // Fall through
        }
        return null;
    }

    /**
     * Get the most recent paid transaction for a WHMCS invoice.
     *
     * @param int $invoiceId
     * @return array|null Transaction record or null
     */
    public static function getLastTransaction($invoiceId)
    {
        $result = localAPI('GetTransactions', ['invoiceid' => $invoiceId]);
        if (empty($result['transactions']['transaction'])) {
            return null;
        }

        $transactions = $result['transactions']['transaction'];
        // Normalize single transaction (WHMCS returns flat array for one result)
        if (isset($transactions['id'])) {
            $transactions = [$transactions];
        }

        $last = null;
        foreach ($transactions as $t) {
            if ((float)($t['amountin'] ?? 0) > 0) {
                $last = $t;
            }
        }
        return $last;
    }

    /**
     * Map a WHMCS payment gateway name to an Oblio collect type.
     *
     * @param string $gateway WHMCS gateway name (e.g. 'stripe', 'banktransfer')
     * @param string $default Fallback collect type
     * @return string Oblio collect type
     */
    public static function mapGatewayToCollectType($gateway, $default = 'Ordin de plata')
    {
        $gateway = strtolower(trim($gateway));

        // Check admin-configured mapping first
        try {
            if (class_exists('\\WHMCS\\Database\\Capsule')) {
                $dbType = \WHMCS\Database\Capsule::table('mod_oblio_gateway_map')
                    ->where('gateway', $gateway)
                    ->value('collect_type');
                if ($dbType) {
                    return $dbType;
                }
            }
        } catch (\Exception $e) {
            // Fall through to built-in map
        }

        $map = [
            'card'         => 'Card',
            'stripe'       => 'Card',
            'paypal'       => 'Card',
            'braintree'    => 'Card',
            'square'       => 'Card',
            'cash'         => 'Chitanta',
            'mailin'       => 'Chitanta',
            'banktransfer' => 'Ordin de plata',
            'bank'         => 'Ordin de plata',
            'transfer'     => 'Ordin de plata',
            'wire'         => 'Ordin de plata',
            'offline'      => 'Ordin de plata',
            'online'       => 'Online',
            'netopia'      => 'Online',
            'payu'         => 'Online',
            'euplatesc'    => 'Online',
        ];

        foreach ($map as $keyword => $type) {
            if (strpos($gateway, $keyword) !== false) {
                return $type;
            }
        }

        return $default;
    }

    /**
     * Create a storno (credit note) invoice in WHMCS for a cancelled/refunded invoice.
     *
     * Duplicates the original invoice's line items with negative amounts and sets
     * the status to the custom 'Storno' value directly in the database. When the
     * caller passes the Oblio storno series/number, the WHMCS invoice is labelled
     * with that same fiscal number (invoicenum) and the WHMCS sequential counter
     * is advanced so the next Paid invoice doesn't collide with it.
     *
     * @param int    $originalInvoiceId WHMCS invoice ID to reverse
     * @param string $origOblioSeries   Original Oblio series (e.g. 'CRK') for notes
     * @param string $origOblioNumber   Original Oblio number (e.g. '0022') for notes
     * @param string $stornoOblioSeries Oblio storno series used as invoicenum prefix
     * @param string $stornoOblioNumber Oblio storno number used as invoicenum suffix
     * @return int New storno invoice ID
     * @throws \Exception
     */
    public static function createStornoInvoice(
        $originalInvoiceId,
        $origOblioSeries = '',
        $origOblioNumber = '',
        $stornoOblioSeries = '',
        $stornoOblioNumber = ''
    ) {
        $original = self::getInvoice($originalInvoiceId);
        if (empty($original)) {
            throw new \Exception('Original invoice #' . $originalInvoiceId . ' not found.');
        }

        $items = $original['items']['item'];
        if (isset($items['id'])) {
            $items = [$items];
        }

        // Prefer referencing the original Oblio fiscal number (e.g. "CRK-0022") over the
        // internal WHMCS ID so the storno reads correctly as an accounting document.
        $origLabel = ($origOblioSeries !== '' && $origOblioNumber !== '')
            ? $origOblioSeries . '-' . $origOblioNumber
            : '#' . $originalInvoiceId;

        $params = [
            'userid'      => $original['userid'],
            'status'      => 'Paid',
            'date'        => date('Ymd'),
            'duedate'     => date('Ymd'),
            'taxrate'     => isset($original['taxrate']) ? (float)$original['taxrate'] : 0,
            'taxrate2'    => isset($original['taxrate2']) ? (float)$original['taxrate2'] : 0,
            'notes'       => 'Storno for Invoice ' . $origLabel,
            'sendinvoice' => false,
        ];

        // CreateInvoice takes line items as flat numbered params (itemdescriptionN / itemamountN / itemtaxedN).
        // Passing them under a 'lineitems' key is silently ignored and produces an empty invoice.
        $n = 1;
        foreach ($items as $item) {
            if ((float)$item['amount'] == 0) {
                continue;
            }
            $params['itemdescription' . $n] = 'Storno: ' . $item['description'];
            $params['itemamount' . $n]      = -round((float)$item['amount'], 2);
            $params['itemtaxed' . $n]       = !empty($item['taxed']) ? 1 : 0;
            $n++;
        }

        if ($n === 1) {
            throw new \Exception('No line items to reverse for invoice #' . $originalInvoiceId . '.');
        }

        $result = localAPI('CreateInvoice', $params);

        if ($result['result'] !== 'success') {
            throw new \Exception('Failed to create storno invoice in WHMCS: ' . ($result['message'] ?? 'Unknown error'));
        }

        $stornoId = (int)$result['invoiceid'];

        // Set custom 'Storno' status - tblinvoices.status is a text column, no enum constraint
        $update = ['status' => 'Storno'];
        if ($stornoOblioSeries !== '' && $stornoOblioNumber !== '') {
            $update['invoicenum'] = $stornoOblioSeries . '-' . $stornoOblioNumber;
        }
        \WHMCS\Database\Capsule::table('tblinvoices')
            ->where('id', $stornoId)
            ->update($update);

        // Keep WHMCS's invoice number counters ahead of Oblio's. Otherwise the next
        // Paid invoice could be assigned the same fiscal number we just gave the storno.
        if ($stornoOblioNumber !== '') {
            self::advanceInvoiceNumberCounters($stornoOblioNumber);
        }

        return $stornoId;
    }

    /**
     * Advance whichever WHMCS invoice number counter is enabled so it stays strictly
     * greater than the given Oblio-assigned number. Supports both invoice numbering
     * modes WHMCS offers, and is a safe no-op if neither is enabled:
     *
     *   - Tax-Compliant Invoicing: TaxNextCustomInvoiceNumber stores the *next* number
     *     to assign. We need it to be > oblio_number, so we set it to oblio_number + 1.
     *     Existing zero-padding width is preserved.
     *
     *   - Sequential Paid Invoice Numbering: SequentialInvoiceNumberValue stores the
     *     *last* number assigned (next is current + 1). We need next > oblio_number,
     *     so we set it to at least oblio_number.
     *
     *   - Neither mode enabled: nothing to advance, the storno's invoicenum still
     *     reads CRK-NNNN purely for display and no collision is possible because
     *     WHMCS isn't auto-assigning invoicenums to other invoices anyway.
     *
     * @param string $oblioNumber Latest Oblio-assigned fiscal number (e.g. '0026')
     */
    public static function advanceInvoiceNumberCounters($oblioNumber)
    {
        try {
            if (!class_exists('\\WHMCS\\Database\\Capsule')) {
                return;
            }
            $oblioInt = (int)$oblioNumber;

            $config = \WHMCS\Database\Capsule::table('tblconfiguration')
                ->whereIn('setting', [
                    'TaxCustomInvoiceNumbering',
                    'TaxNextCustomInvoiceNumber',
                    'SequentialInvoiceNumbering',
                    'SequentialInvoiceNumberValue',
                ])
                ->pluck('value', 'setting')
                ->toArray();

            // Tax-Compliant Invoicing mode (stores NEXT number to issue)
            if (!empty($config['TaxCustomInvoiceNumbering']) && isset($config['TaxNextCustomInvoiceNumber'])) {
                $current = (string)$config['TaxNextCustomInvoiceNumber'];
                $needed  = $oblioInt + 1;
                if ((int)$current < $needed) {
                    $width = max(strlen($current), strlen((string)$needed));
                    $new   = str_pad((string)$needed, $width, '0', STR_PAD_LEFT);
                    \WHMCS\Database\Capsule::table('tblconfiguration')
                        ->where('setting', 'TaxNextCustomInvoiceNumber')
                        ->update(['value' => $new]);
                }
            }

            // Sequential Paid Invoice Numbering mode (stores LAST number issued)
            if (!empty($config['SequentialInvoiceNumbering']) && isset($config['SequentialInvoiceNumberValue'])) {
                $current = (int)$config['SequentialInvoiceNumberValue'];
                if ($current < $oblioInt) {
                    \WHMCS\Database\Capsule::table('tblconfiguration')
                        ->where('setting', 'SequentialInvoiceNumberValue')
                        ->update(['value' => (string)$oblioInt]);
                }
            }
        } catch (\Exception $e) {
            logActivity('Oblio: Failed to advance invoice number counters: ' . $e->getMessage());
        }
    }

    /**
     * Create a replacement WHMCS invoice that copies an existing invoice's current line
     * items (including any just-added late fee) into a fresh Unpaid invoice.
     *
     * Used by the late-fee amendment flow: an Oblio-issued invoice can't be edited once
     * it's in SPV, so when WHMCS bolts a late fee onto it we storno the original and reissue
     * the whole thing - original items plus the fee - as a brand new fiscal document.
     *
     * The new invoice is created with sendinvoice=true so the customer receives the standard
     * "Invoice Created" email pointing at the new invoice. The due date is pushed out by
     * $dueDays so the replacement isn't itself immediately overdue (which would re-trigger
     * the late-fee cron and loop).
     *
     * @param int $oldInvoiceId WHMCS invoice being replaced
     * @param int $dueDays      Days from today for the new invoice's due date
     * @return int New WHMCS invoice ID
     * @throws \Exception
     */
    public static function createReplacementInvoice($oldInvoiceId, $dueDays = 7)
    {
        $original = self::getInvoice($oldInvoiceId);
        if (empty($original)) {
            throw new \Exception('Original invoice #' . $oldInvoiceId . ' not found.');
        }

        $items = $original['items']['item'];
        if (isset($items['id'])) {
            $items = [$items];
        }

        // Reference the original Oblio fiscal number in the new invoice's notes so the paper
        // trail reads clearly (e.g. "Reissue of CRK-0022 with late fee").
        $synced = self::getSyncedInvoice($oldInvoiceId);
        $origLabel = ($synced && $synced->oblio_series !== '' && $synced->oblio_number !== '')
            ? $synced->oblio_series . '-' . $synced->oblio_number
            : '#' . $oldInvoiceId;

        $params = [
            'userid'      => $original['userid'],
            'status'      => 'Unpaid',
            'date'        => date('Ymd'),
            'duedate'     => date('Ymd', strtotime('+' . (int)$dueDays . ' days')),
            'taxrate'     => isset($original['taxrate']) ? (float)$original['taxrate'] : 0,
            'taxrate2'    => isset($original['taxrate2']) ? (float)$original['taxrate2'] : 0,
            'notes'       => 'Reissue of Invoice ' . $origLabel . ' with late fee (original stornoed).',
            'sendinvoice' => true,
        ];

        // Copy every non-zero line item forward at its original sign (positive), preserving
        // the per-item taxed flag. The late fee WHMCS just added is one of these items.
        $n = 1;
        foreach ($items as $item) {
            if ((float)$item['amount'] == 0) {
                continue;
            }
            $params['itemdescription' . $n] = $item['description'];
            $params['itemamount' . $n]      = round((float)$item['amount'], 2);
            $params['itemtaxed' . $n]       = !empty($item['taxed']) ? 1 : 0;
            $n++;
        }

        if ($n === 1) {
            throw new \Exception('No line items to copy for invoice #' . $oldInvoiceId . '.');
        }

        $result = localAPI('CreateInvoice', $params);
        if ($result['result'] !== 'success') {
            throw new \Exception('Failed to create replacement invoice in WHMCS: ' . ($result['message'] ?? 'Unknown error'));
        }

        return (int)$result['invoiceid'];
    }

    /**
     * Record that an invoice was stornoed-and-replaced (late-fee amendment), linking the
     * old invoice to its replacement so EmailPreSend can suppress the now-defunct invoice's
     * dunning/modified emails.
     *
     * Stored as an 'amended' row in mod_oblio_invoices: invoice_id = old, transaction_id =
     * new invoice id (reusing the column to carry the link without a schema change).
     *
     * @param int $oldInvoiceId
     * @param int $newInvoiceId
     */
    public static function logAmendment($oldInvoiceId, $newInvoiceId)
    {
        self::logSync(
            $oldInvoiceId,
            'amended',
            '',
            '',
            'success',
            'Stornoed and replaced by WHMCS invoice #' . $newInvoiceId,
            $newInvoiceId
        );
    }

    /**
     * Was this invoice stornoed-and-replaced within the last $windowSeconds?
     *
     * Used by EmailPreSend to suppress overdue notices / modified-invoice emails that WHMCS
     * fires for the original invoice during the same cron pass that added the late fee.
     *
     * @param int $invoiceId
     * @param int $windowSeconds
     * @return bool
     */
    public static function wasRecentlyAmended($invoiceId, $windowSeconds = 600)
    {
        try {
            if (class_exists('\\WHMCS\\Database\\Capsule')) {
                $cutoff = date('Y-m-d H:i:s', strtotime('-' . (int)$windowSeconds . ' seconds'));
                return \WHMCS\Database\Capsule::table('mod_oblio_invoices')
                    ->where('invoice_id', $invoiceId)
                    ->where('oblio_type', 'amended')
                    ->where('status', 'success')
                    ->where('created_at', '>=', $cutoff)
                    ->exists();
            }
        } catch (\Exception $e) {
            // Fall through
        }
        return false;
    }

    /**
     * Has this invoice already been amended (stornoed-and-replaced) at any point?
     * Re-entrancy guard so a second AddInvoiceLateFee on the same invoice is a no-op.
     *
     * @param int $invoiceId
     * @return bool
     */
    public static function isAmended($invoiceId)
    {
        try {
            if (class_exists('\\WHMCS\\Database\\Capsule')) {
                return \WHMCS\Database\Capsule::table('mod_oblio_invoices')
                    ->where('invoice_id', $invoiceId)
                    ->where('oblio_type', 'amended')
                    ->where('status', 'success')
                    ->exists();
            }
        } catch (\Exception $e) {
            // Fall through
        }
        return false;
    }
}
