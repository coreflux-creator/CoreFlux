<?php
/**
 * Smoke — JE post-flow transaction hygiene.
 *
 * Locks:
 *   - `accountingPostJe()` defensively rolls back any stale active
 *     transaction before its own `beginTransaction()` call.
 *   - The two `beginTransaction` call sites in
 *     `recurring_journal_entries.php` are now both guarded.
 *   - The `cf_begin_transaction()` helper in api_bootstrap.php still
 *     exists and is callable (regression target — earlier sessions
 *     showed it being silently dropped).
 *   - The shutdown handler exists and clears stale transactions.
 *
 * This is a static-analysis smoke (no PDO replay) — the JE post lib
 * is too tangled with the api_bootstrap stack to mock cleanly. The
 * test prevents future "any time I post a JE it says there's already
 * an active transaction" recurrences by locking the guard pattern in
 * source code.
 */
declare(strict_types=1);

$passes = 0; $failures = [];
function check(string $label, bool $cond) {
    global $passes, $failures;
    if ($cond) { $passes++; echo "  ✓ {$label}\n"; }
    else       { $failures[] = $label; echo "  ✗ {$label}\n"; }
}

echo "\nJE transaction-hygiene smoke\n";
echo "==============================\n\n";

// ─── accountingPostJe guard ───
echo "── lib/accounting.php (accountingPostJe) ──\n";
$src = (string) file_get_contents('/app/modules/accounting/lib/accounting.php');
check('accountingPostJe contains stale-tx rollback guard',
    str_contains($src, "rolling back stale active transaction before begin"));
check('guard checks inTransaction before rollBack',
    str_contains($src, "if (\$pdo->inTransaction()) {") &&
    str_contains($src, "\$pdo->rollBack()"));
check('beginTransaction is called AFTER the guard',
    preg_match("/inTransaction\\(\\)\\) \\{[^}]*rollBack\\(\\)[^}]*\\}\\s*\\\$pdo->beginTransaction\\(\\);/s", $src) === 1);
check('outer catch block guards rollBack with inTransaction()',
    str_contains($src, 'if ($pdo->inTransaction()) $pdo->rollBack();'));

// ─── recurring_journal_entries guards ───
echo "\n── api/recurring_journal_entries.php ──\n";
$recur = (string) file_get_contents('/app/modules/accounting/api/recurring_journal_entries.php');
$beginCalls = substr_count($recur, '$pdo->beginTransaction();');
$guardCalls = substr_count($recur, 'rolling back stale active transaction before begin');
check('every beginTransaction has a matching guard',
    $beginCalls === $guardCalls && $beginCalls >= 2);
check('"create" handler has the create-label guard',
    str_contains($recur, '[accounting/recurring-je create]'));
check('"replace_lines" handler has the replace-label guard',
    str_contains($recur, '[accounting/recurring-je replace]'));

// ─── api_bootstrap helper + shutdown ───
echo "\n── core/api_bootstrap.php ──\n";
$bs = (string) file_get_contents('/app/core/api_bootstrap.php');
check('cf_begin_transaction() helper still exists',
    str_contains($bs, 'function cf_begin_transaction'));
check('helper rolls back stale txns before begin',
    preg_match('/function cf_begin_transaction.*?inTransaction\\(\\).*?rollBack/s', $bs) === 1);
check('shutdown handler is registered for stale-txn cleanup',
    str_contains($bs, 'register_shutdown_function'));
check('shutdown handler also rolls back stale txns',
    preg_match('/register_shutdown_function.*?inTransaction\\(\\).*?rollBack/s', $bs) === 1);

// ─── Static audit — no remaining unguarded callers in JE-adjacent code ───
echo "\n── unguarded beginTransaction sweep (JE-adjacent) ──\n";
$jeAdjacent = [
    '/app/modules/accounting/lib/accounting.php',
    '/app/modules/accounting/api/journal_entries.php',
    '/app/modules/accounting/api/recurring_journal_entries.php',
    '/app/modules/accounting/api/import.php',
    '/app/modules/accounting/api/csv_export.php',
];
$unguarded = [];
foreach ($jeAdjacent as $p) {
    if (!is_file($p)) continue;
    $body = (string) file_get_contents($p);
    // Strip out the "guard + beginTransaction" idiom so what remains is
    // unguarded calls.
    $body = preg_replace(
        '/if\\s*\\(\\s*\\\$\\w+->inTransaction\\(\\)\\)\\s*\\{[^}]*rollBack\\(\\)[^}]*\\}\\s*\\\$\\w+->beginTransaction\\(\\);/s',
        '',
        $body
    );
    $body = preg_replace(
        '/\\\$owningTxn\\s*=\\s*!\\\$\\w+->inTransaction\\(\\);\\s*if\\s*\\(\\\$owningTxn\\)\\s*\\\$\\w+->beginTransaction\\(\\);/s',
        '',
        $body
    );
    // Also accept cf_begin_transaction calls.
    $body = preg_replace('/cf_begin_transaction\\(\\)\\s*;/', '', $body);
    if (preg_match_all('/\\\$\\w+->beginTransaction\\(\\)/', $body, $matches) > 0) {
        $unguarded[$p] = count($matches[0]);
    }
}
check('no unguarded beginTransaction in JE-adjacent files',
    empty($unguarded));
if (!empty($unguarded)) {
    foreach ($unguarded as $f => $n) {
        echo "      offender: " . basename($f) . " ({$n})\n";
    }
}

echo "\nje_transaction_hygiene smoke: {$passes} ✓ / " . count($failures) . " ✗\n";
foreach ($failures as $msg) echo "  FAIL: {$msg}\n";
exit($failures ? 1 : 0);
