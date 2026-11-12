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
require_once __DIR__ . '/../lib/edit.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$method = api_method();
$pid = (int) api_query('placement_id', 0);
if ($pid <= 0) api_error('placement_id required', 400);

if ($method === 'GET') {
    rbac_legacy_require($user, 'placements.view');
    $row = scopedFind(
        'SELECT id AS placement_id, client_approver_name, client_approver_email,
                tokenized_email_approval_enabled, bulk_uploads_can_be_pre_approved
         FROM placements WHERE tenant_id = :tenant_id AND id = :id AND deleted_at IS NULL',
        ['id' => $pid]
    );
    if (!$row) api_error('Not found', 404);
    api_ok(['contact' => $row]);
}

if ($method === 'PUT' || $method === 'POST') {
    rbac_legacy_require($user, 'placements.manage');
    try {
        $result = placementApplyNativeEdit($pid, api_json_body(), $user, 'approval_contact');
        api_ok(['ok' => true, 'changed' => $result['changed']]);
    } catch (PDOException $e) {
        error_log('[approval setup] Database operation failed: ' . $e->getCode());
        api_error('Approval setup could not be saved. Please try again.', 503);
    } catch (InvalidArgumentException|RuntimeException $e) {
        api_error($e->getMessage(), in_array($e->getCode(), [403,404,409,422,503], true) ? $e->getCode() : 422);
    }
}

api_error('Method not allowed', 405);
