<?php
/**
 * /api/ai/workers.php — Slice 7A admin endpoints for the worker queue.
 *
 *   GET  ?action=workers     — list workers + status (online/stalled/etc.)
 *   GET  ?action=depth       — queue-depth summary (per-status counters)
 *   GET                      — list recent jobs (filterable status + queue)
 *   POST ?action=retry       — body {id} — resurrect a dead/cancelled job
 *   POST ?action=cancel      — body {id, reason?}
 *
 * RBAC: `ai.audit.view` for reads, `ai.gateway.invoke` for writes.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/RBAC.php';
require_once __DIR__ . '/../../core/rbac/legacy_map.php';
require_once __DIR__ . '/../../core/ai/worker.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();
$action = (string) ($_GET['action'] ?? '');

$canRead  = rbac_legacy_can($user, 'ai.audit.view') || rbac_legacy_can($user, 'accounting.review');
$canWrite = rbac_legacy_can($user, 'ai.gateway.invoke') || rbac_legacy_can($user, 'accounting.approve');

if ($method === 'GET' && $action === 'workers') {
    if (!$canRead) api_error('Forbidden', 403);
    api_ok(['workers' => aiWorkerList()]);
}

if ($method === 'GET' && $action === 'depth') {
    if (!$canRead) api_error('Forbidden', 403);
    api_ok(['depth' => aiWorkerQueueDepth($tid)]);
}

if ($method === 'GET' && $action === '') {
    if (!$canRead) api_error('Forbidden', 403);
    $jobs = aiWorkerJobList($tid, [
        'status' => $_GET['status'] ?? null,
        'queue'  => $_GET['queue']  ?? null,
        'limit'  => isset($_GET['limit']) ? (int) $_GET['limit'] : 100,
    ]);
    api_ok(['jobs' => $jobs, 'count' => count($jobs)]);
}

if ($method === 'POST' && $action === 'retry') {
    if (!$canWrite) api_error('Forbidden', 403);
    $body = api_json_body();
    $id   = (int) ($body['id'] ?? 0);
    if ($id <= 0) api_error('id required', 422);
    $ok = aiWorkerRetry($tid, $id);
    if (!$ok) api_error('job not retryable from current state', 422);
    api_ok(['job' => aiWorkerJobGet($tid, $id)]);
}

if ($method === 'POST' && $action === 'cancel') {
    if (!$canWrite) api_error('Forbidden', 403);
    $body = api_json_body();
    $id   = (int) ($body['id'] ?? 0);
    if ($id <= 0) api_error('id required', 422);
    $ok = aiWorkerCancel($tid, $id, $body['reason'] ?? null);
    if (!$ok) api_error('job not cancellable from current state', 422);
    api_ok(['job' => aiWorkerJobGet($tid, $id)]);
}

api_error("unknown action '{$action}' or wrong HTTP method", 400);
