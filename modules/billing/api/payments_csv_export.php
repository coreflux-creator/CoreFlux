<?php
/**
 * Billing module — payments CSV export.
 *
 *   GET /api/billing/payments_csv_export → streams CSV of AR payments.
 *
 * Optional filters:
 *   ?from=YYYY-MM-DD&to=YYYY-MM-DD       received_at range
 *   ?client_name=Acme
 *   ?method=ach|wire|check|card|cash|other
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
if (!empty($_GET['from']))        { $where[] = 'received_at >= :f'; $params['f']  = $_GET['from']; }
if (!empty($_GET['to']))          { $where[] = 'received_at <= :t'; $params['t']  = $_GET['to']; }
if (!empty($_GET['client_name'])) { $where[] = 'client_name = :c';  $params['c']  = $_GET['client_name']; }
if (!empty($_GET['method']))      { $where[] = 'method = :m';       $params['m']  = $_GET['method']; }

$rows = scopedQuery(
    'SELECT client_name, received_at, method, reference, amount, currency,
            unallocated_amount, notes
       FROM billing_payments
      WHERE ' . implode(' AND ', $where) . '
      ORDER BY received_at DESC, id DESC',
    $params
);

(new CsvExportService([
    'client_name'        => 'Client name',
    'received_at'        => 'Received at',
    'method'             => 'Method',
    'reference'          => 'Reference',
    'amount'             => 'Amount',
    'currency'           => 'Currency',
    'unallocated_amount' => 'Unallocated',
    'notes'              => 'Notes',
]))->stream($rows, 'billing_payments_export_' . date('Y-m-d') . '.csv');
