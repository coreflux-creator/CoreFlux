<?php
/**
 * Shared platform audit writer.
 *
 * The audit_log table exists in both legacy and enterprise shapes. This helper
 * writes the canonical fields when available, falls back to legacy aliases, and
 * mirrors request/source/object metadata into meta_json so older schemas remain
 * searchable by the normalized audit API.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function platformAuditLogWrite(
    ?int $tenantId,
    ?int $actorUserId,
    string $event,
    ?int $targetId = null,
    array $meta = [],
    array $opts = []
): void {
    $event = trim($event);
    if ($event === '') return;
    if (!function_exists('getDB')) return;
    $pdo = getDB();
    if (!$pdo) return;

    try {
        $cols = platformAuditLogColumns($pdo);
        $values = [];
        $has = static fn(string $column): bool => in_array($column, $cols, true);
        $add = static function (string $column, mixed $value) use (&$values, $has): void {
            if ($has($column)) $values[$column] = $value;
        };

        $actorEmail = platformAuditScalar(
            $opts['actor_email'] ?? $meta['actor_email'] ?? ($_SESSION['user']['email'] ?? null),
            255
        );
        $actorType = platformAuditScalar(
            $opts['actor_type'] ?? $meta['actor_type'] ?? ($actorUserId ? 'user' : 'system'),
            40
        );
        $objectType = platformAuditScalar(
            $opts['object_type'] ?? $meta['object_type'] ?? platformAuditObjectTypeFromEvent($event),
            80
        );
        $requestId = platformAuditRequestId($meta, $opts);
        $source = platformAuditScalar($opts['source'] ?? $meta['source'] ?? platformAuditSourceFromEvent($event), 80);
        $ipAddress = platformAuditScalar($opts['ip_address'] ?? ($_SERVER['REMOTE_ADDR'] ?? null), 45);
        $userAgent = platformAuditScalar($opts['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? null), 255);
        $before = $opts['before_json'] ?? $opts['before'] ?? $meta['before_json'] ?? $meta['before'] ?? null;
        $after = $opts['after_json'] ?? $opts['after'] ?? $meta['after_json'] ?? $meta['after'] ?? null;

        $metaForSearch = $meta;
        if ($requestId !== null && !array_key_exists('request_id', $metaForSearch)) $metaForSearch['request_id'] = $requestId;
        if ($source !== null && !array_key_exists('source', $metaForSearch)) $metaForSearch['source'] = $source;
        if ($objectType !== null && !array_key_exists('object_type', $metaForSearch)) $metaForSearch['object_type'] = $objectType;

        $add('tenant_id', $tenantId);
        $add('actor_user_id', $actorUserId);
        $add('user_id', $actorUserId);
        $add('actor_type', $actorType);
        $add('actor_email', $actorEmail);
        if ($has('event')) {
            $values['event'] = $event;
        } elseif ($has('action')) {
            $values['action'] = $event;
        }
        if ($has('target_id')) {
            $values['target_id'] = $targetId;
        } elseif ($has('entity_id')) {
            $values['entity_id'] = $targetId;
        }
        $add('object_type', $objectType);
        $add('entity', $objectType);
        $add('before_json', platformAuditJson($before));
        $add('after_json', platformAuditJson($after));
        $add('meta_json', platformAuditJson($metaForSearch));
        $add('ip_address', $ipAddress);
        $add('request_id', $requestId);
        $add('source', $source);
        $add('user_agent', $userAgent);

        if (!$values) return;
        $columns = array_keys($values);
        $placeholders = array_map(static fn(string $column): string => ':' . $column, $columns);
        if ($has('created_at')) {
            $columns[] = 'created_at';
            $placeholders[] = 'NOW()';
        }
        $sql = 'INSERT INTO audit_log (`' . implode('`, `', $columns) . '`) VALUES (' . implode(', ', $placeholders) . ')';
        $pdo->prepare($sql)->execute($values);
    } catch (Throwable $e) {
        error_log('[platform.audit] ' . $event . ' failed: ' . $e->getMessage());
    }
}

function platformAuditLogColumns(PDO $pdo): array
{
    static $cache = [];
    $key = spl_object_id($pdo);
    if (isset($cache[$key])) return $cache[$key];
    try {
        $rows = $pdo->query('SHOW COLUMNS FROM audit_log')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $cache[$key] = array_values(array_filter(array_map(
            static fn($row): string => (string) ($row['Field'] ?? ''),
            $rows
        )));
    } catch (Throwable $_) {
        $cache[$key] = [
            'tenant_id', 'actor_user_id', 'user_id', 'actor_type', 'actor_email',
            'event', 'action', 'target_id', 'entity_id', 'object_type', 'entity',
            'before_json', 'after_json', 'meta_json', 'ip_address', 'request_id',
            'source', 'user_agent', 'created_at',
        ];
    }
    return $cache[$key];
}

function platformAuditJson(mixed $value): ?string
{
    if ($value === null) return null;
    if (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed === '') return null;
        json_decode($trimmed, true);
        if (json_last_error() === JSON_ERROR_NONE) return $trimmed;
    }
    $json = json_encode($value, JSON_UNESCAPED_SLASHES);
    return $json === false ? null : $json;
}

function platformAuditScalar(mixed $value, int $maxLength): ?string
{
    if ($value === null) return null;
    if (is_bool($value)) $value = $value ? '1' : '0';
    if (is_array($value) || is_object($value)) return null;
    $text = trim((string) $value);
    if ($text === '') return null;
    return substr($text, 0, $maxLength);
}

function platformAuditRequestId(array $meta = [], array $opts = []): ?string
{
    foreach ([
        $opts['request_id'] ?? null,
        $meta['request_id'] ?? null,
        $GLOBALS['CF_API_REQUEST_ID'] ?? null,
        $_SERVER['HTTP_X_REQUEST_ID'] ?? null,
        $_SERVER['HTTP_X_CORRELATION_ID'] ?? null,
    ] as $candidate) {
        $requestId = platformAuditScalar($candidate, 80);
        if ($requestId !== null) return $requestId;
    }
    return null;
}

function platformAuditSourceFromEvent(string $event): ?string
{
    $event = trim($event);
    if ($event === '') return null;
    $head = strtok($event, '.');
    return $head !== false ? platformAuditScalar($head, 80) : null;
}

function platformAuditObjectTypeFromEvent(string $event): ?string
{
    $parts = array_values(array_filter(explode('.', $event), static fn($part): bool => $part !== ''));
    if (!$parts) return null;
    if (count($parts) >= 2) return platformAuditScalar($parts[0] . '_' . $parts[1], 80);
    return platformAuditScalar($parts[0], 80);
}
