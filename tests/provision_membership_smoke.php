<?php
/**
 * Smoke test for the provisionMembership() dual-write helper + its consumers.
 *
 * Source-level shape assertions (the live-DB execution path is covered by
 * scripts/backfill_memberships.php's existing integration test and the
 * api/users.php integration coverage).
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};

// ── Helper ────────────────────────────────────────────────────────────
$src = file_get_contents('/app/core/memberships.php');
$a('memberships.php exists',                       $src !== false);
$a('declares provisionMembership',                 str_contains($src, 'function provisionMembership('));
$a('declares deactivateMembership',                str_contains($src, 'function deactivateMembership('));
$a('declares setPrimaryMembership',                str_contains($src, 'function setPrimaryMembership('));
$a('declares purgeMembershipsForUser',             str_contains($src, 'function purgeMembershipsForUser('));
$a('declares _membershipPersonaTypeForRole',       str_contains($src, 'function _membershipPersonaTypeForRole('));
$a('writes to tenant_memberships',                 str_contains($src, 'INSERT INTO tenant_memberships'));
$a('dual-writes to user_tenants',                  str_contains($src, 'INSERT INTO user_tenants'));
$a('handles is_primary sibling demotion (new)',    str_contains($src, 'UPDATE tenant_memberships') && str_contains($src, 'is_primary = 0'));
$a('handles is_default sibling demotion (legacy)', str_contains($src, 'UPDATE user_tenants') && str_contains($src, 'is_default = 0'));
$a('logs audit row in membership_audit',           str_contains($src, 'INSERT INTO membership_audit'));
$a('respects an already-open transaction (nesting safe)',
                                                   str_contains($src, '$ownsTxn = !$pdo->inTransaction()'));
$a('php -l clean',                                 (function () {
    exec('php -l /app/core/memberships.php 2>&1', $o, $rc); return $rc === 0;
})());

// ── Persona-type mapping ──────────────────────────────────────────────
require_once '/app/core/memberships.php';
$a('role=admin → persona_type=admin',              _membershipPersonaTypeForRole('admin') === 'admin');
$a('role=master_admin preserved',                  _membershipPersonaTypeForRole('master_admin') === 'master_admin');
$a('role=USER (uppercase) → employee',             _membershipPersonaTypeForRole('USER') === 'employee');
$a('role=owner alias → tenant_admin',              _membershipPersonaTypeForRole('owner') === 'tenant_admin');
$a('role=consultant alias → contractor',           _membershipPersonaTypeForRole('consultant') === 'contractor');
$a('unknown role → custom',                        _membershipPersonaTypeForRole('chief_burrito_officer') === 'custom');
$a('null role → employee (safe default)',          _membershipPersonaTypeForRole(null) === 'employee');

// ── Consumer wiring ───────────────────────────────────────────────────
$consumers = [
    '/app/api/users.php'                       => 'primary user CRUD',
    '/app/core/views/admin/user_edit.php'      => 'admin user-edit form',
    '/app/people/includes/people_helper.php'   => 'people module helper',
    '/app/api/auth/consume_magic_link.php'     => 'magic-link JIT membership',
    '/app/api/sso/callback.php'                => 'SSO JIT membership',
];
foreach ($consumers as $f => $label) {
    $c = file_get_contents($f);
    $a("$label requires memberships.php OR delegates via _usersBootstrapMembership",
       str_contains($c, "memberships.php"));
    $a("$label calls provisionMembership/deactivateMembership/purgeMembershipsForUser OR delegates via _usersBootstrap",
       str_contains($c, 'provisionMembership(')
       || str_contains($c, 'deactivateMembership(')
       || str_contains($c, 'purgeMembershipsForUser(')
       || str_contains($c, '_usersBootstrapMembership('));
}

// Sentry runs still green
exec('php -d zend.assertions=1 /app/tests/user_tenants_read_sentry_smoke.php > /dev/null 2>&1', $_, $rcR);
$a('user_tenants read-sentry still green', $rcR === 0);
exec('php -d zend.assertions=1 /app/tests/user_tenants_write_sentry_smoke.php > /dev/null 2>&1', $_, $rcW);
$a('user_tenants write-sentry green',      $rcW === 0);

echo "\n=========================================\n";
echo "provisionMembership smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
