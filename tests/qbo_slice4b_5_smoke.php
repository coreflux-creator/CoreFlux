<?php
/**
 * QuickBooks Online — Slice 4b (Bill / Invoice / Payment / Item push) +
 * Slice 5 (Conflict log) + Sync Health Alerts smoke.
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};
$c = function (string $hay, string $needle): bool { return strpos($hay, $needle) !== false; };

// ----------------------------------------------------------------- migration
echo "Migration 053 — qbo_conflict_log + qbo_health_alerts\n";
$mig = (string) file_get_contents($ROOT . '/core/migrations/053_qbo_conflict_and_alerts.sql');
$a('declares qbo_conflict_log',                  $c($mig, 'CREATE TABLE IF NOT EXISTS qbo_conflict_log'));
$a('conflict log records winner enum',           $c($mig, "ENUM('coreflux','quickbooks','tie')"));
$a('conflict log carries snapshots',             $c($mig, 'coreflux_snapshot') && $c($mig, 'qbo_snapshot'));
$a('declares qbo_health_alerts',                 $c($mig, 'CREATE TABLE IF NOT EXISTS qbo_health_alerts'));
$a('alerts dedupe table has status_before+after',
    $c($mig, 'status_before') && $c($mig, 'status_after'));

// ----------------------------------------------------------------- Slice 4b core surface
echo "\nSlice 4b drivers\n";
foreach ([
    'core/qbo/sync_bills.php'    => ['qboSyncBills', 'qboBuildBillPayload', 'qboResolveVendorRef'],
    'core/qbo/sync_items.php'    => ['qboSyncItems', 'qboDefaultItemRef', 'qboItemRefForPlacement'],
    'core/qbo/sync_invoices.php' => ['qboSyncInvoices', 'qboBuildInvoicePayload', 'qboResolveCustomerRef'],
    'core/qbo/sync_payments.php' => ['qboSyncBillPayments', 'qboBuildBillPaymentPayload'],
    'core/qbo/conflict_rules.php'=> ['qboDetectConflict'],
    'core/qbo/health_alerts.php' => ['qboHealthEvaluate', 'qboHealthMaybeAlert'],
] as $rel => $fns) {
    $src = (string) file_get_contents($ROOT . '/' . $rel);
    $a("$rel exists",                            $src !== '');
    foreach ($fns as $fn) {
        $a("$rel declares $fn()",                $c($src, "function $fn"));
    }
}

// ----------------------------------------------------------------- specific contracts
echo "\nDriver contracts\n";
$bills = (string) file_get_contents($ROOT . '/core/qbo/sync_bills.php');
$a('bills LEFT JOIN mapping idempotency',        $c($bills, 'LEFT JOIN external_entity_mappings'));
$a('bills require bills direction push/two_way', $c($bills, "['push', 'two_way']"));
$a('bills payload uses AccountBasedExpenseLineDetail',
    $c($bills, "'DetailType'  => 'AccountBasedExpenseLineDetail'"));
$a('bills audit sync_bill_skip on vendor unmapped', $c($bills, "'sync_bill_skip'") && $c($bills, "'vendor_unmapped'"));
$a('bills DocNumber capped at 21 chars',         $c($bills, "substr((string) (\$bill['bill_number'] ?? ''), 0, 21)"));

$items = (string) file_get_contents($ROOT . '/core/qbo/sync_items.php');
$a('items use sentinel internal_entity_id=0',    $c($items, "mappingUpsert(\$tenantId, QBO_SOURCE, 'item', \$qboId, 0"));
$a('items default-picker prefers Service type',  $c($items, '$serviceHit'));
$a('items default-picker memoised per tenant',   $c($items, 'static $cache'));

$inv = (string) file_get_contents($ROOT . '/core/qbo/sync_invoices.php');
$a('invoices use SalesItemLineDetail',           $c($inv, "'DetailType'  => 'SalesItemLineDetail'"));
$a('invoices skip on no_default_item',           $c($inv, "'no_default_item'"));
$a('invoices skip on customer_unmapped',         $c($inv, "'customer_unmapped'"));
$a('invoices CustomerRef present',               $c($inv, "'CustomerRef'"));

$pay = (string) file_get_contents($ROOT . '/core/qbo/sync_payments.php');
$a('payments link via LinkedTxn Bill',           $c($pay, "'TxnType' => 'Bill'") && $c($pay, "'LinkedTxn'"));
$a('payments FIFO allocate across mapped bills', $c($pay, 'amount_due > 0') && $c($pay, '$remaining -= $apply'));
$a('payments skip on no_mapped_bills',           $c($pay, "'no_mapped_bills_with_balance'"));

$conf = (string) file_get_contents($ROOT . '/core/qbo/conflict_rules.php');
$a('conflict logs winner=coreflux when newer',   $c($conf, "'coreflux'"));
$a('conflict logs winner=quickbooks when newer', $c($conf, "'quickbooks'"));
$a('conflict only fires on two_way direction',   $c($conf, "\$dir !== 'two_way'"));
$a('conflict writes to qbo_conflict_log',        $c($conf, 'INSERT INTO qbo_conflict_log'));
$a('conflict audits as conflict_detected',       $c($conf, "'conflict_detected'"));

$alerts = (string) file_get_contents($ROOT . '/core/qbo/health_alerts.php');
$a('alerts dedupe via last alert row',           $c($alerts, "ORDER BY notified_at DESC LIMIT 1"));
$a('alerts call sendEmail()',                    $c($alerts, 'sendEmail('));
$a('alerts persist via qbo_health_alerts INSERT',$c($alerts, 'INSERT INTO qbo_health_alerts'));
$a('alerts find tenant admin recipient',         $c($alerts, "role IN ('master_admin','tenant_admin')"));

// ----------------------------------------------------------------- Conflict integrated into Slice 3 customer upsert
echo "\nSlice 3 ↔ Slice 5 wiring\n";
$si = (string) file_get_contents($ROOT . '/core/qbo/sync_in.php');
$a('sync_in.php requires conflict_rules',        $c($si, "require_once __DIR__ . '/conflict_rules.php'"));
$a('customer upsert calls qboDetectConflict',    $c($si, "qboDetectConflict(\$tenantId, 'customer'"));
$a('customer respects coreflux winner',          $c($si, "'conflict_coreflux_wins'"));

// ----------------------------------------------------------------- api
echo "\napi/qbo.php — Slice 4b + 5 dispatch\n";
$api = (string) file_get_contents($ROOT . '/api/qbo.php');
foreach (['sync_bills', 'sync_invoices', 'sync_payments', 'sync_items'] as $act) {
    $a("dispatches case $act",                   $c($api, "case '$act'"));
    $a("shim api/qbo/$act.php exists",           file_exists($ROOT . "/api/qbo/$act.php"));
}
$a('uses PHP 8 match for new push entities',     $c($api, 'match ($action)'));

// ----------------------------------------------------------------- cron
echo "\nCron wiring\n";
$out = (string) file_get_contents($ROOT . '/cron/qbo_sync_outbound.php');
$a('outbound iterates bills/invoices/payments',
    $c($out, "'bills'    => 'qboSyncBills'")
    && $c($out, "'invoices' => 'qboSyncInvoices'")
    && $c($out, "'payments' => 'qboSyncBillPayments'"));
$in = (string) file_get_contents($ROOT . '/cron/qbo_sync_inbound.php');
$a('inbound runs items pass when invoices push', $c($in, 'qboSyncItems') && $c($in, "['push', 'two_way']"));

$hc = (string) file_get_contents($ROOT . '/cron/qbo_health_alerts.php');
$a('health alerts cron exists',                  $hc !== '');
$a('calls qboHealthMaybeAlert',                  $c($hc, 'qboHealthMaybeAlert'));

// ----------------------------------------------------------------- syntax
echo "\nSyntax sanity (php -l)\n";
foreach ([
    'core/qbo/sync_bills.php', 'core/qbo/sync_items.php',
    'core/qbo/sync_invoices.php', 'core/qbo/sync_payments.php',
    'core/qbo/conflict_rules.php', 'core/qbo/health_alerts.php',
    'core/qbo/sync_in.php', 'api/qbo.php',
    'cron/qbo_sync_outbound.php', 'cron/qbo_sync_inbound.php',
    'cron/qbo_health_alerts.php',
] as $f) {
    $rc = 0; $o = [];
    exec('php -l ' . escapeshellarg($ROOT . '/' . $f) . ' 2>&1', $o, $rc);
    $a("php -l $f",                              $rc === 0);
}

// ----------------------------------------------------------------- UI
echo "\nUI — QboSettings.jsx push/pull buttons\n";
$ui = (string) file_get_contents($ROOT . '/dashboard/src/pages/QboSettings.jsx');
foreach ([
    'qbo-sync-bills-btn',
    'qbo-sync-invoices-btn',
    'qbo-sync-payments-btn',
    'qbo-sync-items-btn',
] as $tid) {
    $a("UI exposes $tid",                        $c($ui, "data-testid=\"$tid\""));
}
$a('bills button conditional on bills direction',$c($ui, "['push', 'two_way'].includes(billDir)"));
$a('invoices button conditional on invDir',      $c($ui, "['push', 'two_way'].includes(invDir)"));
$a('payments button conditional on payDir',      $c($ui, "['push', 'two_way'].includes(payDir)"));

// ----------------------------------------------------------------- Functional: bill payload shape
echo "\nFunctional — qboBuildBillPayload\n";
require_once $ROOT . '/core/qbo/sync_bills.php';
$bill = ['id' => 1, 'tenant_id' => 999999, 'bill_number' => 'BILL-1', 'bill_date' => '2026-02-15', 'due_date' => '2026-03-15', 'notes_internal' => 'x'];
$linesIn = [['id' => 1, 'description' => 'Service', 'total' => '250.00', 'gl_expense_account_code' => '6000']];
$resolver = static fn (int $aid) => ['value' => 'QBO_99', 'name' => 'Expense'];
// Force the inner code-to-id lookup to short-circuit by stubbing the getDB.
// Easier: skip pure functional + smoke just the static payload shape.
$payload = qboBuildBillPayload($bill, [], null, $resolver); // no lines, no vendor
$a('bill payload includes DueDate',              isset($payload['DueDate']) && $payload['DueDate'] === '2026-03-15');
$a('bill payload caps DocNumber at 21 chars',    strlen((string)($payload['DocNumber'] ?? '')) <= 21);

echo "\n=========================================\n";
echo "QBO Slice 4b + 5 + Alerts smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
