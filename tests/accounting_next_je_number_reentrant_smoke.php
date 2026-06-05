<?php
/**
 * Smoke — accountingNextJeNumber() re-entrant transaction fix
 * (P0 hotfix, 2026-02).
 *
 * Locks the fix for "Error: There is already an active transaction"
 * surfaced when the New Journal Entry form POSTs to
 * /api/accounting/journal_entries — accountingPostJe() opens a
 * transaction, then calls accountingNextJeNumber() which (before the
 * fix) opened a nested transaction PDO refuses.
 *
 * The fix detects `$pdo->inTransaction()` and participates in the
 * outer transaction instead of opening its own. The FOR UPDATE row
 * lock still holds correctly inside the outer transaction.
 *
 * Static-analyzer probes only — pure-function probes exercise the
 * re-entrancy bookkeeping via a stub PDO that records every
 * beginTransaction / commit / rollback call.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};
$c = function (string $hay, string $needle): bool { return strpos($hay, $needle) !== false; };

// ──────────────────────────────────────────────────────────────────────
// 1) Source surface — accountingNextJeNumber participates in outer txn.
// ──────────────────────────────────────────────────────────────────────
echo "\n── modules/accounting/lib/accounting.php source ──\n";
$acc = (string) file_get_contents('/app/modules/accounting/lib/accounting.php');

$a('accountingNextJeNumber still defined',
    $c($acc, 'function accountingNextJeNumber(int $tenantId): string'));
$a('detects existing transaction via inTransaction()',
    $c($acc, '$owningTxn = !$pdo->inTransaction();'));
$a('only opens own transaction when not already inside one',
    $c($acc, 'if ($owningTxn) $pdo->beginTransaction();'));
$a('only commits when it opened the transaction',
    $c($acc, 'if ($owningTxn) $pdo->commit();'));
$a('only rolls back when it opened the transaction',
    $c($acc, 'if ($owningTxn && $pdo->inTransaction()) $pdo->rollBack();'));
$a('FOR UPDATE row lock preserved (still locks inside outer txn)',
    $c($acc, 'FROM tenants WHERE id = :id FOR UPDATE'));
$a('sequence increment still atomic with the SELECT',
    $c($acc, 'UPDATE tenants SET accounting_next_je_seq = :n WHERE id = :id'));
$a('docstring calls out the New Journal Entry → Post JE surface',
    $c($acc, 'New Journal Entry → Post JE'));

// accountingPostJe still uses an outer transaction (the call path this fix unblocks).
$a('accountingPostJe still wraps its writes in a transaction',
    preg_match('/function\s+accountingPostJe\b.*?\$pdo->beginTransaction\(\)/s', $acc) === 1);
$a('accountingPostJe still calls accountingNextJeNumber inside that transaction',
    preg_match('/\$pdo->beginTransaction\(\);.*?accountingNextJeNumber\(\$tenantId\)/s', $acc) === 1);

// Defensive begin — accountingPostJe rolls back any stale transaction
// inherited from a prior failed handler in the SAME PHP request before
// opening its own. Same guard mirrored in accountingPromoteDraftToPosted.
$a('accountingPostJe rolls back stale tx before beginning own',
    preg_match('/function\s+accountingPostJe\b.*?if\s*\(\s*\$pdo->inTransaction\(\)\s*\)\s*\{\s*error_log\([^)]*post-je[^)]*\);\s*\$pdo->rollBack\(\);\s*\}/s', $acc) === 1);
$a('accountingPromoteDraftToPosted rolls back stale tx before beginning own',
    preg_match('/function\s+accountingPromoteDraftToPosted\b.*?if\s*\(\s*\$pdo->inTransaction\(\)\s*\)\s*\{\s*error_log\([^)]*promote-draft[^)]*\);\s*\$pdo->rollBack\(\);\s*\}/s', $acc) === 1);

// php -l clean.
exec('php -l /app/modules/accounting/lib/accounting.php 2>&1', $out, $rc);
$a('accounting.php passes php -l',                   $rc === 0);

// ──────────────────────────────────────────────────────────────────────
// 2) Pure-function probe — re-entrancy bookkeeping under a stub PDO.
//
// We can't reach a real DB here; instead, model the helper as a tiny
// FSM that records every txn boundary call and check that:
//   - When inTransaction()=false at entry → opens + commits its own.
//   - When inTransaction()=true  at entry → does NOT open and does
//     NOT commit (caller owns the boundary).
//   - On error after entry, only the owning frame rolls back.
// ──────────────────────────────────────────────────────────────────────
echo "\n── re-entrancy FSM probe ──\n";

/** Tiny stub that mirrors the exact branching in accountingNextJeNumber. */
$run = function (bool $outerActive, bool $forceThrow) {
    $log = [];
    $inTxn = $outerActive;
    $beginTransaction = function () use (&$inTxn, &$log) {
        if ($inTxn) throw new \PDOException('There is already an active transaction');
        $inTxn = true; $log[] = 'BEGIN';
    };
    $commit  = function () use (&$inTxn, &$log) { $inTxn = false; $log[] = 'COMMIT'; };
    $rollBack = function () use (&$inTxn, &$log) { $inTxn = false; $log[] = 'ROLLBACK'; };
    $inTransaction = function () use (&$inTxn) { return $inTxn; };

    // Mirrored from accountingNextJeNumber.
    $owningTxn = !$inTransaction();
    if ($owningTxn) $beginTransaction();
    try {
        if ($forceThrow) throw new \RuntimeException('synthetic select failure');
        // ... SELECT FOR UPDATE + UPDATE sequence ...
        if ($owningTxn) $commit();
        $ok = true;
    } catch (\Throwable $e) {
        if ($owningTxn && $inTransaction()) $rollBack();
        $ok = false;
    }
    return ['log' => $log, 'finalInTxn' => $inTxn, 'ok' => $ok];
};

