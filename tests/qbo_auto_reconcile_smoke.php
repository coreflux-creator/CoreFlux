<?php
/**
 * Smoke — QBO auto-reconciliation (Step 4 of QBO Two-Way Sync).
 *
 * Locks:
 *   - Migration 115 declares the auto_reconcile_paid_out_of_band column.
 *   - core/qbo/client.php exposes qboAutoReconcileEnabled / qboAutoReconcileSet.
 *   - core/qbo/auto_reconcile.php exports qboAutoReconcileTenant().
 *   - Cron wires the resolver in correctly and emits the summary line.
 *   - Admin endpoint exists with GET + POST handlers + RBAC guard.
 *   - Live end-to-end exercise: drift row → reconciled invoice + bill.
 *   - Idempotency: re-running the resolver does NOT create duplicate
 *     billing_payments / ap_payments rows.
 *   - Default-off behaviour: with the flag off, nothing reconciles.
 *
 * Run: php -d zend.assertions=1 /app/tests/qbo_auto_reconcile_smoke.php
 */
declare(strict_types=1);

$passes = 0; $failures = [];
function check(string $label, bool $cond) {
    global $passes, $failures;
    if ($cond) { $passes++; echo "  ✓ {$label}\n"; }
    else       { $failures[] = $label; echo "  ✗ {$label}\n"; }
}

echo "\nQBO auto-reconciliation smoke (Step 4)\n";
echo "=========================================\n\n";

// ─────── 1. Migration ───────
echo "── migration 115 ──\n";
$migPath = '/app/core/migrations/115_qbo_auto_reconcile.sql';
check('migration file exists', file_exists($migPath));
$mig = (string) file_get_contents($migPath);
check('migration adds auto_reconcile_paid_out_of_band column',
    str_contains($mig, 'auto_reconcile_paid_out_of_band'));
check('migration uses idempotent information_schema guard',
    str_contains($mig, 'information_schema.COLUMNS') && str_contains($mig, 'PREPARE s FROM @sql'));
check('migration defaults to disabled (0)',
    preg_match('/auto_reconcile_paid_out_of_band\s+TINYINT\(1\)\s+NOT NULL\s+DEFAULT\s+0/i', $mig) === 1);

// ─────── 2. Module shape ───────
echo "\n── core/qbo/auto_reconcile.php ──\n";
$srcPath = '/app/core/qbo/auto_reconcile.php';
check('module exists', file_exists($srcPath));
$src = (string) file_get_contents($srcPath);
check('declares qboAutoReconcileTenant',     str_contains($src, 'function qboAutoReconcileTenant'));
check('gates on qboAutoReconcileEnabled',    str_contains($src, 'qboAutoReconcileEnabled('));
check('queries qbo_sync_drift WHERE status=open + paid_out_of_band',
    preg_match("/qbo_sync_drift.*?status\\s*=\\s*'open'.*?drift_kind\\s*=\\s*'paid_out_of_band'/s", $src) === 1);
check('uses qbo_inbound_payments shadow table',     str_contains($src, 'qbo_inbound_payments'));
check('uses qbo_inbound_billpayments shadow table', str_contains($src, 'qbo_inbound_billpayments'));
check('calls billingAllocatePayment',               str_contains($src, 'billingAllocatePayment('));
check('calls apAllocatePayment',                    str_contains($src, 'apAllocatePayment('));
check('inserts payments with source_system=qbo',    str_contains($src, "'qbo'"));
check('uses billingAudit for audit trail',          str_contains($src, "billingAudit('billing.auto_reconcile."));
check('uses apAudit for AP audit trail',            str_contains($src, "apAudit('ap.auto_reconcile."));
check('closes drift via _qboCloseDrift',            str_contains($src, '_qboCloseDrift('));

