<?php

namespace WHMCS\Module\Addon\Oblio;

/**
 * Oblio API Client
 *
 * Handles authentication and API communication with Oblio.eu
 * Documentation: https://www.oblio.eu/api
 */
class OblioApi
{
    const BASE_URL = 'https://www.oblio.eu';
    const TOKEN_ENDPOINT = '/api/authorize/token';
    const DOCS_ENDPOINT = '/api/docs/';
    const NOMENCLATURE_ENDPOINT = '/api/nomenclature/';

    /** @var string */
    private $clientId;

    /** @var string */
    private $clientSecret;

    /** @var string|null */
    private $accessToken;

    /** @var int */
    private $tokenExpiresAt = 0;

    /**
     * @param string $clientId    Oblio API email/client ID
     * @param string $clientSecret Oblio API secret key
     */
    public function __construct($clientId, $clientSecret)
    {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
    }

    /**
     * Authenticate with the Oblio API using OAuth2 client credentials.
     * Uses application/x-www-form-urlencoded as required by the Oblio API.
     *
     * @return string Access token
     * @throws \Exception
     */
    public function authenticate()
    {
        if ($this->accessToken && time() < $this->tokenExpiresAt) {
            return $this->accessToken;
        }

        $response = $this->makeRequest('POST', self::TOKEN_ENDPOINT, [
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type'    => 'client_credentials',
        ], false, true);

        if (empty($response['access_token'])) {
            throw new \Exception('Oblio API authentication failed: ' . json_encode($response));
        }

        $this->accessToken = $response['access_token'];
        // Subtract 60 seconds as a buffer to account for clock skew and prevent
        // authentication failures if the token expires during a request.
        $this->tokenExpiresAt = time() + (int)($response['expires_in'] ?? 3600) - 60;

        return $this->accessToken;
    }

    /**
     * Create a document in Oblio.
     *
     * @param string $type Document type (currently only 'invoice' is used by this addon)
     * @param array  $data Document data
     * @return array API response
     * @throws \Exception
     */
    public function createDocument($type, array $data)
    {
        $this->authenticate();
        return $this->makeRequest('POST', self::DOCS_ENDPOINT . $type, $data, true);
    }

    /**
     * Get a document from Oblio.
     *
     * @param string $type       Document type (currently only 'invoice' is used by this addon)
     * @param string $cif        Company CIF
     * @param string $seriesName Series name
     * @param string $number     Document number
     * @return array API response
     * @throws \Exception
     */
    public function getDocument($type, $cif, $seriesName, $number)
    {
        $this->authenticate();
        $query = http_build_query([
            'cif'        => $cif,
            'seriesName' => $seriesName,
            'number'     => $number,
        ]);
        return $this->makeRequest('GET', self::DOCS_ENDPOINT . $type . '?' . $query, [], true);
    }

    /**
     * Delete a document from Oblio (only works for last document in a series).
     *
     * @param string $type       Document type (currently only 'invoice' is used by this addon)
     * @param string $cif        Company CIF
     * @param string $seriesName Series name
     * @param string $number     Document number
     * @return array API response
     * @throws \Exception
     */
    public function deleteDocument($type, $cif, $seriesName, $number)
    {
        $this->authenticate();
        return $this->makeRequest('DELETE', self::DOCS_ENDPOINT . $type, [
            'cif'        => $cif,
            'seriesName' => $seriesName,
            'number'     => $number,
        ], true, true);
    }

    /**
     * Cancel a document in Oblio.
     *
     * @param string $type       Document type
     * @param string $cif        Company CIF
     * @param string $seriesName Series name
     * @param string $number     Document number
     * @return array API response
     * @throws \Exception
     */
    public function cancelDocument($type, $cif, $seriesName, $number)
    {
        $this->authenticate();
        return $this->makeRequest('PUT', self::DOCS_ENDPOINT . $type . '/cancel', [
            'cif'        => $cif,
            'seriesName' => $seriesName,
            'number'     => $number,
        ], true, true);
    }

    /**
     * Restore a cancelled document in Oblio.
     *
     * @param string $type       Document type
     * @param string $cif        Company CIF
     * @param string $seriesName Series name
     * @param string $number     Document number
     * @return array API response
     * @throws \Exception
     */
    public function restoreDocument($type, $cif, $seriesName, $number)
    {
        $this->authenticate();
        return $this->makeRequest('PUT', self::DOCS_ENDPOINT . $type . '/restore', [
            'cif'        => $cif,
            'seriesName' => $seriesName,
            'number'     => $number,
        ], true, true);
    }

