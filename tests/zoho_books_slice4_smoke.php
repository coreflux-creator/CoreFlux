<?php
/**
 * Zoho Books — Slice 4 (Invoices / Bills / Vendor Payments push) +
 * Transaction Value at Risk widget smoke.
 *
 * Validates:
 *   - core/zoho_books/sync_bills.php and sync_payments.php expose
 *     the documented surface, payload builders behave correctly.
 *   - sync_invoices.php (built in earlier increment) is still wired.
 *   - api/zoho_books.php dispatches sync_invoices / sync_bills /
 *     sync_payments.
 *   - cron/zoho_books_sync_outbound.php iterates the new workers.
 *   - api/admin/accounting_sync_reconcile.php registers Zoho runners
 *     for invoices, bills, payments (no more worker_pending fallback
 *     for these entities).
 *   - api/admin/accounting_sync_dashboard.php emits
 *     `transaction_value_at_risk` with QBO + Zoho breakdowns.
 *   - AccountingSyncDashboard.jsx renders the widget with
 *     Option A layout: pending $, oldest age (green/amber/red),
 *     24h sparkline (both amount + count).
 *   - ZohoBooksSettings.jsx surfaces per-entity manual sync buttons.
 *
 * Run via: php -d zend.assertions=1 tests/zoho_books_slice4_smoke.php
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ok  $msg\n"; $pass++; }
    else       { echo "FAIL  $msg\n"; $fail++; }
};
$c = function (string $hay, string $needle): bool { return strpos($hay, $needle) !== false; };

// ----------------------------------------------------- driver surfaces
echo "core/zoho_books — Slice 4 driver surfaces\n";
$drivers = [
    'core/zoho_books/sync_invoices.php' => ['zohoBooksSyncInvoices', 'zohoBooksBuildInvoicePayload', 'zohoBooksResolveCustomerRef'],
    'core/zoho_books/sync_bills.php'    => ['zohoBooksSyncBills',    'zohoBooksBuildBillPayload',    'zohoBooksResolveVendorRef'],
    'core/zoho_books/sync_payments.php' => ['zohoBooksSyncVendorPayments', 'zohoBooksBuildVendorPaymentPayload'],
];
foreach ($drivers as $rel => $fns) {
    $src = (string) @file_get_contents($ROOT . '/' . $rel);
    $a("$rel exists", $src !== '');
    foreach ($fns as $fn) {
        $a("$rel declares $fn()", $c($src, "function $fn"));
    }
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg($ROOT . '/' . $rel) . ' 2>&1', $out, $rc);
    $a("php -l $rel", $rc === 0);
}

// ----------------------------------------------------- bill contract
echo "\nDriver contracts — sync_bills.php\n";
$bills = (string) file_get_contents($ROOT . '/core/zoho_books/sync_bills.php');
$a('bills LEFT JOIN mapping idempotency',         $c($bills, 'LEFT JOIN external_entity_mappings'));
$a('bills require bills direction push/two_way',  $c($bills, "['push', 'two_way']"));
$a('bills POST to /books/v3/bills',               $c($bills, "'/books/v3/bills'"));
$a('bills read bill.bill_id from response',       $c($bills, "\$resp['bill']['bill_id']"));
$a('bills audit sync_bill_skip on vendor unmap',  $c($bills, "'sync_bill_skip'") && $c($bills, "'vendor_unmapped'"));
$a('bills mapping under entity_type=bill',        $c($bills, "mappingUpsert(\$tenantId, ZOHO_BOOKS_SOURCE, 'bill'"));

// ----------------------------------------------------- payment contract
echo "\nDriver contracts — sync_payments.php\n";
$pay = (string) file_get_contents($ROOT . '/core/zoho_books/sync_payments.php');
$a('payments require payments direction',         $c($pay, "['push', 'two_way']"));
$a('payments POST to /books/v3/vendorpayments',   $c($pay, "'/books/v3/vendorpayments'"));
$a('payments FIFO allocate across mapped bills',  $c($pay, 'amount_due > 0') && $c($pay, '$remaining -= $apply'));
$a('payments skip on no mapped bills with bal',   $c($pay, "'no_mapped_bills_with_balance'"));
$a('payments build link via bill_id',             $c($pay, "'bill_id'") && $c($pay, "'amount_applied'"));
$a('payments mapping under entity_type=payment',  $c($pay, "mappingUpsert(\$tenantId, ZOHO_BOOKS_SOURCE, 'payment'"));

// ----------------------------------------------------- invoice contract
echo "\nDriver contracts — sync_invoices.php\n";
$inv = (string) file_get_contents($ROOT . '/core/zoho_books/sync_invoices.php');
$a('invoices skip on customer_unmapped',          $c($inv, "'customer_unmapped'"));
$a('invoices POST to /books/v3/invoices',         $c($inv, "'/books/v3/invoices'"));
$a('invoices read invoice.invoice_id',            $c($inv, "\$resp['invoice']['invoice_id']"));
$a('invoices payload uses customer_id',           $c($inv, "'customer_id'"));

// ----------------------------------------------------- functional: bill payload
echo "\nFunctional — zohoBooksBuildBillPayload()\n";
require_once $ROOT . '/core/zoho_books/sync_bills.php';
$billPayload = zohoBooksBuildBillPayload(
    ['id' => 1, 'tenant_id' => 999999, 'bill_number' => 'BILL-1', 'bill_date' => '2026-02-15', 'due_date' => '2026-03-15', 'notes_internal' => 'note'],
    [], // no lines, exercises empty path
    ['value' => 'ZB-V-99', 'name' => 'Vendor X'],
    static fn (int $aid) => ['value' => 'ZB-A-' . $aid, 'name' => 'Acct ' . $aid]
);
$a('bill payload has vendor_id',                  ($billPayload['vendor_id'] ?? '') === 'ZB-V-99');
$a('bill payload has bill_number',                ($billPayload['bill_number'] ?? '') === 'BILL-1');
$a('bill payload has date YYYY-MM-DD',            ($billPayload['date'] ?? '') === '2026-02-15');
$a('bill payload has due_date',                   ($billPayload['due_date'] ?? '') === '2026-03-15');
$a('bill payload has empty line_items when no lines', isset($billPayload['line_items']) && count($billPayload['line_items']) === 0);

// ----------------------------------------------------- functional: payment payload
echo "\nFunctional — zohoBooksBuildVendorPaymentPayload()\n";
require_once $ROOT . '/core/zoho_books/sync_payments.php';
$payPayload = zohoBooksBuildVendorPaymentPayload(
    ['pay_date' => '2026-02-15', 'method' => 'check', 'amount' => 250.00, 'notes' => 'feb payroll'],
    ['value' => 'ZB-V-99', 'name' => 'Vendor X'],
    [
        ['zoho_bill_id' => 'ZB-B-1', 'amount' => 100.00],
        ['zoho_bill_id' => 'ZB-B-2', 'amount' => 150.00],
    ]
);
$a('payment payload has vendor_id',               ($payPayload['vendor_id'] ?? '') === 'ZB-V-99');
$a('payment payload has date',                    ($payPayload['date'] ?? '') === '2026-02-15');
$a('payment payload has amount',                  (float) ($payPayload['amount'] ?? 0) === 250.00);
$a('payment check → payment_mode=check',          ($payPayload['payment_mode'] ?? '') === 'check');
$a('payment ach   → payment_mode=banktransfer',
    (zohoBooksBuildVendorPaymentPayload(['pay_date' => '2026-02-15', 'method' => 'ach', 'amount' => 10, 'notes' => ''], ['value' => 'V', 'name' => 'X'], [['zoho_bill_id' => 'b', 'amount' => 10]])['payment_mode'] ?? '') === 'banktransfer');
$a('payment payload bills array shape',           is_array($payPayload['bills'] ?? null) && count($payPayload['bills']) === 2);
$a('first bill link uses bill_id/amount_applied', ($payPayload['bills'][0]['bill_id'] ?? '') === 'ZB-B-1' && (float) ($payPayload['bills'][0]['amount_applied'] ?? 0) === 100.00);

// ----------------------------------------------------- API dispatch
echo "\napi/zoho_books.php — Slice 4 dispatch\n";
$api = (string) file_get_contents($ROOT . '/api/zoho_books.php');
foreach (['sync_invoices', 'sync_bills', 'sync_payments'] as $act) {
    $a("handles action: $act",                    $c($api, "case '$act'"));
    $a("shim api/zoho_books/$act.php exists",     file_exists($ROOT . "/api/zoho_books/$act.php"));
}
$a('requires zoho sync_invoices module',          $c($api, "require_once __DIR__ . '/../core/zoho_books/sync_invoices.php'"));
$a('requires zoho sync_bills module',             $c($api, "require_once __DIR__ . '/../core/zoho_books/sync_bills.php'"));
$a('requires zoho sync_payments module',          $c($api, "require_once __DIR__ . '/../core/zoho_books/sync_payments.php'"));

// ----------------------------------------------------- cron
echo "\ncron/zoho_books_sync_outbound.php\n";
$cron = (string) file_get_contents($ROOT . '/cron/zoho_books_sync_outbound.php');
$a('cron requires sync_invoices',                 $c($cron, "require_once __DIR__ . '/../core/zoho_books/sync_invoices.php'"));
$a('cron requires sync_bills',                    $c($cron, "require_once __DIR__ . '/../core/zoho_books/sync_bills.php'"));
$a('cron requires sync_payments',                 $c($cron, "require_once __DIR__ . '/../core/zoho_books/sync_payments.php'"));
$a('cron iterates zohoBooksSyncJournalEntries',   $c($cron, 'zohoBooksSyncJournalEntries'));
$a('cron iterates zohoBooksSyncInvoices',         $c($cron, 'zohoBooksSyncInvoices'));
$a('cron iterates zohoBooksSyncBills',            $c($cron, 'zohoBooksSyncBills'));
$a('cron iterates zohoBooksSyncVendorPayments',   $c($cron, 'zohoBooksSyncVendorPayments'));

// ----------------------------------------------------- reconcile
echo "\napi/admin/accounting_sync_reconcile.php — Zoho runners registered\n";
$rec = (string) file_get_contents($ROOT . '/api/admin/accounting_sync_reconcile.php');
foreach (['zohoBooksSyncInvoices', 'zohoBooksSyncBills', 'zohoBooksSyncVendorPayments'] as $fn) {
    $a("reconcile registers $fn",                 $c($rec, $fn));
}
$a('reconcile requires sync_bills',               $c($rec, "require_once __DIR__ . '/../../core/zoho_books/sync_bills.php'"));
$a('reconcile requires sync_payments',            $c($rec, "require_once __DIR__ . '/../../core/zoho_books/sync_payments.php'"));

// ----------------------------------------------------- value at risk widget API
echo "\napi/admin/accounting_sync_dashboard.php — value-at-risk payload\n";
$dash = (string) file_get_contents($ROOT . '/api/admin/accounting_sync_dashboard.php');
$a('emits transaction_value_at_risk',             $c($dash, "'transaction_value_at_risk'"));
$a('VAR has qbo + zoho_books keys',               $c($dash, "'qbo'        => \$varQbo") && $c($dash, "'zoho_books' => \$varZoho"));
$a('VAR queries billing_invoices',                $c($dash, "'table'      => 'billing_invoices'"));
$a('VAR queries ap_bills',                        $c($dash, "'table'      => 'ap_bills'"));
$a('VAR queries ap_payments',                     $c($dash, "'table'      => 'ap_payments'"));
$a('VAR rollup computes oldest_age_minutes',      $c($dash, 'oldest_age_minutes'));
$a('VAR rollup classifies health green/amber/red',
    $c($dash, "\$health = 'green'") && $c($dash, "\$health = 'red'") && $c($dash, "\$health = 'amber'"));
$a('VAR sparkline buckets are 24',                $c($dash, "for (\$i = 0; \$i < 24; \$i++)"));
$a('VAR sparkline groups by hour',                $c($dash, "DATE_FORMAT(t.created_at, '%Y-%m-%d %H:00')"));
$a('VAR amber threshold = 30 min',                $c($dash, '$oldest >= 30'));
$a('VAR red threshold = 240 min (4h)',            $c($dash, '$oldest >= 240'));

// ----------------------------------------------------- dashboard JSX widget
echo "\nAccountingSyncDashboard.jsx — Option A widget\n";
$ui = (string) file_get_contents($ROOT . '/dashboard/src/pages/AccountingSyncDashboard.jsx');
$a('imports transaction_value_at_risk from data', $c($ui, 'transaction_value_at_risk: valueAtRisk'));
$a('renders two side-by-side widgets',            $c($ui, 'data-testid="acct-sync-var-widgets"'));
$a('QBO widget testid',                           $c($ui, 'testid="acct-sync-var-qbo"'));
$a('Zoho widget testid',                          $c($ui, 'testid="acct-sync-var-zoho"'));
$a('exposes pending-amount testid (qbo)',         $c($ui, '"acct-sync-var-qbo-pending-amount"') || $c($ui, '${testid}-pending-amount'));
$a('exposes oldest-age testid template',          $c($ui, '${testid}-oldest-age'));
$a('exposes sparkline testid template',           $c($ui, '${testid}-sparkline'));
$a('exposes health badge testid template',        $c($ui, '${testid}-health'));
$a('renders per-entity breakdown',                $c($ui, '${testid}-entity-${row.key}') || $c($ui, "'${testid}-by-entity'") || $c($ui, '`${testid}-by-entity`'));
$a('green health threshold copy',                 $c($ui, 'Green &lt;30m'));
$a('formatAge helper present',                    $c($ui, 'function formatAge'));
$a('Sparkline component renders svg',             $c($ui, 'function Sparkline') && $c($ui, '<svg'));

// ----------------------------------------------------- ZohoBooksSettings buttons
echo "\nZohoBooksSettings.jsx — Slice 4 manual sync buttons\n";
$zui = (string) file_get_contents($ROOT . '/dashboard/src/pages/ZohoBooksSettings.jsx');
foreach ([
    'zoho-books-sync-invoices-btn',
    'zoho-books-sync-bills-btn',
    'zoho-books-sync-payments-btn',
    'zoho-books-sync-invoices-dryrun-btn',
    'zoho-books-sync-bills-dryrun-btn',
    'zoho-books-sync-payments-dryrun-btn',
] as $tid) {
    $a("UI exposes $tid",                         $c($zui, "'$tid'") || $c($zui, "\"$tid\""));
}
$a('invoices button conditional on invDir',       $c($zui, "invDir  === 'push' || invDir  === 'two_way'"));
$a('bills button conditional on billDir',         $c($zui, "billDir === 'push' || billDir === 'two_way'"));
$a('payments button conditional on payDir',       $c($zui, "payDir  === 'push' || payDir  === 'two_way'"));

echo "\n=========================================\n";
echo "Zoho Books Slice 4 + VAR smoke: {$pass} ok / {$fail} fail\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