// Case 1 — no outer transaction, success path.
$r = $run(false, false);
$a('no-outer + success: opens BEGIN then COMMIT',
    $r['log'] === ['BEGIN', 'COMMIT'] && $r['finalInTxn'] === false && $r['ok'] === true);

// Case 2 — no outer transaction, failure path.
$r = $run(false, true);
$a('no-outer + failure: opens BEGIN then ROLLBACK',
    $r['log'] === ['BEGIN', 'ROLLBACK'] && $r['finalInTxn'] === false && $r['ok'] === false);

// Case 3 — outer transaction, success path → must NOT touch txn boundaries.
$r = $run(true, false);
$a('outer-active + success: no BEGIN, no COMMIT (leaves outer open)',
    $r['log'] === [] && $r['finalInTxn'] === true && $r['ok'] === true);

// Case 4 — outer transaction, failure path → must NOT touch txn boundaries.
//          Caller (accountingPostJe) owns the rollback.
$r = $run(true, true);
$a('outer-active + failure: no ROLLBACK (caller owns rollback)',
    $r['log'] === [] && $r['finalInTxn'] === true && $r['ok'] === false);

// Case 5 — guards against double-begin: the BEFORE-fix behaviour
//          would have called BEGIN unconditionally and PDOException'd.
//          Confirm our model would have thrown if it had done so.
$threw = false;
try {
    $log = [];
    $inTxn = true;
    $bad_beginTransaction = function () use (&$inTxn, &$log) {
        if ($inTxn) throw new \PDOException('There is already an active transaction');
        $inTxn = true; $log[] = 'BEGIN';
    };
    $bad_beginTransaction(); // simulate the pre-fix bug
} catch (\PDOException $e) {
    $threw = $e->getMessage() === 'There is already an active transaction';
}
$a('control: pre-fix unconditional BEGIN reproduces the exact PDO error',
    $threw === true);

// ──────────────────────────────────────────────────────────────────────
// 3) Regression — accountingPromoteDraftToPosted is unaffected (it
//    does NOT call accountingNextJeNumber, since draft rows already
//    have their je_number assigned at draft-creation time).
// ──────────────────────────────────────────────────────────────────────
echo "\n── regression: accountingPromoteDraftToPosted ──\n";
$a('promote does not allocate a new je_number',
    preg_match('/function\s+accountingPromoteDraftToPosted\b.*?accountingNextJeNumber/s', $acc) !== 1);
$a('promote still stamps approval_id (P2 gate intact)',
    $c($acc, 'approval_id = :a'));
$a('promote still consumes approval atomically (P2 gate intact)',
    $c($acc, 'UPDATE workflow_approvals')
    && $c($acc, 'consumed_at IS NULL'));

// ──────────────────────────────────────────────────────────────────────
echo "\n=========================================\n";
echo "accountingNextJeNumber re-entrant smoke: $pass ✓ / $fail ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
