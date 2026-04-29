<?php
/**
 * People API — documents (refs Core StorageService)
 *
 *   GET    /api/people/documents?person_id=N      → list
 *   GET    /api/people/documents?id=N             → get one + signed URL
 *   POST   /api/people/documents?person_id=N      → register an uploaded file
 *                                                  body: { storage_object_id, doc_type, file_name?, signed?, signed_at?, expires_at? }
 *   PATCH  /api/people/documents?id=N             → update metadata
 *   DELETE /api/people/documents?id=N             → soft delete
 *   GET    /api/people/documents?action=upload_url&person_id=N&doc_type=resume&file_name=X.pdf
 *                                                  → returns presigned POST form for direct browser upload
 *
 * SPEC: /app/modules/people/SPEC.md §3.1, §5.3, §11 (S3 via StorageService)
 */

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/StorageService.php';
require_once __DIR__ . '/../lib/people.php';
require_once __DIR__ . '/../lib/audit.php';

use Core\StorageService;

$ctx = api_require_auth();
$user = $ctx['user'];
$method = api_method();
$action = $_GET['action'] ?? '';

if ($method === 'GET' && $action === 'upload_url') {
    RBAC::requirePermission($user, 'people.docs.manage');
    $personId = (int) api_query('person_id', 0);
    $docType  = (string) api_query('doc_type', 'other');
    $fileName = (string) api_query('file_name', 'document');
    if ($personId <= 0) api_error('person_id required', 400);

    $key = StorageService::getInstance()->build_key(
        'people', (int) $ctx['tenant_id'], 'document', $personId, $fileName
    );
    $post = StorageService::getInstance()->get_presigned_post($key);
    api_ok([
        'storage_key' => $key,
        'upload'      => $post,
        'doc_type'    => $docType,
    ]);
}

if ($method === 'GET') {
    if ($id = (int) api_query('id', 0)) {
        RBAC::requirePermission($user, 'people.docs.view');
        $row = scopedFind(
            'SELECT * FROM people_documents
             WHERE tenant_id = :tenant_id AND id = :id AND deleted_at IS NULL',
            ['id' => $id]
        );
        if (!$row) api_error('Not found', 404);
        // The document table stores storage_object_id; for skinny version we
        // look up the storage_objects row to get the key, then sign it.
        $obj = scopedFind(
            'SELECT s3_key FROM storage_objects WHERE id = :id AND tenant_id = :tenant_id',
            ['id' => $row['storage_object_id']]
        );
        $signed = $obj ? StorageService::getInstance()->get_signed_url($obj['s3_key']) : null;
        peopleLogPIIAccess(
            (int) ($user['id'] ?? 0),
            (int) $row['person_id'],
            'document.downloaded',
            ['document_id' => $id, 'doc_type' => $row['doc_type']]
        );
        api_ok(['document' => $row, 'signed_url' => $signed]);
    }

    RBAC::requirePermission($user, 'people.docs.view');
    $personId = (int) api_query('person_id', 0);
    if ($personId <= 0) api_error('person_id required', 400);
    api_ok(['documents' => peopleDocuments($personId)]);
}

if ($method === 'POST') {
    RBAC::requirePermission($user, 'people.docs.manage');
    $personId = (int) api_query('person_id', 0);
    if ($personId <= 0) api_error('person_id required', 400);
    $body = api_json_body();
    api_require_fields($body, ['storage_object_id', 'doc_type']);

    $allowedTypes = ['resume','offer','i9','w4','w9','nda','contract','passport','visa','license','other'];
    if (!in_array($body['doc_type'], $allowedTypes, true)) {
        api_error('Invalid doc_type', 422, ['allowed' => $allowedTypes]);
    }

    $id = scopedInsert('people_documents', [
        'person_id'           => $personId,
        'doc_type'            => $body['doc_type'],
        'storage_object_id'   => (int) $body['storage_object_id'],
        'file_name'           => $body['file_name']  ?? null,
        'signed'              => !empty($body['signed']) ? 1 : 0,
        'signed_at'           => $body['signed_at']  ?? null,
        'expires_at'          => $body['expires_at'] ?? null,
        'uploaded_by_user_id' => $user['id']         ?? null,
    ]);

    // If this is a resume, denormalize to people.resume_storage_object_id
    if ($body['doc_type'] === 'resume') {
        scopedUpdate('people', $personId, [
            'resume_storage_object_id' => (int) $body['storage_object_id'],
        ]);
    }

    peopleAudit('people.document.uploaded', [
        'person_id' => $personId, 'doc_type' => $body['doc_type'], 'document_id' => $id
    ], $personId);
    api_ok(['id' => $id], 201);
}

if ($method === 'PATCH') {
    RBAC::requirePermission($user, 'people.docs.manage');
    $id = (int) api_query('id', 0);
    if ($id <= 0) api_error('id required', 400);
    $body = api_json_body();
    unset($body['id'], $body['tenant_id'], $body['person_id'], $body['storage_object_id']);
    if (!$body) api_error('No fields to update', 422);
    $rows = scopedUpdate('people_documents', $id, $body);
    if ($rows === 0) api_error('Not found or no change', 404);
    api_ok(['ok' => true]);
}

if ($method === 'DELETE') {
    RBAC::requirePermission($user, 'people.docs.manage');
    $id = (int) api_query('id', 0);
    if ($id <= 0) api_error('id required', 400);
    $rows = scopedUpdate('people_documents', $id, ['deleted_at' => date('Y-m-d H:i:s')]);
    if ($rows === 0) api_error('Not found', 404);
    peopleAudit('people.document.deleted', ['document_id' => $id], $id);
    api_ok(['ok' => true]);
}

api_error('Method not allowed', 405);