// ─────── 3. client.php helpers ───────
echo "\n── core/qbo/client.php ──\n";
$clientSrc = (string) file_get_contents('/app/core/qbo/client.php');
check('exports qboAutoReconcileEnabled',   str_contains($clientSrc, 'function qboAutoReconcileEnabled'));
check('exports qboAutoReconcileSet',       str_contains($clientSrc, 'function qboAutoReconcileSet'));
check('qboConnection selects new column',  str_contains($clientSrc, 'auto_reconcile_paid_out_of_band'));
check('client persists flag on update',
    preg_match('/UPDATE qbo_connections\s+SET\s+auto_reconcile_paid_out_of_band/', $clientSrc) === 1);
check('client emits auto_reconcile_toggle audit',
    str_contains($clientSrc, "qboAudit(\$tenantId, 'auto_reconcile_toggle'"));

// ─────── 4. Cron wiring ───────
echo "\n── cron/qbo_two_way_sync.php ──\n";
$cron = (string) file_get_contents('/app/cron/qbo_two_way_sync.php');
check('cron requires auto_reconcile module',  str_contains($cron, "qbo/auto_reconcile.php"));
check('cron invokes qboAutoReconcileTenant',  str_contains($cron, 'qboAutoReconcileTenant('));
check('cron gates auto-recon behind tenant success',
    preg_match('/if \(\$tenantOk\) \{\s*try \{\s*\$arc = qboAutoReconcileTenant/', $cron) === 1);
check('cron emits auto_reconciled in summary line',
    str_contains($cron, 'auto_reconciled=%d') && str_contains($cron, 'auto_payments=%d'));

// ─────── 5. Admin endpoint ───────
echo "\n── /api/admin/qbo/auto_reconcile.php ──\n";
$epPath = '/app/api/admin/qbo/auto_reconcile.php';
check('endpoint exists', file_exists($epPath));
$ep = (string) file_get_contents($epPath);
check('endpoint calls api_require_auth',    str_contains($ep, 'api_require_auth()'));
check('endpoint RBAC-gates to admin/wildcard',
    str_contains($ep, "rbac_legacy_require_any") && str_contains($ep, "'master_admin'"));
check('GET returns enabled flag',           preg_match("/api_ok\\(\\s*\\[\\s*'enabled'/", $ep) === 1);
check('POST persists via qboAutoReconcileSet', str_contains($ep, 'qboAutoReconcileSet('));
check('POST supports run_now passthrough',  str_contains($ep, "'run_now'"));

// ─────── 6. Live end-to-end (SQLite mirror) ───────
echo "\n── live behaviour ──\n";

$GLOBALS['pdo'] = new \PDO('sqlite::memory:');
$GLOBALS['pdo']->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$pdo = $GLOBALS['pdo'];

// Stand-up minimal getDB() before loading the modules.
if (!function_exists('getDB')) {
    function getDB(): \PDO { return $GLOBALS['pdo']; }
}

// Mirror the migration set we care about.
$pdo->exec("CREATE TABLE qbo_connections (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INT UNIQUE, realm_id TEXT, company_name TEXT, environment TEXT,
    access_token_ct BLOB, refresh_token_ct BLOB,
    access_token_exp TEXT, refresh_token_exp TEXT,
    scope TEXT, status TEXT DEFAULT 'active', sync_config TEXT,
    auto_reconcile_paid_out_of_band INT DEFAULT 0,
    last_probe_at TEXT, last_probe_error TEXT,
    connected_by_user_id INT, created_at TEXT, updated_at TEXT)");
$pdo->exec("CREATE TABLE billing_invoices (
    id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INT, invoice_number TEXT,
    client_name TEXT, currency TEXT DEFAULT 'USD', status TEXT,
    total REAL DEFAULT 0, amount_paid REAL DEFAULT 0, amount_due REAL DEFAULT 0,
    due_date TEXT)");
$pdo->exec("CREATE TABLE ap_bills (
    id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INT, bill_number TEXT,
    internal_ref TEXT, vendor_name TEXT, currency TEXT DEFAULT 'USD', status TEXT,
    total REAL DEFAULT 0, amount_paid REAL DEFAULT 0, amount_due REAL DEFAULT 0,
    due_date TEXT)");
