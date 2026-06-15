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
    if ((string) $body['action'] === 'comment' && !workflowCanViewInstance($tenantId, $instanceId, $ctx)) {
        api_error('Forbidden', 403);
    }
    try {
        $row = workflowAct(
            $tenantId,
            $instanceId,
            (int) ($user['id'] ?? 0),
            (string) $body['action'],
            $body['comment'] ?? null,
            (string) ($body['via'] ?? 'app'),
            isset($body['delegated_to_user_id']) ? (int) $body['delegated_to_user_id'] : null
        );
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, 'not an approver')
            || str_contains($msg, 'no current approvers')
            || str_contains($msg, 'Separation of duties')) {
            api_error($msg, 403);
        }
        if (str_contains($msg, 'Instance not found')) api_error('Instance not found', 404);
        if (str_contains($msg, 'Instance already')) api_error($msg, 409);
        throw $e;
    }
    api_ok(['instance' => $row]);
}

if ($method === 'GET' && (int) (api_query('id') ?? 0) > 0) {
    $instanceId = (int) api_query('id');
    $row = workflowGetInstance($tenantId, $instanceId);
    if (!$row) api_error('Instance not found', 404);
    if (!workflowCanViewInstance($tenantId, $instanceId, $ctx)) api_error('Forbidden', 403);
    api_ok(['instance' => $row]);
}

api_error('Unknown method/action', 405);
