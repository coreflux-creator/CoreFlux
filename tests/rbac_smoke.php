<?php
/**
 * RBAC smoke test.
 *
 *   php /app/tests/rbac_smoke.php
 *
 * No DB required. Exercises pattern matching, role resolution, default-deny,
 * and the master-admin override against the real /core/rbac_config.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/RBAC.php';

$pass = 0; $fail = 0;
$assert = function(string $what, bool $cond) use (&$pass, &$fail) {
    if ($cond) { $pass++; echo "  ✓ $what\n"; }
    else        { $fail++; echo "  ✗ $what\n"; }
};

RBAC::reset();

// ---------------------------------------------------------------------------
echo "Role resolution\n";
$assert("global_role=master_admin wins",
    RBAC::resolveRole(['global_role' => 'master_admin', 'role' => 'employee']) === 'master_admin');
$assert("tenant_role wins over role",
    RBAC::resolveRole(['tenant_role' => 'admin', 'role' => 'employee']) === 'admin');
$assert("falls back to role",
    RBAC::resolveRole(['role' => 'manager']) === 'manager');
$assert("empty user defaults to employee",
    RBAC::resolveRole([]) === 'employee');

// ---------------------------------------------------------------------------
echo "\nmaster_admin (catchall '*')\n";
$ma = ['global_role' => 'master_admin'];
$assert("can people.view",       RBAC::hasPermission($ma, 'people.view'));
$assert("can payroll.runs.approve", RBAC::hasPermission($ma, 'payroll.runs.approve'));
$assert("can fictional.future.thing", RBAC::hasPermission($ma, 'fictional.future.thing'));

// ---------------------------------------------------------------------------
echo "\nadmin (module wildcards)\n";
$admin = ['role' => 'admin'];
$assert("can people.view",            RBAC::hasPermission($admin, 'people.view'));
$assert("can people.banking.view",    RBAC::hasPermission($admin, 'people.banking.view'));
$assert("can payroll.runs.approve",   RBAC::hasPermission($admin, 'payroll.runs.approve'));
$assert("can accounting.journal.post", RBAC::hasPermission($admin, 'accounting.journal.post'));
$assert("can admin.export_templates.manage", RBAC::hasPermission($admin, 'admin.export_templates.manage'));
$assert("CANNOT some.other.module",   !RBAC::hasPermission($admin, 'tax.audit.view'));

// ---------------------------------------------------------------------------
echo "\nmanager (mixed exact + module wildcards)\n";
$mgr = ['role' => 'manager'];
$assert("can people.view",          RBAC::hasPermission($mgr, 'people.view'));
$assert("can people.timeoff.manage", RBAC::hasPermission($mgr, 'people.timeoff.manage'));
$assert("can payroll.runs.view",    RBAC::hasPermission($mgr, 'payroll.runs.view'));
$assert("CANNOT people.banking.view", !RBAC::hasPermission($mgr, 'people.banking.view'));
$assert("CANNOT payroll.runs.approve", !RBAC::hasPermission($mgr, 'payroll.runs.approve'));
$assert("CAN accounting.coa.view (Sprint 7a — needed for treasury+close visibility)",
    RBAC::hasPermission($mgr, 'accounting.coa.view'));

// ---------------------------------------------------------------------------
echo "\nemployee (very limited)\n";
$emp = ['role' => 'employee'];
$assert("can people.view",         RBAC::hasPermission($emp, 'people.view'));
$assert("CANNOT people.manage",    !RBAC::hasPermission($emp, 'people.manage'));
$assert("CANNOT payroll.view",     !RBAC::hasPermission($emp, 'payroll.view'));

// ---------------------------------------------------------------------------
echo "\nUnknown roles → default deny\n";
$weird = ['role' => 'no_such_role'];
$assert("unknown role grants nothing",
    !RBAC::hasPermission($weird, 'people.view'));

// ---------------------------------------------------------------------------
echo "\nPattern matcher edge cases\n";
// We test indirectly by configuring a synthetic role via reflection-free
// approach: use a temp config file.
$tmpConfig = sys_get_temp_dir() . '/rbac_test_' . getmypid() . '.php';
file_put_contents($tmpConfig, '<?php return ' . var_export([
    'tester' => [
        'people.banking.*',     // module-specific wildcard
        'reports.exact.thing',  // exact only
    ],
], true) . ';');
RBAC::reset();
RBAC::loadConfig($tmpConfig);

$tester = ['role' => 'tester'];
$assert("module-specific wildcard: people.banking.view OK",
    RBAC::hasPermission($tester, 'people.banking.view'));
$assert("module-specific wildcard: people.banking.manage OK",
    RBAC::hasPermission($tester, 'people.banking.manage'));
$assert("module-specific wildcard does NOT leak: people.view denied",
    !RBAC::hasPermission($tester, 'people.view'));
$assert("exact match works",
    RBAC::hasPermission($tester, 'reports.exact.thing'));
$assert("exact does not match prefix",
    !RBAC::hasPermission($tester, 'reports.exact.thing.extra'));

@unlink($tmpConfig);
RBAC::reset();

// ---------------------------------------------------------------------------
echo "\n";
echo "Total: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
