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

use Core\CsvExportService;

$ctx  = api_require_auth();
$user = $ctx['user'];
RBAC::requirePermission($user, 'ap.view');

$where  = ['tenant_id = :tenant_id'];
$params = [];
if (!empty($_GET['status']))      { $where[] = 'status = :s';     $params['s']  = $_GET['status']; }
if (!empty($_GET['from']))        { $where[] = 'bill_date >= :f'; $params['f']  = $_GET['from']; }
if (!empty($_GET['to']))          { $where[] = 'bill_date <= :t'; $params['t']  = $_GET['to']; }
if (!empty($_GET['vendor_name'])) { $where[] = 'vendor_name = :v'; $params['v']  = $_GET['vendor_name']; }

$rows = scopedQuery(
    'SELECT bill_number, internal_ref, vendor_name, vendor_type,
            received_at, bill_date, due_date, period_start, period_end,
            currency, subtotal, tax_total, total, amount_paid, amount_due,
            status, source, po_number, notes_internal
       FROM ap_bills
      WHERE ' . implode(' AND ', $where) . '
      ORDER BY bill_date DESC, id DESC',
    $params
);

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
