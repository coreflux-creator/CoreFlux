<?php
/**
 * /api/ai/tools.php — AI Tool Gateway HTTP surface (spec §18).
 *
 *   GET   ?action=list                       → tool catalog
 *   POST  ?action=invoke  body:{ tool_name, args, session_id? }
 *           → { ok, status, result?, error? }
 *
 * All calls are tenant-scoped via the existing api_require_auth gate.
 * Per-tool RBAC is enforced inside the gateway. Every invocation is
 * audited in ai_tool_invocations regardless of outcome.
 *
 * Designed so an LLM provider's tool-calling layer (OpenAI / Anthropic
 * function_call) can hit this single endpoint with `{tool_name, args}`
 * and never need to know about the underlying Jaz / accounting wiring.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/ai/tool_gateway.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();
$action = (string) ($_GET['action'] ?? '');

$callerCtx = [
    'tenant_id' => $tid,
    'user_id'   => (int) ($user['id'] ?? 0) ?: null,
    'user'      => $user,
];

if ($method === 'GET' && ($action === 'list' || $action === '')) {
    $env = aiToolInvoke('coreflux.list_tools', [], $callerCtx);
    api_ok($env);
}

if ($method === 'POST' && $action === 'invoke') {
    $body      = api_json_body();
    $toolName  = (string) ($body['tool_name'] ?? '');
    $args      = is_array($body['args'] ?? null) ? $body['args'] : [];
    $sessionId = (string) ($body['session_id'] ?? '');
    if ($toolName === '') api_error('tool_name required', 422);
    $callerCtx['session_id'] = $sessionId;
    $env = aiToolInvoke($toolName, $args, $callerCtx);
    api_ok($env);
}

api_error("unknown action '{$action}' or wrong HTTP method", 400);
