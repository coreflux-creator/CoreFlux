<?php
/**
 * Sprint 7a smoke — RBAC permissions inventory (spec §36).
 *
 * Confirms the new permission strings the spec mandates are recognized
 * and grantable to the appropriate roles.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/RBAC.php';

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok) use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}\n"; $fail++; }
};

RBAC::reset();

// master_admin / tenant_admin should match every spec permission via wildcard.
$adminUser = ['role' => 'admin'];
$tenantAdmin = ['role' => 'tenant_admin'];
$masterAdmin = ['global_role' => 'master_admin'];
$manager     = ['role' => 'manager'];
$employee    = ['role' => 'employee'];

$specPerms = [
    'accounting.create_entry', 'accounting.approve_entry', 'accounting.post_entry',
    'accounting.reverse_entry', 'accounting.close_period', 'accounting.reopen_period',
    'accounting.period.lock',
    'accounting.manage_dimensions', 'accounting.manage_posting_rules',
    'treasury.execute_payment', 'treasury.approve_transfer', 'treasury.create_transfer',
    'treasury.view_bank_balances', 'treasury.manage_forecast',
    'ai.view_recommendations', 'ai.approve_actions', 'ai.configure_agents',
    'ai.enable_auto_execute',
];

echo "master_admin universal access\n";
foreach ($specPerms as $p) {
    $assert("master_admin grants {$p}",  RBAC::hasPermission($masterAdmin, $p));
}

echo "\ntenant_admin universal access\n";
foreach ($specPerms as $p) {
    $assert("tenant_admin grants {$p}",  RBAC::hasPermission($tenantAdmin, $p));
}

echo "\nadmin grants accounting.* + treasury.* + scoped ai.*\n";
foreach (['accounting.create_entry', 'accounting.reverse_entry', 'accounting.period.lock',
          'accounting.manage_dimensions', 'accounting.manage_posting_rules',
          'treasury.execute_payment', 'treasury.approve_transfer',
          'ai.view_recommendations', 'ai.approve_actions'] as $p) {
    $assert("admin grants {$p}",  RBAC::hasPermission($adminUser, $p));
}
$assert('admin does NOT grant ai.enable_auto_execute',
    !RBAC::hasPermission($adminUser, 'ai.enable_auto_execute'));

echo "\nmanager — read-only AI + treasury view\n";
$assert('manager grants ai.view_recommendations',
    RBAC::hasPermission($manager, 'ai.view_recommendations'));
$assert('manager grants treasury.view',
    RBAC::hasPermission($manager, 'treasury.view'));
$assert('manager grants accounting.coa.view',
    RBAC::hasPermission($manager, 'accounting.coa.view'));
$assert('manager does NOT grant treasury.execute_payment',
    !RBAC::hasPermission($manager, 'treasury.execute_payment'));
$assert('manager does NOT grant ai.approve_actions',
    !RBAC::hasPermission($manager, 'ai.approve_actions'));
$assert('manager does NOT grant accounting.reverse_entry',
    !RBAC::hasPermission($manager, 'accounting.reverse_entry'));

echo "\nemployee — strictest\n";
$assert('employee does NOT grant accounting.create_entry',
    !RBAC::hasPermission($employee, 'accounting.create_entry'));
$assert('employee does NOT grant treasury.view_bank_balances',
    !RBAC::hasPermission($employee, 'treasury.view_bank_balances'));
$assert('employee does NOT grant ai.view_recommendations',
    !RBAC::hasPermission($employee, 'ai.view_recommendations'));

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
