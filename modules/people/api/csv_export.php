<?php
/**
 * People module — CSV export.
 *
 *   GET /api/people/csv_export → streams a CSV of all active people in tenant.
 *
 * Optional filters:
 *   ?status=active|bench|inactive|do_not_rehire
 *   ?classification=w2|1099|c2c|temp|perm|candidate|alumni
 *
 * Built on Core\CsvExportService primitive per HARD_RULES (2026-02-XX).
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/CsvExportService.php';

use Core\CsvExportService;

$ctx  = api_require_auth();
$user = $ctx['user'];
RBAC::requirePermission($user, 'people.view');

$where  = ['tenant_id = :tenant_id', 'deleted_at IS NULL'];
$params = [];
if (!empty($_GET['status']))         { $where[] = 'status = :s';         $params['s']  = $_GET['status']; }
if (!empty($_GET['classification'])) { $where[] = 'classification = :c'; $params['c']  = $_GET['classification']; }

$rows = scopedQuery(
    'SELECT first_name, middle_name, last_name, preferred_name,
            email_primary, email_secondary, phone_primary, phone_secondary,
            classification, status, work_auth_status, work_auth_expiry,
            requires_sponsorship, linkedin_url, source, external_id, recruiter_notes
       FROM people
      WHERE ' . implode(' AND ', $where) . '
      ORDER BY last_name, first_name',
    $params
);

(new CsvExportService([
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
