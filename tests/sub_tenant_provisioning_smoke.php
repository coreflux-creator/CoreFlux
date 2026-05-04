<?php
/**
 * Sub-Tenant Provisioning smoke test
 *
 * Static contract checks (no live DB):
 *   - Migration `007_subtenant_provisioning.sql` shape
 *   - `core/sub_tenants.php` defaults + helper signatures + scope resolver
 *   - `/api/sub_tenants.php` exposes expected actions + parses
 *   - `/switch_tenant.php` rewritten to set $_SESSION['tenant_id']
 *
 * Live-MySQL integration (provision happy-path, scope set/get, audit row,
 * cross-tenant journal posting) is deferred to the deploy-time smoke
 * harness once the migration runs on Cloudways.
 */

declare(strict_types=1);

$pass = 0;
$fail = 0;
$assert = function (string $name, $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ {$name}\n"; $pass++; }
    else       { echo "  ✗ {$name}\n"; $fail++; }
};

// ─── Migration shape ───
echo "Migration 007 shape\n";
$mig = file_get_contents(__DIR__ . '/../core/migrations/007_subtenant_provisioning.sql');
$assert('migration file loads',                       is_string($mig) && strlen($mig) > 200);
$assert('adds tenants.tenant_type',                   strpos($mig, "tenant_type ENUM(''master'',''sub'')") !== false);
$assert('adds tenants.is_active',                     strpos($mig, 'is_active TINYINT(1)') !== false);
$assert('adds user_tenants.last_active_at',           strpos($mig, 'last_active_at DATETIME') !== false);
$assert('creates tenant_module_scope',                strpos($mig, 'CREATE TABLE IF NOT EXISTS tenant_module_scope') !== false);
$assert('scope_mode ENUM declared',                   strpos($mig, "scope_mode ENUM('shared','isolated')") !== false);
$assert('uniqueness on (tenant,module)',              strpos($mig, 'uq_tms_tenant_module') !== false);
$assert('creates tenant_provisioning_log',            strpos($mig, 'CREATE TABLE IF NOT EXISTS tenant_provisioning_log') !== false);
$assert('idempotent guard (information_schema)',      strpos($mig, 'information_schema.columns') !== false);
$assert('utf8mb4_unicode_ci',                         strpos($mig, 'utf8mb4_unicode_ci') !== false);

// ─── Library contract (parse-only; no live DB needed) ───
echo "core/sub_tenants.php contract\n";
$lib = file_get_contents(__DIR__ . '/../core/sub_tenants.php');
$assert('SUBTENANT_MODULE_SCOPE_DEFAULTS const',      strpos($lib, 'SUBTENANT_MODULE_SCOPE_DEFAULTS') !== false);
$assert("default people => 'shared'",                 preg_match("/'people'\s*=>\s*'shared'/", $lib) === 1);
$assert("default placements => 'shared'",             preg_match("/'placements'\s*=>\s*'shared'/", $lib) === 1);
$assert("default companies => 'shared'",              preg_match("/'companies'\s*=>\s*'shared'/", $lib) === 1);
$assert("default billing => 'isolated'",              preg_match("/'billing'\s*=>\s*'isolated'/", $lib) === 1);
$assert("default ap => 'isolated'",                   preg_match("/'ap'\s*=>\s*'isolated'/", $lib) === 1);
$assert("default accounting => 'isolated'",           preg_match("/'accounting'\s*=>\s*'isolated'/", $lib) === 1);
$assert("default payroll => 'isolated'",              preg_match("/'payroll'\s*=>\s*'isolated'/", $lib) === 1);
$assert("default treasury => 'isolated'",             preg_match("/'treasury'\s*=>\s*'isolated'/", $lib) === 1);

$expectedFns = [
    'effectiveTenantIdForModule',
    'subTenantScopeMode',
    'subTenantLookup',
    'subTenantProvision',
    'subTenantDeactivate',
    'subTenantScopeSet',
    'subTenantScopeMap',
    'subTenantTouchLastActive',
    'subTenantLastActiveFor',
    'subTenantSlugify',
    'subTenantAudit',
];
foreach ($expectedFns as $fn) {
    $assert("function declared: {$fn}",               preg_match('/function\s+' . preg_quote($fn) . '\s*\(/', $lib) === 1);
}

