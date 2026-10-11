<?php
/**
 * /api/accounting/evidence — Canonical evidence attachment surface.
 *
 *   GET    ?subject_type=X&subject_id=N         → list attached evidence
 *   POST   { subject_type, subject_id, document_type, ... }
 *                                               → attach (idempotent via hash)
 *   POST   ?action=supersede { old_id, new_id }
 *   DELETE ?id=N                                → soft delete
 *
 * Bytes themselves are uploaded out-of-band (existing object storage flow).
 * This endpoint records the metadata + payload only.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/evidence_attachments.php';

$ctx      = api_require_auth();
$user     = $ctx['user'];
$userId   = (int) ($user['id'] ?? 0);
$tenantId = (int) (currentTenantId() ?? 0);
if (!$tenantId) api_error('No active tenant', 400);

$method = api_method();
$action = (string) api_query('action', '');

// Re-sign a download URL on demand. Checked BEFORE the generic GET list
// handler since the list handler exits via api_ok().
if ($method === 'GET' && $action === 'signed_url') {
    require_once __DIR__ . '/../../core/StorageService.php';
    $id  = (int) api_query('id', 0);
    if (!$id) api_error('id required', 422);
    $stmt = getDB()->prepare(
        'SELECT storage_key, label FROM evidence_attachments
          WHERE tenant_id = :t AND id = :id AND deleted_at IS NULL LIMIT 1'
    );
    $stmt->execute(['t' => $tenantId, 'id' => $id]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row || empty($row['storage_key'])) api_error('not found or no file', 404);
    $downloadName = preg_replace('/[^A-Za-z0-9._-]/', '_',
        basename(str_replace('\\', '/', (string) ($row['label'] ?: 'download')))) ?: 'download';
    $url = \Core\StorageService::getInstance()->get_signed_url(
        $row['storage_key'], 300, ['filename_for_download' => $downloadName]
    );
    api_ok(['signed_url' => $url, 'filename' => $row['label']]);
}

if ($method === 'GET') {
    $subjectType = (string) api_query('subject_type', '');
    $subjectId   = (int) api_query('subject_id', 0);
    if ($subjectType === '' || !$subjectId) api_error('subject_type + subject_id required', 422);
    api_ok([
        'rows' => evidenceListFor($tenantId, $subjectType, $subjectId, (bool) api_query('include_deleted', 0)),
    ]);
}

if ($method === 'POST' && $action === 'supersede') {
    $body  = api_json_body();
    $old   = (int) ($body['old_id'] ?? 0);
    $new   = (int) ($body['new_id'] ?? 0);
    if (!$old || !$new) api_error('old_id + new_id required', 422);
    try {
        api_ok(['ok' => evidenceSupersede($tenantId, $old, $new)]);
    } catch (\DomainException $e) {
        api_error($e->getMessage(), 409);
    }
}

if ($method === 'POST') {
    $body  = api_json_body();
    if (($body['subject_type'] ?? '') === 'csv_import_run') {
        require_once __DIR__ . '/../../core/StorageService.php';
        $subjectId = (int) ($body['subject_id'] ?? 0);
        $documentType = (string) ($body['document_type'] ?? '');
        $key = (string) ($body['storage_key'] ?? '');
        if ($subjectId <= 0 || !in_array($documentType, ['csv_source', 'column_map'], true)
            || !str_starts_with($key, "csv_imports/{$tenantId}/csv_import_run/{$subjectId}/")) {
            api_error('Invalid import evidence', 422);
        }
        $run = getDB()->prepare(
            'SELECT id FROM csv_import_history
              WHERE id = :id AND tenant_id = :tenant_id AND created_by_user_id = :user_id LIMIT 1'
        );
        $run->execute(['id' => $subjectId, 'tenant_id' => $tenantId, 'user_id' => $userId]);
        if (!$run->fetchColumn()) api_error('Import run not found for this user', 404);
        $head = \Core\StorageService::getInstance()->head($key);
        if ($head === null || (int) ($head['size_bytes'] ?? 0) < 1
            || (int) ($body['size_bytes'] ?? 0) !== (int) $head['size_bytes']) {
            api_error('Uploaded import file is missing or has a different size', 422);
        }
    }
    $args  = array_merge($body, ['attached_by_user_id' => $userId]);
    try {
        $attached = evidenceAttach($tenantId, $args);
        if (($body['subject_type'] ?? '') === 'csv_import_run' && (int) ($attached['id'] ?? 0) <= 0) {
            api_error('Import evidence could not be registered', 503);
        }
        api_ok($attached, 201);
    } catch (\InvalidArgumentException $e) {
        api_error($e->getMessage(), 422);
    }
}

if ($method === 'DELETE') {
    $id = (int) api_query('id', 0);
    if (!$id) api_error('id required', 422);
    try {
        api_ok(['ok' => evidenceSoftDelete($tenantId, $id, $userId)]);
    } catch (\DomainException $e) {
        api_error($e->getMessage(), 409);
    }
}

api_error('Method not allowed', 405);
