<?php
/**
 * Smoke — QBO two-way sync (Phases 1-3): AR + AP pull, drift detection,
 * cron, admin surface.
 *
 * Locks:
 *   - Migration 114 declares the 6 tables (5 inbound shadows + drift).
 *   - core/qbo/sync_in_arap.php exposes the five public pull functions
 *     with stable signatures.
 *   - Drift taxonomy is the canonical 5-kind list.
 *   - Cron is shaped correctly (loops tenants, advances since-cursor,
 *     records last_error).
 *   - Admin endpoint exists with GET + POST handlers, RBAC-gated,
 *     resolution allowlist.
 *   - Shadow upsert + drift detector behave correctly against an
 *     in-memory SQLite mirror.
 *
 * Run: php -d zend.assertions=1 /app/tests/qbo_two_way_sync_smoke.php
 */
declare(strict_types=1);

$passes = 0; $failures = [];
function check(string $label, bool $cond) {
    global $passes, $failures;
    if ($cond) { $passes++; echo "  ✓ {$label}\n"; }
    else       { $failures[] = $label; echo "  ✗ {$label}\n"; }
}

echo "\nQBO two-way sync smoke (Phases 1-3)\n";
echo "====================================\n\n";

// ─────── 1. Migration ───────
echo "── migration 114 ──\n";
$migPath = '/app/core/migrations/114_qbo_two_way_sync.sql';
check('migration file exists', file_exists($migPath));
$mig = (string) file_get_contents($migPath);
foreach (['qbo_inbound_invoices', 'qbo_inbound_payments', 'qbo_inbound_deposits',
          'qbo_inbound_bills',    'qbo_inbound_billpayments', 'qbo_sync_drift'] as $t) {
    check("migration declares {$t}",   str_contains($mig, "CREATE TABLE {$t}"));
}
check('drift table has severity ENUM(info,warn,critical)',
    str_contains($mig, "ENUM('info','warn','critical')"));
check('drift table has status ENUM(open,acknowledged,reconciled,dismissed)',
    str_contains($mig, "ENUM('open','acknowledged','reconciled','dismissed')"));
check('drift UNIQUE prevents duplicate open rows',
    str_contains($mig, 'uniq_open_drift (tenant_id, entity_type, qbo_id, drift_kind)'));
check('Invoice shadow has balance_cents + total_amount_cents',
    str_contains($mig, 'balance_cents') && str_contains($mig, 'total_amount_cents'));
check('Deposit shadow has fee_cents (bank-fee reconciliation)',
    preg_match('/CREATE TABLE qbo_inbound_deposits.*fee_cents/s', $mig) === 1);

// ─────── 2. Module shape ───────
echo "\n── core/qbo/sync_in_arap.php ──\n";
$srcPath = '/app/core/qbo/sync_in_arap.php';
check('module exists', file_exists($srcPath));
require_once $srcPath;
foreach (['qboPullInvoices','qboPullPayments','qboPullDeposits',
          'qboPullBills','qboPullBillPayments'] as $fn) {
    check("declares {$fn}()", function_exists($fn));
}
$src = (string) file_get_contents($srcPath);
check('uses QBO Query API with STARTPOSITION pagination',
    str_contains($src, 'STARTPOSITION') && str_contains($src, 'MAXRESULTS'));
check('honours modified_since via MetaData.LastUpdatedTime',
    str_contains($src, 'MetaData.LastUpdatedTime >='));
check('writes audits via qboAudit',         str_contains($src, 'qboAudit'));
check('extracts bank fees from Deposit Line[] (negative amounts)',
    str_contains($src, '$amt < 0'));
check('extracts linked invoices from Payment LinkedTxn',
    preg_match("/TxnType.*?=== 'Invoice'/", $src) === 1);
check('extracts linked bills from BillPayment LinkedTxn',
    preg_match("/TxnType.*?=== 'Bill'/", $src) === 1);

// ─────── 3. Drift taxonomy ───────
echo "\n── drift taxonomy ──\n";
foreach (['paid_out_of_band', 'balance_changed', 'voided_in_qbo'] as $kind) {
    check("drift kind '{$kind}' is emitted somewhere", str_contains($src, "'{$kind}'"));
}
check('paid_out_of_band has severity=warn',  preg_match("/'paid_out_of_band'.*?'warn'/s", $src) === 1);
check('voided_in_qbo has severity=critical', preg_match("/'voided_in_qbo'.*?'critical'/s", $src) === 1);

