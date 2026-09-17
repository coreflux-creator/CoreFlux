<?php
/**
 * Billing module — invoice CSV export, one row per line item.
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
// Delegated tenant scope sentinel for legacy CSV smokes: :tenant_id.

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

$where = ['i.tenant_id = :tenant_id'];
$params = ['tenant_id' => $tenantId];
if ($datasetOptions['status'] !== '') {
    $where[] = 'i.status = :status';
    $params['status'] = $datasetOptions['status'];
}
if ($datasetOptions['from'] !== '') {
    $where[] = 'i.issue_date >= :from_date';
    $params['from_date'] = $datasetOptions['from'];
}
if ($datasetOptions['to'] !== '') {
    $where[] = 'i.issue_date <= :to_date';
    $params['to_date'] = $datasetOptions['to'];
}
if ($datasetOptions['client_name'] !== '') {
    $where[] = 'i.client_name = :client_name';
    $params['client_name'] = $datasetOptions['client_name'];
}

$stmt = getDB()->prepare(
    'SELECT i.id AS invoice_id, i.invoice_number, i.external_id, i.source_system,
            i.status AS record_status, i.amount_paid,
            i.client_name, i.issue_date, i.due_date, i.period_start, i.period_end,
            i.currency, i.po_number, i.aggregation, i.notes_external,
            l.id AS line_id, l.line_no, l.description AS line_description,
            l.quantity AS line_quantity, l.unit AS line_unit,
            l.unit_price AS line_unit_price, l.subtotal AS line_subtotal,
            l.tax_amount AS line_tax_amount, l.total AS line_total
       FROM billing_invoices i
  LEFT JOIN billing_invoice_lines l ON l.invoice_id = i.id
      WHERE ' . implode(' AND ', $where) . '
      ORDER BY i.issue_date DESC, i.id DESC, l.line_no ASC, l.id ASC
      LIMIT 10000'
);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

exportDatasetAudit($tenantId, $userId ?: null, 'billing.invoice.exported', null, exportDatasetAuditMeta([
    'dataset' => 'billing_invoices',
    'format' => 'csv',
    'mode' => 'raw',
    'rows' => count($rows),
], $datasetOptions));

(new CsvExportService([
    'invoice_id'     => 'Invoice ID',
    'invoice_number' => 'Invoice #',
    'external_id'    => 'External ID (audit / integration)',
    'source_system'  => 'Source system',
    'record_status'  => 'Record status (read only)',
    'amount_paid'    => 'Amount paid (read only)',
    'client_name'    => 'Client name',
    'issue_date'     => 'Issue date',
    'due_date'       => 'Due date',
    'period_start'   => 'Period start',
    'period_end'     => 'Period end',
    'currency'       => 'Currency',
    'po_number'      => 'PO number',
    'aggregation'    => 'Aggregation',
    'notes_external' => 'Notes (external)',
    'line_id'          => 'Line ID (read only)',
    'line_no'          => 'Line #',
    'line_description' => 'Line description',
    'line_quantity'    => 'Line quantity',
    'line_unit'        => 'Line unit',
    'line_unit_price'  => 'Line unit price',
    'line_subtotal'    => 'Line subtotal',
    'line_tax_amount'  => 'Line tax amount',
    'line_total'       => 'Line total',
]))->stream($rows, 'invoices_export_' . date('Y-m-d') . '.csv');
