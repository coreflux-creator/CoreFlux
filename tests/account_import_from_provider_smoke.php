<?php
/**
 * tests/account_import_from_provider_smoke.php
 *
 * Locks the 2026-02 PULL = TRUE-COPY enhancement.  Previously
 * `accountingAccountMappingsAutoMap()` only mapped EXISTING CoreFlux
 * accounts to provider rows — provider accounts with no CF
 * counterpart were left invisible.  Operators expected pulling the
 * Jaz CoA to populate CoreFlux's CoA so they don't have to type 249
 * accounts manually.  This now happens in a third "import" pass.
 *
 * Coverage:
 *   1. New module `core/accounting/account_import.php` with
 *      `accountingImportProviderAccounts()`.
 *   2. Bucket-based code allocator: asset → 1001+, liability → 2001+,
 *      equity → 3001+, revenue → 4001+, expense → 5001+ (skips codes
 *      already taken in the tenant's CoA).
 *   3. Idempotency via the `consumed` set — never re-imports a
 *      provider_account_id that was either mapped by this run or by
 *      a prior run.
 *   4. Service-layer integration in
 *      `accountingAccountMappingsAutoMap()` — seeds `consumed` from
 *      `accountingAccountMappingsList()`, tracks consumed during
 *      auto-map, calls the importer last, returns
 *      `matched_by_import` in the envelope.
 *   5. UI surfacing: JazSyncNowCard summary now shows
 *      "imported new from Jaz: N" plus import_errors[] in the red
 *      error block; success flash mentions imports.
 *
 * Run:
 *   php -d zend.assertions=1 tests/account_import_from_provider_smoke.php
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

echo "── core/accounting/account_import.php — importer module ──\n";
$imp = read('core/accounting/account_import.php');
ok('Declares accountingImportProviderAccounts()',
   (bool) preg_match('/function\s+accountingImportProviderAccounts\(/', $imp));
ok('Signature accepts (tenantId, subTenantId, provider, providerAccounts, alreadyConsumed, userId)',
   (bool) preg_match('/int\s+\$tenantId,\s+int\s+\$subTenantId,\s+string\s+\$provider,\s+array\s+\$providerAccounts,\s+array\s+\$alreadyConsumed,\s+\?int\s+\$userId/s', $imp));
ok('Pre-loads taken CF codes in one round-trip',
   str_contains($imp, "SELECT code FROM accounting_accounts WHERE tenant_id = :t"));
ok('Per-bucket cursor starts at +1 (skips bucket base 1000)',
   str_contains($imp, '$cursor[$b] = 1; // start at +1'));
ok('Skips providerAccounts present in $alreadyConsumed set (idempotent)',
   str_contains($imp, "if (!empty(\$alreadyConsumed[\$pid])) continue;"));
ok('Bucket base codes asset=1000 / liability=2000 / equity=3000 / revenue=4000 / expense=5000',
   str_contains($imp, "'asset'     => 1000")
   && str_contains($imp, "'liability' => 2000")
   && str_contains($imp, "'equity'    => 3000")
   && str_contains($imp, "'revenue'   => 4000")
   && str_contains($imp, "'expense'   => 5000"));
ok('Normal-side defaults match accounting convention',
   str_contains($imp, "'asset'     => 'debit'")
   && str_contains($imp, "'liability' => 'credit'")
   && str_contains($imp, "'revenue'   => 'credit'")
   && str_contains($imp, "'expense'   => 'debit'"));
ok('Rejects unknown account_type buckets with structured error row',
   str_contains($imp, 'unrecognized account_type'));
ok('Rejects provider rows missing name',
   str_contains($imp, 'provider row missing name'));
ok('Walks bucket cursor with collision skip (do/while)',
   str_contains($imp, '$cursor[$bucket]++') && str_contains($imp, 'isset($taken[strtolower($candidate)])'));
ok('Caps allocator at 9999 attempts to avoid infinite loops',
   str_contains($imp, 'tries++ > 9999'));
ok('Uses provider native code when available (truncated to 40 chars)',
   str_contains($imp, "\$code = substr(\$providerCode, 0, 40)"));
ok('Falls back to bucket allocator on provider-code collision',
   str_contains($imp, 'fall through to bucket-allocator')
   && str_contains($imp, "\$code = '';"));
ok('INSERTs into accounting_accounts with active=1, is_postable=1, NULL parent',
   str_contains($imp, 'INSERT INTO accounting_accounts')
   && str_contains($imp, 'NULL, 1, :cur, :d, 1, NOW(), NOW()'));
ok('Saves mapping with source=imported, confidence=100',
   str_contains($imp, "'source'                => 'imported'")
   && str_contains($imp, "'confidence'            => 100"));
ok('Rolls back the INSERT if the mapping save fails',
   str_contains($imp, 'DELETE FROM accounting_accounts WHERE id = :id AND tenant_id = :t LIMIT 1')
   && str_contains($imp, "'mapping save failed: '"));
ok('Returns {imported, errors, allocated_codes}',
   str_contains($imp, "'imported'        => \$imported")
   && str_contains($imp, "'errors'          => \$errors")
   && str_contains($imp, "'allocated_codes' => \$allocatedCodes"));

echo "── account_mapping_service.php — integration wiring ──\n";
$svc = read('core/accounting/account_mapping_service.php');
ok('Seeds $consumed set from existing mappings (prevents double-import)',
   (bool) preg_match('/\$existing\s*=\s*accountingAccountMappingsList\(\$tenantId,\s*\$subTenantId,\s*\$provider\);\s*foreach\s*\(\$existing\s+as\s+\$m\)/s', $svc));
ok('Records consumed[$pid] = true after every successful CF→provider mapping',
   str_contains($svc, '$consumed[$pid] = true;'));
ok('Loads account_import.php and calls the importer',
   str_contains($svc, "require_once __DIR__ . '/account_import.php'")
   && str_contains($svc, 'accountingImportProviderAccounts('));
ok('Adds matched_by_import to the response envelope',
   str_contains($svc, "'matched_by_import'  => \$matchedByImport"));
ok('Reports import_errors[] when importer surfaces failures',
   str_contains($svc, "\$out['import_errors'] = \$importErrors;"));
ok('Mapped total now includes imports (count(newMappings)+matchedByImport)',
   (bool) preg_match("/'mapped'\s+=>\s+count\(\\\$newMappings\)\s+\+\s+\\\$matchedByImport/", $svc));
ok('Note mentions "imported N new accounts from PROVIDER" when imports happened',
   str_contains($svc, 'imported {$matchedByImport} new account')
   && str_contains($svc, 'strtoupper($provider)'));

echo "── JazSyncNowCard UI surfacing ──\n";
$ui = read('dashboard/src/pages/JazIntegrationSettings.jsx');
ok('Telemetry block shows "imported new from Jaz" counter',
   str_contains($ui, 'imported new from Jaz:'));
ok('Telemetry block shows "no match (still unmapped)" counter',
   str_contains($ui, 'no match (still unmapped):'));
ok('Import errors pipe into the red errorList block (per-row codes "import …")',
   str_contains($ui, 'pullR.import_errors') && str_contains($ui, "code: `import "));
ok('Success flash mentions imports — "N mapped (M imported into CoreFlux)"',
   str_contains($ui, 'mapped (${imp} imported into CoreFlux)'));
ok('Error counter includes import_errors',
   str_contains($ui, 'coa?.pull?.import_errors?.length'));

echo "\n=========================================\n";
echo "Account-import-from-provider smoke: {$pass} \u{2713} / {$fail} \u{2717}\n";
echo "=========================================\n";
exit($fail > 0 ? 1 : 0);