$pdo->exec("CREATE TABLE billing_payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INT, client_name TEXT,
    received_at TEXT, method TEXT, reference TEXT, external_id TEXT,
    source_system TEXT DEFAULT 'manual',
    amount REAL DEFAULT 0, currency TEXT DEFAULT 'USD',
    unallocated_amount REAL DEFAULT 0, notes TEXT,
    created_by_user_id INT, created_at TEXT,
    UNIQUE (tenant_id, source_system, external_id))");
$pdo->exec("CREATE TABLE billing_payment_allocations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    payment_id INT, invoice_id INT, amount_applied REAL,
    applied_by_user_id INT, applied_at TEXT)");
$pdo->exec("CREATE TABLE ap_payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INT, vendor_name TEXT,
    pay_date TEXT, method TEXT, reference TEXT, external_id TEXT,
    source_system TEXT DEFAULT 'manual',
    amount REAL DEFAULT 0, currency TEXT DEFAULT 'USD',
    unallocated_amount REAL DEFAULT 0, bank_account_id INT,
    status TEXT DEFAULT 'draft', notes TEXT,
    created_by_user_id INT, created_at TEXT, updated_at TEXT,
    UNIQUE (tenant_id, source_system, external_id))");
$pdo->exec("CREATE TABLE ap_payment_allocations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    payment_id INT, bill_id INT, amount_applied REAL,
    applied_by_user_id INT, applied_at TEXT)");
$pdo->exec("CREATE TABLE qbo_inbound_payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INT, qbo_payment_id TEXT,
    customer_qbo_id TEXT, customer_name TEXT, payment_date TEXT,
    total_amount_cents INT DEFAULT 0, unapplied_cents INT DEFAULT 0,
    payment_method TEXT, deposit_qbo_id TEXT, linked_invoice_ids TEXT,
    qbo_last_updated TEXT, raw_payload TEXT,
    first_seen_at TEXT, last_seen_at TEXT,
    UNIQUE (tenant_id, qbo_payment_id))");
$pdo->exec("CREATE TABLE qbo_inbound_billpayments (
    id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INT, qbo_billpayment_id TEXT,
    vendor_qbo_id TEXT, payment_date TEXT, total_amount_cents INT DEFAULT 0,
    pay_type TEXT, bank_account_qbo_id TEXT, linked_bill_ids TEXT,
    qbo_last_updated TEXT, raw_payload TEXT,
    first_seen_at TEXT, last_seen_at TEXT,
    UNIQUE (tenant_id, qbo_billpayment_id))");
$pdo->exec("CREATE TABLE qbo_sync_drift (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INT, entity_type TEXT, coreflux_id INT NULL, qbo_id TEXT,
    drift_kind TEXT, severity TEXT DEFAULT 'warn',
    coreflux_snapshot TEXT, qbo_snapshot TEXT, summary TEXT,
    status TEXT DEFAULT 'open',
    resolved_by_user_id INT, resolved_at TEXT, resolution_note TEXT,
    detected_at TEXT, last_seen_at TEXT,
    UNIQUE (tenant_id, entity_type, qbo_id, drift_kind))");
$pdo->exec("CREATE TABLE audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INT, actor_user_id INT,
    event TEXT, target_id INT, meta_json TEXT, ip_address TEXT, created_at TEXT)");
$pdo->exec("CREATE TABLE qbo_audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INT, action TEXT,
    detail_json TEXT, created_at TEXT)");

// Pre-load a tenant connection (auto_reconcile off by default).
$pdo->prepare("INSERT INTO qbo_connections (tenant_id, realm_id, status, environment, access_token_ct, refresh_token_ct) VALUES (101, 'R-1', 'active', 'sandbox', x'00', x'00')")->execute();

