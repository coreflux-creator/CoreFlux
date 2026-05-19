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

use Core\CsvExportService;

$ctx  = api_require_auth();
$user = $ctx['user'];
rbac_legacy_require($user, 'billing.view');

$where  = ['tenant_id = :tenant_id'];
$params = [];
if (!empty($_GET['status']))      { $where[] = 'status = :s';      $params['s']  = $_GET['status']; }
if (!empty($_GET['from']))        { $where[] = 'issue_date >= :f'; $params['f']  = $_GET['from']; }
if (!empty($_GET['to']))          { $where[] = 'issue_date <= :t'; $params['t']  = $_GET['to']; }
if (!empty($_GET['client_name'])) { $where[] = 'client_name = :c'; $params['c']  = $_GET['client_name']; }

$rows = scopedQuery(
    'SELECT invoice_number, client_name, currency, issue_date, due_date,
            period_start, period_end,
            subtotal, tax_total, total, amount_paid, amount_due,
            status, po_number, aggregation, notes_external
       FROM billing_invoices
      WHERE ' . implode(' AND ', $where) . '
      ORDER BY issue_date DESC, id DESC',
    $params
);

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
