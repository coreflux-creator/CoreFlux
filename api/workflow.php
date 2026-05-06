<?php
/**
 * WorkflowEngine — inbox + act endpoints.
 *
 *   GET  /api/workflow/inbox                                → instances awaiting current user
 *   POST /api/workflow/instances/{id}/act                   { action, comment, via }
 *
 * Path-style ID parsing kept simple: pass instance id via ?id=N.
 * Mobile + web both use the same routes.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/workflow_engine.php';

$ctx       = api_require_auth();
$user      = $ctx['user'];
$tenantId  = (int) $ctx['tenant_id'];
$method    = api_method();
$action    = (string) (api_query('action') ?? '');
$path      = (string) (api_query('path')   ?? '');

if ($method === 'GET' && ($path === 'inbox' || $action === 'inbox')) {
    $subjectType = api_query('subject_type') ?: null;
    $instances = workflowGetPendingForUser($tenantId, (int) ($user['id'] ?? 0), $subjectType);
    api_ok(['instances' => $instances]);
}

if ($method === 'POST' && $action === 'act') {
    $instanceId = (int) (api_query('id') ?? 0);
    if (!$instanceId) api_error('id required', 422);
    $body = api_json_body();
    api_require_fields($body, ['action']);
    $allowed = ['approve','reject','skip','delegate','comment','escalate'];
    if (!in_array($body['action'], $allowed, true)) api_error('invalid action', 422, ['allowed' => $allowed]);
    $row = workflowAct(
        $tenantId,
        $instanceId,
        (int) ($user['id'] ?? 0),
        (string) $body['action'],
        $body['comment'] ?? null,
        (string) ($body['via'] ?? 'app'),
        isset($body['delegated_to_user_id']) ? (int) $body['delegated_to_user_id'] : null
    );
    api_ok(['instance' => $row]);
}

if ($method === 'GET' && (int) (api_query('id') ?? 0) > 0) {
    $row = workflowGetInstance($tenantId, (int) api_query('id'));
    if (!$row) api_error('Instance not found', 404);
    api_ok(['instance' => $row]);
}

api_error('Unknown method/action', 405);
