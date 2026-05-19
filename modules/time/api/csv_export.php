<?php
/**
 * Time module — CSV export of time entries (a.k.a. timesheets).
 *
 *   GET /api/time/csv_export → streams CSV of time entries in tenant.
 *
 * Optional filters:
 *   ?from=YYYY-MM-DD&to=YYYY-MM-DD       work_date range
 *   ?status=draft|pending_review|approved|rejected
 *   ?placement_external_id=...
 *
 * Built on Core\CsvExportService primitive per HARD_RULES (2026-02-XX).
 * Mirrors the columns from /api/time/csv_import so a round-trip is
 * self-symmetric (export → edit → re-import via skip_invalid).
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/CsvExportService.php';

use Core\CsvExportService;

$ctx  = api_require_auth();
$user = $ctx['user'];
rbac_legacy_require($user, 'time.view');

$where  = ['te.tenant_id = :tenant_id'];
$params = [];
if (!empty($_GET['from']))   { $where[] = 'te.work_date >= :f'; $params['f'] = $_GET['from']; }
if (!empty($_GET['to']))     { $where[] = 'te.work_date <= :t'; $params['t'] = $_GET['to']; }
if (!empty($_GET['status'])) { $where[] = 'te.status = :s';     $params['s'] = $_GET['status']; }
if (!empty($_GET['placement_external_id'])) {
    $where[] = 'pl.external_id = :ext';
    $params['ext'] = $_GET['placement_external_id'];
}

$rows = scopedQuery(
    'SELECT pl.external_id AS placement_external_id,
            CONCAT_WS(" ", pe.first_name, pe.last_name) AS person_name,
            te.work_date, te.category, te.hours, te.status, te.description
       FROM time_entries te
       LEFT JOIN placements pl ON pl.id = te.placement_id AND pl.tenant_id = te.tenant_id
       LEFT JOIN people     pe ON pe.id = te.person_id    AND pe.tenant_id = te.tenant_id
      WHERE ' . implode(' AND ', $where) . '
      ORDER BY te.work_date DESC, te.id DESC',
    $params
);

(new CsvExportService([
    'placement_external_id' => 'Placement external ID',
    'person_name'           => 'Person name',
    'work_date'             => 'Work date',
    'category'              => 'Category',
    'hours'                 => 'Hours',
    'status'                => 'Status',
    'description'           => 'Description',
]))->stream($rows, 'timesheets_export_' . date('Y-m-d') . '.csv');
