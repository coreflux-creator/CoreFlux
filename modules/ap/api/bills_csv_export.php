<?php
/**
 * AP module — bill CSV export, one row per line item.
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
// Delegated tenant scope sentinel for legacy CSV smokes: :tenant_id.

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

$where = ['b.tenant_id = :tenant_id'];
$params = ['tenant_id' => $tenantId];
if ($datasetOptions['status'] !== '') {
    $where[] = 'b.status = :status';
    $params['status'] = $datasetOptions['status'];
}
if ($datasetOptions['from'] !== '') {
    $where[] = 'b.bill_date >= :from_date';
    $params['from_date'] = $datasetOptions['from'];
}
if ($datasetOptions['to'] !== '') {
    $where[] = 'b.bill_date <= :to_date';
    $params['to_date'] = $datasetOptions['to'];
}
if ($datasetOptions['vendor_name'] !== '') {
    $where[] = 'b.vendor_name = :vendor_name';
    $params['vendor_name'] = $datasetOptions['vendor_name'];
}

$stmt = getDB()->prepare(
    'SELECT b.id AS bill_id, b.bill_number, b.external_id, b.source_system,
            b.status AS record_status, b.amount_paid,
            b.vendor_name, b.vendor_type, b.bill_date, b.due_date, b.received_at,
            b.period_start, b.period_end, b.currency, b.po_number, b.notes_internal,
            l.id AS line_id, l.line_no, l.description AS line_description,
            l.quantity AS line_quantity, l.unit AS line_unit,
            l.unit_price AS line_unit_price, l.subtotal AS line_subtotal,
            l.tax_amount AS line_tax_amount, l.total AS line_total
       FROM ap_bills b
  LEFT JOIN ap_bill_lines l ON l.bill_id = b.id
      WHERE ' . implode(' AND ', $where) . '
      ORDER BY b.bill_date DESC, b.id DESC, l.line_no ASC, l.id ASC
      LIMIT 10000'
);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

exportDatasetAudit($tenantId, $userId ?: null, 'ap.bills.exported', null, exportDatasetAuditMeta([
    'dataset' => 'ap_bills',
    'format' => 'csv',
    'mode' => 'raw',
    'rows' => count($rows),
], $datasetOptions));

(new CsvExportService([
    'bill_id'        => 'Bill ID',
    'bill_number'    => 'Bill #',
    'external_id'    => 'External ID (audit / integration)',
    'source_system'  => 'Source system',
    'record_status'  => 'Record status (read only)',
    'amount_paid'    => 'Amount paid (read only)',
    'vendor_name'    => 'Vendor name',
    'vendor_type'    => 'Vendor type',
    'bill_date'      => 'Bill date',
    'due_date'       => 'Due date',
    'received_at'    => 'Received at',
    'period_start'   => 'Period start',
    'period_end'     => 'Period end',
    'currency'       => 'Currency',
    'po_number'      => 'PO number',
    'notes_internal' => 'Notes (internal)',
    'line_id'          => 'Line ID (read only)',
    'line_no'          => 'Line #',
    'line_description' => 'Line description',
    'line_quantity'    => 'Line quantity',
    'line_unit'        => 'Line unit',
    'line_unit_price'  => 'Line unit price',
    'line_subtotal'    => 'Line subtotal',
    'line_tax_amount'  => 'Line tax amount',
    'line_total'       => 'Line total',
]))->stream($rows, 'bills_export_' . date('Y-m-d') . '.csv');
