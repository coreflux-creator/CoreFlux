<?php
/**
 * Master-tenant CRUD smoke test.
 * Validates:
 *   - /api/tenants.php endpoint surface (master_admin gated)
 *   - MasterTenantsAdmin.jsx wired into AdminModule
 *   - Legacy /core/views/admin/tenant_edit.php redirected to SPA
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $name, $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ {$name}\n"; $pass++; } else { echo "  ✗ {$name}\n"; $fail++; }
};
$lint = function (string $path): bool {
    $rc = 0; @exec('php -l ' . escapeshellarg($path) . ' 2>&1', $_, $rc); return $rc === 0;
};

echo "/api/tenants.php\n";
$src = file_get_contents(__DIR__ . '/../api/tenants.php');
$assert('endpoint exists',                    is_string($src) && strlen($src) > 200);
$assert('master_admin gate at top',           strpos($src, "if (\$role !== 'master_admin')") !== false);
$assert('GET list query',                     strpos($src, 'parent_id IS NULL OR t.tenant_type') !== false);
$assert('GET list joins user_count',          strpos($src, "FROM user_tenants ut") !== false
                                             && strpos($src, "ut.status = 'active'") !== false);
$assert('GET list joins sub_count',           strpos($src, "tenant_type = 'sub'") !== false);
$assert('POST create',                        strpos($src, "if (\$method === 'POST')") !== false);
$assert('PATCH partial update',               strpos($src, "if (\$method === 'PATCH')") !== false);
$assert('DELETE soft-deactivate',             strpos($src, "if (\$method === 'DELETE')") !== false
                                             && strpos($src, 'is_active = 0') !== false);
$assert('rejects deactivate w/ active subs',  strpos($src, 'active sub-tenants underneath') !== false);
$assert('detects slug conflicts',             strpos($src, '_tenantSlugConflict(') !== false
                                             && strpos($src, 'already in use') !== false);
$assert('audits master_tenant.created',       strpos($src, 'master_tenant.created') !== false);
$assert('audits master_tenant.updated',       strpos($src, 'master_tenant.updated') !== false);
$assert('audits master_tenant.deactivated',   strpos($src, 'master_tenant.deactivated') !== false);
$assert('PHP parses cleanly',                 $lint(__DIR__ . '/../api/tenants.php'));

echo "MasterTenantsAdmin.jsx\n";
$ui = file_get_contents(__DIR__ . '/../dashboard/src/pages/MasterTenantsAdmin.jsx');
$assert('component exists',                   is_string($ui) && strlen($ui) > 500);
$assert('hits /api/tenants.php',              strpos($ui, '/api/tenants.php') !== false);
$assert('shows user_count + sub_count',       strpos($ui, 't.user_count') !== false
                                             && strpos($ui, 't.sub_count') !== false);
$assert('links to /admin/sub-tenants',        strpos($ui, '/admin/sub-tenants') !== false);
$assert('full edit form (name/slug/domain/branding)',
                                              strpos($ui, 'k="name"')          !== false
                                              && strpos($ui, 'k="slug"')       !== false
                                              && strpos($ui, 'k="domain"')     !== false
                                              && strpos($ui, 'k="hero_title"') !== false);
$assert('landing_enabled toggle',             strpos($ui, 'data-testid="master-tenant-landing_enabled"') !== false);
$assert('save button',                        strpos($ui, 'data-testid="master-tenant-save"') !== false);
$assert('deactivate button',                  strpos($ui, 'data-testid={`master-tenant-deactivate-${t.id}`}') !== false);

echo "AdminModule wiring\n";
$ad = file_get_contents(__DIR__ . '/../dashboard/src/pages/AdminModule.jsx');
$assert('imports MasterTenantsAdmin',         strpos($ad, "import MasterTenantsAdmin") !== false);
$assert('routes /tenants → MasterTenantsAdmin',
                                              strpos($ad, '<MasterTenantsAdmin') !== false
                                              && strpos($ad, '<TenantsPage />') === false);
$assert('mock TenantsPage removed',           strpos($ad, 'const TenantsPage = ()') === false);

echo "Legacy PHP form redirected\n";
$legacy = file_get_contents(__DIR__ . '/../core/views/admin/tenant_edit.php');
$assert('redirects to SPA',                   strpos($legacy, '/spa.php#/admin/tenants') !== false
                                             && strpos($legacy, "header('Location") !== false);
$assert('legacy form HTML removed',           strpos($legacy, '</form>') === false);
$assert('PHP parses cleanly',                 $lint(__DIR__ . '/../core/views/admin/tenant_edit.php'));

echo "\nPass: {$pass}\nFail: {$fail}\n";
exit($fail === 0 ? 0 : 1);
