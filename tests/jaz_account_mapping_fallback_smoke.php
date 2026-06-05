<?php
/**
 * Smoke — Jaz outbox account-mapping fallback.
 *
 * The bug: `_accLookupJazResourceId` ONLY consulted
 * `accounting_destination_links` even though migration 098 created
 * `accounting_account_mappings` specifically to let the operator
 * declare CoA↔Jaz mappings ahead of any push. Result: the very first
 * JE that referenced an unmapped account hard-failed with
 * "account #N is not linked to Jaz" and the outbox got stuck in
 * Retrying forever — even when the operator had already filled out
 * the mapping grid in JazIntegrationSettings.jsx.
 *
 * Fix: when the destination_links lookup misses on an `account`
 * row, fall back to `accounting_account_mappings.provider_account_id`
 * and backfill the destination_links row so future lookups are fast.
 *
 * This smoke uses a real SQLite-shaped in-memory PDO so we can exercise
 * the SQL path end-to-end without booting the full MySQL stack.
 *
 * Run: php -d zend.assertions=1 /app/tests/jaz_account_mapping_fallback_smoke.php
 */
declare(strict_types=1);

$passes  = 0;
$failures = [];
function check(string $label, bool $cond) {
    global $passes, $failures;
    if ($cond) { $passes++; echo "  ✓ {$label}\n"; }
    else       { $failures[] = $label; echo "  ✗ {$label}\n"; }
}

echo "\nJaz account-mapping fallback smoke\n";
echo "==================================\n\n";

// 1) Source-code shape checks (cheap + reliable).
$mapperSrc = file_get_contents(__DIR__ . '/../core/accounting/jaz_payload_mapper.php');

echo "── jaz_payload_mapper.php contract ──\n";
check('still throws AccountingAdapterValidationException for unknown links',
    str_contains($mapperSrc, "is not linked to Jaz"));
check('imports the account_mapping_service',
    str_contains($mapperSrc, "require_once __DIR__ . '/account_mapping_service.php'"));
check('falls back to accountingAccountMappingLookup for account types',
    str_contains($mapperSrc, "accountingAccountMappingLookup(\$tenantId, \$subTenantId, 'jaz', \$corefluxObjectId)"));
check('fallback gated to coreflux_object_type === account',
    preg_match("/if \(\\\$corefluxObjectType === 'account'\)/", $mapperSrc) === 1);
check('fallback backfills destination_links so next lookup is fast',
    str_contains($mapperSrc, 'INSERT IGNORE INTO accounting_destination_links'));
check('fallback idempotency key is stable per account',
    str_contains($mapperSrc, "'mapping-fallback:account:' . \$corefluxObjectId"));
check('fallback returns the mapped provider_account_id',
    preg_match('/return \(string\) \$providerId;/', $mapperSrc) === 1);

// 2) Real PDO exercise. Stand up SQLite, install the relevant table
//    shapes, and run the resolver through both the hit path and the
//    fallback path.
echo "\n── resolver behaviour against a real PDO ──\n";

// Bootstrap a SQLite PDO and have the rest of the codebase use it.
$pdo = new \PDO('sqlite::memory:');
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE accounting_destination_links (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INT, sub_tenant_id INT, provider TEXT,
  provider_org_id TEXT, coreflux_object_type TEXT, coreflux_object_id INT,
  provider_object_type TEXT, provider_object_id TEXT,
  source_system TEXT, source_object_id TEXT,
  sync_status TEXT DEFAULT 'pending', idempotency_key TEXT
)");
$pdo->exec("CREATE TABLE accounting_account_mappings (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INT, sub_tenant_id INT, provider TEXT,
  coreflux_account_id INT, coreflux_account_code TEXT,
  provider_account_id TEXT, provider_account_code TEXT,
  provider_account_name TEXT, provider_account_type TEXT,
  confidence INT DEFAULT 100, source TEXT DEFAULT 'manual',
  notes TEXT, last_synced_at TEXT, created_by_user_id INT,
  created_at TEXT, updated_at TEXT
)");

// Stub getDB() to return our in-memory PDO + minimal exception class.
if (!function_exists('getDB')) {
    function getDB(): \PDO { global $pdo; return $pdo; }
}
if (!class_exists('AccountingAdapterValidationException')) {
    class AccountingAdapterValidationException extends \RuntimeException {}
}

