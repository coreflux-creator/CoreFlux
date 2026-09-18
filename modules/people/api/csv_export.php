<?php
/**
 * People module — CSV export.
 *
 *   GET /api/people/csv_export → streams the tenant's default People export template.
 *
 * Optional filters:
 *   ?status=active|bench|inactive|do_not_rehire
 *   ?classification=w2|1099|c2c|temp|perm|candidate|alumni
 *
 * Add ?raw=1 for the complete legacy extract. The governed template is the
 * default so the template editor and the ordinary Export CSV button always
 * describe the same file.
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/CsvExportService.php';
require_once __DIR__ . '/../../../core/export_service.php';

use Core\CsvExportService;

$ctx  = api_require_auth();
$user = $ctx['user'];
$tenantId = (int) $ctx['tenant_id'];
$userId = (int) ($user['id'] ?? 0);
rbac_legacy_require($user, 'people.view');

$datasetOptions = [
    'status'         => (string) ($_GET['status'] ?? ''),
    'classification' => (string) ($_GET['classification'] ?? ''),
];

$rawMode = in_array(strtolower((string) ($_GET['raw'] ?? '')), ['1', 'true', 'yes'], true);
$templateId = (int) ($_GET['template_id'] ?? 0);
if (!$rawMode && $templateId <= 0) {
    $defaultTemplate = exportTemplateDefault($tenantId, 'people_directory');
    $templateId = (int) ($defaultTemplate['id'] ?? 0);
}
if (!$rawMode && $templateId > 0) {
    try {
        exportTemplateStreamDatasetCsv(
            $tenantId,
            'people_directory',
            $templateId,
            $datasetOptions,
            'people-directory',
            $userId ?: null,
            null,
            ['filename_parts' => [date('Y-m-d')]]
        );
        exit;
    } catch (ExportServiceException $e) {
        api_error($e->getMessage(), 422);
    }
}

$where  = ['tenant_id = :tenant_id', 'deleted_at IS NULL'];
$params = [];
if ($datasetOptions['status'] !== '')         { $where[] = 'status = :s';         $params['s']  = $datasetOptions['status']; }
if ($datasetOptions['classification'] !== '') { $where[] = 'classification = :c'; $params['c']  = $datasetOptions['classification']; }

$rows = scopedQuery(
    'SELECT id AS person_id, first_name, middle_name, last_name, preferred_name,
            email_primary, email_secondary, phone_primary, phone_secondary,
            classification, status, work_auth_status, work_auth_expiry,
            requires_sponsorship, linkedin_url, source, external_id, recruiter_notes
       FROM people
      WHERE ' . implode(' AND ', $where) . '
      ORDER BY last_name, first_name',
    $params
);

(new CsvExportService([
    'person_id'            => 'Person ID',
    'first_name'           => 'First name',
    'middle_name'          => 'Middle name',
    'last_name'            => 'Last name',
    'preferred_name'       => 'Preferred name',
    'email_primary'        => 'Primary email',
    'email_secondary'      => 'Secondary email',
    'phone_primary'        => 'Primary phone',
    'phone_secondary'      => 'Secondary phone',
    'classification'       => 'Classification',
    'status'               => 'Status',
    'work_auth_status'     => 'Work auth status',
    'work_auth_expiry'     => 'Work auth expiry',
    'requires_sponsorship' => 'Requires sponsorship',
    'linkedin_url'         => 'LinkedIn URL',
    'source'               => 'Source',
    'external_id'          => 'External ID',
    'recruiter_notes'      => 'Recruiter notes',
]))->stream($rows, 'people_export_' . date('Y-m-d') . '.csv');
