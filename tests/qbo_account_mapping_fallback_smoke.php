<?php
/**
 * Smoke — QBO charter primitive #4 (account-mapping fallback).
 *
 * Locks the contract: qboResolveAccountRef must consult
 * `accounting_account_mappings` (operator-managed grid from migration
 * 098, same one Jaz uses) before hitting QBO's auto-discover query —
 * because the discovery API is slow + rate-limited, and the operator
 * may have pre-mapped accounts that don't share the AcctNum
 * convention.
 *
 * Run: php -d zend.assertions=1 /app/tests/qbo_account_mapping_fallback_smoke.php
 */
declare(strict_types=1);

$passes = 0; $failures = [];
function check(string $label, bool $cond) {
    global $passes, $failures;
    if ($cond) { $passes++; echo "  ✓ {$label}\n"; }
    else       { $failures[] = $label; echo "  ✗ {$label}\n"; }
}

echo "\nQBO charter primitive #4 smoke\n";
echo "==============================\n\n";

$src = (string) file_get_contents('/app/core/qbo/sync_je.php');

echo "── source-level wiring ──\n";
check('imports account_mapping_service',
    str_contains($src, "require_once __DIR__ . '/../accounting/account_mapping_service.php'"));
check('consults accountingAccountMappingLookup',
    str_contains($src, "accountingAccountMappingLookup(\$tenantId, 0, 'qbo', \$accountId)"));
check('fallback gated to function_exists check (defensive)',
    str_contains($src, "function_exists('accountingAccountMappingLookup')"));
check('uses provider_account_id from the mapping row',
    str_contains($src, "\$opMap['provider_account_id']"));
check('uses provider_account_name for the friendly name',
    str_contains($src, "\$opMap['provider_account_name']"));
check('fallback backfills mappingUpsert for fast path',
    preg_match('/mappingUpsert\(.*?accounting_account_mappings/s', $src) === 1);
check('backfill failure does NOT break the resolver (try/catch swallows)',
    preg_match('/try \{\s*mappingUpsert.*?\} catch \(\\\\Throwable \$_\)/s', $src) === 1);
check('fallback runs BEFORE the QBO auto-discover query',
    (strpos($src, 'accountingAccountMappingLookup') < strpos($src, 'qboConnection($tenantId)')));
check('returns the mapped value when found (does not fall through)',
    preg_match("/return \['value' => \(string\) \\\$opVal/", $src) === 1);

echo "\n── existing behaviour preserved ──\n";
check('qboResolveAccountRef function still defined',
    str_contains($src, 'function qboResolveAccountRef('));
check('mappingFindExternal fast path still first',
    (strpos($src, 'mappingFindExternal') < strpos($src, 'accountingAccountMappingLookup')));
check('auto-discover query still runs when mapping table empty',
    str_contains($src, 'select Id, Name, AcctNum from Account'));

$total = $passes + count($failures);
echo "\n=========================================\n";
echo "qbo_account_mapping_fallback smoke: {$passes} ✓ / " . count($failures) . " ✗\n";
echo "=========================================\n";
foreach ($failures as $msg) echo "  FAIL: {$msg}\n";
exit($failures ? 1 : 0);
