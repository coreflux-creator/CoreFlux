<?php
/**
 * RBAC B1 smoke — verifies migration 055 + backfill script structure.
 *
 * B2 (resolver + session refactor) will add functional smoke. Here we
 * just gate the schema + the backfill script's documented contract.
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};
$c = function (string $hay, string $needle): bool { return strpos($hay, $needle) !== false; };

// ----------------------------------------------------------------- migration 055
echo "Migration 055 — RBAC schema foundation\n";
$mig = (string) file_get_contents($ROOT . '/core/migrations/055_rbac_memberships.sql');
$a('file present',                              $mig !== '');
$a('declares tenant_memberships',               $c($mig, 'CREATE TABLE IF NOT EXISTS tenant_memberships'));
$a('membership has persona_label',              $c($mig, 'persona_label       VARCHAR(80)'));
$a('persona_type enum covers required roles',
    $c($mig, "ENUM('master_admin','tenant_admin','admin','manager',\n                             'employee','contractor','client','vendor',\n                             'platform_staff','custom')"));
$a('membership unique on (user, tenant, persona_label)',
    $c($mig, 'UNIQUE KEY uq_membership (user_id, tenant_id, persona_label)'));
$a('membership has linked_entity_type/_id',
    $c($mig, 'linked_entity_type  VARCHAR(40)') && $c($mig, 'linked_entity_id    BIGINT UNSIGNED'));
$a('status enum includes pending+suspended',    $c($mig, "ENUM('active','pending','suspended','revoked')"));

$a('declares membership_module_access',         $c($mig, 'CREATE TABLE IF NOT EXISTS membership_module_access'));
$a('access_level enum none/read/write/admin',   $c($mig, "ENUM('none','read','write','admin')"));
$a('sub_tenant_scope JSON column present',      $c($mig, 'sub_tenant_scope    JSON NULL'));
$a('module_access unique on (membership, module)', $c($mig, 'UNIQUE KEY uq_membership_module (membership_id, module_key)'));

$a('adds users.is_global_admin (idempotent)',   $c($mig, 'ADD COLUMN is_global_admin TINYINT(1)') && $c($mig, 'information_schema.columns') && $c($mig, "'DO 0'"));

$a('declares membership_audit',                 $c($mig, 'CREATE TABLE IF NOT EXISTS membership_audit'));
$a('membership_audit has actor + target',
    $c($mig, 'actor_user_id   INT UNSIGNED') && $c($mig, 'target_user_id  INT UNSIGNED'));

// ----------------------------------------------------------------- backfill script
echo "\nBackfill script — scripts/backfill_memberships.php\n";
$script = (string) file_get_contents($ROOT . '/scripts/backfill_memberships.php');
$a('file present',                              $script !== '');
$a('refuses to run if schema missing',          $c($script, "Migration 055 not applied yet"));
$a('supports --dry-run flag',                   $c($script, "--dry-run") && $c($script, "isset(\$opts['dry-run'])"));
$a('supports --tenant scoping',                 $c($script, "--tenant=") && $c($script, "isset(\$opts['tenant'])"));
$a('upserts via ON DUPLICATE KEY UPDATE',       $c($script, 'ON DUPLICATE KEY UPDATE'));
$a('maps roles → persona_types',                $c($script, '$personaTypeFor') && $c($script, "'master_admin'  => 'master_admin'"));
$a('admin/tenant_admin/master_admin → admin access',
    $c($script, "'master_admin', 'tenant_admin', 'admin' => 'admin'"));
$a('manager → write access',                    $c($script, "'manager'                               => 'write'"));
$a('employee/contractor → read access',         $c($script, "'employee', 'contractor'                => 'read'"));
$a('uses getUserModules for module enumeration',$c($script, 'getUserModules($role)'));
$a('grants per-module access_level',            $c($script, "':a' => 'a'") || $c($script, "':a'") || $c($script, "'a' => \$accessLevel"));
$a('inactive → suspended status mapping',       $c($script, "\$status === 'inactive'") && $c($script, "\$status = 'suspended'"));

// ----------------------------------------------------------------- syntax
echo "\nSyntax sanity\n";
$rc = 0; $o = [];
exec('php -l ' . escapeshellarg($ROOT . '/scripts/backfill_memberships.php') . ' 2>&1', $o, $rc);
$a('php -l scripts/backfill_memberships.php',  $rc === 0);

echo "\n=========================================\n";
echo "RBAC B1 smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
