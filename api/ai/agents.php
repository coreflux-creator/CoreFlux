<?php
/**
 * /api/ai/agents.php — Slice 7C Agent Registry endpoints.
 *
 *   GET                              — list agents (filter status / owner_module)
 *   GET  ?action=handoffs            — list handoffs (filter status / from / to)
 *   GET  ?action=handoff_detail&id=N — single handoff
 *   POST ?action=upsert              — body {agent_key, label, …}
 *   POST ?action=handoff             — body {from_agent_key, to_agent_key, reason?, payload?}
 *   POST ?action=resolve             — body {id, status, note?}
 *
 * RBAC: `ai.audit.view` (or accounting.review) for reads.
 *       `ai.gateway.invoke` (or accounting.approve) for writes.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/RBAC.php';
require_once __DIR__ . '/../../core/rbac/legacy_map.php';
require_once __DIR__ . '/../../core/ai/agents.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$uid    = (int) ($user['id'] ?? 0) ?: null;
$method = api_method();
$action = (string) ($_GET['action'] ?? '');

$canRead  = rbac_legacy_can($user, 'ai.audit.view') || rbac_legacy_can($user, 'accounting.review');
$canWrite = rbac_legacy_can($user, 'ai.gateway.invoke') || rbac_legacy_can($user, 'accounting.approve');

if ($method === 'GET' && $action === '') {
    if (!$canRead) api_error('Forbidden', 403);
    $rows = agentRegistryList($tid, [
        'status'       => $_GET['status']       ?? null,
        'owner_module' => $_GET['owner_module'] ?? null,
        'limit'        => isset($_GET['limit']) ? (int) $_GET['limit'] : 200,
    ]);
    api_ok(['agents' => $rows, 'count' => count($rows)]);
}

if ($method === 'GET' && $action === 'handoffs') {
    if (!$canRead) api_error('Forbidden', 403);
    $rows = agentHandoffList($tid, [
        'status'         => $_GET['status']         ?? null,
        'from_agent_key' => $_GET['from_agent_key'] ?? null,
        'to_agent_key'   => $_GET['to_agent_key']   ?? null,
        'limit'          => isset($_GET['limit']) ? (int) $_GET['limit'] : 50,
    ]);
    api_ok(['handoffs' => $rows, 'count' => count($rows)]);
}

if ($method === 'GET' && $action === 'handoff_detail') {
    if (!$canRead) api_error('Forbidden', 403);
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 422);
    $row = agentHandoffGet($tid, $id);
    if (!$row) api_error('handoff not found', 404);
    api_ok(['handoff' => $row]);
}

if ($method === 'POST' && $action === 'upsert') {
    if (!$canWrite) api_error('Forbidden', 403);
    $body = api_json_body();
    try {
        $r = agentRegistryUpsert($tid,
            (string) ($body['agent_key'] ?? ''),
            array_merge($body, ['created_by_user_id' => $uid]));
    } catch (\InvalidArgumentException $e) { api_error($e->getMessage(), 422); }
    api_ok(['agent' => $r]);
}

if ($method === 'POST' && $action === 'handoff') {
    if (!$canWrite) api_error('Forbidden', 403);
    $body = api_json_body();
    try {
        $r = agentHandoffCreate($tid,
            (string) ($body['from_agent_key'] ?? ''),
            (string) ($body['to_agent_key']   ?? ''),
            array_merge($body, ['initiated_by_user_id' => $uid]));
    } catch (\InvalidArgumentException $e) { api_error($e->getMessage(), 422); }
      catch (\RuntimeException        $e) { api_error($e->getMessage(), 404); }
    api_ok(['handoff' => $r]);
}

if ($method === 'POST' && $action === 'resolve') {
    if (!$canWrite) api_error('Forbidden', 403);
    $body = api_json_body();
    $id   = (int) ($body['id'] ?? 0);
    if ($id <= 0) api_error('id required', 422);
    try {
        $r = agentHandoffResolve($tid, $id,
            (string) ($body['status'] ?? ''),
            ['note' => $body['note'] ?? null, 'resolved_by_user_id' => $uid]);
    } catch (\InvalidArgumentException $e) { api_error($e->getMessage(), 422); }
      catch (\RuntimeException        $e) { api_error($e->getMessage(), 422); }
    api_ok(['handoff' => $r]);
}

api_error("unknown action '{$action}' or wrong HTTP method", 400);
