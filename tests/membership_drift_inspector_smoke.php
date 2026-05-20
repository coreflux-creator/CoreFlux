<?php
/**
 * Membership drift inspector smoke.
 *
 * Verifies the structure of /api/admin/membership_drift.php (auth gate,
 * route shape, helper wiring) without depending on a live DB row.
 *
 *   php -d zend.assertions=1 /app/tests/membership_drift_inspector_smoke.php
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};

$apiPath = $ROOT . '/api/admin/membership_drift.php';
$jsxPath = $ROOT . '/dashboard/src/pages/MembershipDriftBanner.jsx';
$usersAdminPath = $ROOT . '/dashboard/src/pages/UsersAdmin.jsx';

// Files exist
$a('endpoint file exists',          file_exists($apiPath));
$a('banner component exists',       file_exists($jsxPath));

// Endpoint contents
$src = (string) file_get_contents($apiPath);
$a('endpoint requires master_admin (or is_global_admin)',
    str_contains($src, "'master_admin'") && str_contains($src, 'is_global_admin'));
$a('endpoint pulls memberships.php helper',
    str_contains($src, "require_once __DIR__ . '/../../core/memberships.php'"));
$a('endpoint exposes GET / POST?action=heal / POST?action=heal_all',
    str_contains($src, "if (\$method === 'GET')")
    && str_contains($src, "\$action === 'heal'")
    && str_contains($src, "\$action === 'heal_all'"));
$a('endpoint uses healMembershipsForUser()',
    str_contains($src, 'healMembershipsForUser('));
$a('endpoint caps detail rows at 100',
    (bool) preg_match('/LIMIT\s+100/', $src));
$a('endpoint caps heal_all batch at 250',
    (bool) preg_match('/LIMIT\s+250/', $src));
$a('endpoint uses NOT EXISTS de-dup (matches read-shim semantics)',
    (bool) preg_match('/NOT\s+EXISTS\s*\(\s*SELECT\s+1\s+FROM\s+tenant_memberships/i', $src));
$a('endpoint marks tenant-leak-allow (cross-tenant by design)',
    str_contains($src, 'tenant-leak-allow'));

// Banner contents
$jsx = (string) file_get_contents($jsxPath);
$a('banner pulls drift endpoint',
    str_contains($jsx, "/api/admin/membership_drift.php"));
$a('banner exposes drift-banner test id',
    str_contains($jsx, 'data-testid="drift-banner"'));
$a('banner exposes heal-all action',
    str_contains($jsx, 'drift-heal-all-btn'));
$a('banner exposes per-row heal action',
    str_contains($jsx, 'drift-heal-${u.id}'));
$a('banner exposes a clean-state pill (drift-banner-clean)',
    str_contains($jsx, 'drift-banner-clean'));
$a('banner loops heal_all until clean (re-poll guard)',
    str_contains($jsx, 'while (current > 0'));

// UsersAdmin wiring
$ua = (string) file_get_contents($usersAdminPath);
$a('UsersAdmin imports MembershipDriftBanner',
    str_contains($ua, "import MembershipDriftBanner from './MembershipDriftBanner'"));
$a('UsersAdmin gates banner on isMaster',
    (bool) preg_match('/isMaster\s*&&\s*<MembershipDriftBanner/', $ua));
$a('UsersAdmin reloads users list when a heal completes',
    (bool) preg_match('/<MembershipDriftBanner[^>]*onHealed=\{reload\}/', $ua));

// Helper wiring sanity
require_once $ROOT . '/core/memberships.php';
$a('healMembershipsForUser() callable from PHP', function_exists('healMembershipsForUser'));

echo "\n=========================================\n";
echo "Membership drift inspector smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
