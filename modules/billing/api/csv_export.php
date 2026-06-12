<?php
/**
 * Billing module — invoices CSV export (header-level; invoice_lines excluded).
 *
 *   GET /api/billing/csv_export → streams CSV of all invoices in tenant.
 *
 * Optional filters:
 *   ?status=draft|approved|sent|partially_paid|paid|void
 *   ?from=YYYY-MM-DD&to=YYYY-MM-DD       issue_date range
 *   ?client_name=Acme
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
rbac_legacy_require($user, 'billing.view');

$datasetOptions = [
    'status'      => (string) ($_GET['status'] ?? ''),
    'from'        => (string) ($_GET['from'] ?? ''),
    'to'          => (string) ($_GET['to'] ?? ''),
    'client_name' => (string) ($_GET['client_name'] ?? ''),
];

$tplId = (int) ($_GET['template_id'] ?? 0);
if ($tplId > 0) {
    try {
        exportTemplateStreamDatasetCsv(
            $tenantId,
            'billing_invoices',
            $tplId,
            $datasetOptions,
            'billing-invoices',
            $userId ?: null,
            null,
            ['filename_parts' => [date('Y-m-d')]]
        );
        exit;
    } catch (ExportServiceException $e) {
        api_error($e->getMessage(), 422);
    }
}

$rows = exportDatasetFetchBillingInvoices($tenantId, $datasetOptions);

exportDatasetAudit($tenantId, $userId ?: null, 'billing.invoice.exported', null, [
    'dataset' => 'billing_invoices',
    'format' => 'csv',
    'mode' => 'raw',
    'rows' => count($rows),
    'option_keys' => array_values(array_filter(array_keys($datasetOptions), fn($key) => $datasetOptions[$key] !== '')),
]);

(new CsvExportService([
    'invoice_number' => 'Invoice #',
    'client_name'    => 'Client name',
    'currency'       => 'Currency',
    'issue_date'     => 'Issue date',
    'due_date'       => 'Due date',
    'period_start'   => 'Period start',
    'period_end'     => 'Period end',
    'subtotal'       => 'Subtotal',
    'tax_total'      => 'Tax total',
    'total'          => 'Total',
    'amount_paid'    => 'Amount paid',
    'amount_due'     => 'Amount due',
    'status'         => 'Status',
    'po_number'      => 'PO number',
    'aggregation'    => 'Aggregation',
    'notes_external' => 'Notes (external)',
]))->stream($rows, 'invoices_export_' . date('Y-m-d') . '.csv');
