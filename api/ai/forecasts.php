<?php
/**
 * /api/ai/forecasts.php — Slice E cash-forecast reviewer endpoints.
 *
 *   GET                              — list forecasts (newest first)
 *   GET  ?action=detail&id=N         — full forecast (weeks array)
 *   POST ?action=run                 — body {weeks?, starting_at?, currency?}
 *                                      → invokes coreflux.run_cash_forecast
 *
 * RBAC: `accounting.read` for list+detail. `accounting.write` for run.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/RBAC.php';
require_once __DIR__ . '/../../core/rbac/legacy_map.php';
require_once __DIR__ . '/../../core/ai/cash_forecast.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$uid    = (int) ($user['id'] ?? 0) ?: null;
$method = api_method();
$action = (string) ($_GET['action'] ?? '');

$canRead  = rbac_legacy_can($user, 'accounting.read');
$canWrite = rbac_legacy_can($user, 'accounting.write');

if ($method === 'GET' && $action === '') {
    if (!$canRead) api_error('Forbidden', 403);
    $rows = cashForecastList($tid, ['limit' => isset($_GET['limit']) ? (int) $_GET['limit'] : 50]);
    api_ok(['forecasts' => $rows, 'count' => count($rows)]);
}

if ($method === 'GET' && $action === 'detail') {
    if (!$canRead) api_error('Forbidden', 403);
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 422);
    $row = cashForecastGet($tid, $id);
    if (!$row) api_error('forecast not found', 404);
    api_ok(['forecast' => $row]);
}

if ($method === 'POST' && $action === 'run') {
    if (!$canWrite) api_error('Forbidden', 403);
    $body = api_json_body();
    try {
        $result = cashForecastRun($tid, [
            'weeks'         => isset($body['weeks'])       ? (int) $body['weeks']       : null,
            'starting_at'   => isset($body['starting_at']) ? (string) $body['starting_at'] : null,
            'currency'      => isset($body['currency'])    ? (string) $body['currency']    : null,
            'actor_user_id' => $uid,
        ]);
    } catch (\InvalidArgumentException $e) { api_error($e->getMessage(), 422); }
    api_ok(['forecast' => $result]);
}

api_error("unknown action '{$action}' or wrong HTTP method", 400);
