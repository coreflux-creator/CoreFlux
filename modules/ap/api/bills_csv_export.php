<?php
/**
 * AP module — bills CSV export (header-level; bill_lines excluded).
 *
 *   GET /api/ap/bills_csv_export → streams CSV of all bills in tenant.
 *
 * Optional filters:
 *   ?status=inbox|pending_review|pending_approval|approved|partially_paid|paid|void|disputed
 *   ?from=YYYY-MM-DD&to=YYYY-MM-DD     bill_date range
 *   ?vendor_name=Acme
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
rbac_legacy_require($user, 'ap.export.run');

$datasetOptions = [
    'status'      => (string) ($_GET['status'] ?? ''),
    'from'        => (string) ($_GET['from'] ?? ''),
    'to'          => (string) ($_GET['to'] ?? ''),
    'vendor_name' => (string) ($_GET['vendor_name'] ?? ''),
];

$tplId = (int) ($_GET['template_id'] ?? 0);
if ($tplId > 0) {
    try {
        exportTemplateStreamDatasetCsv(
            $tenantId,
            'ap_bills',
            $tplId,
            $datasetOptions,
            'ap-bills',
            $userId ?: null,
            null,
            ['filename_parts' => [date('Y-m-d')]]
        );
        exit;
    } catch (ExportServiceException $e) {
        api_error($e->getMessage(), 422);
    }
}

$rows = exportDatasetFetchApBills($tenantId, $datasetOptions);

exportDatasetAudit($tenantId, $userId ?: null, 'ap.bills.exported', null, [
    'dataset' => 'ap_bills',
    'format' => 'csv',
    'mode' => 'raw',
    'rows' => count($rows),
    'option_keys' => array_values(array_filter(array_keys($datasetOptions), fn($key) => $datasetOptions[$key] !== '')),
]);

(new CsvExportService([
    'bill_number'    => 'Bill #',
    'internal_ref'   => 'Internal ref',
    'vendor_name'    => 'Vendor name',
    'vendor_type'    => 'Vendor type',
    'received_at'    => 'Received at',
    'bill_date'      => 'Bill date',
    'due_date'       => 'Due date',
    'period_start'   => 'Period start',
    'period_end'     => 'Period end',
    'currency'       => 'Currency',
    'subtotal'       => 'Subtotal',
    'tax_total'      => 'Tax total',
    'total'          => 'Total',
    'amount_paid'    => 'Amount paid',
    'amount_due'     => 'Amount due',
    'status'         => 'Status',
    'source'         => 'Source',
    'po_number'      => 'PO number',
    'notes_internal' => 'Notes (internal)',
]))->stream($rows, 'bills_export_' . date('Y-m-d') . '.csv');
