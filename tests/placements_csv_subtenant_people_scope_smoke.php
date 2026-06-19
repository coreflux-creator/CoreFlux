<?php
/**
 * Smoke — Placements CSV importer resolves person_id under the
 * *people* module scope, not the placements scope (or raw session
 * tenant_id).
 *
 * Real-world failure this guards against:
 *   1. Master tenant 1 owns People. Sub-tenant 7 inherits via the
 *      default `'people' => 'shared'` policy.
 *   2. Operator logs in to sub-tenant 7, opens People — IdBadge shows
 *      P-114, P-115 (rows that live in tenant_id=1).
 *   3. Operator pastes those ids into a placements CSV upload.
 *   4. Previous bug: importer ran `SELECT id FROM people WHERE
 *      tenant_id = 7 AND id IN (114, 115)` → zero matches → every
 *      row reported "not found in this tenant's People".
 *
 * Fix locked in:
 *   - require_once core/sub_tenants.php at the top of csv_import.php
 *   - dry_run uses `effectiveTenantIdForModule('people')` for the
 *     people lookup tenant (line ~138)
 *   - commit replaces both `scopedFind(... FROM people ...)` calls
 *     with raw prepared statements bound to
 *     `effectiveTenantIdForModule('people')` (line ~303 / ~317)
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

$root = dirname(__DIR__);
$csvImportPath = $root . '/modules/placements/api/csv_import.php';
$subTenantsPath = $root . '/core/sub_tenants.php';
$svc = (string) file_get_contents($csvImportPath);

echo "\n1. sub_tenants.php is loaded so effectiveTenantIdForModule() is callable\n";
$a('require_once core/sub_tenants.php present',
    str_contains($svc, "require_once __DIR__ . '/../../../core/sub_tenants.php'"));

echo "\n2. dry_run uses people-module-effective tenant for lookups\n";
$a('dry_run resolves people tenant via effectiveTenantIdForModule(\'people\')',
    str_contains($svc, "\$tid = effectiveTenantIdForModule('people') ?? currentTenantId();"));
$a('dry_run no longer uses raw currentTenantId() for the people lookup',
    !preg_match('/if\s*\(\$result\[\'rows\'\]\)\s*\{\s*\$pdo\s*=\s*getDB\(\);\s*\$tid\s*=\s*currentTenantId\(\);/', $svc));
$a('dry_run lookup comment explains the shared-scope rationale',
    str_contains($svc, 'IdBadge')
    && str_contains($svc, "every row misses with \"not found in this"));

echo "\n3. commit binds tenant explicitly via people module scope\n";
$a('commit id-lookup uses effectiveTenantIdForModule(\'people\') and getDB() prepared statement',
    str_contains($svc, "\$peopleTid = effectiveTenantIdForModule('people') ?? currentTenantId();")
    && str_contains($svc, "\$stmt = getDB()->prepare(\n                'SELECT id FROM people WHERE tenant_id = :tenant_id AND id = :pid AND deleted_at IS NULL'"));
$a('commit email-lookup also binds via effectiveTenantIdForModule(\'people\')',
    substr_count($svc, "\$peopleTid = effectiveTenantIdForModule('people') ?? currentTenantId();") >= 2
    && str_contains($svc, "'SELECT id FROM people WHERE tenant_id = :tenant_id AND LOWER(TRIM(email_primary)) = :email AND deleted_at IS NULL'"));
$a('commit no longer routes person lookups through plain scopedFind (placements URL scope)',
    !preg_match("/\\\$person\\s*=\\s*scopedFind\\(\\s*'SELECT id FROM people /", $svc));

echo "\n4. effectiveTenantIdForModule() default policy still maps people→shared\n";
require_once $subTenantsPath;
$a('SUBTENANT_MODULE_SCOPE_DEFAULTS[\'people\'] === \'shared\'',
    (defined('SUBTENANT_MODULE_SCOPE_DEFAULTS')
     ? (SUBTENANT_MODULE_SCOPE_DEFAULTS['people'] ?? null)
     : null) === 'shared');

echo "\n5. PHP syntax\n";
$out = []; $rc = 0;
exec('php -l ' . escapeshellarg($csvImportPath) . ' 2>&1', $out, $rc);
$a('php -l modules/placements/api/csv_import.php', $rc === 0, implode("\n", $out));

echo "\n=========================================\n";
echo "Placements CSV sub-tenant people-scope smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
