<?php
/**
 * Placements API — approval contact (SPEC §3.10).
 *
 *   GET /api/placements/approval_contact?placement_id=N
 *   PUT /api/placements/approval_contact?placement_id=N
 *
 * Contact fields are denormalized on the placements row; this endpoint
 * is just the focused read/write surface.
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/placements.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$method = api_method();
$pid = (int) api_query('placement_id', 0);
if ($pid <= 0) api_error('placement_id required', 400);

if ($method === 'GET') {
    RBAC::requirePermission($user, 'placements.view');
    $row = scopedFind(
        'SELECT id AS placement_id, client_approver_name, client_approver_email,
                tokenized_email_approval_enabled, bulk_uploads_can_be_pre_approved
         FROM placements WHERE tenant_id = :tenant_id AND id = :id',
        ['id' => $pid]
    );
    if (!$row) api_error('Not found', 404);
    api_ok(['contact' => $row]);
}

if ($method === 'PUT' || $method === 'POST') {
    RBAC::requirePermission($user, 'placements.manage');
    $body = api_json_body();
    if (!empty($body['client_approver_email']) && !filter_var($body['client_approver_email'], FILTER_VALIDATE_EMAIL)) {
        api_error('Invalid client_approver_email', 422);
    }
    $update = [];
    foreach (['client_approver_name','client_approver_email',
              'tokenized_email_approval_enabled','bulk_uploads_can_be_pre_approved'] as $k) {
        if (array_key_exists($k, $body)) {
            $update[$k] = is_bool($body[$k]) ? (int) $body[$k] : $body[$k];
        }
    }
    if (!$update) api_error('No fields to update', 422);
    $rows = scopedUpdate('placements', $pid, $update);
    if ($rows === 0) api_error('Not found or no change', 404);
    placementsAudit('placement.approval_contact.updated', ['placement_id' => $pid, 'fields' => array_keys($update)], $pid);
    api_ok(['ok' => true]);
}

api_error('Method not allowed', 405);
