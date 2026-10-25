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
function purepayCall(string $apiKey, string $method, string $path, array $body = [], int $timeoutSec = 25, ?string $idempotencyKey = null, ?string $apiBase = null, ?string $collection = null): array
{
    if ($apiKey === '' || preg_match('/[\r\n]/',$apiKey)) throw new PurePayApiException('Pure//Pay: valid API key required');
    $method = strtoupper($method);
    if ($collection !== null && ($method !== 'GET' || !in_array($collection, ['vendors','bills','payments','bill_payments'], true))) {
        throw new InvalidArgumentException('Collection response validation requires a supported read');
    }
    // A server-configured integration can use its preview without moving the AP rail.
    $url = ($apiBase ?? purepayApiBase()) . '/' . ltrim($path, '/');
    $headers = [
        'Authorization: Bearer ' . $apiKey,
        'Accept: application/json',
        'Content-Type: application/json',
        'User-Agent: CoreFlux/1.0 (+purepay_adapter.php)',
    ];
    if ($method === 'POST') {
        if ($idempotencyKey === null || !preg_match('/^[A-Za-z0-9_-]{16,255}$/', $idempotencyKey)) {
            throw new PurePayApiException('Pure//Pay POST requires a stable idempotency key');
        }
        $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
    }
    $bodyJson = ($method === 'GET' || !$body) ? null : json_encode($body, JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);

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
            CURLOPT_FOLLOWLOCATION => false,
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

    if ($status === 204 && trim($rawBody) === '') {
        if ($collection !== null) purepayIncompleteCollection($collection);
        return [];
    }
    $data = json_decode($rawBody, true);
    if (!is_array($data)) {
        $e = new PurePayApiException("Pure//Pay returned invalid JSON (HTTP {$status})");
        $e->httpStatus = $status ?: null;
        $e->raw = ['body' => substr($rawBody, 0, 2000)];
        $e->outcomeUncertain = $method !== 'GET';
        throw $e;
    }
    if ($status < 200 || $status >= 300 || isset($data['error']) || isset($data['errors'])) {
        $msg = $data['detail'] ?? $data['message'] ?? $data['error'] ?? null;
        if (is_array($msg)) $msg = json_encode($msg, JSON_UNESCAPED_SLASHES);
        if (!$msg && isset($data['errors'])) $msg = json_encode($data['errors'], JSON_UNESCAPED_SLASHES);
        $e = new PurePayApiException('Pure//Pay: ' . str_replace($apiKey,'[redacted]',((string) $msg ?: "HTTP {$status}")));
        $e->httpStatus = $status ?: null;
        $e->errorCode = (string) ($data['code'] ?? $data['error_code'] ?? '');
        $e->raw = $data;
        $e->outcomeUncertain = $method !== 'GET' && !in_array($status,[400,401,403,404,422],true);
        throw $e;
    }
    if ($collection !== null) {
        // Associative decoding alone conflates {} and [], including nested values.
        // Only an actual JSON list or the exact legacy list wrapper proves a page.
        $shape = json_decode($rawBody);
        if ($collection === 'bill_payments') {
            if (!($shape instanceof \stdClass) || !is_array($shape->items ?? null)) purepayIncompleteCollection($collection);
        } elseif (!is_array($shape)
            && !($shape instanceof \stdClass && array_keys(get_object_vars($shape)) === [$collection]
                && is_array($shape->{$collection}))) purepayIncompleteCollection($collection);
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
    throw new PurePayApiException('Pure//Pay collection is incomplete or unrecognized; no absence of records can be inferred');
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
function purepayGetVendor(string $apiKey, string $vendorId): array
{
    return purepayCall($apiKey, 'GET', '/vendors/' . rawurlencode($vendorId));
}
function purepayIdempotencyKey(int $tenantId, string $operation, string $sourceRef): string
{
    return hash('sha256', 'coreflux:purepay:' . $tenantId . ':' . $operation . ':' . $sourceRef);
}

function purepayCreateVendor(string $apiKey, array $payload, string $idempotencyKey): array
{
    return purepayCall($apiKey, 'POST', '/vendors', $payload, 25, $idempotencyKey);
}
function purepayListBills(string $apiKey, ?string $status = null): array
{
    $path = '/bills' . ($status ? ('?status=' . rawurlencode($status)) : '');
    return purepayCall($apiKey, 'GET', $path);
}
function purepayCreateBill(string $apiKey, array $payload, string $idempotencyKey): array
{
    return purepayCall($apiKey, 'POST', '/bills', $payload, 25, $idempotencyKey);
}
function purepayGetBill(string $apiKey, string $billId): array
{
    return purepayCall($apiKey, 'GET', '/bills/' . rawurlencode($billId));
}
function purepayPayBill(string $apiKey, string $billId, array $payload, string $idempotencyKey): array
{
    if ($billId === '') throw new PurePayApiException('Pure//Pay: bill id required');
    return purepayCall($apiKey, 'POST', '/bills/' . rawurlencode($billId) . '/pay', $payload, 25, $idempotencyKey);
}
function purepayListPayments(string $apiKey): array { return purepayCall($apiKey, 'GET', '/payments'); }
function purepayGetPayment(string $apiKey, string $paymentId): array
{
    return purepayCall($apiKey, 'GET', '/payments/' . rawurlencode($paymentId));
}
function purepayGetWallet(string $apiKey): array { return purepayCall($apiKey, 'GET', '/wallet'); }

function purepayIncompleteCollection(string $resource): never
{
    $e = new PurePayApiException('Pure//Pay '.$resource.' list could not be verified complete. Reconcile provider records before retrying.');
    $e->errorCode = 'incomplete_collection';
    throw $e;
}

/**
 * Read the complete supported collection before making an absence-based decision.
 * The inspected v1 vendor/bill endpoints support limit/skip (default 200); the
 * payment endpoint silently caps its unpaged response at 1000. Never interpret
 * that cap, an unknown pagination envelope, or repeated pages as an empty match.
 * A complete list is an observation, not a reservation against concurrent payment.
 */
function purepayCompleteCollection(string $apiKey, string $resource, ?string $status = null): array
{
    if (!in_array($resource, ['vendors', 'bills', 'payments'], true) || ($status !== null && $resource !== 'bills')) {
        throw new InvalidArgumentException('Choose a supported Pure//Pay collection');
    }
    $refuse = static fn() => purepayIncompleteCollection($resource);
    $pageSize = 200;
    $maxRows = 10000;
    $all = [];
    $seen = [];
    $idAlias = ['vendors'=>'vendor_id', 'bills'=>'bill_id', 'payments'=>'payment_id'][$resource];
    for ($skip = 0; $skip <= $maxRows; $skip += $pageSize) {
        $query = $resource === 'payments' ? [] : ['limit'=>$pageSize, 'skip'=>$skip];
        if ($status !== null && $status !== '') $query['status'] = $status;
        $path = '/'.$resource.($query ? '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
        $response = purepayCall($apiKey, 'GET', $path, collection: $resource);
        // v1 publishes bare arrays. Preserve the exact legacy resource wrapper,
        // but require a new contract review for metadata/cursors we cannot honor.
        if (array_is_list($response)) $rows = $response;
        elseif (array_keys($response) === [$resource] && is_array($response[$resource]) && array_is_list($response[$resource])) $rows = $response[$resource];
        else $refuse();
        $count = count($rows);
        if (($resource === 'payments' && $count >= 1000)
            || ($resource !== 'payments' && $count > $pageSize)
            || count($all) + $count > $maxRows) $refuse();
        foreach ($rows as $row) {
            if (!is_array($row)) $refuse();
            $id = $row['id'] ?? $row[$idAlias] ?? null;
            if (!is_string($id) || trim($id) === '' || strlen($id) > 255 || preg_match('/[\x00-\x1f\x7f]/', $id)
                || (isset($row['id'], $row[$idAlias]) && $row['id'] !== $row[$idAlias]) || isset($seen[$id])) $refuse();
            if ($resource === 'payments') {
                $bill = $row['bill_id'] ?? $row['payable_id'] ?? null;
                if (!is_string($bill) || trim($bill) === '' || strlen($bill) > 255 || preg_match('/[\x00-\x1f\x7f]/', $bill)
                    || (isset($row['bill_id'], $row['payable_id']) && $row['bill_id'] !== $row['payable_id'])) $refuse();
            }
            $seen[$id] = true;
            $all[] = $row;
        }
        if ($resource === 'payments' || $count < $pageSize) return $all;
    }
    $refuse();
}

/** Complete stored history of this bill, independent of unrelated organization volume.
 * Rechecked reads are observations, not cross-system reservations or settlement proof.
 */
function purepayCompleteBillPayments(string $apiKey, string $billId): array
{
    $identifier = static fn($v) => is_string($v) && trim($v) !== '' && strlen($v) <= 255 && !preg_match('/[\x00-\x1f\x7f]/',$v);
    if (!$identifier($billId)) throw new InvalidArgumentException('A valid PurePay bill identity is required');
    $refuse = static fn() => purepayIncompleteCollection('bill payments');
    try {
        $response = purepayCall($apiKey,'GET','/bills/'.rawurlencode($billId).'/payments',collection:'bill_payments');
    } catch (PurePayApiException $e) {
        if ($e->httpStatus !== 404) $refuse();
        // An older provider has no scoped endpoint. Only a verified complete legacy
        // collection can supply an answer; its 1,000-row cap continues to refuse.
        try { $legacy = purepayCompleteCollection($apiKey,'payments'); }
        catch (PurePayApiException $legacyError) { $refuse(); }
        return array_values(array_filter($legacy,static fn($row)=>($row['bill_id']??$row['payable_id'])===$billId));
    }
    $keys = array_keys($response); sort($keys);
    $expected = ['contract_version','organization_id','bill_id','complete','total','items','consistency','observed_at']; sort($expected);
    if ($keys !== $expected || $response['contract_version'] !== '2026-10-25.1'
        || $response['consistency'] !== 'rechecked_read_not_transactional_snapshot'
        || !$identifier($response['organization_id']) || $response['bill_id'] !== $billId
        || $response['complete'] !== true || !is_int($response['total'])
        || !is_array($response['items']) || !array_is_list($response['items'])
        || $response['total'] !== count($response['items']) || $response['total'] > 10000
        || !is_string($response['observed_at'])
        || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D',$response['observed_at'])) $refuse();
    $seen = [];
    foreach ($response['items'] as $item) {
        if (!is_array($item)) $refuse();
        $keys = array_keys($item); sort($keys);
        if ($keys !== ['amount','bill_id','currency','id','status'] || !$identifier($item['id'])
            || $item['bill_id'] !== $billId || isset($seen[$item['id']])
            || ($item['status'] !== null && (!is_string($item['status']) || trim($item['status']) === ''
                || strlen($item['status']) > 64 || preg_match('/[\x00-\x1f\x7f]/',$item['status'])))
            || ($item['amount'] !== null && (!is_string($item['amount']) || !preg_match('/^-?\d{1,11}\.\d{2}$/D',$item['amount'])))
            || ($item['currency'] !== null && (!is_string($item['currency']) || !preg_match('/^[A-Z]{3}$/D',$item['currency'])))) $refuse();
        $seen[$item['id']] = true;
    }
    return $response['items'];
}
