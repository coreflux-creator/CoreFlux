<?php
/**
 * AI agent dispatcher (Sprint 7g).
 *
 *   GET  /api/ai_agents.php?action=list  → catalog of available agents
 *   POST /api/ai_agents.php?action=run&agent=<key> → runs the agent and
 *        returns an aiAsk() envelope ready for <AISuggestion />
 *
 * RBAC: any of `accounting.je.view` (matches existing reports). master_admin
 * '*' covers it. AI tenant gating + feature_class gating happen inside aiAsk()
 * — this endpoint does NOT bypass them.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/ai_agents.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];
$action = strtolower(str_replace('-', '_', (string) (api_query('action') ?? 'list')));

RBAC::requirePermission($user, 'accounting.je.view');

if ($action === 'list') {
    if (api_method() !== 'GET') api_error('Method not allowed', 405);
    $agents = [];
    foreach (AI_AGENTS as $key => $a) {
        $agents[] = [
            'key'         => $key,
            'label'       => $a['label'],
            'description' => $a['description'],
            'kind'        => $a['kind'],
            'feature_key' => $a['feature_key'],
        ];
    }
    api_ok(['agents' => $agents]);
}

if ($action === 'run') {
    if (api_method() !== 'POST') api_error('Method not allowed', 405);
    $agentKey = (string) (api_query('agent') ?? '');
    if ($agentKey === '') api_error('agent required', 422);
    if (!isset(AI_AGENTS[$agentKey])) api_error('Unknown agent: ' . $agentKey, 404);
    try {
        $envelope = aiAgentRun($tid, $agentKey);
    } catch (\AIDisabledException $e) {
        api_error($e->getMessage(), 503);
    } catch (\InvalidArgumentException $e) {
        api_error($e->getMessage(), 422);
    } catch (\Throwable $e) {
        api_error('Agent run failed: ' . $e->getMessage(), 502);
    }
    api_ok([
        'agent'    => $agentKey,
        'envelope' => $envelope,
    ]);
}

api_error('Unknown action: ' . $action, 400);
