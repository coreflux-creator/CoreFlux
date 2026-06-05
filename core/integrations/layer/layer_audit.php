<?php
/**
 * LayerFi integration audit writer.
 *
 * Writes a structured row to `integration_audit_log` (provider-neutral) and
 * mirrors a compact event into the unified `audit_log` so LayerFi activity
 * also shows up in the existing Accounting audit surface.
 *
 * Best-effort: never throws. Defense-in-depth scrubbing strips any known
 * secret-bearing keys from metadata so a token can never leak into the log.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/layer_config.php';

const LAYER_AUDIT_SECRET_KEYS = [
    'client_secret', 'clientSecret', 'access_token', 'accessToken',
    'businessAccessToken', 'business_access_token', 'authorization',
    'password', 'platform_token', 'platformToken',
];

function layer_audit(string $action, string $status, array $opts = []): void
{
    $tenantId = $opts['tenant_id'] ?? (function_exists('currentTenantId') ? currentTenantId() : null);
    $userId   = $opts['user_id']   ?? ($_SESSION['user']['id'] ?? null);
    $meta     = is_array($opts['metadata'] ?? null) ? $opts['metadata'] : [];

    foreach (LAYER_AUDIT_SECRET_KEYS as $k) {
        if (array_key_exists($k, $meta)) $meta[$k] = '***redacted***';
    }

    $cfg = layer_config();
    $requestId = $opts['request_id'] ?? ($GLOBALS['CF_API_REQUEST_ID'] ?? null);

    try {
        getDB()->prepare(
            'INSERT INTO integration_audit_log
                (tenant_id, user_id, provider, environment, action, external_object_type,
                 external_object_id, status, request_id, error_code, error_message, metadata, created_at)
             VALUES (:t, :u, :p, :e, :a, :ot, :oid, :s, :rid, :ec, :em, :md, NOW())'
        )->execute([
            't'   => $tenantId,
            'u'   => $userId,
            'p'   => 'layer',
            'e'   => $cfg['environment'],
            'a'   => $action,
            'ot'  => $opts['object_type'] ?? null,
            'oid' => $opts['object_id'] ?? null,
            's'   => $status,
            'rid' => $requestId,
            'ec'  => $opts['error_code'] ?? null,
            'em'  => isset($opts['error_message']) ? mb_substr((string) $opts['error_message'], 0, 500) : null,
            'md'  => json_encode($meta, JSON_UNESCAPED_SLASHES),
        ]);
    } catch (\Throwable $e) {
        error_log('[layer.audit] ' . $action . ' integration_audit_log write-failed: ' . $e->getMessage());
    }

    // Mirror into the unified audit_log (event-shaped) — best-effort.
    try {
        getDB()->prepare(
            'INSERT INTO audit_log (tenant_id, actor_user_id, event, target_id, meta_json, ip_address, created_at)
             VALUES (:t, :u, :ev, :tid, :mj, :ip, NOW())'
        )->execute([
            't'   => $tenantId,
            'u'   => $userId,
            'ev'  => $action,
            'tid' => null,
            'mj'  => json_encode(['status' => $status] + $meta, JSON_UNESCAPED_SLASHES),
            'ip'  => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (\Throwable $e) {
        /* unified mirror is optional */
    }
}
