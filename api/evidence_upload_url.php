<?php
/**
 * /api/evidence_upload_url.php — get a presigned upload URL for an
 * evidence attachment, then register it via /api/accounting/evidence.php.
 *
 * Two-step flow (browser):
 *   1. POST /api/evidence_upload_url.php
 *        { subject_type, subject_id, filename, content_type? }
 *      → { storage_key, upload: { url, fields }, signed_url }
 *   2. Multipart-POST the file bytes directly to upload.url with upload.fields
 *      + 'file' field.
 *   3. POST /api/accounting/evidence.php  (existing endpoint)
 *        { subject_type, subject_id, document_type, label, storage_key,
 *          content_type, size_bytes, sha256_hash? }
 *
 * Why split into two endpoints rather than one?
 *   - Step 1 cheap, no upload yet. UI can show "ready to upload" state.
 *   - Bytes stream straight to S3 without proxying through PHP.
 *   - Existing /api/accounting/evidence.php handles the metadata in
 *     exactly one place (idempotent dedupe via sha256_hash).
 *
 * Subject types allowed today (whitelist guards against arbitrary
 * subject_type insertion attacks):
 *   - time_entry        (signed paper timesheets, photos of sign-in sheets)
 *   - time_bundle       (approved bundle PDFs that feed billing/payroll)
 *   - billing_invoice   (supporting time bundles, customer POs, contracts)
 *   - ap_bill           (vendor invoices, receipts)
 *   - placement         (signed agreements, SOWs)
 *   - person            (W-4s, I-9s, IDs)
 *   - accounting_event  (canonical event-driven attachments)
 *   - journal_entry     (manual JE supporting docs)
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/StorageService.php';

use Core\StorageService;

$ctx      = api_require_auth();
$tenantId = (int) ($ctx['tenant_id'] ?? 0);
if (!$tenantId) api_error('No active tenant', 400);

if (api_method() !== 'POST') api_error('Method not allowed', 405);

$body         = api_json_body();
$subjectType  = (string) ($body['subject_type'] ?? '');
$subjectId    = (int) ($body['subject_id'] ?? 0);
$filename     = (string) ($body['filename'] ?? '');
$contentType  = (string) ($body['content_type'] ?? '');

$ALLOWED_SUBJECTS = [
    'time_entry', 'time_bundle', 'time_uploaded_document',
    'billing_invoice', 'ap_bill', 'ap_bill_line',
    'placement', 'person', 'company',
    'accounting_event', 'journal_entry',
];
if (!in_array($subjectType, $ALLOWED_SUBJECTS, true)) {
    api_error('subject_type not in allowlist', 422, ['allowed' => $ALLOWED_SUBJECTS]);
}
if ($subjectId <= 0)  api_error('subject_id required', 422);
if ($filename === '') api_error('filename required', 422);

// Map subject_type → storage module bucket prefix so files land in a
// sensible namespace ({module}/{tenant}/{entity_type}/{id}/{file}).
$MODULE_FOR = [
    'time_entry'              => 'time',
    'time_bundle'             => 'time',
    'time_uploaded_document'  => 'time',
    'billing_invoice'         => 'billing',
    'ap_bill'                 => 'ap',
    'ap_bill_line'            => 'ap',
    'placement'               => 'placements',
    'person'                  => 'people',
    'company'                 => 'companies',
    'accounting_event'        => 'accounting',
    'journal_entry'           => 'accounting',
];
$module = $MODULE_FOR[$subjectType] ?? 'evidence';

$storage = StorageService::getInstance();
$key     = $storage->build_key($module, $tenantId, $subjectType, $subjectId, $filename);
$post    = $storage->get_presigned_post($key);

api_ok([
    'storage_key' => $key,
    'upload'      => $post,                  // { url, fields }
    'signed_url'  => $storage->get_signed_url($key),
    'subject'     => ['subject_type' => $subjectType, 'subject_id' => $subjectId],
    'driver'      => $storage->driver_name(),
]);