    /**
     * Collect (record payment for) an invoice in Oblio.
     *
     * @param string $cif        Company CIF
     * @param string $seriesName Series name
     * @param string $number     Document number
     * @param array  $collect    Collection parameters (type, seriesName/documentNumber, value, etc.)
     * @return array API response
     * @throws \Exception
     */
    public function collectInvoice($cif, $seriesName, $number, array $collect)
    {
        $this->authenticate();
        return $this->makeRequest('PUT', self::DOCS_ENDPOINT . 'invoice/collect', [
            'cif'        => $cif,
            'seriesName' => $seriesName,
            'number'     => $number,
            'collect'    => $collect,
        ], true, true);
    }

    /**
     * Create a storno (total reversal) invoice in Oblio referencing the original invoice.
     *
     * @param string $cif                Company CIF
     * @param string $seriesName         Series name for the new storno document
     * @param string $originalSeriesName Series name of the original invoice
     * @param string $originalNumber     Number of the original invoice
     * @return array API response
     * @throws \Exception
     */
    public function stornoInvoice($cif, $seriesName, $originalSeriesName, $originalNumber)
    {
        $this->authenticate();
        return $this->makeRequest('POST', self::DOCS_ENDPOINT . 'invoice', [
            'cif'               => $cif,
            'seriesName'        => $seriesName,
            'referenceDocument' => [
                'type'       => 'Factura',
                'refund'     => 1,
                'seriesName' => $originalSeriesName,
                'number'     => $originalNumber,
            ],
        ], true);
    }

    /**
     * Send an invoice to SPV (e-Factura) in Oblio.
     *
     * @param string $cif        Company CIF
     * @param string $seriesName Series name
     * @param string $number     Document number
     * @return array API response
     * @throws \Exception
     */
    public function sendToSPV($cif, $seriesName, $number)
    {
        $this->authenticate();
        return $this->makeRequest('POST', self::DOCS_ENDPOINT . 'einvoice', [
            'cif'        => $cif,
            'seriesName' => $seriesName,
            'number'     => $number,
        ], true, true);
    }

    /**
     * Get companies from Oblio nomenclature.
     *
     * @return array
     * @throws \Exception
     */
    public function getCompanies()
    {
        $this->authenticate();
        return $this->makeRequest('GET', self::NOMENCLATURE_ENDPOINT . 'companies', [], true);
    }

    /**
     * Get all document series for a specific company.
     *
     * @param string $cif Company CIF
     * @return array
     * @throws \Exception
     */
    public function getSeries($cif)
    {
        $this->authenticate();
        $query = http_build_query(['cif' => $cif]);
        return $this->makeRequest('GET', self::NOMENCLATURE_ENDPOINT . 'series?' . $query, [], true);
    }

    /**
     * Get VAT rates for a specific company.
     *
     * @param string $cif Company CIF
     * @return array
     * @throws \Exception
     */
    public function getVatRates($cif)
    {
        $this->authenticate();
        $query = http_build_query(['cif' => $cif]);
        return $this->makeRequest('GET', self::NOMENCLATURE_ENDPOINT . 'vat_rates?' . $query, [], true);
    }

    /**
     * Get available languages for a specific company.
     *
     * @param string $cif Company CIF
     * @return array
     * @throws \Exception
     */
    public function getLanguages($cif)
    {
        $this->authenticate();
        $query = http_build_query(['cif' => $cif]);
        return $this->makeRequest('GET', self::NOMENCLATURE_ENDPOINT . 'languages?' . $query, [], true);
    }

    /**
     * Make an HTTP request to the Oblio API.
     *
     * @param string $method      HTTP method (GET, POST, PUT, DELETE)
     * @param string $endpoint    API endpoint path
     * @param array  $data        Request data
     * @param bool   $useAuth     Whether to include authorization header
     * @param bool   $formEncoded Whether to send data as form-encoded (vs JSON)
     * @return array Decoded response
     * @throws \Exception
     */
    private function makeRequest($method, $endpoint, array $data, $useAuth, $formEncoded = false)
    {
        $url = self::BASE_URL . $endpoint;

        $headers = [
            'Accept: application/json',
        ];

        if ($useAuth) {
            $headers[] = 'Authorization: Bearer ' . $this->accessToken;
        }

        $ch = curl_init();

        if ($method === 'GET') {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        } elseif ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($formEncoded) {
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            } else {
                $headers[] = 'Content-Type: application/json';
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if ($formEncoded) {
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            } else {
                $headers[] = 'Content-Type: application/json';
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            if (!empty($data)) {
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            }
        }

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new \Exception('Oblio API cURL error: ' . $curlError);
        }

        $decoded = json_decode($response, true);

        if ($httpCode >= 400) {
            $errorMsg = isset($decoded['statusMessage'])
                ? $decoded['statusMessage']
                : (isset($decoded['error']) ? $decoded['error'] : $response);
            throw new \Exception('Oblio API error (HTTP ' . $httpCode . '): ' . $errorMsg);
        }

        return $decoded ?: [];
    }
}