// Pre-load a CoreFlux invoice ($1000, status=sent, $1000 due).
$pdo->prepare("INSERT INTO billing_invoices (id, tenant_id, invoice_number, client_name, status, total, amount_paid, amount_due, due_date) VALUES (1, 101, 'INV-001', 'Acme Corp', 'sent', 1000.00, 0, 1000.00, '2026-03-01')")->execute();
// Pre-load a CoreFlux bill ($500, status=approved, $500 due).
$pdo->prepare("INSERT INTO ap_bills (id, tenant_id, bill_number, internal_ref, vendor_name, status, total, amount_paid, amount_due, due_date) VALUES (1, 101, 'B-77', 'AP-77', 'Globex Vendor', 'approved', 500.00, 0, 500.00, '2026-03-01')")->execute();
// Pre-load matching QBO inbound payment for the invoice.
$pdo->prepare("INSERT INTO qbo_inbound_payments (tenant_id, qbo_payment_id, customer_name, payment_date, total_amount_cents, linked_invoice_ids, first_seen_at, last_seen_at) VALUES (101, 'QBO-PAY-9', 'Acme Corp', '2026-02-15', 100000, ?, '2026-02-15 00:00:00', '2026-02-15 00:00:00')")
    ->execute([json_encode(['QBO-INV-9'])]);
// Pre-load matching QBO inbound bill-payment for the bill.
$pdo->prepare("INSERT INTO qbo_inbound_billpayments (tenant_id, qbo_billpayment_id, payment_date, total_amount_cents, pay_type, linked_bill_ids, first_seen_at, last_seen_at) VALUES (101, 'QBO-BP-22', '2026-02-16', 50000, 'Check', ?, '2026-02-16 00:00:00', '2026-02-16 00:00:00')")
    ->execute([json_encode(['QBO-BILL-22'])]);
// Pre-load open paid_out_of_band drift rows for both AR + AP.
$pdo->prepare("INSERT INTO qbo_sync_drift (tenant_id, entity_type, coreflux_id, qbo_id, drift_kind, severity, status, detected_at, last_seen_at) VALUES (101, 'invoice', 1, 'QBO-INV-9', 'paid_out_of_band', 'warn', 'open', '2026-02-15 00:00:00', '2026-02-15 00:00:00')")->execute();
$pdo->prepare("INSERT INTO qbo_sync_drift (tenant_id, entity_type, coreflux_id, qbo_id, drift_kind, severity, status, detected_at, last_seen_at) VALUES (101, 'bill', 1, 'QBO-BILL-22', 'paid_out_of_band', 'warn', 'open', '2026-02-16 00:00:00', '2026-02-16 00:00:00')")->execute();

