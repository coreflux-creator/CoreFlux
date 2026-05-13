<?php
/**
 * AP module — payments CSV export.
 *
 *   GET /api/ap/payments_csv_export → streams CSV of AP payments in tenant.
 *
 * Optional filters:
 *   ?status=draft|queued|sent|cleared|failed|void
 *   ?from=YYYY-MM-DD&to=YYYY-MM-DD       pay_date range
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
if (!empty($_GET['from']))        { $where[] = 'pay_date >= :f';  $params['f']  = $_GET['from']; }
if (!empty($_GET['to']))          { $where[] = 'pay_date <= :t';  $params['t']  = $_GET['to']; }
if (!empty($_GET['vendor_name'])) { $where[] = 'vendor_name = :v'; $params['v']  = $_GET['vendor_name']; }

$rows = scopedQuery(
    'SELECT vendor_name, pay_date, method, reference, amount, currency,
            unallocated_amount, status, cleared_at, sent_at, notes
       FROM ap_payments
      WHERE ' . implode(' AND ', $where) . '
      ORDER BY pay_date DESC, id DESC',
    $params
);

(new CsvExportService([
    'vendor_name'        => 'Vendor name',
    'pay_date'           => 'Pay date',
    'method'             => 'Method',
    'reference'          => 'Reference',
    'amount'             => 'Amount',
    'currency'           => 'Currency',
    'unallocated_amount' => 'Unallocated',
    'status'             => 'Status',
    'cleared_at'         => 'Cleared at',
    'sent_at'            => 'Sent at',
    'notes'              => 'Notes',
]))->stream($rows, 'ap_payments_export_' . date('Y-m-d') . '.csv');
