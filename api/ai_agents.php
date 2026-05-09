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
    $modes  = aiAgentModeReadAll($tid);
    foreach (AI_AGENTS as $key => $a) {
        $agents[] = [
            'key'         => $key,
            'label'       => $a['label'],
            'description' => $a['description'],
            'kind'        => $a['kind'],
            'feature_key' => $a['feature_key'],
            'mode'        => $modes[$key] ?? 'advisory',
        ];
    }
    api_ok([
        'agents'        => $agents,
        'modes'         => AI_AGENT_MODES,
        'digest'        => aiAgentDigestRead($tid),
    ]);
}

if ($action === 'run') {
    if (api_method() !== 'POST') api_error('Method not allowed', 405);
    $agentKey = (string) (api_query('agent') ?? '');
    if ($agentKey === '') api_error('agent required', 422);
    if (!isset(AI_AGENTS[$agentKey])) api_error('Unknown agent: ' . $agentKey, 404);
    try {
        $envelope = aiAgentRunWithMode($tid, $user['id'] ?? null, $agentKey);
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

if ($action === 'mode_set') {
    if (api_method() !== 'POST') api_error('Method not allowed', 405);
    RBAC::requirePermission($user, 'ai.config.manage');
    $body = api_json_body();
    $agentKey = (string) ($body['agent'] ?? '');
    $mode     = (string) ($body['mode']  ?? '');
    try {
        aiAgentModeWrite($tid, $agentKey, $mode);
    } catch (\InvalidArgumentException $e) {
        api_error($e->getMessage(), 422);
    }
    api_ok(['agent' => $agentKey, 'mode' => $mode]);
}

if ($action === 'digest_settings_set') {
    if (api_method() !== 'POST') api_error('Method not allowed', 405);
    RBAC::requirePermission($user, 'ai.config.manage');
    $body = api_json_body();
    try {
        $cfg = aiAgentDigestWrite($tid, $body);
    } catch (\InvalidArgumentException $e) {
        api_error($e->getMessage(), 422);
    }
    api_ok(['digest' => $cfg]);
}

if ($action === 'digest_send_now') {
    if (api_method() !== 'POST') api_error('Method not allowed', 405);
    RBAC::requirePermission($user, 'ai.config.manage');
    try {
        $r = aiAgentDigestSend($tid, $user['id'] ?? null);
    } catch (\AIDisabledException $e) {
        api_error($e->getMessage(), 503);
    } catch (\RuntimeException $e) {
        api_error($e->getMessage(), 422);
    } catch (\Throwable $e) {
        // Persist the failure so the user can see it on the page.
        try {
            getDB()->prepare(
                'INSERT INTO ai_agent_digest_settings (tenant_id, last_send_error)
                 VALUES (:t, :err)
                 ON DUPLICATE KEY UPDATE last_send_error = VALUES(last_send_error)'
            )->execute(['t' => $tid, 'err' => substr($e->getMessage(), 0, 500)]);
        } catch (\Throwable $_) {}
        api_error('Digest send failed: ' . $e->getMessage(), 502);
    }
    api_ok($r);
}

api_error('Unknown action: ' . $action, 400);