// Stub the upstream functions that auto_reconcile.php calls into.
if (!function_exists('qboAudit')) {
    function qboAudit(int $tenantId, string $action, array $opts = []): void {
        $GLOBALS['pdo']->prepare(
            "INSERT INTO qbo_audit_log (tenant_id, action, detail_json, created_at) VALUES (:t, :a, :d, :c)"
        )->execute(['t'=>$tenantId,'a'=>$action,'d'=>json_encode($opts),'c'=>date('Y-m-d H:i:s')]);
    }
}
if (!function_exists('qboAutoReconcileEnabled')) {
    function qboAutoReconcileEnabled(int $tenantId): bool {
        $s = $GLOBALS['pdo']->prepare('SELECT auto_reconcile_paid_out_of_band FROM qbo_connections WHERE tenant_id = :t LIMIT 1');
        $s->execute(['t' => $tenantId]);
        $r = $s->fetch(\PDO::FETCH_ASSOC);
        return $r && (int) $r['auto_reconcile_paid_out_of_band'] === 1;
    }
}
// Stub billing + ap helpers used by the resolver.
if (!function_exists('billingAudit')) {
    function billingAudit(string $event, array $meta = [], ?int $targetId = null): void {
        $GLOBALS['pdo']->prepare(
            "INSERT INTO audit_log (tenant_id, actor_user_id, event, target_id, meta_json, created_at) VALUES (NULL, NULL, :e, :t, :m, :c)"
        )->execute(['e'=>$event,'t'=>$targetId,'m'=>json_encode($meta),'c'=>date('Y-m-d H:i:s')]);
    }
}
if (!function_exists('apAudit')) {
    function apAudit(string $event, array $meta = [], ?int $targetId = null): void {
        $GLOBALS['pdo']->prepare(
            "INSERT INTO audit_log (tenant_id, actor_user_id, event, target_id, meta_json, created_at) VALUES (NULL, NULL, :e, :t, :m, :c)"
        )->execute(['e'=>$event,'t'=>$targetId,'m'=>json_encode($meta),'c'=>date('Y-m-d H:i:s')]);
    }
}
if (!function_exists('billingAllocatePayment')) {
    function billingAllocatePayment(int $paymentId, array $request, ?int $actorUserId = null): array {
        $pdo = $GLOBALS['pdo'];
        $pay = $pdo->query("SELECT * FROM billing_payments WHERE id={$paymentId}")->fetch(\PDO::FETCH_ASSOC);
        if (!$pay) throw new \RuntimeException("payment {$paymentId} not found");
        $applied = [];
        foreach ($request['allocations'] ?? [] as $a) {
            $iid = (int) $a['invoice_id']; $amt = (float) $a['amount'];
            $inv = $pdo->query("SELECT * FROM billing_invoices WHERE id={$iid} AND tenant_id={$pay['tenant_id']}")->fetch(\PDO::FETCH_ASSOC);
            if (!$inv) throw new \RuntimeException("invoice {$iid} not found");
            $apply = min($amt, (float) $inv['amount_due']);
            if ($apply <= 0) continue;
            $pdo->prepare("INSERT INTO billing_payment_allocations (payment_id, invoice_id, amount_applied, applied_by_user_id) VALUES (?, ?, ?, ?)")
                ->execute([$paymentId, $iid, $apply, $actorUserId]);
            $newPaid = round((float) $inv['amount_paid'] + $apply, 2);
            $newDue  = round((float) $inv['total'] - $newPaid, 2);
            $newStatus = $newDue < 0.005 ? 'paid' : 'partially_paid';
            $pdo->prepare("UPDATE billing_invoices SET amount_paid=?, amount_due=?, status=? WHERE id=?")
                ->execute([$newPaid, max(0,$newDue), $newStatus, $iid]);
            $newUnalloc = round((float) $pay['unallocated_amount'] - $apply, 2);
            $pdo->prepare("UPDATE billing_payments SET unallocated_amount=? WHERE id=?")
                ->execute([$newUnalloc, $paymentId]);
            $applied[] = ['invoice_id'=>$iid,'amount_applied'=>$apply,'new_status'=>$newStatus];
        }
        return ['applied'=>$applied];
    }
}
if (!function_exists('apAllocatePayment')) {
    function apAllocatePayment(int $paymentId, array $request, ?int $actorUserId = null): array {
        $pdo = $GLOBALS['pdo'];
        $pay = $pdo->query("SELECT * FROM ap_payments WHERE id={$paymentId}")->fetch(\PDO::FETCH_ASSOC);
        if (!$pay) throw new \RuntimeException("payment {$paymentId} not found");
        $applied = [];
        foreach ($request['allocations'] ?? [] as $a) {
            $bid = (int) $a['bill_id']; $amt = (float) $a['amount'];
            $bill = $pdo->query("SELECT * FROM ap_bills WHERE id={$bid} AND tenant_id={$pay['tenant_id']}")->fetch(\PDO::FETCH_ASSOC);
            if (!$bill) throw new \RuntimeException("bill {$bid} not found");
            $apply = min($amt, (float) $bill['amount_due']);
            if ($apply <= 0) continue;
            $pdo->prepare("INSERT INTO ap_payment_allocations (payment_id, bill_id, amount_applied, applied_by_user_id) VALUES (?, ?, ?, ?)")
                ->execute([$paymentId, $bid, $apply, $actorUserId]);
            $newPaid = round((float) $bill['amount_paid'] + $apply, 2);
            $newDue  = round((float) $bill['total'] - $newPaid, 2);
            $newStatus = $newDue < 0.005 ? 'paid' : 'partially_paid';
            $pdo->prepare("UPDATE ap_bills SET amount_paid=?, amount_due=?, status=? WHERE id=?")
                ->execute([$newPaid, max(0,$newDue), $newStatus, $bid]);
            $newUnalloc = round((float) $pay['unallocated_amount'] - $apply, 2);
            $pdo->prepare("UPDATE ap_payments SET unallocated_amount=? WHERE id=?")
                ->execute([$newUnalloc, $paymentId]);
            $applied[] = ['bill_id'=>$bid,'amount_applied'=>$apply,'new_status'=>$newStatus];
        }
        return ['applied'=>$applied];
    }
}