// Load just the two functions we need without pulling provider_adapter.
$mapperBody = file_get_contents(__DIR__ . '/../core/accounting/jaz_payload_mapper.php');
$lookupBody = preg_match('/function _accLookupJazResourceId.*?\n\}\n/s', $mapperBody, $m1) ? $m1[0] : '';
eval('function accountingAccountMappingLookup(int $tenantId, int $subTenantId, string $provider, int $cfId): ?array {
    $stmt = getDB()->prepare("SELECT provider_account_id, provider_account_code, provider_account_name, confidence
        FROM accounting_account_mappings
        WHERE tenant_id = :t AND sub_tenant_id = :st AND provider = :p AND coreflux_account_id = :cfid LIMIT 1");
    $stmt->execute(["t"=>$tenantId,"st"=>$subTenantId,"p"=>$provider,"cfid"=>$cfId]);
    $r = $stmt->fetch(\PDO::FETCH_ASSOC);
    return $r ?: null;
}');
eval(preg_replace('/^\s*<\?php/', '', $lookupBody) ?: '');

// Case A — destination_links HIT (legacy fast path).
$pdo->exec("INSERT INTO accounting_destination_links
  (tenant_id, sub_tenant_id, provider, coreflux_object_type, coreflux_object_id,
   provider_object_type, provider_object_id, sync_status, idempotency_key)
   VALUES (1, 1, 'jaz', 'account', 50, 'account', 'JAZ-RID-50', 'posted', 'legacy-50')");
try {
    $rid = _accLookupJazResourceId(1, 1, 'account', 50);
    check("destination_links hit returns provider_object_id", $rid === 'JAZ-RID-50');
} catch (\Throwable $e) {
    check("destination_links hit returns provider_object_id (got exception: {$e->getMessage()})", false);
}

// Case B — account_mappings fallback. No destination_links row yet for #81;
// mapping table has it. Mirrors the bug the user hit on JEs #109 / #81.
$pdo->exec("INSERT INTO accounting_account_mappings
  (tenant_id, sub_tenant_id, provider, coreflux_account_id, coreflux_account_code,
   provider_account_id, provider_account_code, provider_account_name)
   VALUES (1, 1, 'jaz', 81, '1002', 'JAZ-ACC-81', '1002', 'Cash')");
try {
    $rid = _accLookupJazResourceId(1, 1, 'account', 81);
    check("account_mappings fallback returns provider_account_id", $rid === 'JAZ-ACC-81');
} catch (\Throwable $e) {
    check("account_mappings fallback returns provider_account_id (got exception: {$e->getMessage()})", false);
}

// Case B.1 — backfill is best-effort. Under MySQL it succeeds and
// future calls hit the fast path. Under SQLite the `INSERT IGNORE`
// syntax isn't supported and the (caught) exception is swallowed —
// proving the resolver still returns correctly when the backfill
// fails. We re-call without touching the mapping table and assert
// the lookup is still successful.
try {
    $rid = _accLookupJazResourceId(1, 1, 'account', 81);
    check("backfill failure does NOT break the resolver", $rid === 'JAZ-ACC-81');
} catch (\Throwable $e) {
    check("backfill failure does NOT break the resolver (exc: {$e->getMessage()})", false);
}

// Case C — neither table has the account → loud failure with the
// original error message (operator hasn't mapped yet).
try {
    _accLookupJazResourceId(1, 1, 'account', 9999);
    check("unmapped account still throws Validation", false);
} catch (AccountingAdapterValidationException $e) {
    check("unmapped account still throws Validation", str_contains($e->getMessage(), 'is not linked to Jaz'));
}

// Case D — vendor / customer types still REQUIRE destination_links
// (we deliberately scoped the fallback to 'account' only). #42 has no
// links → must throw, not silently succeed.
try {
    _accLookupJazResourceId(1, 1, 'vendor', 42);
    check("vendor lookup with no link still throws", false);
} catch (AccountingAdapterValidationException $e) {
    check("vendor lookup with no link still throws", true);
}

$total = $passes + count($failures);
echo "\n=========================================\n";
echo "jaz_account_mapping_fallback smoke: {$passes} ✓ / " . count($failures) . " ✗\n";
echo "=========================================\n";
if ($failures) {
    foreach ($failures as $msg) echo "  FAIL: {$msg}\n";
    exit(1);
}
exit(0);
