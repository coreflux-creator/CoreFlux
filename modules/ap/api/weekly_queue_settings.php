<?php
/**
 * AP API — Tenant-level weekly queue email schedule.
 *
 *   GET  /api/ap/weekly_queue_settings.php
 *   POST /api/ap/weekly_queue_settings.php  body: {weekly_queue_email_dow, weekly_queue_email_hour}
 *
 * `dow`:  0 = disabled, 1..7 = Mon..Sun (ISO-8601). Default 7 (Sunday).
 * `hour`: 0..23 UTC. Default 22.
 *
 * Permissions: read = ap.bill.view, write = admin/manager role.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];
$method = api_method();

$canWrite = function (array $u): bool {
    $role = (string) ($u['role'] ?? '');
    $g    = (string) ($u['global_role'] ?? '');
    return in_array($role, ['admin','manager'], true)
        || in_array($g,    ['master_admin','tenant_admin'], true);
};

if ($method === 'GET') {
    RBAC::requirePermission($user, 'ap.bill.view');
    $pdo = getDB();
    $row = null;
    try {
        $st = $pdo->prepare('SELECT weekly_queue_email_dow, weekly_queue_email_hour FROM ap_settings WHERE tenant_id = :t');
        $st->execute(['t' => $tid]);
        $row = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $_) { /* migration not applied yet */ }
    api_ok([
        'dow'  => $row['weekly_queue_email_dow']  ?? 7,
        'hour' => $row['weekly_queue_email_hour'] ?? 22,
        'can_write' => $canWrite($user),
    ]);
}

if ($method === 'POST') {
    if (!$canWrite($user)) api_error('Admin/manager role required', 403);
    $body = api_json_body();
    $dow  = (int) ($body['weekly_queue_email_dow']  ?? 7);
    $hour = (int) ($body['weekly_queue_email_hour'] ?? 22);
    if ($dow  < 0 || $dow  > 7)  api_error('dow must be 0..7 (0=disabled)', 422);
    if ($hour < 0 || $hour > 23) api_error('hour must be 0..23', 422);
    $pdo = getDB();
    $pdo->prepare(
        'INSERT INTO ap_settings (tenant_id, weekly_queue_email_dow, weekly_queue_email_hour)
         VALUES (:t, :d, :h)
         ON DUPLICATE KEY UPDATE
            weekly_queue_email_dow = VALUES(weekly_queue_email_dow),
            weekly_queue_email_hour= VALUES(weekly_queue_email_hour)'
    )->execute(['t' => $tid, 'd' => $dow, 'h' => $hour]);
    api_ok(['ok' => true, 'dow' => $dow, 'hour' => $hour]);
}

api_error('Method not allowed', 405);