// Now pull in the resolver. require_once would also try to pull billing.php
// + ap.php — we've already stubbed their public surface above so suppress
// those imports by guarding their require_once calls when running standalone.
// Cleanest path: include the file with a small hand-curated extraction.
$resolverSrc = file_get_contents('/app/core/qbo/auto_reconcile.php');
// Strip the require_once block so we don't reach into the real billing.php
// (which in turn boots api_bootstrap.php and the whole tenant scope stack).
$resolverSrc = preg_replace(
    "/require_once __DIR__ \\. '\\/client\\.php';\\s*"
    . "require_once __DIR__ \\. '\\/\\.\\.\\/db\\.php';\\s*"
    . "require_once __DIR__ \\. '\\/\\.\\.\\/\\.\\.\\/modules\\/billing\\/lib\\/billing\\.php';\\s*"
    . "require_once __DIR__ \\. '\\/\\.\\.\\/\\.\\.\\/modules\\/ap\\/lib\\/ap\\.php';/",
    '',
    $resolverSrc
);
// Strip leading <?php tag for eval.
$resolverSrc = preg_replace('/^\s*<\?php/', '', $resolverSrc);
eval($resolverSrc);

// ─────── (a) Default-off behaviour ───────
echo "── default-off: flag off ──\n";
$res = qboAutoReconcileTenant(101, null);
check('reconcile no-op when flag is off',         $res['enabled'] === false);
check('no payments created when flag is off',     $res['payments_created'] === 0);
check('drift rows untouched when flag is off',
    (int) $pdo->query("SELECT COUNT(*) FROM qbo_sync_drift WHERE status='open'")->fetchColumn() === 2);

// ─────── (b) Flip flag on, run resolver ───────
echo "\n── flag on: full reconciliation ──\n";
$pdo->prepare("UPDATE qbo_connections SET auto_reconcile_paid_out_of_band=1 WHERE tenant_id=101")->execute();

$res = qboAutoReconcileTenant(101, 42);
check('resolver reports enabled=true',           $res['enabled'] === true);
check('one invoice reconciled',                   $res['invoices_reconciled'] === 1);
check('one bill reconciled',                      $res['bills_reconciled'] === 1);
check('two payments created (AR + AP)',           $res['payments_created'] === 2);
check('two drift rows closed',                    $res['drift_rows_closed'] === 2);
check('no resolver errors',                       count($res['errors']) === 0);

// Verify CoreFlux state.
$inv = $pdo->query("SELECT * FROM billing_invoices WHERE id=1")->fetch(\PDO::FETCH_ASSOC);
check('invoice now status=paid',                  $inv['status'] === 'paid');
check('invoice amount_paid = $1000',              (float) $inv['amount_paid'] === 1000.0);
check('invoice amount_due = $0',                  (float) $inv['amount_due']  === 0.0);

$bill = $pdo->query("SELECT * FROM ap_bills WHERE id=1")->fetch(\PDO::FETCH_ASSOC);
check('bill now status=paid',                     $bill['status'] === 'paid');
check('bill amount_paid = $500',                  (float) $bill['amount_paid'] === 500.0);

// Verify payment rows.
$arPay = $pdo->query("SELECT * FROM billing_payments WHERE source_system='qbo' AND external_id='QBO-PAY-9'")->fetch(\PDO::FETCH_ASSOC);
check('AR payment row exists with source=qbo',    $arPay !== false);
check('AR payment carries external_id=QBO-PAY-9', $arPay['external_id'] === 'QBO-PAY-9');
check('AR payment amount = $1000',                (float) $arPay['amount'] === 1000.0);
check('AR payment fully allocated (unalloc=0)',   (float) $arPay['unallocated_amount'] === 0.0);
check('AR payment method=other',                  $arPay['method'] === 'other');

