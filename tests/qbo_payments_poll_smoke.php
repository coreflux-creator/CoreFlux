<?php
/**
 * Smoke — QBO Payments polling cron (Step 6 Phase 4).
 *
 * Locks:
 *   - Cron exists at /app/cron/qbo_payments_poll.php.
 *   - Selects only pending statuses (ISSUED/PENDING/CAPTURED/AUTHORIZED).
 *   - Calls qboGetCharge + qboRecordChargeShadow for each.
 *   - Stamps an error_message on failures so the operator sees them.
 *   - Emits a structured summary line.
 *
 * Live exercise:
 *   - Pre-load three charges (one ISSUED, one CAPTURED-not-yet-settled,
 *     one already SETTLED).
 *   - Stub the QBO transport to return advanced statuses.
 *   - Run the polling loop logic and verify all three rows converge.
 *
 * Run: php -d zend.assertions=1 /app/tests/qbo_payments_poll_smoke.php
 */
declare(strict_types=1);

$passes = 0; $failures = [];
function check(string $label, bool $cond) {
    global $passes, $failures;
    if ($cond) { $passes++; echo "  ✓ {$label}\n"; }
    else       { $failures[] = $label; echo "  ✗ {$label}\n"; }
}

echo "\nQBO Payments polling cron smoke (Step 6 Phase 4)\n";
echo "=================================================\n\n";

// ─────── 1. File shape ───────
$path = '/app/cron/qbo_payments_poll.php';
check('cron exists', file_exists($path));
$src = (string) file_get_contents($path);
check('requires qbo/payments_client.php',          str_contains($src, "qbo/payments_client.php"));
check('selects only pending statuses',
    str_contains($src, "status IN ('ISSUED','PENDING','CAPTURED','AUTHORIZED')"));
check('filters on settled_at IS NULL',             str_contains($src, 'settled_at IS NULL'));
check('joins to qbo_connections (active only)',
    str_contains($src, "qbo_connections cn ON cn.tenant_id = c.tenant_id AND cn.status = 'active'"));
check('calls qboGetCharge per row',                str_contains($src, 'qboGetCharge('));
check('re-upserts via qboRecordChargeShadow',      str_contains($src, 'qboRecordChargeShadow('));
check('counts advanced statuses',                  str_contains($src, "\$totals['advanced']++"));
check('stamps error_message on poll failure',       str_contains($src, "'poll_error: '"));
check('emits structured summary line',
    str_contains($src, 'qbo_payments_poll done:') && str_contains($src, 'polled=%d'));

// ─────── 2. Live exercise ───────
echo "\n── live exercise ──\n";

$GLOBALS['pdo'] = new \PDO('sqlite::memory:');
$GLOBALS['pdo']->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$pdo = $GLOBALS['pdo'];

if (!function_exists('getDB')) { function getDB(): \PDO { return $GLOBALS['pdo']; } }

$pdo->exec("CREATE TABLE qbo_connections (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INT UNIQUE, realm_id TEXT, status TEXT DEFAULT 'active',
    environment TEXT, access_token_ct BLOB, refresh_token_ct BLOB,
    scope TEXT, sync_config TEXT,
    auto_reconcile_paid_out_of_band INT DEFAULT 0,
    last_probe_at TEXT, last_probe_error TEXT,
    company_name TEXT, access_token_exp TEXT, refresh_token_exp TEXT,
    connected_by_user_id INT, created_at TEXT, updated_at TEXT)");
$pdo->exec("CREATE TABLE qbo_payment_charges (
    id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INT, qbo_charge_id TEXT,
    charge_type TEXT DEFAULT 'card',
    amount_cents INT DEFAULT 0, currency TEXT DEFAULT 'USD', status TEXT DEFAULT 'ISSUED',
    card_brand TEXT, card_last4 TEXT, card_exp_month INT, card_exp_year INT,
    bank_name TEXT, account_last4 TEXT, routing_last4 TEXT,
    coreflux_invoice_id INT, coreflux_payment_id INT, context_token TEXT,
    error_code TEXT, error_message TEXT,
    raw_payload TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    captured_at TEXT, settled_at TEXT, updated_at TEXT,
    UNIQUE (tenant_id, qbo_charge_id))");