// Now actually load the file so the constant + scope-resolver pure logic
// can be exercised. We skip DB-touching code paths.
require_once __DIR__ . '/../core/sub_tenants.php';
$assert('default scope: people=shared',               (SUBTENANT_MODULE_SCOPE_DEFAULTS['people'] ?? null) === 'shared');
$assert('default scope: billing=isolated',            (SUBTENANT_MODULE_SCOPE_DEFAULTS['billing'] ?? null) === 'isolated');
$assert('slugify normalises',                         subTenantSlugify('NY Engineering 2026!') === 'ny-engineering-2026');
$assert('slugify empty fallback',                     str_starts_with(subTenantSlugify(''), 'sub-'));

// Provision validation (rejects without parent or empty name; doesn't touch DB
// because we throw before the begin-transaction step).
try {
    subTenantProvision(0, ['name' => 'Foo']);
    $assert('provision rejects parent_id=0',          false);
} catch (Throwable $e) {
    $assert('provision rejects parent_id=0',          $e instanceof Throwable);
}

// ─── API endpoint surface (parse-only) ───
echo "/api/sub_tenants.php\n";
$api = file_get_contents(__DIR__ . '/../api/sub_tenants.php');
$assert('GET list path',                              strpos($api, "if (\$method === 'GET')") !== false);
$assert('POST create',                                strpos($api, "if (\$method === 'POST')") !== false);
$assert('PATCH update',                               strpos($api, "if (\$method === 'PATCH')") !== false);
$assert('DELETE deactivate',                          strpos($api, "if (\$method === 'DELETE')") !== false);
$assert('action=scope subresource',                   strpos($api, "if (\$action === 'scope')") !== false);
$assert('action=switch session writer',               strpos($api, "if (\$action === 'switch'") !== false);
$assert('uses subTenantProvision()',                  strpos($api, 'subTenantProvision(') !== false);
$assert('uses subTenantScopeSet()',                   strpos($api, 'subTenantScopeSet(') !== false);
$assert('uses subTenantTouchLastActive()',            strpos($api, 'subTenantTouchLastActive(') !== false);
$assert('forbids non-master_admin sibling mgmt',      strpos($api, "subTenantUserCanManageParent") !== false);
$assert('PHP parses cleanly',                         _php_lint(__DIR__ . '/../api/sub_tenants.php'));

// ─── switch_tenant.php rewrite ───
echo "switch_tenant.php\n";
$sw = file_get_contents(__DIR__ . '/../switch_tenant.php');
$assert("sets \$_SESSION['tenant_id']",               strpos($sw, "\$_SESSION['tenant_id']") !== false);
$assert("legacy alias active_tenant_id",              strpos($sw, "\$_SESSION['active_tenant_id']") !== false);
$assert('whitelists ?next= to local paths',           strpos($sw, "strncmp(\$nextPath, '/', 1) !== 0") !== false);
$assert('updates last_active_at',                     strpos($sw, 'subTenantTouchLastActive(') !== false);
$assert('rejects inactive tenants',                   strpos($sw, 'tenant_inactive') !== false);
$assert("default redirect = /spa.php",                strpos($sw, "= '/spa.php'") !== false);
$assert('PHP parses cleanly',                         _php_lint(__DIR__ . '/../switch_tenant.php'));

// ─── Module list coverage ───
echo "Module scope coverage\n";
$declaredModules = ['people','placements','companies','billing','ap','accounting','payroll','treasury','time','tax'];
foreach ($declaredModules as $m) {
    $assert("scope default declared: {$m}",
            isset(SUBTENANT_MODULE_SCOPE_DEFAULTS[$m])
            && in_array(SUBTENANT_MODULE_SCOPE_DEFAULTS[$m], ['shared','isolated'], true));
}

echo "\n";
echo "Pass: {$pass}\n";
echo "Fail: {$fail}\n";
exit($fail === 0 ? 0 : 1);

function _php_lint(string $path): bool {
    $output = [];
    $rc = 0;
    @exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $rc);
    return $rc === 0;
}
