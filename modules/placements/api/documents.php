<?php
/**
 * Placements API — documents (S3 via Core StorageService). SPEC §3.8.
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/StorageService.php';
require_once __DIR__ . '/../../../core/storage_register.php';
require_once __DIR__ . '/../lib/placements.php';

use Core\StorageService;

$ctx = api_require_auth();
$user = $ctx['user'];
$method = api_method();
$action = $_GET['action'] ?? '';
$pid    = (int) api_query('placement_id', 0);
$tid    = (int) $ctx['tenant_id'];

$requirePlacement = static function (int $placementId): array {
    if ($placementId <= 0) api_error('placement_id required', 400);
    $placement = scopedFind(
        'SELECT id FROM placements WHERE tenant_id = :tenant_id AND id = :id AND deleted_at IS NULL',
        ['id' => $placementId]
    );
    if (!$placement) api_error('Placement not found', 404);
    return $placement;
};

if ($method === 'GET' && $action === 'upload_url') {
    rbac_legacy_require($user, 'placements.docs.manage');
    $requirePlacement($pid);
    $fileName = (string) api_query('file_name', 'document');
    $key  = StorageService::getInstance()->build_key('placements', $tid, 'document', $pid, $fileName);
    $post = StorageService::getInstance()->get_presigned_post($key);
    api_ok(['storage_key' => $key, 'upload' => $post]);
}

if ($method === 'GET') {
    rbac_legacy_require($user, 'placements.docs.view');
    $documentId = (int) api_query('id', 0);
    if ($documentId > 0) {
        $document = scopedFind(
            'SELECT d.*, s.s3_key
               FROM placement_documents d
               JOIN storage_objects s ON s.id = d.storage_object_id AND s.tenant_id = d.tenant_id
              WHERE d.tenant_id = :tenant_id AND d.id = :id AND d.deleted_at IS NULL',
            ['id' => $documentId]
        );
        if (!$document) api_error('Document not found', 404);
        api_ok([
            'document' => $document,
            'signed_url' => StorageService::getInstance()->get_signed_url((string) $document['s3_key']),
        ]);
    }
    $requirePlacement($pid);
    api_ok(['documents' => placementDocuments($pid)]);
}

if ($method === 'POST') {
    rbac_legacy_require($user, 'placements.docs.manage');
    $requirePlacement($pid);
    $body = api_json_body();
    api_require_fields($body, ['doc_type']);
    $allowed = ['msa','sow','work_order','rate_sheet','timesheet_template','poc','noc','other'];
    if (!in_array($body['doc_type'], $allowed, true)) {
        api_error('Invalid doc_type', 422, ['allowed' => $allowed]);
    }
    $storageObjectId = (int) ($body['storage_object_id'] ?? 0);
    if ($storageObjectId <= 0) {
        api_require_fields($body, ['storage_key', 'filename']);
        $expectedPrefix = "placements/{$tid}/document/{$pid}/";
        if (!str_starts_with((string) $body['storage_key'], $expectedPrefix)) {
            api_error('Invalid storage key', 422);
        }
        $storageObjectId = registerStorageObject(
            $tid,
            'placements',
            'document',
            $pid,
            (string) $body['storage_key'],
            (string) $body['filename'],
            isset($body['mime']) ? (string) $body['mime'] : null,
            isset($body['size_bytes']) ? (int) $body['size_bytes'] : null,
            isset($user['id']) ? (int) $user['id'] : null
        );
    } else {
        $ownedObject = scopedFind(
            'SELECT id FROM storage_objects WHERE tenant_id = :tenant_id AND id = :id',
            ['id' => $storageObjectId]
        );
        if (!$ownedObject) api_error('Storage object not found', 404);
    }
    $id = scopedInsert('placement_documents', [
        'placement_id'        => $pid,
        'doc_type'            => $body['doc_type'],
        'storage_object_id'   => $storageObjectId,
        'file_name'           => $body['file_name'] ?? $body['filename'] ?? null,
        'effective_from'      => $body['effective_from'] ?? null,
        'effective_to'        => $body['effective_to']   ?? null,
        'uploaded_by_user_id' => $user['id'] ?? null,
    ]);
    placementsAudit('placement.document.uploaded', ['placement_id' => $pid, 'document_id' => $id, 'doc_type' => $body['doc_type']], $pid);
    api_ok(['id' => $id, 'storage_object_id' => $storageObjectId], 201);
}

if ($method === 'DELETE') {
    rbac_legacy_require($user, 'placements.docs.manage');
    $id = (int) api_query('id', 0);
    if ($id <= 0) api_error('id required', 400);
    $rows = scopedUpdate('placement_documents', $id, ['deleted_at' => date('Y-m-d H:i:s')]);
    if ($rows === 0) api_error('Not found', 404);
    placementsAudit('placement.document.deleted', ['document_id' => $id], $id);
    api_ok(['ok' => true]);
}

api_error('Method not allowed', 405);
