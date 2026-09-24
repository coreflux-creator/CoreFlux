<?php
/** Read-only Quanta v1 API client. See https://helloquanta.app/api/v1/docs. */
declare(strict_types=1);

require_once __DIR__ . '/../encryption.php';
require_once __DIR__ . '/../db.php';

const QUANTA_API_BASE = 'https://helloquanta.app/api/v1';

final class QuantaApiException extends RuntimeException
{
    public int $httpStatus;

    public function __construct(string $message, int $httpStatus = 0)
    {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
    }
}

function quantaConnection(int $tenantId): ?array
{
    $stmt = getDB()->prepare('SELECT * FROM quanta_connections WHERE tenant_id = :t LIMIT 1');
    $stmt->execute(['t' => $tenantId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function quantaApiKey(int $tenantId): string
{
    $connection = quantaConnection($tenantId);
    if (!$connection || $connection['status'] !== 'active' || empty($connection['api_key_ct'])) {
        throw new QuantaApiException('Quanta is not connected for this workspace');
    }
    $key = decryptField((string) $connection['api_key_ct']);
    if (!$key) throw new QuantaApiException('Quanta API key could not be decrypted');
    return $key;
}

/** Test seam receives (method, url, headers) and returns {status, body}. */
function quantaGet(string $apiKey, string $path, array $query = []): array
{
    if ($apiKey === '') throw new QuantaApiException('Quanta API key required');
    if (!preg_match('#^/[a-z0-9/_-]+$#', $path)) throw new InvalidArgumentException('Invalid Quanta API path');
    $url = QUANTA_API_BASE . $path;
    if ($query) $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    $headers = ['Authorization: Bearer ' . $apiKey, 'Accept: application/json'];

    if (isset($GLOBALS['__quanta_transport']) && is_callable($GLOBALS['__quanta_transport'])) {
        $response = ($GLOBALS['__quanta_transport'])('GET', $url, $headers);
        $status = (int) ($response['status'] ?? 0);
        $raw = (string) ($response['body'] ?? '');
    } else {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $result = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($result === false) throw new QuantaApiException('Quanta network error: ' . $error, $status);
        $raw = (string) $result;
    }
    $data = json_decode($raw, true);
    if ($status < 200 || $status >= 300) {
        $detail = is_array($data) ? ($data['detail'] ?? $data['message'] ?? null) : null;
        if (!is_string($detail) || $detail === '') $detail = 'HTTP ' . $status;
        $detail = str_replace($apiKey, '[redacted]', $detail);
        throw new QuantaApiException('Quanta: ' . substr($detail, 0, 300), $status);
    }
    if (!is_array($data)) throw new QuantaApiException('Quanta returned invalid JSON', $status);
    return $data;
}

/** Quanta's cursor contract is {items, next_cursor, has_more}. Never return a partial set. */
function quantaListAll(string $apiKey, string $path, array $query = [], int $maxItems = 10000): array
{
    $query['limit'] = 200;
    $items = [];
    $seenCursors = [];
    for ($page = 0; $page < 100; $page++) {
        $result = quantaGet($apiKey, $path, $query);
        if (!isset($result['items']) || !is_array($result['items']) || !array_is_list($result['items'])) {
            throw new QuantaApiException('Quanta list response has no items array');
        }
        foreach ($result['items'] as $item) {
            if (!is_array($item)) throw new QuantaApiException('Quanta returned an invalid list item');
            $items[] = $item;
            if (count($items) > $maxItems) throw new QuantaApiException('Quanta result exceeds the safe page limit; narrow Changed since');
        }
        if (empty($result['has_more'])) return $items;
        $cursor = (string) ($result['next_cursor'] ?? '');
        if ($cursor === '' || isset($seenCursors[$cursor])) {
            throw new QuantaApiException('Quanta pagination cursor is missing or repeated');
        }
        $seenCursors[$cursor] = true;
        $query['cursor'] = $cursor;
    }
    throw new QuantaApiException('Quanta pagination exceeded 100 pages; narrow Changed since');
}

function quantaCatalog(string $apiKey): array
{
    return [
        'workers' => quantaListAll($apiKey, '/workers', [], 5000),
        'worksites' => quantaListAll($apiKey, '/worksites', [], 2000),
    ];
}

function quantaEntries(string $apiKey, string $updatedSince, string $status = 'approved'): array
{
    if (!in_array($status, ['approved', 'submitted,approved'], true)) {
        throw new InvalidArgumentException('Unsupported Quanta timesheet status filter');
    }
    $since = DateTimeImmutable::createFromFormat('!Y-m-d', $updatedSince);
    if (!$since || $since->format('Y-m-d') !== $updatedSince) {
        throw new InvalidArgumentException('Changed since must be YYYY-MM-DD');
    }
    return quantaListAll($apiKey, '/time-entries', [
        'timesheet_status' => $status,
        'updated_since' => $updatedSince . 'T00:00:00Z',
    ]);
}
