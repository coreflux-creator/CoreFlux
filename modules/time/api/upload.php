<?php
/**
 * Time — Manual timesheet upload + AI extraction.
 *
 *   GET  ?action=upload_url&file_name=X  → presigned S3 POST (PDF or image)
 *   POST ?action=extract                 body { storage_key, file_name, mime_type?, week_ending? }
 *                                        → AI-extract; returns draft lines.
 *                                        Records the document; returns its id.
 *   GET  ?id=N                            → re-fetch a previously-uploaded doc + its draft.
 *   POST ?id=N&action=consume            body { entry_ids: [...] }
 *                                        → mark as consumed once user has saved entries.
 *
 * Drafts are NOT auto-saved. The user must map each line's `project` to a
 * placement (typeahead) and click save in the UI; entries land via the
 * normal /api/time/entries.php POST with source='ai_inbox' and
 * source_ref_id = uploaded-doc id.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/time.php';

$ctx       = api_require_auth();
$tenantId  = (int) $ctx['tenant_id'];
$user      = $ctx['user'];
$userId    = (int) ($user['id'] ?? 0);
$pdo       = getDB();
$method    = api_method();
$action    = (string) ($_GET['action'] ?? '');

// ─── presigned S3 POST ───
if ($method === 'GET' && $action === 'upload_url') {
    RBAC::requirePermission($user, 'time.entry.create');
    require_once __DIR__ . '/../../../core/StorageService.php';
    $fileName = (string) ($_GET['file_name'] ?? 'timesheet.pdf');
    $svc = \Core\StorageService::getInstance();
    $key = $svc->build_key('time', $tenantId, 'manual_upload', $userId, $fileName);
    $post = $svc->get_presigned_post($key, ['max_bytes' => 25 * 1024 * 1024, 'ttl_seconds' => 600]);
    api_ok(['storage_key' => $key, 'upload' => $post]);
}

// ─── extract ───
if ($method === 'POST' && $action === 'extract') {
    RBAC::requirePermission($user, 'time.entry.create');
    require_once __DIR__ . '/../../../core/StorageService.php';
    require_once __DIR__ . '/../../../core/storage_register.php';
    require_once __DIR__ . '/../../../core/ai_service.php';
    $body = api_json_body();
    api_require_fields($body, ['storage_key']);
    $storageKey   = (string) $body['storage_key'];
    $fileName     = (string) ($body['file_name'] ?? 'timesheet.pdf');
    $mimeType     = (string) ($body['mime_type'] ?? 'application/pdf');
    $weekEnding   = $body['week_ending'] ?? null;

    // Register the storage object so it lives in the audit chain.
    $storageObjectId = registerStorageObject([
        'tenant_id' => $tenantId,
        's3_key'    => $storageKey,
        'file_name' => $fileName,
        'kind'      => 'time_upload',
    ]);

    // Insert the doc record up-front so AI failures still produce an audit row.
    $pdo->prepare(
        'INSERT INTO time_uploaded_documents
            (tenant_id, uploaded_by_user_id, file_name, storage_object_id,
             storage_key, mime_type, week_ending_hint, extraction_status, created_at)
         VALUES (:t, :u, :fn, :so, :sk, :mt, :wh, "pending", NOW())'
    )->execute([
        't' => $tenantId, 'u' => $userId,
        'fn' => $fileName, 'so' => $storageObjectId,
        'sk' => $storageKey, 'mt' => $mimeType,
        'wh' => $weekEnding,
    ]);
    $docId = (int) $pdo->lastInsertId();

    // Run AI extraction.
    try {
        $signedUrl = \Core\StorageService::getInstance()->get_signed_url($storageKey);
        $schema = '{"week_ending":string|null,"person_name":string|null,'
            . '"lines":[{"work_date":string,"project":string|null,"client":string|null,'
            . '"category":"regular_billable"|"regular_nonbillable"|"OT_billable"|"OT_nonbillable"|"holiday"|"vacation"|"sick"|"bereavement"|"unpaid_leave"|null,'
            . '"hours":number,"description":string|null}]}';
        $instruction = 'Extract a paper or PDF timesheet. Each row is a (date, project, hours) triple. '
            . 'work_date MUST be ISO YYYY-MM-DD. If the timesheet shows a single week with day-of-week columns (Mon-Sun), '
            . 'derive each work_date from the implied week. Default category to regular_billable when not labelled. '
            . 'Round hours to two decimals. Skip rows whose hours are zero or blank.';
        if ($weekEnding) $instruction .= " The week ending date is {$weekEnding}.";

        $res = aiExtract([
            'feature_key' => 'time.timesheet.from_upload',
            'instruction' => $instruction,
            'schema_hint' => $schema,
            'images'      => [['url' => $signedUrl, 'mime' => $mimeType]],
        ]);

        $draft = $res['data'] ?? [];
        $lines = is_array($draft['lines'] ?? null) ? $draft['lines'] : [];
        $confidence = timeUploadConfidence($lines);

        $pdo->prepare(
            'UPDATE time_uploaded_documents
                SET extraction_status = "extracted",
                    ai_extracted_json = :j,
                    ai_confidence     = :c,
                    ai_model          = :m
              WHERE id = :id'
        )->execute([
            'j' => json_encode($draft),
            'c' => $confidence,
            'm' => $res['model'] ?? null,
            'id' => $docId,
        ]);

        timeAudit('time.upload.extracted', [
            'document_id'    => $docId,
            'line_count'     => count($lines),
            'confidence'     => $confidence,
            'model'          => $res['model'] ?? null,
            'interaction_id' => $res['interaction_id'] ?? null,
        ], $docId);

        api_ok([
            'document_id' => $docId,
            'draft'       => $draft,
            'confidence'  => $confidence,
            'model'       => $res['model'] ?? null,
        ]);
    } catch (\Throwable $e) {
        $pdo->prepare(
            'UPDATE time_uploaded_documents
                SET extraction_status = "failed", ai_error = :e
              WHERE id = :id'
        )->execute(['e' => substr($e->getMessage(), 0, 500), 'id' => $docId]);
        timeAudit('time.upload.extract_failed', ['document_id' => $docId, 'error' => $e->getMessage()], $docId);
        api_error('Extraction failed: ' . $e->getMessage(), 502);
    }
}

// ─── re-fetch ───
if ($method === 'GET' && (int) ($_GET['id'] ?? 0) > 0) {
    RBAC::requirePermission($user, 'time.entry.create');
    $id = (int) $_GET['id'];
    $stmt = $pdo->prepare(
        'SELECT * FROM time_uploaded_documents
          WHERE tenant_id = :t AND id = :id LIMIT 1'
    );
    $stmt->execute(['t' => $tenantId, 'id' => $id]);
    $doc = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$doc) api_error('Not found', 404);
    $doc['ai_extracted'] = $doc['ai_extracted_json'] ? json_decode((string) $doc['ai_extracted_json'], true) : null;
    unset($doc['ai_extracted_json']);
    api_ok(['document' => $doc]);
}

// ─── consume ───
if ($method === 'POST' && $action === 'consume') {
    RBAC::requirePermission($user, 'time.entry.create');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 422);
    $body = api_json_body();
    $entryIds = is_array($body['entry_ids'] ?? null) ? $body['entry_ids'] : [];
    $count = count($entryIds);
    $pdo->prepare(
        'UPDATE time_uploaded_documents
            SET extraction_status = "consumed",
                consumed_at = NOW(),
                consumed_entry_count = :c
          WHERE tenant_id = :t AND id = :id'
    )->execute(['c' => $count, 't' => $tenantId, 'id' => $id]);
    timeAudit('time.upload.consumed', ['document_id' => $id, 'entry_count' => $count], $id);
    api_ok(['ok' => true, 'entry_count' => $count]);
}

api_error('Unknown action', 422);

/**
 * Heuristic: fraction of extracted lines that have a parseable work_date
 * AND a non-zero hours value AND a project string.
 */
function timeUploadConfidence(array $lines): float
{
    if (empty($lines)) return 0.0;
    $good = 0;
    foreach ($lines as $l) {
        $hasDate    = !empty($l['work_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $l['work_date']);
        $hasHours   = !empty($l['hours']) && (float) $l['hours'] > 0;
        $hasProject = !empty($l['project']);
        if ($hasDate && $hasHours && $hasProject) $good++;
    }
    return round($good / count($lines), 3);
}
