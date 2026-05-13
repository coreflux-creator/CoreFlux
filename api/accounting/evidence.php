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
    api_ok(['ok' => evidenceSupersede($tenantId, $old, $new)]);
}

if ($method === 'POST') {
    $body  = api_json_body();
    $args  = array_merge($body, ['attached_by_user_id' => $userId]);
    try {
        api_ok(evidenceAttach($tenantId, $args), 201);
    } catch (\InvalidArgumentException $e) {
        api_error($e->getMessage(), 422);
    }
}

if ($method === 'DELETE') {
    $id = (int) api_query('id', 0);
    if (!$id) api_error('id required', 422);
    api_ok(['ok' => evidenceSoftDelete($tenantId, $id, $userId)]);
}

api_error('Method not allowed', 405);