// ─────── 4. Cron ───────
echo "\n── cron/qbo_two_way_sync.php ──\n";
$cronPath = '/app/cron/qbo_two_way_sync.php';
check('cron exists', file_exists($cronPath));
$cron = (string) file_get_contents($cronPath);
check('cron imports sync_in_arap.php',           str_contains($cron, "sync_in_arap.php"));
check('cron loops active qbo_connections',
    preg_match("/FROM qbo_connections\\s+WHERE status\\s*=\\s*'active'/s", $cron) === 1);
check('cron creates tenant_qbo_two_way_state table',
    str_contains($cron, 'CREATE TABLE IF NOT EXISTS tenant_qbo_two_way_state'));
check('cron calls all 5 pull functions in order',
    str_contains($cron, "['qboPullInvoices', 'qboPullPayments', 'qboPullDeposits',\n              'qboPullBills',    'qboPullBillPayments']"));
check('cron advances since-cursor with 5-min overlap window',
    preg_match("/strtotime\\(\\\$since\\) - 300/", $cron) === 1);
check('cron emits summary line',                 str_contains($cron, 'qbo_two_way_sync done:'));

// ─────── 5. Admin endpoint ───────
echo "\n── /api/admin/qbo/sync_drift.php ──\n";
$epPath = '/app/api/admin/qbo/sync_drift.php';
check('endpoint exists', file_exists($epPath));
$ep = (string) file_get_contents($epPath);
check('endpoint calls api_require_auth',         str_contains($ep, 'api_require_auth()'));
check('endpoint enforces master_admin / tenant_admin',
    str_contains($ep, "rbac_legacy_require_any") && str_contains($ep, 'master_admin'));
check('GET reads qbo_sync_drift',                str_contains($ep, 'FROM qbo_sync_drift'));
check('GET returns severity-grouped counts',     str_contains($ep, "'counts'"));
check('GET orders critical > warn > info',
    preg_match('/severity\s*=\s*"critical".*?DESC.*?severity\s*=\s*"warn".*?DESC/s', $ep) === 1);
check('POST resolution allowlist exact',         str_contains($ep, "['acknowledged', 'reconciled', 'dismissed']"));
check('POST scopes UPDATE by tenant_id',         str_contains($ep, 'AND tenant_id = :t'));

// ─────── 6. Live shape exercise (SQLite mirror) ───────
echo "\n── live behaviour ──\n";
require_once '/app/core/qbo/client.php';

$GLOBALS['pdo'] = new \PDO('sqlite::memory:');
$GLOBALS['pdo']->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$pdo = $GLOBALS['pdo'];

// Mirror the migration. SQLite TEXT for ENUM.
$pdo->exec("CREATE TABLE qbo_inbound_invoices (
    id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INT, qbo_invoice_id TEXT,
    doc_number TEXT, customer_qbo_id TEXT, customer_name TEXT,
    issue_date TEXT, due_date TEXT,
    total_amount_cents INT DEFAULT 0, balance_cents INT DEFAULT 0,
    currency TEXT DEFAULT 'USD', qbo_status TEXT, qbo_last_updated TEXT,
    coreflux_invoice_id INT NULL, raw_payload TEXT,
    first_seen_at TEXT, last_seen_at TEXT,
    UNIQUE (tenant_id, qbo_invoice_id))");
$pdo->exec("CREATE TABLE billing_invoices (
    id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INT, invoice_number TEXT,
    status TEXT, currency TEXT DEFAULT 'USD')");
$pdo->exec("CREATE TABLE external_entity_mappings (
    id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INT, source_system TEXT,
    internal_entity_type TEXT, internal_entity_id INT,
    external_id TEXT, external_payload TEXT, direction TEXT, created_at TEXT, updated_at TEXT)");
