<?php
/**
 * Placements module — CSV export.
 *
 *   GET /api/placements/csv_export → streams CSV of placements in tenant.
 *
 * Optional filters:
 *   ?status=draft|active|ended|cancelled
 *   ?engagement_type=w2|1099|c2c|temp_to_perm|direct_hire
 *
 * Built on Core\CsvExportService primitive per HARD_RULES (2026-02-XX).
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
rbac_legacy_require($user, 'placements.view');

$datasetOptions = [
    'status' => (string) ($_GET['status'] ?? ''),
    'engagement_type' => (string) ($_GET['engagement_type'] ?? ''),
];

$tplId = (int) ($_GET['template_id'] ?? 0);
if ($tplId > 0) {
    try {
        exportTemplateStreamDatasetCsv(
            $tenantId,
            'placements_directory',
            $tplId,
            $datasetOptions,
            'placements',
            $userId ?: null,
            null,
            ['filename_parts' => [date('Y-m-d')]]
        );
        exit;
    } catch (ExportServiceException $e) {
        api_error($e->getMessage(), 422);
    }
}

$where  = ['p.tenant_id = :tenant_id', 'p.deleted_at IS NULL'];
$params = [];
if (!empty($_GET['status']))          { $where[] = 'p.status = :s';           $params['s']  = $_GET['status']; }
if (!empty($_GET['engagement_type'])) { $where[] = 'p.engagement_type = :et'; $params['et'] = $_GET['engagement_type']; }

$rows = scopedQuery(
    'SELECT pe.email_primary AS person_email,
            CONCAT_WS(" ", pe.first_name, pe.last_name) AS person_name,
            p.title, p.engagement_type, p.status,
            p.start_date, p.end_date, p.due_date,
            p.end_client_name, p.worksite_state, p.worksite_country, p.remote_policy,
            (SELECT bill_rate FROM placement_rates r WHERE r.placement_id = p.id ORDER BY r.effective_from DESC LIMIT 1) AS bill_rate,
            (SELECT pay_rate  FROM placement_rates r WHERE r.placement_id = p.id ORDER BY r.effective_from DESC LIMIT 1) AS pay_rate,
            p.external_id, p.notes
       FROM placements p
       LEFT JOIN people pe ON pe.id = p.person_id AND pe.tenant_id = p.tenant_id
      WHERE ' . implode(' AND ', $where) . '
      ORDER BY p.start_date DESC, p.id DESC',
    $params
);

exportDatasetAudit($tenantId, $userId ?: null, 'placement.exported', null, [
    'dataset' => 'placements_directory',
    'format' => 'csv',
    'mode' => 'raw',
    'rows' => count($rows),
    'option_keys' => array_values(array_filter(array_keys($datasetOptions), fn($key) => $datasetOptions[$key] !== '')),
]);

(new CsvExportService([
    'person_email'      => 'Person email',
    'person_name'       => 'Person name',
    'title'             => 'Title',
    'engagement_type'   => 'Engagement type',
    'status'            => 'Status',
    'start_date'        => 'Start date',
    'end_date'          => 'End date',
    'due_date'          => 'Due date',
    'end_client_name'   => 'End client name',
    'worksite_state'    => 'Worksite state',
    'worksite_country'  => 'Worksite country',
    'remote_policy'     => 'Remote policy',
    'bill_rate'         => 'Bill rate ($/hr)',
    'pay_rate'          => 'Pay rate ($/hr)',
    'external_id'       => 'External ID',
    'notes'             => 'Notes',
]))->stream($rows, 'placements_export_' . date('Y-m-d') . '.csv');
