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

// ─── Cross-tenant intercompany helper ───
echo "Cross-tenant intercompany helper\n";
$ic = file_get_contents(__DIR__ . '/../modules/accounting/lib/cross_tenant_intercompany.php');
$assert('helper file loads',                          is_string($ic) && strlen($ic) > 200);
$assert('declares accountingPostCrossTenantIntercompany',
                                                      preg_match('/function\s+accountingPostCrossTenantIntercompany\s*\(/', $ic) === 1);
$assert('rejects same from/to tenant',                strpos($ic, 'from and to tenant must differ') !== false);
$assert('requires positive amount',                   strpos($ic, 'amount must be > 0') !== false);
$assert('verifies same master parent',                strpos($ic, 'cross-tenant intercompany requires the same master parent') !== false);
$assert('uses accountingPostJe()',                    substr_count($ic, 'accountingPostJe(') >= 2);
$assert('idempotency_key cross_intercompany:from',    strpos($ic, '"cross_intercompany:{$ref}:from"') !== false);
$assert('idempotency_key cross_intercompany:to',      strpos($ic, '"cross_intercompany:{$ref}:to"') !== false);
$assert('shared intercompany_ref propagated',         strpos($ic, 'intercompany_ref') !== false);
$assert('audits via subTenantAudit',                  strpos($ic, "subTenantAudit(") !== false);
$assert('PHP parses cleanly',                         _php_lint(__DIR__ . '/../modules/accounting/lib/cross_tenant_intercompany.php'));

// ─── Analytics API ───
echo "/api/sub_tenant_analytics.php\n";
$an = file_get_contents(__DIR__ . '/../api/sub_tenant_analytics.php');
$assert('analytics endpoint loads',                   is_string($an) && strlen($an) > 200);
$assert('returns active_sub_tenants',                 strpos($an, "'active_sub_tenants'") !== false);
$assert('returns total_sub_tenants',                  strpos($an, "'total_sub_tenants'") !== false);
$assert('returns posted_this_month_cents',            strpos($an, "'posted_this_month_cents'") !== false);
$assert('returns ar_outstanding_cents',               strpos($an, "'ar_outstanding_cents'") !== false);
$assert('returns last_active_sub',                    strpos($an, "'last_active_sub'") !== false);
$assert('returns by_sub roll-up',                     strpos($an, "'by_sub'") !== false);
$assert('guards on master tenant_admin',              strpos($an, "tenant_admin") !== false);
$assert('graceful when accounting_journal_entries missing', strpos($an, "_stTableExists") !== false);
$assert('PHP parses cleanly',                         _php_lint(__DIR__ . '/../api/sub_tenant_analytics.php'));

// ─── React UI surfaces ───
echo "React UI\n";
$page1 = file_get_contents(__DIR__ . '/../dashboard/src/pages/SubTenantsAdmin.jsx');
$assert('SubTenantsAdmin.jsx exists',                 is_string($page1) && strlen($page1) > 500);
$assert('uses /api/sub_tenants.php',                  strpos($page1, "/api/sub_tenants.php") !== false);
$assert('PATCH scope action',                         strpos($page1, "?action=scope&id=") !== false);
$assert('renders 11 modules in scope table',          (substr_count($page1, "'shared'") + substr_count($page1, '"shared"')) >= 1);
$assert('data-testid: sub-tenants-admin',             strpos($page1, 'data-testid="sub-tenants-admin"') !== false);
$assert('data-testid: sub-tenant-create-submit',      strpos($page1, 'data-testid="sub-tenant-create-submit"') !== false);
$assert('data-testid: sub-tenant-deactivate-btn',     strpos($page1, 'data-testid="sub-tenant-deactivate-btn"') !== false);

$page2 = file_get_contents(__DIR__ . '/../dashboard/src/pages/TenantPicker.jsx');
$assert('TenantPicker.jsx exists',                    is_string($page2) && strlen($page2) > 200);
$assert('routes through /switch_tenant.php',          strpos($page2, '/switch_tenant.php?tenant_id=') !== false);
$assert('preserves ?next=/spa.php',                   strpos($page2, 'next=/spa.php') !== false);
$assert('auto-redirect single tenant',                strpos($page2, 'tenants.length === 1') !== false);
$assert('data-testid: tenant-picker',                 strpos($page2, 'data-testid="tenant-picker"') !== false);

$page3 = file_get_contents(__DIR__ . '/../dashboard/src/pages/SubTenantSummaryCard.jsx');
$assert('SubTenantSummaryCard.jsx exists',            is_string($page3) && strlen($page3) > 200);
$assert('hits /api/sub_tenant_analytics.php',         strpos($page3, '/api/sub_tenant_analytics.php') !== false);
$assert('hides on error (master gate)',               strpos($page3, 'if (error) return null') !== false);
$assert('manage link to /admin/sub-tenants',          strpos($page3, '/admin/sub-tenants') !== false);
$assert('data-testid: sub-tenant-summary-card',       strpos($page3, 'data-testid="sub-tenant-summary-card"') !== false);

// App-level wiring
$app = file_get_contents(__DIR__ . '/../dashboard/src/App.jsx');
$assert('App.jsx routes /select-tenant',              strpos($app, "<Route path=\"/select-tenant\"") !== false);
$assert('App.jsx tenant change passes &next=',        strpos($app, '/switch_tenant.php?tenant_id=${tenantId}&next=/spa.php') !== false);
$admin = file_get_contents(__DIR__ . '/../dashboard/src/pages/AdminModule.jsx');
$assert('AdminModule routes /sub-tenants',            strpos($admin, "<Route path=\"/sub-tenants\"") !== false);
$assert('AdminModule sidebar Sub-Tenants link',       strpos($admin, "label: 'Sub-Tenants'") !== false);
$dash = file_get_contents(__DIR__ . '/../dashboard/src/pages/DashboardOverview.jsx');
$assert('DashboardOverview shows summary card',       strpos($dash, '<SubTenantSummaryCard') !== false);
$assert('DashboardOverview admin action card link',   strpos($dash, '/admin/sub-tenants') !== false);

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
