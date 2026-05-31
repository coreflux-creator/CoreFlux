<?php
/**
 * /api/ai/runs.php — AI Tool Gateway runs surface (Slice 1).
 *
 *   POST  body: {agent, prompt_version?, sub_tenant_id?, input_summary?,
 *               tools?: [{name, args}]}
 *         Creates an ai_runs row, optionally invokes each listed tool
 *         within the run (Slice 1 has no LLM planner so tool sequencing
 *         is caller-driven), then completes the run with a synthesized
 *         output_summary. Returns {ai_run_id, status, tool_calls[]}.
 *
 *   GET   ?id=<uuid>    — full run trace (run + tool_calls[])
 *   GET   ?list=1[&agent=&status=&limit=]   — recent runs for the caller's tenant
 *
 * RBAC:
 *   POST       → ai.use
 *   GET id     → ai.audit.view  (anyone with audit view can drill any run)
 *   GET list   → ai.audit.view
 *
 * Slice 1 is plumbing only — no LLM call. The Ask AI panel and admin
 * trace explorer are the two consumers.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/RBAC.php';
require_once __DIR__ . '/../../core/rbac/legacy_map.php';
require_once __DIR__ . '/../../core/ai/gateway.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();

if ($method === 'POST') {
    rbac_legacy_require($user, 'ai.use');
    $body = api_json_body();
    $agent = trim((string) ($body['agent'] ?? ''));
    if ($agent === '') api_error('agent required', 422);
    if (strlen($agent) > 80) api_error('agent too long', 422);

    $subTenantId = isset($body['sub_tenant_id']) && (int) $body['sub_tenant_id'] > 0
        ? (int) $body['sub_tenant_id'] : null;

    $callerCtx = [
        'tenant_id'  => $tid,
        'user_id'    => (int) ($user['id'] ?? 0) ?: null,
        'user'       => $user,
        'session_id' => session_id() ?: '',
    ];

    // Two modes:
    //   1. LLM mode — body.intent present, no body.tools.
    //      The gateway hands intent to the LLM with the tool catalog
    //      as function specs; the LLM plans + executes tools itself.
    //   2. Deterministic mode (Slice 1 path) — body.tools is a
    //      caller-controlled list. No LLM call. Useful for the
    //      Slice-1 Ask-AI shell and for smoke testing.
    $intent       = trim((string) ($body['intent'] ?? ''));
    $hasTools     = isset($body['tools']) && is_array($body['tools']) && !empty($body['tools']);
    $mode         = $body['mode'] ?? null;
    $useLlm       = $mode === 'llm' || ($mode === null && $intent !== '' && !$hasTools);

    if ($useLlm) {
        if ($intent === '') api_error('intent required for LLM mode', 422);
        try {
            $opts = [
                'agent'        => $agent,
                'sub_tenant_id' => $subTenantId,
            ];
            if (isset($body['provider']))    $opts['provider']    = (string) $body['provider'];
            if (isset($body['model']))       $opts['model']       = (string) $body['model'];
            if (isset($body['temperature'])) $opts['temperature'] = (float)  $body['temperature'];
            $res = aiGatewayRunWithLlm($tid, $callerCtx['user_id'], $intent, $callerCtx, $opts);
        } catch (AiLlmConfigException $e) {
            api_error('LLM provider not configured: ' . $e->getMessage(), 503);
        } catch (\Throwable $e) {
            api_error('LLM run failed: ' . $e->getMessage(), 500);
        }
        api_ok($res, 201);
    }

    // Deterministic mode (Slice 1 contract preserved).
    $runId = aiGatewayCreateRun(
        $tid,
        (int) ($user['id'] ?? 0) ?: null,
        $agent,
        isset($body['prompt_version']) ? (string) $body['prompt_version'] : null,
        $subTenantId,
        isset($body['input_summary']) ? (string) $body['input_summary'] : ($intent !== '' ? $intent : null),
        isset($body['model_name']) ? (string) $body['model_name'] : null
    );

    $toolCalls = [];
    $hadBlocked = false; $hadFail = false;
    if ($hasTools) {
        foreach ($body['tools'] as $idx => $t) {
            $name = trim((string) ($t['name'] ?? ''));
            if ($name === '') continue;
            $args = is_array($t['args'] ?? null) ? $t['args'] : [];
            $env  = aiGatewayInvokeTool($runId, $name, $args, $callerCtx);
            $toolCalls[] = [
                'name'     => $name,
                'envelope' => $env,
            ];
            if (!$env['ok']) {
                if (in_array((string) ($env['status'] ?? ''), ['denied','validation_failed'], true)) {
                    $hadBlocked = true;
                } else {
                    $hadFail = true;
                }
            }
            // Defensive cap — never let one POST chain more than 20
            // tool calls. The LLM planner respects its own budget;
            // this is the floor for the deterministic path.
            if ($idx >= 19) break;
        }
    }

    $status = $hadFail ? 'failed' : 'completed';
    aiGatewayCompleteRun(
        $tid, $runId, $status,
        sprintf('%d tool calls; %d blocked, %d failed',
                count($toolCalls),
                count(array_filter($toolCalls, fn ($c) => ($c['envelope']['status'] ?? '') === 'denied' || ($c['envelope']['status'] ?? '') === 'validation_failed')),
                count(array_filter($toolCalls, fn ($c) => !$c['envelope']['ok'] && !in_array($c['envelope']['status'] ?? '', ['denied','validation_failed'], true)))
        )
    );

    api_ok([
        'ai_run_id'  => $runId,
        'status'     => $status,
        'tool_calls' => $toolCalls,
    ], 201);
}

if ($method === 'GET') {
    rbac_legacy_require($user, 'ai.audit.view');
    $id = (string) ($_GET['id'] ?? '');
    if ($id !== '') {
        $rec = aiGatewayGetRun($tid, $id);
        if (!$rec) api_error('not found', 404);
        api_ok($rec);
    }
    // List mode.
    $agent  = isset($_GET['agent'])  ? (string) $_GET['agent']  : null;
    $status = isset($_GET['status']) ? (string) $_GET['status'] : null;
    $userId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : null;
    $limit  = (int) ($_GET['limit'] ?? 100);
    $rows = aiGatewayListRuns($tid, $userId ?: null, $agent ?: null, $status ?: null, $limit);
    api_ok(['runs' => $rows, 'count' => count($rows)]);
}

api_error('method not allowed', 405);
