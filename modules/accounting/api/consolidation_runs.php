<?php
/**
 * Accounting API — Consolidation run snapshots.
 *
 *   GET    /api/accounting/consolidation_runs                → list (optional ?report_type=)
 *   GET    /api/accounting/consolidation_runs?id=N           → detail with payload
 *   POST   /api/accounting/consolidation_runs?action=lock    → compute + lock snapshot
 *   POST   /api/accounting/consolidation_runs?action=reverse&id=N  → unlock (requires reason)
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/accounting.php';
require_once __DIR__ . '/../lib/consolidation.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$uid    = (int) ($user['id'] ?? 0) ?: null;
$method = api_method();
$action = (string) ($_GET['action'] ?? '');

if ($method === 'GET' && !empty($_GET['id'])) {
    rbac_legacy_require($user, 'accounting.reports.view');
    $row = consolidationGetRun($tid, (int) $_GET['id']);
    if (!$row) api_error('Not found', 404);
    api_ok(['run' => $row]);
}
if ($method === 'GET') {
    rbac_legacy_require($user, 'accounting.reports.view');
    api_ok(['rows' => consolidationListRuns($tid, $_GET['report_type'] ?? null)]);
}

if ($method === 'POST' && $action === 'lock') {
    rbac_legacy_require($user, 'accounting.reports.export');
    $body = api_json_body();
    try { $res = consolidationLockRun($tid, $body, $uid); }
    catch (\Throwable $e) { api_error($e->getMessage(), 422); }
    api_ok($res, 201);
}

if ($method === 'POST' && $action === 'reverse') {
    rbac_legacy_require($user, 'accounting.reports.export');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    $body   = api_json_body();
    $reason = trim((string) ($body['reason'] ?? ''));
    try { consolidationReverseRun($tid, $id, $reason, $uid); }
    catch (\Throwable $e) { api_error($e->getMessage(), 409); }
    api_ok(['ok' => true]);
}

api_error('Method not allowed', 405);
