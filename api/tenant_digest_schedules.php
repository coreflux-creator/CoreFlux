<?php
/**
 * Tenant digest schedule admin API.
 *
 *   GET  /api/tenant_digest_schedules.php          → all known digest schedules for tenant
 *   POST /api/tenant_digest_schedules.php          body{digest_key, dow, hour, enabled, recipients?}
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/digest_schedules.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();

$canWrite = function (array $u): bool {
    $g = (string) ($u['global_role'] ?? '');
    $r = (string) ($u['role']        ?? '');
    return in_array($g, ['master_admin','tenant_admin'], true) || in_array($r, ['admin','manager'], true);
};
$ALLOWED_KEYS = ['money_movement', 'dunning', 'ap_weekly_queue'];

if ($method === 'GET') {
    $out = [];
    foreach ($ALLOWED_KEYS as $k) $out[$k] = cf_digest_schedule_get($tid, $k);
    api_ok(['schedules' => $out, 'can_write' => $canWrite($user)]);
}

if ($method === 'POST') {
    if (!$canWrite($user)) api_error('Admin/manager role required', 403);
    $body = api_json_body();
    $key  = (string) ($body['digest_key'] ?? '');
    if (!in_array($key, $ALLOWED_KEYS, true)) api_error("digest_key must be one of: " . implode(', ', $ALLOWED_KEYS), 422);
    $dow  = (int) ($body['dow']  ?? 0);
    $hour = (int) ($body['hour'] ?? 13);
    if ($dow < 0 || $dow > 7)   api_error('dow must be 0..7',  422);
    if ($hour < 0 || $hour > 23) api_error('hour must be 0..23', 422);
    $enabled = !empty($body['enabled']);
    $rec = $body['recipients'] ?? null;
    if ($rec !== null && !is_array($rec)) api_error('recipients must be an array of {email, name}', 422);
    cf_digest_schedule_set($tid, $key, $dow, $hour, $enabled, $rec, (int) ($user['id'] ?? 0) ?: null);
    api_ok(['ok' => true]);
}

api_error('Method not allowed', 405);
