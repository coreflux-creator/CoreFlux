<?php
/**
 * /api/ai/workflows.php — workflow runtime surface (Slice 3).
 *
 *   GET                          — list graphs in the registry
 *   GET ?action=list             — list workflow runs (filters: graph, status, user_id)
 *   GET ?id=<uuid>               — full workflow trace (run + checkpoints + approvals)
 *   POST ?action=start           — body {graph, input, sub_tenant_id?, ai_run_id?}
 *                                  → starts a workflow, runs until completion or
 *                                    first approval interrupt.
 *   POST ?action=resume          — body {workflow_run_id}
 *                                  → resumes a paused workflow after its
 *                                    pending approval has been decided.
 *   POST ?action=decide_approval — body {approval_id, decision, decision_payload?}
 *                                  → records reviewer decision; caller may then
 *                                    POST ?action=resume.
 *
 * RBAC:
 *   start         → ai.use
 *   resume        → ai.use
 *   list/detail   → ai.audit.view
 *   decide_approval -> ai.workflow.approve, accounting.approve, or
 *                      platform.ai.admin
 *
 * Slice 3 ships one graph: `transaction_classification`. Future
 * graphs land as additional files in core/ai/workflows/graphs/ and
 * register themselves on require.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/RBAC.php';
require_once __DIR__ . '/../../core/rbac/legacy_map.php';
require_once __DIR__ . '/../../core/ai/workflows/engine.php';
// Register all known graphs. Each graph file self-registers on
// require — keeping the engine generic.
require_once __DIR__ . '/../../core/ai/workflows/graphs/transaction_classification.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();
$action = (string) ($_GET['action'] ?? '');

function aiWorkflowCanDecideApproval(array $user): bool
{
    return rbac_legacy_can($user, 'ai.workflow.approve')
        || rbac_legacy_can($user, 'accounting.approve')
        || rbac_legacy_can($user, 'platform.ai.admin');
}

if ($method === 'GET' && $action === '' && empty($_GET['id'])) {
    rbac_legacy_require($user, 'ai.audit.view');
    api_ok(['graphs' => workflowGraphs()]);
}

if ($method === 'GET' && $action === 'list') {
    rbac_legacy_require($user, 'ai.audit.view');
    $filters = [
        'graph_name' => $_GET['graph_name'] ?? null,
        'status'     => $_GET['status']     ?? null,
        'user_id'    => isset($_GET['user_id']) ? (int) $_GET['user_id'] : null,
    ];
    $limit = (int) ($_GET['limit'] ?? 100);
    api_ok(['runs' => workflowList($tid, $filters, $limit)]);
}

if ($method === 'GET' && !empty($_GET['id'])) {
    rbac_legacy_require($user, 'ai.audit.view');
    $rec = workflowGet($tid, (string) $_GET['id']);
    if (!$rec) api_error('not found', 404);
    api_ok($rec);
}

if ($method === 'POST' && $action === 'start') {
    rbac_legacy_require($user, 'ai.use');
    $body  = api_json_body();
    $graph = trim((string) ($body['graph'] ?? ''));
    if ($graph === '') api_error('graph required', 422);
    $input = is_array($body['input'] ?? null) ? $body['input'] : [];

    $nodeCtx = [
        'user'          => $user,
        'session_id'    => session_id() ?: '',
        'sub_tenant_id' => isset($body['sub_tenant_id']) && (int) $body['sub_tenant_id'] > 0
                            ? (int) $body['sub_tenant_id'] : null,
    ];
    if (!empty($body['ai_run_id'])) $nodeCtx['ai_run_id'] = (string) $body['ai_run_id'];

    try {
        $res = workflowStart($tid, (int) ($user['id'] ?? 0) ?: null, $graph, $input, $nodeCtx);
    } catch (\InvalidArgumentException $e) {
        api_error($e->getMessage(), 422);
    } catch (\Throwable $e) {
        api_error('workflow start failed: ' . $e->getMessage(), 500);
    }
    api_ok($res, 201);
}

if ($method === 'POST' && $action === 'resume') {
    rbac_legacy_require($user, 'ai.use');
    $body  = api_json_body();
    $wfId  = (string) ($body['workflow_run_id'] ?? '');
    if ($wfId === '') api_error('workflow_run_id required', 422);
    $nodeCtx = [
        'user'       => $user,
        'session_id' => session_id() ?: '',
    ];
    try {
        $res = workflowResume($tid, $wfId, $nodeCtx);
    } catch (\InvalidArgumentException $e) {
        api_error($e->getMessage(), 422);
    } catch (\Throwable $e) {
        api_error('workflow resume failed: ' . $e->getMessage(), 500);
    }
    api_ok($res);
}

if ($method === 'POST' && $action === 'decide_approval') {
    if (!aiWorkflowCanDecideApproval($user)) {
        api_error('Forbidden - approval permission required', 403, [
            'required_any' => ['ai.workflow.approve', 'accounting.approve', 'platform.ai.admin'],
        ]);
    }
    $body = api_json_body();
    $apprId   = (int) ($body['approval_id'] ?? 0);
    $decision = (string) ($body['decision'] ?? '');
    $payload  = is_array($body['decision_payload'] ?? null) ? $body['decision_payload'] : [];
    if ($apprId <= 0) api_error('approval_id required', 422);
    try {
        $res = workflowDecideApproval($tid, $apprId, $decision,
                                       (int) ($user['id'] ?? 0) ?: null, $payload);
    } catch (\InvalidArgumentException $e) {
        api_error($e->getMessage(), 422);
    }
    api_ok($res);
}

api_error("unknown action '{$action}' or wrong HTTP method", 400);
