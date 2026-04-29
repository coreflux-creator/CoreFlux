<?php
/**
 * Placements API — documents (S3 via Core StorageService). SPEC §3.8.
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/StorageService.php';
require_once __DIR__ . '/../lib/placements.php';

use Core\StorageService;

$ctx = api_require_auth();
$user = $ctx['user'];
$method = api_method();
$action = $_GET['action'] ?? '';
$pid    = (int) api_query('placement_id', 0);

if ($method === 'GET' && $action === 'upload_url') {
    RBAC::requirePermission($user, 'placements.docs.manage');
    if ($pid <= 0) api_error('placement_id required', 400);
    $fileName = (string) api_query('file_name', 'document');
    $key  = StorageService::getInstance()->build_key('placements', (int) $ctx['tenant_id'], 'document', $pid, $fileName);
    $post = StorageService::getInstance()->get_presigned_post($key);
    api_ok(['storage_key' => $key, 'upload' => $post]);
}

if ($method === 'GET') {
    RBAC::requirePermission($user, 'placements.docs.view');
    if ($pid <= 0) api_error('placement_id required', 400);
    api_ok(['documents' => placementDocuments($pid)]);
}

if ($method === 'POST') {
    RBAC::requirePermission($user, 'placements.docs.manage');
    if ($pid <= 0) api_error('placement_id required', 400);
    $body = api_json_body();
    api_require_fields($body, ['storage_object_id', 'doc_type']);
    $allowed = ['msa','sow','work_order','rate_sheet','timesheet_template','poc','noc','other'];
    if (!in_array($body['doc_type'], $allowed, true)) {
        api_error('Invalid doc_type', 422, ['allowed' => $allowed]);
    }
    $id = scopedInsert('placement_documents', [
        'placement_id'        => $pid,
        'doc_type'            => $body['doc_type'],
        'storage_object_id'   => (int) $body['storage_object_id'],
        'file_name'           => $body['file_name'] ?? null,
        'effective_from'      => $body['effective_from'] ?? null,
        'effective_to'        => $body['effective_to']   ?? null,
        'uploaded_by_user_id' => $user['id'] ?? null,
    ]);
    placementsAudit('placement.document.uploaded', ['placement_id' => $pid, 'document_id' => $id, 'doc_type' => $body['doc_type']], $pid);
    api_ok(['id' => $id], 201);
}

if ($method === 'DELETE') {
    RBAC::requirePermission($user, 'placements.docs.manage');
    $id = (int) api_query('id', 0);
    if ($id <= 0) api_error('id required', 400);
    $rows = scopedUpdate('placement_documents', $id, ['deleted_at' => date('Y-m-d H:i:s')]);
    if ($rows === 0) api_error('Not found', 404);
    placementsAudit('placement.document.deleted', ['document_id' => $id], $id);
    api_ok(['ok' => true]);
}

api_error('Method not allowed', 405);
