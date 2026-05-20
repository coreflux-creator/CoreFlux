<?php
/**
 * Tenant routing rebuild smoke (2026-02).
 *
 * Verifies the role-based landing + tenant switcher rebuild:
 *   1. Bootstrap role-floor — master_admin/is_global_admin never downgraded.
 *   2. Login.php platform-mode branch + per-role landing.
 *   3. switch_tenant.php platform-mode toggle + global-role acceptance.
 *   4. /api/admin/manageable_tenants.php endpoint shape.
 *   5. Header.jsx wires the new endpoint + Platform Admin entry.
 *
 *   php -d zend.assertions=1 /app/tests/tenant_routing_rebuild_smoke.php
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};

// --------------- 1. api_bootstrap.php role-floor ---------------------------
$bootstrap = (string) file_get_contents($ROOT . '/core/api_bootstrap.php');
$a('platform-mode bypass present in api_require_auth',
    str_contains($bootstrap, '$isPlatformMA') && str_contains($bootstrap, "'master_admin'"));
$a('master_admin floor — user_tenants override skipped',
    (bool) preg_match('/if\s*\(\s*!\$isPlatformMA\s*&&\s*\$user\s*&&\s*\$tenantId\s*\)/', $bootstrap));
$a('persona_type override gated on !$isPlatformMA',
    (bool) preg_match('/personaType\s*!==\s*\'\'\s*&&\s*!\$isPlatformMA/', $bootstrap));
$a('global_role returned in $ctx',
    (bool) preg_match("/'global_role'\\s*=>\\s*\\\$globalRole/", $bootstrap));
$a('requireTenant is bypassed for platform_mode',
    (bool) preg_match('/\$requireTenant\s*&&\s*!\$tenantId\s*&&\s*!\$isPlatformMA/', $bootstrap));

// --------------- 2. login.php landing logic --------------------------------
$login = (string) file_get_contents($ROOT . '/login.php');
$a('login.php detects is_global_admin column',  str_contains($login, "is_global_admin"));
$a('login.php has platformMode branch',         str_contains($login, '$platformMode'));
$a('login.php skips tenant pin for master_admin',
    (bool) preg_match('/if\s*\(\s*\$isPlatformMA\s*\)\s*\{[^}]*\$platformMode\s*=\s*true/', $login));
$a('login.php hydrates full tenant inventory for platform_mode',
    str_contains($login, 'getAllTenants()'));
$a('login.php landing redirect honors platform_mode',
    str_contains($login, 'spa.php#/admin'));
$a('session sets platform_mode flag',           str_contains($login, "_SESSION['platform_mode']"));

// --------------- 3. switch_tenant.php --------------------------------------
$sw = (string) file_get_contents($ROOT . '/switch_tenant.php');
$a('switch_tenant.php accepts ?platform=1',     str_contains($sw, '$wantPlatform'));
$a('switch_tenant.php uses global_role for master_admin bypass',
    str_contains($sw, '$isPlatformMA'));
$a('switch_tenant.php clears tenant on platform toggle',
    (bool) preg_match("/\\\$_SESSION\\['tenant_id'\\]\\s*=\\s*null;/", $sw));
$a('switch_tenant.php restores master_admin role on platform toggle',
    (bool) preg_match("/\\\$_SESSION\\['user'\\]\\['role'\\]\\s*=\\s*'master_admin'/", $sw));
$a('_subTenantSwitchAllowed accepts $isPlatformMA',
    (bool) preg_match('/_subTenantSwitchAllowed\([^)]+,\s*bool\s+\$isPlatformMA/', $sw));

// --------------- 4. manageable_tenants endpoint ----------------------------
$mt = (string) file_get_contents($ROOT . '/api/admin/manageable_tenants.php');
$a('manageable_tenants endpoint exists',        strlen($mt) > 400);
$a('manageable_tenants pulls membership shim',  str_contains($mt, 'membershipReadSourceSql()'));
$a('master_admin sees every active tenant',     str_contains($mt, "tenant_type" ) && str_contains($mt, "'access'") && str_contains($mt, "'platform'"));
$a('via_parent inheritance for tenant_admin',   str_contains($mt, "'via_parent'") && str_contains($mt, "tenant_type = 'sub'") );
$a('nested response includes sub_tenants',      str_contains($mt, "'sub_tenants'"));
$a('platform_mode flag surfaced in response',   str_contains($mt, "'platform_mode'"));
$a('allows auth without a pinned tenant',       str_contains($mt, 'api_require_auth(false)'));

// --------------- 5. Header.jsx wiring --------------------------------------
$hdr = (string) file_get_contents($ROOT . '/dashboard/src/layout/Header.jsx');
$a('Header pulls /api/admin/manageable_tenants.php',
    str_contains($hdr, "/api/admin/manageable_tenants.php"));
$a('Header renders Platform Admin entry for master_admin',
    str_contains($hdr, 'tenant-switcher-platform') && str_contains($hdr, 'Platform Admin'));
$a('Header renders nested sub_tenants',         str_contains($hdr, 'sub.sub_tenants') || str_contains($hdr, '(t.sub_tenants'));
$a('Header shows "inherited" badge for via_parent rows',
    str_contains($hdr, 'inherited'));
$a('Header shows "Viewing as" badge for master_admin pinned',
    str_contains($hdr, 'Viewing as'));
$a('Header platform toggle hits switch_tenant.php?platform=1',
    str_contains($hdr, 'switch_tenant.php?platform=1'));
$a('Header Admin Panel link also gated on is_global_admin',
    str_contains($hdr, 'is_global_admin'));

// --------------- 6. session.php surfaces flags -----------------------------
$sess = (string) file_get_contents($ROOT . '/session.php');
$a('session.php exposes platform_mode',         str_contains($sess, "platform_mode"));
$a('session.php exposes is_global_admin',       str_contains($sess, "is_global_admin"));

// --------------- 7. App.jsx routes platform_mode landing -------------------
$app = (string) file_get_contents($ROOT . '/dashboard/src/App.jsx');
$a('App.jsx routes platform_mode users to /admin',
    str_contains($app, "navigate('/admin'") && str_contains($app, 'platform_mode'));
$a('App.jsx imports useNavigate',               str_contains($app, "useNavigate"));

// --------------- 8. PHP syntax sanity --------------------------------------
$phpFiles = [
    'core/api_bootstrap.php', 'login.php', 'switch_tenant.php',
    'api/admin/manageable_tenants.php', 'session.php',
];
foreach ($phpFiles as $rel) {
    $rc = 0; $out = [];
    exec('php -l ' . escapeshellarg($ROOT . '/' . $rel) . ' 2>&1', $out, $rc);
    $a("php -l $rel", $rc === 0);
}

echo "\n=========================================\n";
echo "Tenant routing rebuild smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
