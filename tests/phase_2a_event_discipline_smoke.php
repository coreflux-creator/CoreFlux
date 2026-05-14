<?php
/**
 * Phase 2a smoke (2026-02-XX) — Module Emission Discipline migration.
 *
 * Verifies:
 *   1. core/module_emission_discipline.php helper exists + non-throwing.
 *   2. Migration 044 creates module_emission_discipline_log with the
 *      expected schema + indexes.
 *   3. New event_registry entry for treasury.bank_transaction.categorized.
 *   4. New posting_rules seed entry for the same event with line_source
 *      = 'payload' (passthrough).
 *   5. Treasury feed categorize + split BOTH try accountingProcessEvent
 *      first, fall back to direct accountingPostJe on engine 'ignored'.
 *   6. AP bills + Billing invoices fallback paths now call
 *      moduleEmissionDisciplineLog() so telemetry captures every fire.
 *   7. Three matching scenarios exist in /app/sim/scenarios/ so the
 *      harness can verify the refactor doesn't regress JE output.
 *   8. The contract smoke (module_emission_discipline_smoke.php) still
 *      passes (no NEW direct-GL callers).
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};
$read = fn (string $p) => (string) file_get_contents($p);

echo "Helper: core/module_emission_discipline.php\n";
$helper = $read(__DIR__ . '/../core/module_emission_discipline.php');
$a('helper file exists',                    $helper !== '');
$a('exports moduleEmissionDisciplineLog()', str_contains($helper, 'function moduleEmissionDisciplineLog'));
$a('INSERT into discipline_log',            str_contains($helper, 'INSERT INTO module_emission_discipline_log'));
$a('never throws (swallow + error_log)',    str_contains($helper, 'Throwable') && str_contains($helper, 'error_log'));

echo "\nMigration 044 — module_emission_discipline_log\n";
$mig = $read(__DIR__ . '/../core/migrations/044_module_emission_discipline.sql');
$a('migration file exists',                 $mig !== '');
$a('creates table',                         str_contains($mig, 'CREATE TABLE IF NOT EXISTS module_emission_discipline_log'));
foreach (['tenant_id','source_module','event_type','context','created_by_user_id','created_at'] as $col) {
    $a("column: {$col}",                    str_contains($mig, " {$col} ") || str_contains($mig, "{$col} "));
}
$a('indexed by (tenant, module, created)',  str_contains($mig, 'ix_tenant_module_created'));
$a('indexed by (tenant, event, created)',   str_contains($mig, 'ix_tenant_event_created'));

echo "\nEvent registry: treasury.bank_transaction.categorized\n";
$reg = $read(__DIR__ . '/../core/seeds/event_registry_seed.php');
$a('new event_type registered',             str_contains($reg, "'treasury.bank_transaction.categorized'"));
$a('requires bank_txn_id,amount,…',
    preg_match('/treasury\.bank_transaction\.categorized.*?bank_txn_id.*?amount.*?currency.*?direction.*?lines/s', $reg) === 1);
$a('parents treasury.bank_transaction.matched',
    preg_match('/treasury\.bank_transaction\.categorized.*?treasury\.bank_transaction\.matched/s', $reg) === 1);

echo "\nSeed pack: passthrough rule for the new event\n";
$seed = $read(__DIR__ . '/../core/posting_engine/seed_defaults.php');
$a('seed pack has categorized passthrough', str_contains($seed, "'treasury.bank_transaction.categorized'"));
$a('passthrough uses line_source=payload',
    preg_match('/treasury\.bank_transaction\.categorized.*?line_source.*?payload/s', $seed) === 1);
$a('matched fallback rule still present',   str_contains($seed, "'treasury.bank_transaction.matched'"));

echo "\nTreasury feed refactor — categorize + split both event-first\n";
$tx = $read(__DIR__ . '/../modules/treasury/api/account_transactions.php');
$a('imports posting_engine/process.php',    str_contains($tx, "require_once __DIR__ . '/../../../core/posting_engine/process.php'"));
$a('imports discipline log helper',         substr_count($tx, "require_once __DIR__ . '/../../../core/module_emission_discipline.php'") >= 2);
$a('emits treasury.bank_transaction.categorized', substr_count($tx, "'event_type'       => 'treasury.bank_transaction.categorized'") >= 2);
$a('passes split_count in payload',         str_contains($tx, "'split_count' => count(\$splits)"));
$a('falls back only on engine non-posted',  substr_count($tx, "(\$eventResult['status'] ?? null) === 'posted'") >= 2);
$a('discipline log fires on fallback (categorize)',
    preg_match('/moduleEmissionDisciplineLog\(.+treasury_feed.+treasury\.bank_transaction\.categorized/s', $tx) === 1);
$a('discipline log fires on fallback (split)',
    substr_count($tx, "moduleEmissionDisciplineLog('treasury_feed', 'treasury.bank_transaction.categorized'") >= 2);
$stripPhpComments = function (string $code): string {
    $tokens = token_get_all($code);
    $out = '';
    foreach ($tokens as $t) {
        if (is_array($t)) {
            if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue;
            $out .= $t[1];
        } else { $out .= $t; }
    }
    return $out;
};
$a('no orphan direct accountingPostJe()',   substr_count($stripPhpComments($tx), 'accountingPostJe(') === 2);  // 2 fallbacks only

echo "\nAP bills + Billing invoices instrumented\n";
$bills = $read(__DIR__ . '/../modules/ap/api/bills.php');
$inv   = $read(__DIR__ . '/../modules/billing/api/invoices.php');
$a('AP bills imports discipline helper',    str_contains($bills, "require_once __DIR__ . '/../../../core/module_emission_discipline.php'"));
$a('AP bills logs fallback fire',           str_contains($bills, "moduleEmissionDisciplineLog('ap', 'ap.bill.approved'"));
$a('AP bills logs BEFORE direct post',      strpos($bills, "moduleEmissionDisciplineLog('ap'") < strpos($bills, "accountingPostJe(\$tid, ["));
$a('Billing imports discipline helper',     str_contains($inv, "require_once __DIR__ . '/../../../core/module_emission_discipline.php'"));
$a('Billing logs fallback fire',            str_contains($inv, "moduleEmissionDisciplineLog('billing', 'billing.invoice.sent'"));

echo "\nHarness scenarios cover the refactor\n";
foreach ([
    'ap_bill_happy_path.json',
    'ar_invoice_happy_path.json',
    'treasury_bank_feed_categorize.json',
] as $sc) {
    $a("scenario exists: {$sc}",            is_file(__DIR__ . '/../sim/scenarios/' . $sc));
}
$tsc = json_decode($read(__DIR__ . '/../sim/scenarios/treasury_bank_feed_categorize.json'), true);
$a('treasury scenario declares no_direct_gl invariant',
    in_array('no_direct_gl', $tsc['invariants'] ?? [], true));
$a('treasury scenario step emits matched event',
    isset($tsc['steps'][0]['event_type'])
    && str_starts_with((string) $tsc['steps'][0]['event_type'], 'treasury.bank_transaction.'));

echo "\nContract smoke (no NEW direct-GL callers) still green\n";
$out = (string) shell_exec('php -d zend.assertions=1 ' . escapeshellarg(__DIR__ . '/module_emission_discipline_smoke.php') . ' 2>&1');
$a('contract smoke passes',                 (bool) preg_match('/--- \d+ passed, 0 failed ---/', $out));

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