$pdo->exec("CREATE TABLE qbo_audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INT, action TEXT,
    detail_json TEXT, created_at TEXT)");

$pdo->prepare("INSERT INTO qbo_connections (tenant_id, realm_id, status, scope, access_token_ct, refresh_token_ct, environment) VALUES (101, 'R-1', 'active', 'com.intuit.quickbooks.accounting com.intuit.quickbooks.payment', x'00', x'00', 'sandbox')")->execute();

// Three charges: one will advance ISSUED→CAPTURED, one CAPTURED→SETTLED,
// one already SETTLED (won't be selected). Use recent created_at so the
// 30-day window catches them.
$d2 = date('Y-m-d H:i:s', strtotime('-2 days'));
$d3 = date('Y-m-d H:i:s', strtotime('-3 days'));
$d8 = date('Y-m-d H:i:s', strtotime('-8 days'));
$pdo->prepare("INSERT INTO qbo_payment_charges (tenant_id, qbo_charge_id, charge_type, amount_cents, currency, status, created_at) VALUES (101, 'CHG-A', 'card', 1000, 'USD', 'ISSUED', :d)")->execute(['d'=>$d2]);
$pdo->prepare("INSERT INTO qbo_payment_charges (tenant_id, qbo_charge_id, charge_type, amount_cents, currency, status, created_at, captured_at) VALUES (101, 'CHG-B', 'card', 2500, 'USD', 'CAPTURED', :d, :d)")->execute(['d'=>$d3]);
$pdo->prepare("INSERT INTO qbo_payment_charges (tenant_id, qbo_charge_id, charge_type, amount_cents, currency, status, created_at, captured_at, settled_at) VALUES (101, 'CHG-C', 'card', 700, 'USD', 'SETTLED', :d, :d, :d)")->execute(['d'=>$d8]);

// Stub the payment client functions the cron calls.
if (!function_exists('qboAudit')) { function qboAudit(...$args): void {} }
if (!function_exists('qboAccessToken')) { function qboAccessToken(int $tid): string { return 'tok'; } }
if (!function_exists('qboRefreshAccessToken')) { function qboRefreshAccessToken(int $tid): string { return 'tok'; } }
if (!function_exists('qboEnvironment')) { function qboEnvironment(): string { return 'sandbox'; } }
if (!function_exists('qboConnection')) {
    function qboConnection(int $tid): ?array {
        $s = $GLOBALS['pdo']->prepare("SELECT * FROM qbo_connections WHERE tenant_id=:t LIMIT 1");
        $s->execute(['t'=>$tid]);
        return $s->fetch(\PDO::FETCH_ASSOC) ?: null;
    }
}
if (!class_exists('QboApiException')) {
    class QboApiException extends \RuntimeException {
        public ?int $httpStatus = null;
        public ?string $errorCode = null;
        public ?array $raw = null;
    }
}

// qboGetCharge stub — returns advanced state for A + B.
function qboGetCharge(int $tid, string $chargeId): array {
    if ($chargeId === 'CHG-A') return ['id' => 'CHG-A', 'amount' => '10.00', 'currency' => 'USD', 'status' => 'CAPTURED'];
    if ($chargeId === 'CHG-B') return ['id' => 'CHG-B', 'amount' => '25.00', 'currency' => 'USD', 'status' => 'SETTLED'];
    throw new \RuntimeException('charge not found');
}

