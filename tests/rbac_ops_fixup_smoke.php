<?php
/**
 * RBAC operational fix smoke — verifies the three real bugs surfaced
 * after the B4 dual-check sweep are addressed:
 *
 *   1. Legacy `admin` role gained the missing module wildcards so the
 *      dual-check bridge can't silently deny admin users on AP / billing /
 *      time / placements / reports / staffing / integrations / ai.
 *
 *   2. Migration 058 backfills `users.is_global_admin = 1` for master_admin
 *      users and seeds membership_module_access for the three synthetic
 *      module_keys (integrations, ai, staffing) that B1's backfill missed.
 *
 *   3. /api/users.php POST auto-creates a tenant_memberships row + default
 *      module access grants for newly-created users so they land on the
 *      new model in the same shape as backfilled users.
 *
 *   php -d zend.assertions=1 /app/tests/rbac_ops_fixup_smoke.php
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};
$c = function (string $hay, string $needle): bool { return strpos($hay, $needle) !== false; };

// ----------------------------------------------------------------- (1) rbac_config admin role
echo "(1) legacy admin role — module coverage\n";
$cfg = (string) file_get_contents($ROOT . '/core/rbac_config.php');
$admin = '';
if (preg_match("/'admin'\s*=>\s*\[(.*?)\]/s", $cfg, $m)) $admin = $m[1];
$a("admin has ap.*",            $c($admin, "'ap.*'"));
$a("admin has billing.*",       $c($admin, "'billing.*'"));
$a("admin has time.*",          $c($admin, "'time.*'"));
$a("admin has placements.*",    $c($admin, "'placements.*'"));
$a("admin has reports.*",       $c($admin, "'reports.*'"));
$a("admin has staffing.*",      $c($admin, "'staffing.*'"));
$a("admin has integrations.*",  $c($admin, "'integrations.*'"));
$a("admin has ai.config.manage (B4 bridge mapping needs this)", $c($admin, "'ai.config.manage'"));
$a("admin keeps ai.view_recommendations / ai.approve_actions / ai.configure_agents",
    $c($admin, "'ai.view_recommendations'")
    && $c($admin, "'ai.approve_actions'")
    && $c($admin, "'ai.configure_agents'"));
$a("admin does NOT have catch-all ai.* (autonomy switch gated)", !$c($admin, "'ai.*'"));
$a("admin keeps reporting.* (back-compat)", $c($admin, "'reporting.*'"));
$a("admin does NOT have tenant.*", !$c($admin, "'tenant.*'"));

// ----------------------------------------------------------------- (2) migration 058
echo "\n(2) migration 058 — synthetic module + is_global_admin backfill\n";
$migPath = $ROOT . '/core/migrations/058_rbac_seed_synthetic_modules.sql';
$mig = (string) file_get_contents($migPath);
$a('migration file exists',                        $mig !== '');
$a('flips is_global_admin for master_admin users', $c($mig, 'SET is_global_admin = 1')
                                                  && $c($mig, "role = 'master_admin'"));
$a('seeds integrations module access',             $c($mig, "'integrations'"));
$a('seeds ai module access',                       $c($mig, "'ai'"));
$a('seeds staffing module access',                 $c($mig, "'staffing'"));
$a('uses INSERT IGNORE for idempotency',           substr_count($mig, 'INSERT IGNORE') >= 3);
$a('grants admin level to master_admin/tenant_admin/admin personas',
    $c($mig, "WHEN 'master_admin' THEN 'admin'") &&
    $c($mig, "WHEN 'tenant_admin' THEN 'admin'") &&
    $c($mig, "WHEN 'admin'        THEN 'admin'"));
$a('grants read to managers on integrations',
    preg_match('/integrations.*?WHEN \'manager\'\s+THEN \'read\'/s', $mig) === 1);
$a('grants nothing below manager on ai',
    preg_match('/ai.*?WHEN \'admin\'\s+THEN \'admin\'\s+ELSE \'none\'/s', $mig) === 1);

// ----------------------------------------------------------------- (3) users.php POST bootstrap
echo "\n(3) /api/users.php — auto-create membership on user create\n";
$users = (string) file_get_contents($ROOT . '/api/users.php');
$rc = 0; $o = [];
exec('php -l ' . escapeshellarg($ROOT . '/api/users.php') . ' 2>&1', $o, $rc);
$a('users.php syntax clean',                       $rc === 0);
$a('defines _usersBootstrapMembership helper',     $c($users, 'function _usersBootstrapMembership('));
$a('helper provisions via central memberships helper', $c($users, 'provisionMembership(') && $c($users, 'memberships.php'));
$a('helper inserts into membership_module_access', $c($users, 'INSERT IGNORE INTO membership_module_access'));
$a('helper invoked from POST handler',             $c($users, '_usersBootstrapMembership($pdo, $newId, $tenantId, $tenantRole, $actorId)'));
$a('helper covers operational module list',
    $c($users, "['people','placements','time','billing','ap','accounting','payroll','treasury','reports']"));
$a('helper covers synthetic modules (integrations/ai/staffing)',
    $c($users, "'integrations'") && $c($users, "'ai'") && $c($users, "'staffing'"));
$a('persona_type derives from legacy role via match()',$c($users, "match (\$role)"));
$a('master_admin/tenant_admin/admin → access_level=admin',
    $c($users, "'master_admin', 'tenant_admin', 'admin' => 'admin'"));
$a('manager → write',                              $c($users, "'manager'                               => 'write'"));
$a('employee/contractor → read',                   $c($users, "'employee', 'contractor'                => 'read'"));
$a('caller-supplied is_global_admin honored when caller is master_admin',
    $c($users, "!empty(\$body['is_global_admin']) && \$role === 'master_admin'"));
$a('bootstrap wrapped in try/catch (never blocks user creation)',
    substr_count(substr($users, strpos($users, '_usersBootstrapMembership')), 'catch (\Throwable') >= 2);

// ----------------------------------------------------------------- summary
echo "\n=========================================\n";
echo "RBAC ops fix-up smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
