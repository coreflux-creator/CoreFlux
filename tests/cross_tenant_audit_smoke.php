<?php
/**
 * Cross-tenant accounting audit smoke (2026-02).
 *
 *   1. Migration table + indexes exist.
 *   2. crossTenantAuditLog() helper exists and skips same-tenant edges.
 *   3. consolidation lib + intercompany lib fire the helper after upsert.
 *   4. /api/admin/cross_tenant_audit.php endpoint shape, scope guard.
 *   5. CrossTenantAuditAdmin.jsx + AdminModule route + tile.
 *
 *   php -d zend.assertions=1 /app/tests/cross_tenant_audit_smoke.php
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};

// 1. Migration ---------------------------------------------------------------
$mig = $ROOT . '/core/migrations/062_cross_tenant_accounting_audit.sql';
$a('migration file exists', file_exists($mig));
$src = (string) file_get_contents($mig);
$a('creates cross_tenant_accounting_audit table',
    str_contains($src, 'CREATE TABLE IF NOT EXISTS cross_tenant_accounting_audit'));
$a('table has acting/left/right tenant_id columns',
    str_contains($src, 'acting_tenant_id') && str_contains($src, 'left_tenant_id') && str_contains($src, 'right_tenant_id'));
$a('table has indexes for filter columns',
    str_contains($src, 'idx_xtaudit_acting') && str_contains($src, 'idx_xtaudit_left') && str_contains($src, 'idx_xtaudit_right'));

// 2. Helper ------------------------------------------------------------------
require_once $ROOT . '/core/cross_tenant_audit.php';
$a('crossTenantAuditLog() defined',          function_exists('crossTenantAuditLog'));
$a('crossTenantAuditEntityTenantId() defined', function_exists('crossTenantAuditEntityTenantId'));

// 3. Lib wiring --------------------------------------------------------------
$consol = (string) file_get_contents($ROOT . '/modules/accounting/lib/consolidation.php');
$a('consolidation lib fires _consolidationLogCrossTenant on create',
    str_contains($consol, "_consolidationLogCrossTenant(\$tenantId, \$p, \$c, \$row, \$id, 'created')"));
$a('consolidation lib fires _consolidationLogCrossTenant on update',
    str_contains($consol, "_consolidationLogCrossTenant(\$tenantId, \$p, \$c, \$row, (int) \$existing['id'], 'updated')"));
$a('consolidation helper requires core/cross_tenant_audit.php',
    str_contains($consol, "require_once __DIR__ . '/../../../core/cross_tenant_audit.php'"));

$ic = (string) file_get_contents($ROOT . '/modules/accounting/lib/intercompany.php');
$a('intercompany lib fires _intercompanyLogCrossTenant on both branches',
    substr_count($ic, '_intercompanyLogCrossTenant(') >= 2);
$a('intercompany helper requires core/cross_tenant_audit.php',
    str_contains($ic, "require_once __DIR__ . '/../../../core/cross_tenant_audit.php'"));

// 4. Endpoint ----------------------------------------------------------------
$ep = (string) file_get_contents($ROOT . '/api/admin/cross_tenant_audit.php');
$a('endpoint exists and is auth-gated',         str_contains($ep, 'api_require_auth(false)'));
$a('endpoint rejects non-admin (403)',          str_contains($ep, "'Forbidden — admins only'"));
$a('endpoint master_admin sees every tenant',   str_contains($ep, '$isPlatformMA'));
$a('endpoint scopes non-platform to own tenant',
    (bool) preg_match('/a\.acting_tenant_id\s*=\s*:tid\s*OR\s*a\.left_tenant_id\s*=\s*:tid\s*OR\s*a\.right_tenant_id\s*=\s*:tid/', $ep));
$a('endpoint validates `since` ISO date (422)', str_contains($ep, "Invalid 'since' date"));
$a('endpoint caps limit at 500',                (bool) preg_match('/min\(500/', $ep));
$a('endpoint joins tenants for human-friendly names',
    str_contains($ep, 'left_tenant_name') && str_contains($ep, 'right_tenant_name'));

// 5. UI wiring ---------------------------------------------------------------
$ui = (string) file_get_contents($ROOT . '/dashboard/src/pages/CrossTenantAuditAdmin.jsx');
$a('UI page exists with data-testid="cross-tenant-audit"',
    str_contains($ui, 'data-testid="cross-tenant-audit"'));
$a('UI exposes action + since filters',
    str_contains($ui, 'xt-audit-action-filter') && str_contains($ui, 'xt-audit-since-filter'));
$a('UI renders the rows table',
    str_contains($ui, 'data-testid="xt-audit-table"'));

$adm = (string) file_get_contents($ROOT . '/dashboard/src/pages/AdminModule.jsx');
$a('AdminModule imports CrossTenantAuditAdmin',
    str_contains($adm, "import CrossTenantAuditAdmin from './CrossTenantAuditAdmin'"));
$a('AdminModule routes /admin/cross-tenant-audit',
    (bool) preg_match('/path="\/cross-tenant-audit"\s+element=\{<CrossTenantAuditAdmin/', $adm));
$a('AdminModule overview tile linking to /admin/cross-tenant-audit',
    str_contains($adm, 'href="/admin/cross-tenant-audit"'));

// 6. PHP syntax sanity -------------------------------------------------------
foreach ([
    'core/cross_tenant_audit.php',
    'api/admin/cross_tenant_audit.php',
    'modules/accounting/lib/consolidation.php',
    'modules/accounting/lib/intercompany.php',
] as $rel) {
    $rc = 0; $out = [];
    exec('php -l ' . escapeshellarg($ROOT . '/' . $rel) . ' 2>&1', $out, $rc);
    $a("php -l $rel", $rc === 0);
}

echo "\n=========================================\n";
echo "Cross-tenant accounting audit smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