// Replay qboRecordChargeShadow logic (mirrors payments_client.php).
function qboRecordChargeShadow(int $tid, array $charge, array $context = []): int {
    $pdo = $GLOBALS['pdo'];
    $chargeId = (string) ($charge['id'] ?? '');
    $amountCents = (int) round(((float) ($charge['amount'] ?? 0)) * 100);
    $status = (string) ($charge['status'] ?? 'ISSUED');
    $captured = $status === 'CAPTURED' ? date('Y-m-d H:i:s') : null;
    $settled  = $status === 'SETTLED'  ? date('Y-m-d H:i:s') : null;
    $sel = $pdo->prepare("SELECT id FROM qbo_payment_charges WHERE tenant_id=:t AND qbo_charge_id=:c LIMIT 1");
    $sel->execute(['t'=>$tid,'c'=>$chargeId]);
    $r = $sel->fetch(\PDO::FETCH_ASSOC);
    if ($r) {
        $pdo->prepare("UPDATE qbo_payment_charges SET status=?, amount_cents=?, captured_at=COALESCE(?, captured_at), settled_at=COALESCE(?, settled_at) WHERE id=?")
            ->execute([$status, $amountCents, $captured, $settled, $r['id']]);
        return (int) $r['id'];
    }
    return 0;
}

// Now run the cron logic (extract the polling block).
$pdo = $GLOBALS['pdo'];
$tenants = $pdo->query("SELECT DISTINCT c.tenant_id FROM qbo_payment_charges c JOIN qbo_connections cn ON cn.tenant_id = c.tenant_id AND cn.status = 'active' WHERE c.status IN ('ISSUED','PENDING','CAPTURED','AUTHORIZED') AND (c.settled_at IS NULL) AND c.created_at >= datetime('now','-30 days')")->fetchAll(\PDO::FETCH_ASSOC);
$totals = ['tenants' => 0, 'polled' => 0, 'advanced' => 0, 'errors' => 0];
foreach ($tenants as $row) {
    $tid = (int) $row['tenant_id'];
    $totals['tenants']++;
    $stmt = $pdo->prepare("SELECT id, qbo_charge_id, status, charge_type FROM qbo_payment_charges WHERE tenant_id = :t AND status IN ('ISSUED','PENDING','CAPTURED','AUTHORIZED') AND (settled_at IS NULL) ORDER BY created_at ASC LIMIT 200");
    $stmt->execute(['t' => $tid]);
    foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $c) {
        $totals['polled']++;
        $before = (string) $c['status'];
        try {
            $live = qboGetCharge($tid, (string) $c['qbo_charge_id']);
            qboRecordChargeShadow($tid, $live, ['charge_type' => $c['charge_type']]);
            if (strtoupper((string) ($live['status'] ?? '')) !== $before) $totals['advanced']++;
        } catch (\Throwable $e) {
            $totals['errors']++;
        }
    }
}

check('tenants iterated = 1',                $totals['tenants'] === 1);
check('polled 2 pending charges (skipped SETTLED)',  $totals['polled'] === 2);
check('advanced 2 charges',                  $totals['advanced'] === 2);
check('no errors',                            $totals['errors'] === 0);

$a = $pdo->query("SELECT status, captured_at FROM qbo_payment_charges WHERE qbo_charge_id='CHG-A'")->fetch(\PDO::FETCH_ASSOC);
check('CHG-A advanced ISSUED → CAPTURED',     $a['status'] === 'CAPTURED');
check('CHG-A captured_at stamped',            !empty($a['captured_at']));
$b = $pdo->query("SELECT status, settled_at, captured_at FROM qbo_payment_charges WHERE qbo_charge_id='CHG-B'")->fetch(\PDO::FETCH_ASSOC);
check('CHG-B advanced CAPTURED → SETTLED',    $b['status'] === 'SETTLED');
check('CHG-B settled_at stamped',             !empty($b['settled_at']));
check('CHG-B captured_at preserved',          !empty($b['captured_at']));
$c = $pdo->query("SELECT status FROM qbo_payment_charges WHERE qbo_charge_id='CHG-C'")->fetch(\PDO::FETCH_ASSOC);
check('CHG-C (already SETTLED) untouched',    $c['status'] === 'SETTLED');

echo "\nqbo_payments_poll smoke: {$passes} ✓ / " . count($failures) . " ✗\n";
foreach ($failures as $msg) echo "  FAIL: {$msg}\n";
exit($failures ? 1 : 0);
