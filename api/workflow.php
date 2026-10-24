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
    $instance = workflowGetInstance($tenantId, $instanceId);
    if (!$instance) api_error('Instance not found', 404);
    if (($instance['subject_type'] ?? '') === 'billing_invoice') {
        if (!in_array($body['action'], ['approve', 'reject', 'comment'], true)) {
            api_error('Billing invoice review requires an approve or reject decision', 422);
        }
        require_once __DIR__ . '/../core/RBAC.php';
        require_once __DIR__ . '/../modules/billing/lib/approval_settings.php';
        rbac_legacy_require($user, 'billing.invoice.approve');
        if (!billingInvoiceReviewerIsEligible($tenantId, (int) ($user['id'] ?? 0))) {
            api_error('Billing invoice approval access is required', 403);
        }
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
    } catch (\InvalidArgumentException $e) {
        if (($instance['subject_type'] ?? '') !== 'billing_invoice') throw $e;
        api_error($e->getMessage(), 422);
    } catch (\RuntimeException $e) {
        if (($instance['subject_type'] ?? '') !== 'billing_invoice') throw $e;
        $message = $e->getMessage();
        $denied = str_contains($message, 'Separation of duties')
            || str_contains($message, 'not an approver')
            || str_contains($message, 'no current approvers')
            || str_contains($message, 'approval access is required');
        api_error($message, $denied ? 403 : 409);
    }
    api_ok(['instance' => $row]);
}

if ($method === 'GET' && (int) (api_query('id') ?? 0) > 0) {
    $row = workflowGetInstance($tenantId, (int) api_query('id'));
    if (!$row) api_error('Instance not found', 404);
    api_ok(['instance' => $row]);
}

api_error('Unknown method/action', 405);
