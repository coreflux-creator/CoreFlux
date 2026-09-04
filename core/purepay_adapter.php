<?php
/**
 * Pure//Pay REST adapter.
 *
 * Published contract: https://purepay.online/developers
 * Base: https://purepay.online/api/v1
 */

declare(strict_types=1);

class PurePayApiException extends \RuntimeException
{
    public ?int $httpStatus = null;
    public ?string $errorCode = null;
    public ?array $raw = null;
    public bool $outcomeUncertain = false;
}

function purepayApiBase(): string
{
    $override = trim((string) (getenv('PUREPAY_API_BASE') ?: ''));
    return rtrim($override !== '' ? $override : 'https://purepay.online/api/v1', '/');
}

/**
 * Test seam: $GLOBALS['__purepay_transport'] receives
 * (method, url, headers, bodyJson) and returns {status, body}.
 */
function purepayCall(string $apiKey, string $method, string $path, array $body = [], int $timeoutSec = 25): array
{
    if ($apiKey === '') throw new PurePayApiException('Pure//Pay: API key required');
    $method = strtoupper($method);
    $url = purepayApiBase() . '/' . ltrim($path, '/');
    $headers = [
        'Authorization: Bearer ' . $apiKey,
        'Accept: application/json',
        'Content-Type: application/json',
        'User-Agent: CoreFlux/1.0 (+purepay_adapter.php)',
    ];
    $bodyJson = ($method === 'GET' || !$body) ? null : json_encode($body, JSON_UNESCAPED_SLASHES);

    if (isset($GLOBALS['__purepay_transport']) && is_callable($GLOBALS['__purepay_transport'])) {
        $resp = ($GLOBALS['__purepay_transport'])($method, $url, $headers, $bodyJson);
        $status = (int) ($resp['status'] ?? 0);
        $rawBody = (string) ($resp['body'] ?? '');
    } else {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => $timeoutSec,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($bodyJson !== null) $opts[CURLOPT_POSTFIELDS] = $bodyJson;
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        if ($raw === false || $curlError !== '') {
            $e = new PurePayApiException('Pure//Pay network error: ' . ($curlError ?: 'empty response'));
            $e->httpStatus = $status ?: null;
            $e->outcomeUncertain = $method !== 'GET';
            throw $e;
        }
        $rawBody = (string) $raw;
    }

    if ($status === 204 && trim($rawBody) === '') return [];
    $data = json_decode($rawBody, true);
    if (!is_array($data)) {
        $e = new PurePayApiException("Pure//Pay returned invalid JSON (HTTP {$status})");
        $e->httpStatus = $status ?: null;
        $e->raw = ['body' => substr($rawBody, 0, 2000)];
        $e->outcomeUncertain = $method !== 'GET';
        throw $e;
    }
    if ($status >= 400 || isset($data['error']) || isset($data['errors'])) {
        $msg = $data['detail'] ?? $data['message'] ?? $data['error'] ?? null;
        if (is_array($msg)) $msg = json_encode($msg, JSON_UNESCAPED_SLASHES);
        if (!$msg && isset($data['errors'])) $msg = json_encode($data['errors'], JSON_UNESCAPED_SLASHES);
        $e = new PurePayApiException('Pure//Pay: ' . ((string) $msg ?: "HTTP {$status}"));
        $e->httpStatus = $status ?: null;
        $e->errorCode = (string) ($data['code'] ?? $data['error_code'] ?? '');
        $e->raw = $data;
        $e->outcomeUncertain = $method !== 'GET' && ($status === 0 || $status >= 500);
        throw $e;
    }
    return $data;
}

function purepayCollection(array $response, array $keys): array
{
    if (array_is_list($response)) return $response;
    foreach ($keys as $key) {
        if (isset($response[$key]) && is_array($response[$key])) {
            $value = $response[$key];
            if (array_is_list($value)) return $value;
            foreach (['items', 'rows', 'data'] as $nested) {
                if (isset($value[$nested]) && is_array($value[$nested]) && array_is_list($value[$nested])) {
                    return $value[$nested];
                }
            }
        }
    }
    if (isset($response['data']) && is_array($response['data']) && array_is_list($response['data'])) {
        return $response['data'];
    }
    return [];
}

function purepayResource(array $response, array $keys): array
{
    foreach ($keys as $key) {
        if (isset($response[$key]) && is_array($response[$key]) && !array_is_list($response[$key])) {
            return $response[$key];
        }
    }
    if (isset($response['data']) && is_array($response['data']) && !array_is_list($response['data'])) {
        return $response['data'];
    }
    return $response;
}

function purepayResourceId(array $response, array $keys): string
{
    $resource = purepayResource($response, $keys);
    foreach (['id', 'vendor_id', 'bill_id', 'payment_id'] as $key) {
        if (isset($resource[$key]) && (string) $resource[$key] !== '') return (string) $resource[$key];
    }
    return '';
}

function purepayListVendors(string $apiKey): array { return purepayCall($apiKey, 'GET', '/vendors'); }
function purepayCreateVendor(string $apiKey, array $payload): array { return purepayCall($apiKey, 'POST', '/vendors', $payload); }
function purepayListBills(string $apiKey, ?string $status = null): array
{
    $path = '/bills' . ($status ? ('?status=' . rawurlencode($status)) : '');
    return purepayCall($apiKey, 'GET', $path);
}
function purepayCreateBill(string $apiKey, array $payload): array { return purepayCall($apiKey, 'POST', '/bills', $payload); }
function purepayPayBill(string $apiKey, string $billId, array $payload): array
{
    if ($billId === '') throw new PurePayApiException('Pure//Pay: bill id required');
    return purepayCall($apiKey, 'POST', '/bills/' . rawurlencode($billId) . '/pay', $payload);
}
function purepayListPayments(string $apiKey): array { return purepayCall($apiKey, 'GET', '/payments'); }
function purepayGetWallet(string $apiKey): array { return purepayCall($apiKey, 'GET', '/wallet'); }
