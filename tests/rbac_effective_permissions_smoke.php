<?php
/**
 * Effective permissions inspector smoke — covers the /admin endpoint +
 * the React modal + the wiring into UsersAdmin.
 *
 *   php -d zend.assertions=1 /app/tests/rbac_effective_permissions_smoke.php
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};
$c = function (string $hay, string $needle): bool { return strpos($hay, $needle) !== false; };

// ----------------------------------------------------------------- endpoint
echo "/api/admin/user_effective_permissions.php\n";
$ep = (string) file_get_contents($ROOT . '/api/admin/user_effective_permissions.php');
$rc = 0; $o = [];
exec('php -l ' . escapeshellarg($ROOT . '/api/admin/user_effective_permissions.php') . ' 2>&1', $o, $rc);
$a('php -l clean',                              $rc === 0);
$a('requires api_bootstrap',                    $c($ep, "require_once __DIR__ . '/../../core/api_bootstrap.php'"));
$a('GET-only',                                  $c($ep, "api_method() !== 'GET'"));
$a('admin gate',                                $c($ep, "in_array(\$role, ['master_admin', 'tenant_admin']")
                                                && $c($ep, '$isGlobalAdmin'));
$a('returns user.is_global_admin as bool',      $c($ep, "(int) (\$user['is_global_admin'] ?? 0) === 1"));
$a('joins tenant_memberships per user',         $c($ep, 'FROM tenant_memberships tm'));
$a('joins membership_module_access per membership',
    $c($ep, 'FROM membership_module_access'));
$a('surfaces orphan memberships (no user_tenants row)',
    $c($ep, '(no user_tenants row)'));
$a('builds can_matrix from RbacLegacyMap::table',
    $c($ep, 'RbacLegacyMap::table()')
    && $c($ep, "\$canMatrix[\$perm]"));
$a('runs dual-check verdict (legacy && new) per permission',
    $c($ep, 'RBAC::hasPermission')
    && $c($ep, 'api_can(')
    && $c($ep, '\$legacyOk && \$newOk'));
$a('PARKED permissions defer to legacy only',   $c($ep, "\$isParked = \$module === '_platform'"));
$a('summary counts canonical / synthetic / parked',
    $c($ep, "'canonical_modules_count'")
    && $c($ep, "'synthetic_modules_count'")
    && $c($ep, "'parked_perms_count'"));
$a('restores $_SESSION after impersonation',
    $c($ep, '$_SESSION = $savedSession'));

// ----------------------------------------------------------------- React modal
echo "\nUserEffectivePermissionsModal.jsx\n";
$mod = (string) file_get_contents($ROOT . '/dashboard/src/pages/UserEffectivePermissionsModal.jsx');
$a('component file exists',                     $mod !== '');
$a('calls /api/admin/user_effective_permissions.php',
    $c($mod, '/api/admin/user_effective_permissions.php'));
$a('renders global-admin badge',                $c($mod, 'data-testid="user-permissions-global-admin"'));
$a('renders tenant cards',                      $c($mod, 'user-permissions-tenant-'));
$a('renders membership rows',                   $c($mod, 'user-permissions-membership-'));
$a('renders module-access tiles',               $c($mod, 'user-permissions-module-'));
$a('renders permission matrix table',           $c($mod, 'data-testid="user-permissions-matrix"'));
$a('filter input present',                      $c($mod, 'data-testid="user-permissions-filter"'));
$a('"only denied" filter present',              $c($mod, 'data-testid="user-permissions-only-denied"'));
$a('"only disagreement" filter present',        $c($mod, 'data-testid="user-permissions-only-disagreement"'));
$a('renders DENY/ALLOW verdict per row',        $c($mod, 'ALLOW') && $c($mod, 'DENY'));
$a('PARKED marker present',                     $c($mod, 'PARKED'));
$a('close button present',                      $c($mod, 'data-testid="user-permissions-close"'));
$a('refresh button present',                    $c($mod, 'data-testid="user-permissions-refresh"'));

// ----------------------------------------------------------------- UsersAdmin wiring
echo "\nUsersAdmin.jsx wiring\n";
$ua = (string) file_get_contents($ROOT . '/dashboard/src/pages/UsersAdmin.jsx');
$a('imports UserEffectivePermissionsModal',     $c($ua, "import UserEffectivePermissionsModal from './UserEffectivePermissionsModal'"));
$a('imports Shield icon',                       $c($ua, "Shield } from 'lucide-react'") || $c($ua, ', Shield'));
$a('permsFor state',                            $c($ua, "setPermsFor"));
$a('Shield button per row',                     $c($ua, 'data-testid={`users-perms-'));
$a('modal embedded when permsFor set',          $c($ua, '<UserEffectivePermissionsModal'));

// ----------------------------------------------------------------- sub_tenants regression
echo "\nSub-tenant provisioning — subdomain backfill\n";
$st = (string) file_get_contents($ROOT . '/core/sub_tenants.php');
$a('subTenantProvision derives subdomain from slug',
    $c($st, '$subdomain = trim((string)($opts[\'subdomain\'] ?? \'\')) ?: $slug'));
$a('INSERT now includes subdomain column',      $c($st, 'INSERT INTO tenants (name, slug, subdomain,'));
$a('INSERT binds :sd',                          $c($st, "'sd' => \$subdomain"));

// ----------------------------------------------------------------- summary
echo "\n=========================================\n";
echo "Effective permissions smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