$apPay = $pdo->query("SELECT * FROM ap_payments WHERE source_system='qbo' AND external_id='QBO-BP-22'")->fetch(\PDO::FETCH_ASSOC);
check('AP payment row exists with source=qbo',    $apPay !== false);
check('AP payment carries external_id=QBO-BP-22', $apPay['external_id'] === 'QBO-BP-22');
check('AP payment amount = $500',                 (float) $apPay['amount'] === 500.0);
check('AP payment fully allocated',               (float) $apPay['unallocated_amount'] === 0.0);

// Verify drift rows transitioned.
$openDrift = (int) $pdo->query("SELECT COUNT(*) FROM qbo_sync_drift WHERE status='open'")->fetchColumn();
check('no open drift rows remain',                $openDrift === 0);
$reconciled = $pdo->query("SELECT * FROM qbo_sync_drift WHERE status='reconciled'")->fetchAll(\PDO::FETCH_ASSOC);
check('both drift rows now status=reconciled',    count($reconciled) === 2);
foreach ($reconciled as $d) {
    check("drift {$d['entity_type']} has resolution_note",
        is_string($d['resolution_note']) && str_contains($d['resolution_note'], 'auto-reconciled'));
}

// Audit trail.
$arAudit = $pdo->query("SELECT COUNT(*) FROM audit_log WHERE event='billing.auto_reconcile.allocated'")->fetchColumn();
check('billing audit event recorded',             (int) $arAudit >= 1);
$apAudit = $pdo->query("SELECT COUNT(*) FROM audit_log WHERE event='ap.auto_reconcile.allocated'")->fetchColumn();
check('ap audit event recorded',                  (int) $apAudit >= 1);
$qboAudit = $pdo->query("SELECT COUNT(*) FROM qbo_audit_log WHERE action='auto_reconcile_run'")->fetchColumn();
check('qbo audit run-summary recorded',           (int) $qboAudit >= 1);

// ─────── (c) Idempotency ───────
echo "\n── idempotency: re-run resolver ──\n";
$res2 = qboAutoReconcileTenant(101, 42);
check('second run finds no new drift',            $res2['drift_rows_closed'] === 0);
check('second run creates no new payments',       $res2['payments_created']   === 0);
$arPayCount = (int) $pdo->query("SELECT COUNT(*) FROM billing_payments WHERE source_system='qbo'")->fetchColumn();
check('still exactly one AR QBO payment row',     $arPayCount === 1);
$apPayCount = (int) $pdo->query("SELECT COUNT(*) FROM ap_payments WHERE source_system='qbo'")->fetchColumn();
check('still exactly one AP QBO payment row',     $apPayCount === 1);

// ─────── (d) Stale drift (CoreFlux already paid) auto-closes ───────
echo "\n── stale drift cleanup ──\n";
// Mark a third drift row that points at the already-paid invoice.
$pdo->prepare("INSERT INTO qbo_sync_drift (tenant_id, entity_type, coreflux_id, qbo_id, drift_kind, severity, status, detected_at, last_seen_at) VALUES (101, 'invoice', 1, 'QBO-INV-99', 'paid_out_of_band', 'warn', 'open', '2026-02-20 00:00:00', '2026-02-20 00:00:00')")->execute();
$res3 = qboAutoReconcileTenant(101, 42);
$d99 = $pdo->query("SELECT status, resolution_note FROM qbo_sync_drift WHERE qbo_id='QBO-INV-99'")->fetch(\PDO::FETCH_ASSOC);
check('stale drift auto-closed',                  $d99['status'] === 'reconciled');
check('stale drift note flags no-op',             str_contains((string) $d99['resolution_note'], 'no-op'));

// ─────── 7. Smoke test summary ───────
$total = $passes + count($failures);
echo "\n=========================================\n";
echo "qbo_auto_reconcile smoke: {$passes} ✓ / " . count($failures) . " ✗\n";
echo "=========================================\n";
foreach ($failures as $msg) echo "  FAIL: {$msg}\n";
exit($failures ? 1 : 0);
