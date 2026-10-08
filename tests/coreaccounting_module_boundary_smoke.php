<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/accounting/standalone_boundary.php';

$checks = [];
$check = static function (string $label, bool $passed) use (&$checks): void {
    $checks[] = $passed;
    echo ($passed ? 'OK  ' : 'FAIL  ') . $label . PHP_EOL;
};

foreach (['accounting', 'billing', 'ap', 'treasury'] as $module) {
    $check("standalone permits {$module}", coreAccountingAllowsModule($module, 'coreaccounting'));
}
foreach (['payroll', 'time', 'staffing', 'placements', 'people'] as $module) {
    $check("standalone denies {$module}", !coreAccountingAllowsModule($module, 'coreaccounting'));
}
$check('ERP retains non-finance modules', coreAccountingAllowsModule('payroll', 'production'));
$check('flat core API remains for separate audit', coreAccountingAllowsModule(null, 'coreaccounting'));
$check('direct module endpoint comes from the server path',
    coreAccountingRequestModule('/srv/app/modules/payroll/api/runs.php', 'accounting') === 'payroll');
$check('router retains its parsed module scope',
    coreAccountingRequestModule('/srv/app/api/index.php', 'time') === 'time');
$check('Windows module paths are recognized',
    coreAccountingRequestModule('C:\\app\\modules\\staffing\\api\\timesheets.php', null) === 'staffing');

$bootstrap = (string) file_get_contents(__DIR__ . '/../core/api_bootstrap.php');
$router = (string) file_get_contents(__DIR__ . '/../api/index.php');
$check('common API auth applies standalone module policy',
    str_contains($bootstrap, "coreAccountingRequestModule(\$_SERVER['SCRIPT_FILENAME'] ?? null, currentModuleKey())")
    && str_contains($bootstrap, "coreAccountingAllowsModule(\$moduleKey, (string) getenv('COREFLUX_ENV'))"));
$check('direct module request is rejected before auto-migration',
    strpos($bootstrap, "coreAccountingRequestModule(\$_SERVER['SCRIPT_FILENAME'] ?? null, null)")
        < strpos($bootstrap, 'coreflux_run_migrations()')
    && strpos($bootstrap, 'if (!coreAccountingAllowsModule($directModule')
        < strpos($bootstrap, 'coreflux_run_migrations()'));
$check('router pins module before common auth',
    strpos($router, "setRequestModuleScope(\$parsed['module_id'])")
        < strpos($router, '$authCtx = api_require_auth()'));
$check('router rejects non-finance scope before shared bootstrap',
    strpos($router, 'coreAccountingAllowsModule($preflight[\'module_id\'], \'coreaccounting\')')
        < strpos($router, "require_once __DIR__ . '/../core/api_bootstrap.php'"));

$failed = count(array_filter($checks, static fn(bool $passed): bool => !$passed));
echo $failed ? "Failed: {$failed}" . PHP_EOL : 'Passed: ' . count($checks) . PHP_EOL;
exit($failed ? 1 : 0);
