<?php
/**
 * Smoke — Defense-in-depth: every lib-level helper that opens its own
 * transaction must use the nested-safe cf_tx_begin / cf_tx_commit /
 * cf_tx_rollback helpers, NOT raw $pdo->beginTransaction().
 *
 * Inventory snapshot (2026-02):
 *   /app/core/api_bootstrap.php           cf_tx_* helpers declared
 *   /app/modules/ap/lib/ap.php            apNextInternalRef, apAllocatePayment
 *   /app/modules/ap/lib/pwp.php           apPwpAutoLink, apPwpRelease
 *   /app/modules/ap/lib/recurring.php     apRecurringGenerateDue (loop body)
 *   /app/modules/accounting/lib/intercompany.php  intercompanyPostSplit
 *   /app/modules/billing/lib/billing.php  billingNextInvoiceNumber, billingAllocatePayment
 *   /app/modules/engagements/lib/engagements.php  engagementsCreate
 *   /app/modules/payroll/lib/csv_import.php       payrollImportRunCsv
 *   /app/modules/payroll/lib/cycles.php           payrollCycleAdvance
 *   /app/modules/people/lib/companies.php         companiesMerge
 *   /app/modules/placements/lib/rate_approve.php  placementsRateApproveOne
 *   /app/modules/staffing/lib/timesheets.php      bulk_save, submit, reject, approve, reopen
 *   /app/modules/time/lib/settlement.php          extract, unExtract
 *   /app/modules/time/lib/settlement_create.php   autoCreate, _settleTimeIntoPayroll
 *   /app/core/mercury_payments.php        mpTransition
 *   /app/core/mercury_recipients.php      mercuryRecipientCreate
 *   /app/core/sub_tenants.php             subTenantProvision
 *
 * The legacy `cf_begin_transaction()` helper is preserved (used by API
 * handler entry points) and is intentionally NOT replaced — it owns the
 * outermost tx and is the place lib helpers detect the outer caller.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

echo "\n1. cf_tx_begin / cf_tx_commit / cf_tx_rollback are declared\n";
$bootstrap = (string) file_get_contents('/app/core/api_bootstrap.php');
$helpers   = (string) file_get_contents('/app/core/tx_helpers.php');
$a('tx_helpers.php is required from api_bootstrap',
    str_contains($bootstrap, "require_once __DIR__ . '/tx_helpers.php';"));
$a('cf_tx_begin declared',    str_contains($helpers, 'function cf_tx_begin(\PDO $pdo): bool'));
$a('cf_tx_commit declared',   str_contains($helpers, 'function cf_tx_commit(\PDO $pdo, bool $owns): void'));
$a('cf_tx_rollback declared', str_contains($helpers, 'function cf_tx_rollback(\PDO $pdo, bool $owns): void'));
$a('cf_tx_begin is no-op when already in tx',
    str_contains($helpers, 'function cf_tx_begin(\PDO $pdo): bool')
    && str_contains($helpers, 'if ($pdo->inTransaction()) return false;'));
$a('cf_tx_commit only commits when owns',
    str_contains($helpers, 'function cf_tx_commit(\PDO $pdo, bool $owns): void')
    && str_contains($helpers, 'if ($owns && $pdo->inTransaction()) $pdo->commit();'));
$a('cf_tx_rollback only rolls back when owns',
    str_contains($helpers, 'function cf_tx_rollback(\PDO $pdo, bool $owns): void')
    && str_contains($helpers, 'if ($owns && $pdo->inTransaction()) $pdo->rollBack();'));

echo "\n2. Inventory check — no lib-level helper still uses raw \$pdo->beginTransaction\n";
$libGlob = [
    '/app/modules/ap/lib/ap.php',
    '/app/modules/ap/lib/pwp.php',
    '/app/modules/ap/lib/recurring.php',
    '/app/modules/accounting/lib/intercompany.php',
    '/app/modules/billing/lib/billing.php',
    '/app/modules/engagements/lib/engagements.php',
    '/app/modules/payroll/lib/csv_import.php',
    '/app/modules/payroll/lib/cycles.php',
    '/app/modules/people/lib/companies.php',
    '/app/modules/placements/lib/rate_approve.php',
    '/app/modules/staffing/lib/timesheets.php',
    '/app/modules/time/lib/settlement.php',
    '/app/modules/time/lib/settlement_create.php',
    '/app/core/mercury_payments.php',
    '/app/core/mercury_recipients.php',
    '/app/core/sub_tenants.php',
];
foreach ($libGlob as $f) {
    $src = (string) file_get_contents($f);
    // The only allowed beginTransaction reference is inside the
    // accounting.php legacy helpers (lines 270/757 use the stale-
    // rollback pattern intentionally and aren't covered by this sweep
    // because they're framework-level).
    $hasRaw = (bool) preg_match('/\\\$(?:pdo|db)->beginTransaction\\(\\)/', $src);
    $a(basename($f) . ': no raw \$pdo/$db->beginTransaction', !$hasRaw,
        $hasRaw ? 'found a remaining raw call' : '');
    $hasCfBegin = str_contains($src, 'cf_tx_begin(');
    // ap.php/pwp.php/billing.php were patched earlier with the inline
    // owning-tx pattern ($ownsTxn = !$pdo->inTransaction(); + guarded
    // begin/commit/rollback). Both patterns are nested-safe and
    // semantically equivalent — accept either.
    $hasInline  = str_contains($src, '$ownsTxn = !$pdo->inTransaction();')
               && str_contains($src, 'if ($ownsTxn) $pdo->beginTransaction();');
    $a(basename($f) . ': uses cf_tx_begin or inline guard pattern', $hasCfBegin || $hasInline);
}

echo "\n3. PHP syntax across the patched surface\n";
foreach ($libGlob as $f) {
    $out = (string) shell_exec("php -l {$f} 2>&1");
    $a('php -l ' . basename($f), str_contains($out, 'No syntax errors'), trim($out));
}

echo "\n4. users.tenant_id baseline migration exists & is idempotent\n";
$mig = (string) @file_get_contents('/app/core/migrations/056_users_tenant_id_baseline.sql');
$a('migration file present',                  $mig !== '');
$a('column-add is gated by information_schema check',
    str_contains($mig, "FROM information_schema.columns") && str_contains($mig, "@col_exists := ("));
$a('uses PREPARE/EXECUTE for the conditional ALTER',
    str_contains($mig, 'PREPARE stmt FROM @add_col_sql') && str_contains($mig, 'EXECUTE stmt'));
$a('backfills from tenant_memberships',
    str_contains($mig, 'FROM tenant_memberships') && str_contains($mig, "status = 'active'"));
$a('defaults backfill to 0 sentinel for users without memberships',
    str_contains($mig, 'COALESCE(m.tenant_id, 0)'));

echo "\n5. Live PDO exercise — nested cascade survives without 'active transaction'\n";

$pdo = new \PDO('sqlite::memory:');
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

// Stub the cf_tx helpers locally (no need to bootstrap the real ones).
function _smoke_cf_tx_begin(\PDO $p): bool { if ($p->inTransaction()) return false; $p->beginTransaction(); return true; }
function _smoke_cf_tx_commit(\PDO $p, bool $owns): void { if ($owns && $p->inTransaction()) $p->commit(); }
function _smoke_cf_tx_rollback(\PDO $p, bool $owns): void { if ($owns && $p->inTransaction()) $p->rollBack(); }

$pdo->exec("CREATE TABLE t (id INTEGER PRIMARY KEY, v INTEGER)");

// Simulate an OUTER caller (e.g. Create-Bill endpoint) holding a tx,
// and INNER helpers (apNextInternalRef, apAllocatePayment, pwp release)
// each guarding their own tx via cf_tx_*.
$inner1 = function () use ($pdo) {
    $owns = _smoke_cf_tx_begin($pdo);
    try {
        $pdo->exec("INSERT INTO t (v) VALUES (1)");
        _smoke_cf_tx_commit($pdo, $owns);
    } catch (\Throwable $e) { _smoke_cf_tx_rollback($pdo, $owns); throw $e; }
};
$inner2 = function () use ($pdo, $inner1) {
    $owns = _smoke_cf_tx_begin($pdo);
    try {
        $pdo->exec("INSERT INTO t (v) VALUES (2)");
        $inner1(); // deeply nested
        $pdo->exec("INSERT INTO t (v) VALUES (3)");
        _smoke_cf_tx_commit($pdo, $owns);
    } catch (\Throwable $e) { _smoke_cf_tx_rollback($pdo, $owns); throw $e; }
};

// Outer caller owns the tx; nested calls must NOT fire the "already an
// active transaction" SQL exception and must NOT prematurely commit.
$pdo->beginTransaction();
$err = null;
try { $inner2(); } catch (\Throwable $e) { $err = $e->getMessage(); }
$a('no nested-tx exception when caller owns the outer tx', $err === null, $err ?? '');
$a('outer tx still active after nested helpers ran', $pdo->inTransaction());
$pdo->commit();
$a('all 3 rows committed atomically with the outer tx',
    (int) $pdo->query('SELECT COUNT(*) FROM t')->fetchColumn() === 3);

// And the opposite: no outer tx → helpers own their own.
$err2 = null;
try { $inner2(); } catch (\Throwable $e) { $err2 = $e->getMessage(); }
$a('standalone inner call still works without an outer tx', $err2 === null, $err2 ?? '');
$a('inner rows committed', (int) $pdo->query('SELECT COUNT(*) FROM t')->fetchColumn() === 6);

echo "\n— pass={$pass}  fail={$fail}\n";
exit($fail === 0 ? 0 : 1);
