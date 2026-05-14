<?php
/**
 * Module Emission Discipline — contract smoke (Phase 2a guardrail).
 *
 * Per harness spec §5 and §24 + Live Books Rails Phase 2a:
 *   Modules MUST be event producers. They MUST NOT write to the GL
 *   directly. The single chokepoint for posting is accountingPostJe()
 *   in /app/modules/accounting/lib/accounting.php, and it should be
 *   called ONLY from:
 *     (a) The accounting module itself (manual JEs, reversals).
 *     (b) The posting engine (via accountingProcessEvent → rule render).
 *     (c) The replay endpoints (intentionally re-run history).
 *     (d) An allowlisted set of legacy bypass sites that Phase 2a will
 *         migrate to events. New code MUST NOT add to this allowlist.
 *
 * This smoke greps the module trees and:
 *   1. Asserts the allowlist exactly matches reality (no new bypasses).
 *   2. Asserts no module file does a raw INSERT INTO accounting_journal_*.
 *   3. Asserts the posting engine + accounting module own the helper.
 *
 * Failing this smoke = the Phase 2a discipline regressed.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};

/** Allowlisted direct-GL callers carrying the Phase-2a migration debt.
 *  Anything else in /app/modules/** failing the grep is a discipline
 *  violation that MUST be filed as Phase-2a work BEFORE it merges. */
$ALLOWED_BYPASS = [
    'modules/ap/api/bills.php',                       // legacy fallback after event try
    'modules/billing/api/invoices.php',               // legacy fallback after event try
    'modules/treasury/api/account_transactions.php',  // pure bypass — Phase-2a refactor target
];

/** Files inside /app that LEGITIMATELY own accountingPostJe (definition
 *  + posting engine + replay endpoints + the accounting module's own
 *  internal helpers — intercompany, dimensions, recurring JEs, cross-
 *  tenant IC are all accounting-module-internal concerns and posting GL
 *  is precisely their job).  These are not "callers" in the discipline
 *  sense — the discipline rule applies to OTHER modules. */
$DEFINITION_OR_ENGINE = [
    'modules/accounting/',                    // entire accounting module (lib + api + ui)
    'core/posting_engine/',                   // event-driven path
    'api/ap_bill_replay.php',                 // event replay
    'api/billing_invoice_replay.php',         // event replay
    'api/posting_rules_replay.php',           // posting rules replay
    'api/accounting_events.php',              // event dispatcher
];

echo "Grep /app/modules + /app/api for accountingPostJe callers\n";
$ROOT = realpath(__DIR__ . '/..');
$cmd  = sprintf('grep -rln %s %s/modules %s/api 2>/dev/null',
    escapeshellarg('accountingPostJe('), $ROOT, $ROOT);
$out  = (string) shell_exec($cmd);
$files = array_filter(array_map('trim', explode("\n", $out)));

$violations = [];
foreach ($files as $abs) {
    $rel = ltrim(str_replace($ROOT, '', $abs), '/');
    $isEngine = false;
    foreach ($DEFINITION_OR_ENGINE as $okPrefix) {
        if (str_starts_with($rel, $okPrefix)) { $isEngine = true; break; }
    }
    if ($isEngine) continue;
    if (in_array($rel, $ALLOWED_BYPASS, true)) continue;
    $violations[] = $rel;
}

$a('no NEW module files call accountingPostJe()',    empty($violations));
if (!empty($violations)) {
    foreach ($violations as $v) echo "        violation: {$v}\n";
}
$a('allowlist still tracks AP bills bypass',         in_array('modules/ap/api/bills.php', array_map(fn ($abs) => ltrim(str_replace($ROOT, '', $abs), '/'), $files), true));
$a('allowlist still tracks billing invoices bypass', in_array('modules/billing/api/invoices.php', array_map(fn ($abs) => ltrim(str_replace($ROOT, '', $abs), '/'), $files), true));
$a('allowlist still tracks treasury bypass',         in_array('modules/treasury/api/account_transactions.php', array_map(fn ($abs) => ltrim(str_replace($ROOT, '', $abs), '/'), $files), true));

echo "\nGrep for raw INSERT INTO accounting_journal_* outside the accounting module\n";
$cmd2 = sprintf('grep -rln -E %s %s/modules %s/api %s/core %s/scripts 2>/dev/null',
    escapeshellarg('INSERT INTO accounting_journal_(entries|lines)'),
    $ROOT, $ROOT, $ROOT, $ROOT);
$out2 = (string) shell_exec($cmd2);
$rawFiles = array_filter(array_map('trim', explode("\n", $out2)));
$rawViolations = [];
foreach ($rawFiles as $abs) {
    $rel = ltrim(str_replace($ROOT, '', $abs), '/');
    if (str_starts_with($rel, 'modules/accounting/')
     || str_starts_with($rel, 'core/posting_engine/')
     || str_starts_with($rel, 'core/migrations/')) continue;
    $rawViolations[] = $rel;
}
$a('no raw INSERT INTO accounting_journal_* in modules', empty($rawViolations));
if (!empty($rawViolations)) {
    foreach ($rawViolations as $v) echo "        raw insert: {$v}\n";
}

echo "\naccountingPostJe is defined exactly once\n";
$cmdDef = sprintf('grep -rn "^function accountingPostJe" %s 2>/dev/null | wc -l', $ROOT);
$count  = (int) shell_exec($cmdDef);
$a('single definition of accountingPostJe',          $count === 1);

echo "\naccountingProcessEvent (event chokepoint) is reachable\n";
$proc = (string) file_get_contents($ROOT . '/core/posting_engine/process.php');
$a('event engine calls accountingPostJe internally', str_contains($proc, 'accountingPostJe('));
$a('event engine validates event_registry first',    str_contains($proc, 'event_registry') || str_contains($proc, 'eventRegistry'));

echo "\nLegacy fallback paths are clearly labeled\n";

/** Strip PHP comments so strpos() checks don't match `accountingPostJe()`
 *  references inside doc comments before the actual call. */
$stripPhpComments = function (string $code): string {
    $tokens = token_get_all($code);
    $out = '';
    foreach ($tokens as $t) {
        if (is_array($t)) {
            if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue;
            $out .= $t[1];
        } else {
            $out .= $t;
        }
    }
    return $out;
};

$bills    = (string) file_get_contents($ROOT . '/modules/ap/api/bills.php');
$billsCode = $stripPhpComments($bills);
$a('AP bills tries event layer first',               strpos($billsCode, 'accountingProcessEvent(') < strpos($billsCode, 'accountingPostJe('));
$a('AP bills marks legacy fallback in comments',     str_contains($bills, 'legacy direct') || str_contains($bills, 'Fallback path'));

$inv     = (string) file_get_contents($ROOT . '/modules/billing/api/invoices.php');
$invCode = $stripPhpComments($inv);
$a('Billing invoices tries event layer first',       strpos($invCode, 'accountingProcessEvent(') < strpos($invCode, 'accountingPostJe('));
$a('Billing invoices marks legacy fallback',         str_contains($inv, 'legacy') || str_contains($inv, 'fallback'));

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
