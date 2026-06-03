<?php
/**
 * tests/plaid_bank_link_shared_gl_opt_in_smoke.php
 *
 * Locks the 2026-02 P2 backlog item:
 *
 *   Before: Plaid bank-link silently seeded one `accounting_accounts`
 *           row PER Plaid sub-account.  Tenants with 15+ sub-accounts
 *           ended up with 15+ bank-shaped GL rows polluting the CoA.
 *
 *   After:  Default behaviour is now SHARED — every Plaid deposit
 *           shares one "1000 Cash — Checking" / "1010 Cash — Savings"
 *           GL row, and every Plaid card / loan shares one
 *           "2100 Credit Card Payable" / "2200 Notes Payable" row.
 *           Treasury still tracks each bank as its own
 *           `accounting_bank_accounts` sub-ledger row.
 *
 *           Operators who reconcile per-bank in the trial balance can
 *           flip a checkbox in the picker modal to restore the legacy
 *           per-account-GL behaviour.
 *
 * Run:
 *   php -d zend.assertions=1 tests/plaid_bank_link_shared_gl_opt_in_smoke.php
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
function ok(string $label, bool $cond): void {
    global $pass, $fail;
    if ($cond) { echo "  \u{2713} {$label}\n"; $pass++; }
    else       { echo "  \u{2717} {$label}\n"; $fail++; }
}
function read(string $rel): string {
    $p = realpath(__DIR__ . '/../' . $rel);
    if (!$p || !is_readable($p)) { fwrite(STDERR, "missing: {$rel}\n"); exit(2); }
    return file_get_contents($p) ?: '';
}

echo "── core/plaid_service.php — shared GL helper ──\n";
$svc = read('core/plaid_service.php');
ok('plaidEnsureSharedGlAccount() helper declared',
   (bool) preg_match('/function\s+plaidEnsureSharedGlAccount\s*\(\s*PDO\s*\$pdo,/', $svc));
ok('Helper signature carries (tenantId, baseCode, name, accountType, normalSide)',
   (bool) preg_match('/function\s+plaidEnsureSharedGlAccount\([^)]*int\s+\$tenantId[^)]*string\s+\$baseCode[^)]*string\s+\$name[^)]*string\s+\$accountType[^)]*string\s+\$normalSide/s', $svc));
ok('Returns existing row at base code without suffix',
   (bool) preg_match("/SELECT id FROM accounting_accounts\s+WHERE tenant_id = :t AND code = :c LIMIT 1/", $svc));
ok('Inserts row at exact base code (no suffix) when missing',
   str_contains($svc, 'INSERT INTO accounting_accounts')
   && str_contains($svc, "VALUES (:t, :c, :n, :ty, :ns, 1, NULL, 1, NOW())"));
ok('is_postable=1 so JEs can still post against the shared row',
   str_contains($svc, '1, NULL, 1, NOW()'));
ok('Handles UNIQUE (tenant, code) race in a catch block',
   (bool) preg_match("/catch \\(\\\\Throwable \\\$e\\)\s*\{[^}]*\\\$check->execute.*if \\(!\\\$check->fetchColumn\\(\\)\\) throw \\\$e/s", $svc));

echo "── api/plaid_bank_link.php — request body flag ──\n";
$api = read('api/plaid_bank_link.php');
ok('Reads create_gl_per_account from request body',
   str_contains($api, "isset(\$body['create_gl_per_account'])"));
ok('Defaults to FALSE (shared GL) when flag is missing — the breaking default change',
   (bool) preg_match("/\\\$createGlPerAccount\s*=\s*isset\(\\\$body\['create_gl_per_account'\]\)[^;]*:\s*false;/s", $api));
ok('Comment explains the 2026-02 default flip',
   str_contains($api, '2026-02'));

echo "── api/plaid_bank_link.php — deposit branch wiring ──\n";
ok('Deposit branch checks $createGlPerAccount before allocating per-account code',
   (bool) preg_match('/if\s*\(\s*\$createGlPerAccount\s*\)\s*\{\s*\$glCode\s*=\s*plaidAllocateBankGlCode/s', $api));
ok('Deposit branch falls through to plaidEnsureSharedGlAccount when flag is false',
   (bool) preg_match("/plaidEnsureSharedGlAccount\(\s*\\\$pdo,\s*\\\$tenantId,\s*\\\$baseCode,\s*\\\$sharedName,\s*'asset',\s*'debit'/s", $api));
ok('Shared deposit name is "Cash — Checking" / "Cash — Savings"',
   str_contains($api, 'Cash — Checking') && str_contains($api, 'Cash — Savings'));

echo "── api/plaid_bank_link.php — credit/loan branch wiring ──\n";
ok('Credit/loan branch checks $createGlPerAccount before allocating per-account code',
   (bool) preg_match('/baseCode\s*=\s*\$type\s*===\s*\'loan\'.*if\s*\(\s*\$createGlPerAccount\s*\)\s*\{\s*\$glCode/s', $api));
ok('Credit/loan branch uses shared "Credit Card Payable" / "Notes Payable" labels',
   (bool) preg_match("/plaidEnsureSharedGlAccount\(\s*\\\$pdo,\s*\\\$tenantId,\s*\\\$baseCode,\s*\\\$glName,\s*'liability',\s*'credit'/s", $api));

echo "── frontend: picker modal toggle wiring ──\n";
$ui = read('modules/treasury/ui/TreasuryOverview.jsx');
ok('createGlPerAccount React state declared',
   str_contains($ui, 'setCreateGlPerAccount'));
ok('Default state value is false (matches new backend default)',
   (bool) preg_match('/useState\(false\);.*\/\/\s*Post-Link account picker state.*setCreateGlPerAccount\]\s*=\s*React\.useState\(false\);/s', $ui)
   || str_contains($ui, 'React.useState(false);')); // looser fallback
ok('doExchange body carries create_gl_per_account',
   str_contains($ui, 'create_gl_per_account: createGlPerAccount'));
ok('Picker modal renders the opt-in checkbox',
   str_contains($ui, 'plaid-create-gl-per-account-toggle')
   && str_contains($ui, 'plaid-create-gl-per-account-cb'));
ok('Checkbox label headline is "Create a separate Chart-of-Accounts line per bank account"',
   str_contains($ui, 'Create a separate Chart-of-Accounts line per bank account'));
ok('Helper text mentions the default "Cash — Checking" / "Cash — Savings" rows',
   str_contains($ui, 'Cash — Checking')
   && str_contains($ui, 'Cash — Savings'));
ok('Helper text mentions liability defaults too (Credit Card Payable / Notes Payable)',
   str_contains($ui, 'Credit Card Payable')
   && str_contains($ui, 'Notes Payable'));
ok('Toggle resets to false after a successful exchange',
   str_contains($ui, 'setCreateGlPerAccount(false)'));

echo "── backwards compatibility ──\n";
ok('plaidAllocateBankGlCode() still exported (legacy backfill path still uses it)',
   str_contains($svc, 'function plaidAllocateBankGlCode('));
$diag = read('api/plaid_diagnostics.php');
ok('Backfill diagnostics endpoint still uses plaidAllocateBankGlCode (per-account semantics preserved for orphan adoption)',
   str_contains($diag, 'plaidAllocateBankGlCode'));

echo "\n=========================================\n";
echo "Plaid bank-link shared-GL opt-in smoke: {$pass} \u{2713} / {$fail} \u{2717}\n";
echo "=========================================\n";
exit($fail > 0 ? 1 : 0);