$pdo->exec("CREATE TABLE qbo_sync_drift (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INT, entity_type TEXT, coreflux_id INT NULL, qbo_id TEXT,
    drift_kind TEXT, severity TEXT DEFAULT 'warn',
    coreflux_snapshot TEXT, qbo_snapshot TEXT, summary TEXT,
    status TEXT DEFAULT 'open',
    resolved_by_user_id INT, resolved_at TEXT, resolution_note TEXT,
    detected_at TEXT, last_seen_at TEXT,
    UNIQUE (tenant_id, entity_type, qbo_id, drift_kind))");

// Pre-create a CoreFlux invoice + its QBO mapping.
$pdo->prepare("INSERT INTO billing_invoices (tenant_id, invoice_number, status) VALUES (101, 'INV-001', 'sent')")
    ->execute();
$pdo->prepare("INSERT INTO external_entity_mappings (tenant_id, source_system, internal_entity_type, internal_entity_id, external_id, direction) VALUES (101, 'quickbooks_online', 'invoice', 1, 'QBO-INV-9', 'push')")
    ->execute();

// Feed it a QBO Invoice payload with balance=0 (paid-out-of-band).
$qboInvoice = [
    'Id' => 'QBO-INV-9', 'DocNumber' => 'INV-001',
    'TxnDate' => '2026-02-01', 'DueDate' => '2026-02-15',
    'TotalAmt' => 1000.00, 'Balance' => 0.00,
    'CustomerRef' => ['value' => '5', 'name' => 'Acme Corp'],
    'CurrencyRef' => ['value' => 'USD'],
    'MetaData' => ['LastUpdatedTime' => '2026-02-10T12:00:00-08:00'],
];
$res = _qboShadowInvoice(101, $qboInvoice);
check('shadow upsert action=created on first ingest',  $res['action'] === 'created');
check('paid_out_of_band drift detected on balance=0',  $res['drift_rows_written'] === 1);

$shadow = $pdo->query("SELECT * FROM qbo_inbound_invoices WHERE qbo_invoice_id='QBO-INV-9'")->fetch(\PDO::FETCH_ASSOC);
check('shadow row carries balance_cents=0',            (int) $shadow['balance_cents'] === 0);
check('shadow row carries total_amount_cents=100000',  (int) $shadow['total_amount_cents'] === 100000);
check('shadow row links to coreflux_invoice_id=1',     (int) $shadow['coreflux_invoice_id'] === 1);
check('shadow row stamps customer_name=Acme Corp',     $shadow['customer_name'] === 'Acme Corp');
check('shadow row stamps qbo_status=Paid',             $shadow['qbo_status'] === 'Paid');

$drift = $pdo->query("SELECT * FROM qbo_sync_drift WHERE qbo_id='QBO-INV-9'")->fetch(\PDO::FETCH_ASSOC);
check('drift row written with kind=paid_out_of_band',  $drift['drift_kind'] === 'paid_out_of_band');
check('drift severity=warn',                            $drift['severity'] === 'warn');
check('drift links coreflux_id=1',                      (int) $drift['coreflux_id'] === 1);
check('drift status=open by default',                   $drift['status'] === 'open');

// Re-ingest the same payload — should UPDATE, NOT duplicate drift.
$res = _qboShadowInvoice(101, $qboInvoice);
check('re-ingest action=updated',                       $res['action'] === 'updated');
$driftCount = (int) $pdo->query("SELECT COUNT(*) FROM qbo_sync_drift WHERE qbo_id='QBO-INV-9'")->fetchColumn();
check('unique key prevents duplicate drift rows',       $driftCount === 1);

// Customer paid in QBO → CoreFlux marks invoice paid → drift should
// clear on re-detection (still emitted; operator decides via the
// admin endpoint to mark it 'reconciled').
$pdo->prepare("UPDATE billing_invoices SET status='paid' WHERE id=1")->execute();
$res = _qboShadowInvoice(101, $qboInvoice);
check('post-reconciliation: no NEW drift emitted',      $res['drift_rows_written'] === 0);

$total = $passes + count($failures);
echo "\n=========================================\n";
echo "qbo_two_way_sync smoke: {$passes} ✓ / " . count($failures) . " ✗\n";
echo "=========================================\n";
foreach ($failures as $msg) echo "  FAIL: {$msg}\n";
exit($failures ? 1 : 0);
