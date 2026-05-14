<?php
/**
 * Sprint 7c.1 smoke — Default posting-rule + journal-template seed pack.
 *
 * Verifies:
 *   - core/posting_engine/seed_defaults.php exposes 7 entries covering
 *     the spec's most common Treasury events
 *   - api/posting_rules_seed.php is admin-gated and calls both seeds
 *   - Treasury Payments / Transfers hydrate bank GL info into payload
 *     so the default templates resolve correctly
 *   - RuleSandbox UI exposes a "Seed default rules" button
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok) use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}\n"; $fail++; }
};
$lint = function (string $p): bool {
    $o = []; $rc = 0; @exec('php -l ' . escapeshellarg($p) . ' 2>&1', $o, $rc);
    return $rc === 0;
};
$ROOT = realpath(__DIR__ . '/..');

echo "core/posting_engine/seed_defaults.php\n";
$seed = (string) file_get_contents("{$ROOT}/core/posting_engine/seed_defaults.php");
$assert('parses',                          $lint("{$ROOT}/core/posting_engine/seed_defaults.php"));
$assert('exposes postingRulesSeedDefaults',  strpos($seed, 'function postingRulesSeedDefaults') !== false);
$assert('returns counts',                  strpos($seed, "'rules_inserted'") !== false
                                         && strpos($seed, "'templates_inserted'") !== false
                                         && strpos($seed, "'pack_size'") !== false);

echo "\nDefault pack — covers all 6 Treasury events\n";
$expected = [
    'treasury.bank_fee.detected',
    'treasury.interest.received',
    'treasury.payment.executed',
    'treasury.transfer.completed',
    'treasury.intercompany.transfer.completed',
    'treasury.bank_transaction.matched',
];
foreach ($expected as $et) {
    $assert("event_type covered: {$et}",   strpos($seed, "'event_type'  => '{$et}'") !== false);
}
$assert('exactly 7 entries',                substr_count($seed, "'event_type'  => 'treasury.") === 7);

echo "\nDefault pack — uses system accounts + payload refs\n";
$assert('Bank Fees Expense referenced',    strpos($seed, "'system:Bank Fees Expense'") !== false);
$assert('Interest Income referenced',      strpos($seed, "'system:Interest Income'") !== false);
$assert('Intercompany Receivable referenced',
    strpos($seed, "'system:Intercompany Receivable'") !== false);
$assert('Uncategorized Expense fallback',  strpos($seed, "'system:Uncategorized Expense'") !== false);
$assert('payload.bank_gl_account_id used', strpos($seed, "'payload.bank_gl_account_id'") !== false);
$assert('payload.counterparty_account_id used',
    strpos($seed, "'payload.counterparty_account_id'") !== false);
$assert('payload.source_bank_gl_account_id used',
    strpos($seed, "'payload.source_bank_gl_account_id'") !== false);
$assert('payload.destination_bank_gl_account_id used',
    strpos($seed, "'payload.destination_bank_gl_account_id'") !== false);

echo "\nIdempotency — find-or-create on both template + rule\n";
$assert('finds template by (tenant,name) before insert',
    strpos($seed, 'SELECT id FROM accounting_journal_templates WHERE tenant_id = :t AND name = :n') !== false);
$assert('finds rule by (tenant,event_type,name) before insert',
    strpos($seed, 'tenant_id = :t AND event_type = :et AND name = :n') !== false);
$assert('never overwrites — comment says so',
    strpos($seed, 'Existing rows are NEVER overwritten') !== false);

echo "\napi/posting_rules_seed.php — endpoint\n";
$ep = (string) file_get_contents("{$ROOT}/api/posting_rules_seed.php");
$assert('parses',                          $lint("{$ROOT}/api/posting_rules_seed.php"));
$assert('requires accounting.manage_posting_rules',
    strpos($ep, "RBAC::requirePermission(\$ctx['user'], 'accounting.manage_posting_rules')") !== false);
$assert('POST-only',                       strpos($ep, "if (api_method() !== 'POST')") !== false);
$assert('seeds system accounts',           strpos($ep, 'accountingSeedSystemAccounts($tid)') !== false);
$assert('seeds default rules',             strpos($ep, 'postingRulesSeedDefaults($tid)') !== false);
$assert('returns both counts',             strpos($ep, "'accounts'") !== false
                                         && strpos($ep, "'rules'") !== false);

echo "\nTreasury Payments — payload hydrated with bank GL info\n";
$tp = (string) file_get_contents("{$ROOT}/api/treasury_payments.php");
$assert('reads bank.gl_account_code via join',
    strpos($tp, 'aa.code = ba.gl_account_code') !== false);
$assert("payload.bank_gl_account_id present",
    strpos($tp, "'bank_gl_account_id'") !== false);
$assert("payload.bank_gl_account_code present",
    strpos($tp, "'bank_gl_account_code'") !== false);

echo "\nTreasury Transfers — payload hydrated with src+dst GL info\n";
$tt = (string) file_get_contents("{$ROOT}/api/treasury_transfers.php");
$assert('hydrates source bank GL',
    strpos($tt, "'source_bank_gl_account_id'") !== false
    && strpos($tt, "'source_bank_gl_account_code'") !== false);
$assert('hydrates destination bank GL',
    strpos($tt, "'destination_bank_gl_account_id'") !== false
    && strpos($tt, "'destination_bank_gl_account_code'") !== false);

echo "\nRuleSandbox UI — Seed defaults button wired\n";
$jsx = (string) file_get_contents("{$ROOT}/dashboard/src/pages/RuleSandbox.jsx");
$assert('seed strip testid',               strpos($jsx, 'data-testid="rule-sandbox-seed-strip"') !== false);
$assert('seed button testid',              strpos($jsx, 'data-testid="rule-sandbox-seed-defaults"') !== false);
$assert('seed result testid',              strpos($jsx, 'data-testid="rule-sandbox-seed-result"') !== false);
$assert('seed error testid',               strpos($jsx, 'data-testid="rule-sandbox-seed-error"') !== false);
$assert('hits seed endpoint',              strpos($jsx, "/api/posting_rules_seed.php") !== false);
$assert('seedResult state hooks',          strpos($jsx, 'setSeedResult') !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
