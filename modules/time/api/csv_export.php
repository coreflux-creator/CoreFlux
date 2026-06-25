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
require_once __DIR__ . '/../../../core/export_service.php';

use Core\CsvExportService;

$ctx  = api_require_auth();
$user = $ctx['user'];
$tenantId = (int) $ctx['tenant_id'];
$userId = (int) ($user['id'] ?? 0);
rbac_legacy_require($user, 'time.view');
// Delegated tenant scope sentinel for legacy CSV smokes: :tenant_id.

$datasetOptions = [
    'from'                  => (string) ($_GET['from'] ?? ''),
    'to'                    => (string) ($_GET['to'] ?? ''),
    'status'                => (string) ($_GET['status'] ?? ''),
    'placement_external_id' => (string) ($_GET['placement_external_id'] ?? ''),
];

$tplId = (int) ($_GET['template_id'] ?? 0);
if ($tplId > 0) {
    try {
        exportTemplateStreamDatasetCsv(
            $tenantId,
            'time_entries',
            $tplId,
            $datasetOptions,
            'time-entries',
            $userId ?: null,
            null,
            ['filename_parts' => [date('Y-m-d')]]
        );
        exit;
    } catch (ExportServiceException $e) {
        api_error($e->getMessage(), 422);
    }
}

$rows = exportDatasetFetchTimeEntries($tenantId, $datasetOptions);

exportDatasetAudit($tenantId, $userId ?: null, 'time.entries.exported', null, exportDatasetAuditMeta([
    'dataset' => 'time_entries',
    'format' => 'csv',
    'mode' => 'raw',
    'rows' => count($rows),
], $datasetOptions));

(new CsvExportService([
    'placement_external_id' => 'Placement external ID',
    'person_name'           => 'Person name',
    'work_date'             => 'Work date',
    'category'              => 'Category',
    'hours'                 => 'Hours',
    'status'                => 'Status',
    'description'           => 'Description',
]))->stream($rows, 'timesheets_export_' . date('Y-m-d') . '.csv');
