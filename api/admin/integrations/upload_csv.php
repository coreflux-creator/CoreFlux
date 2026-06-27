<?php
/**
 * /api/admin/integrations/upload_csv.php
 *
 * Upload a CSV export from any integration (JobDiva Job /
 * Candidate / Customer list, QBO export, Airtable view, etc.) and
 * index every column as a first-class mappable path under the chosen
 * `entity_type`. Operator drops the file into the Field Mapping
 * Studio, every column becomes available in the picker + Auto-map
 * suggester proposes targets.
 *
 *   POST /api/admin/integrations/upload_csv.php
 *     Content-Type: multipart/form-data
 *     fields:
 *       integration (text)  e.g. "jobdiva"
 *       entity_type (text)  e.g. "placement", "person", "company"
 *       file        (file)  the CSV file
 *
 *   → 200 {
 *       ok: true,
 *       rows_seen, rows_indexed, rows_skipped,
 *       field_count, sample_headers: [...up to 20...],
 *       errors: [...up to 20...]
 *     }
 *
 * RBAC: tenant_admin.integrations.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/integrations/csv_indexer.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];
if (api_method() !== 'POST') api_error('Method not allowed', 405);
rbac_legacy_require($user, 'tenant_admin.integrations');

$integration = trim((string) ($_POST['integration'] ?? ''));
$entityType  = trim((string) ($_POST['entity_type'] ?? ''));
if ($integration === '' || $entityType === '') {
    api_error('integration and entity_type are required', 400);
}
// Whitelist integration + entity_type to prevent stray writes.
if (!preg_match('/^[a-z0-9_]{1,40}$/', $integration)) {
    api_error('integration must be lowercase alphanumeric/underscore', 400);
}
if (!preg_match('/^[a-z0-9_]{1,40}$/', $entityType)) {
    api_error('entity_type must be lowercase alphanumeric/underscore', 400);
}

if (!isset($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    api_error('csv file upload missing or failed (error code ' . (int) ($_FILES['file']['error'] ?? -1) . ')', 400);
}
$tmp = (string) ($_FILES['file']['tmp_name'] ?? '');
if ($tmp === '' || !is_readable($tmp)) {
    api_error('uploaded csv not readable on server', 500);
}
// Defensive size cap — 25MB. A 25MB CSV is well over 200k rows.
$size = (int) ($_FILES['file']['size'] ?? 0);
if ($size > 25 * 1024 * 1024) {
    api_error('csv too large — please split into chunks under 25 MB', 413);
}

$summary = csvIndexerIngest($tid, $integration, $entityType, $tmp);

api_ok([
    'ok'             => true,
    'integration'    => $integration,
    'entity_type'    => $entityType,
    'rows_seen'      => (int) $summary['rows_seen'],
    'rows_indexed'   => (int) $summary['rows_indexed'],
    'rows_skipped'   => (int) $summary['rows_skipped'],
    'field_count'    => (int) $summary['field_count'],
    'sample_headers' => $summary['sample_headers'],
    'errors'         => $summary['errors'],
]);
