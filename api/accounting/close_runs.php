<?php
/**
 * /api/accounting/close_runs.php — Slice D orchestrator endpoints.
 *
 *   GET                              — list runs (filterable by status + period_id)
 *   GET  ?action=detail&id=N         — single run + checklist tasks + linked packet
 *   POST ?action=start               — body {period_id} → returns the run
 *   POST ?action=refresh             — body {id}        → recompute progress counters
 *   POST ?action=build_packet        — body {id}        → builds packet + artifact
 *   POST ?action=lock                — body {id}        → terminal lock
 *   POST ?action=reopen              — body {id, reason} → supersede + new run
 *
 * RBAC:
 *   list / detail     → `accounting.read`  OR `accounting.connection.view`
 *   start / refresh   → `accounting.write`
 *   build_packet      → `accounting.write`
 *   lock / reopen     → `accounting.approve`
 *
 * Every mutation writes an `accounting_close_*` audit_log event.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/RBAC.php';
require_once __DIR__ . '/../../core/rbac/legacy_map.php';
require_once __DIR__ . '/../../core/accounting/close_runs.php';
require_once __DIR__ . '/../../modules/accounting/lib/accounting.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$uid    = (int) ($user['id'] ?? 0) ?: null;
$method = api_method();
$action = (string) ($_GET['action'] ?? '');

$canRead   = rbac_legacy_can($user, 'accounting.read') || rbac_legacy_can($user, 'accounting.connection.view');
$canWrite  = rbac_legacy_can($user, 'accounting.write');
$canApprove = rbac_legacy_can($user, 'accounting.approve');

// ── List ───────────────────────────────────────────────────────────────
if ($method === 'GET' && $action === '') {
    if (!$canRead) api_error('Forbidden', 403);
    $runs = closeRunList($tid, [
        'status'    => $_GET['status']    ?? null,
        'period_id' => isset($_GET['period_id']) ? (int) $_GET['period_id'] : null,
        'limit'     => isset($_GET['limit']) ? (int) $_GET['limit'] : 50,
    ]);
    api_ok(['runs' => $runs, 'count' => count($runs)]);
}

// ── Detail ─────────────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'detail') {
    if (!$canRead) api_error('Forbidden', 403);
    $id  = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 422);
    $run = closeRunGet($tid, $id);
    if (!$run) api_error('close run not found', 404);
    // Recompute progress so the dashboard sees fresh counters.
    $run   = closeRunRefreshProgress($tid, $id);
    $tasks = closeRunTasks($tid, $id);
    api_ok(['run' => $run, 'tasks' => $tasks]);
}

// ── Start ──────────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'start') {
    if (!$canWrite) api_error('Forbidden', 403);
    $body     = api_json_body();
    $periodId = (int) ($body['period_id'] ?? 0);
    if ($periodId <= 0) api_error('period_id required', 422);
    try {
        $run = closeRunStart($tid, $periodId, $uid);
    } catch (\Throwable $e) {
        api_error($e->getMessage(), 422);
    }
    accountingAudit('accounting_close_run_started', [
        'run_id' => $run['id'], 'period_id' => $periodId,
    ], (int) $run['id']);
    api_ok(['run' => $run]);
}

// ── Refresh progress counters ──────────────────────────────────────────
if ($method === 'POST' && $action === 'refresh') {
    if (!$canWrite) api_error('Forbidden', 403);
    $body = api_json_body();
    $id   = (int) ($body['id'] ?? 0);
    if ($id <= 0) api_error('id required', 422);
    try {
        $run = closeRunRefreshProgress($tid, $id);
    } catch (\Throwable $e) {
        api_error($e->getMessage(), 404);
    }
    api_ok(['run' => $run]);
}

// ── Build packet ───────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'build_packet') {
    if (!$canWrite) api_error('Forbidden', 403);
    $body = api_json_body();
    $id   = (int) ($body['id'] ?? 0);
    if ($id <= 0) api_error('id required', 422);
    try {
        $result = closeRunBuildPacket($tid, $id, $uid);
        $run    = closeRunGet($tid, $id);
    } catch (\Throwable $e) {
        api_error($e->getMessage(), 422);
    }
    accountingAudit('accounting_close_packet_built', $result, $id);
    api_ok(['run' => $run, 'packet' => $result]);
}

// ── Lock ───────────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'lock') {
    if (!$canApprove) api_error('Forbidden', 403);
    $body = api_json_body();
    $id   = (int) ($body['id'] ?? 0);
    if ($id <= 0) api_error('id required', 422);
    try {
        $run = closeRunLock($tid, $id, $uid);
    } catch (\Throwable $e) {
        api_error($e->getMessage(), 422);
    }
    accountingAudit('accounting_close_run_locked', [
        'run_id' => $id, 'period_id' => $run['period_id'] ?? null,
    ], $id);
    api_ok(['run' => $run]);
}

// ── Reopen ─────────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'reopen') {
    if (!$canApprove) api_error('Forbidden', 403);
    $body   = api_json_body();
    $id     = (int) ($body['id'] ?? 0);
    $reason = (string) ($body['reason'] ?? '');
    if ($id <= 0)              api_error('id required', 422);
    if (trim($reason) === '')  api_error('reason required', 422);
    try {
        $newRun = closeRunReopen($tid, $id, $reason, $uid);
    } catch (\Throwable $e) {
        api_error($e->getMessage(), 422);
    }
    accountingAudit('accounting_close_run_reopened', [
        'old_run_id' => $id, 'new_run_id' => $newRun['id'], 'reason' => $reason,
    ], $id);
    api_ok(['old_run_id' => $id, 'new_run' => $newRun]);
}

api_error("unknown action '{$action}' or wrong HTTP method", 400);
