<?php
/** Structural smoke for bulk payroll setup and cycle-safe run creation. */
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$checks = [];
$check = static function (string $label, bool $passed) use (&$checks, &$failures): void {
    $checks[] = $label;
    echo ($passed ? 'OK  ' : 'BAD ') . $label . PHP_EOL;
    if (!$passed) $failures[] = $label;
};

$files = [
    'modules/payroll/api/profiles.php',
    'modules/payroll/api/profiles_csv_import.php',
    'modules/payroll/api/profiles_csv_export.php',
    'modules/payroll/api/pay_periods.php',
    'modules/payroll/api/preflight.php',
    'modules/payroll/api/runs.php',
    'modules/payroll/lib/profiles.php',
    'modules/payroll/lib/payroll.php',
    'modules/payroll/lib/cycles.php',
    'modules/payroll/ui/PayrollProfiles.jsx',
    'modules/payroll/ui/PayrollProfilesCsvImport.jsx',
];
foreach ($files as $file) $check("{$file} exists", is_file("{$root}/{$file}"));

$import = (string) file_get_contents("{$root}/modules/payroll/api/profiles_csv_import.php");
$export = (string) file_get_contents("{$root}/modules/payroll/api/profiles_csv_export.php");
$profiles = (string) file_get_contents("{$root}/modules/payroll/api/profiles.php");
$profileLib = (string) file_get_contents("{$root}/modules/payroll/lib/profiles.php");
$payrollLib = (string) file_get_contents("{$root}/modules/payroll/lib/payroll.php");
$runs = (string) file_get_contents("{$root}/modules/payroll/api/runs.php");
$periods = (string) file_get_contents("{$root}/modules/payroll/api/pay_periods.php");
$periodUi = (string) file_get_contents("{$root}/modules/payroll/ui/PayPeriods.jsx");
$profileUi = (string) file_get_contents("{$root}/modules/payroll/ui/PayrollProfiles.jsx");
$profileEdit = (string) file_get_contents("{$root}/modules/payroll/ui/PayrollProfileEdit.jsx");
$runUi = (string) file_get_contents("{$root}/modules/payroll/ui/PayrollRunDetail.jsx");
$manifest = (string) file_get_contents("{$root}/modules/payroll/manifest.php");

$check('profile import supports template, inspection, dry run, and commit',
    str_contains($import, "['template', 'sample']")
    && str_contains($import, "action === 'inspect'")
    && str_contains($import, "action === 'dry_run'")
    && str_contains($import, "action === 'commit'"));
$check('profile import resolves employee, schedule, and cycle IDs or names',
    str_contains($import, 'employee_number')
    && str_contains($import, 'schedule_name')
    && str_contains($import, 'cycle_name'));
$check('profile import validates multi-cycle assignment',
    str_contains($import, 'this schedule has multiple active cycles; choose one'));
$check('profile import uses human percentages and dollars',
    str_contains($import, 'retirement_percent')
    && str_contains($import, 'round($retirement * 100)')
    && str_contains($import, 'round($health * 100)'));
$check('profile import is transactional by default',
    str_contains($import, '$pdo->beginTransaction()')
    && str_contains($import, '$pdo->rollBack()'));
$check('profile export contains round-trip identifiers and setup gaps',
    str_contains($export, "'employee_number'")
    && str_contains($export, "'cycle_id'")
    && str_contains($export, "'setup_gaps'"));
$check('profile writes have explicit read and manage permissions',
    str_contains($profiles, "'payroll.profiles.view'")
    && str_contains($profiles, "'payroll.profiles.manage'"));
$check('string false is not treated as enabled',
    str_contains($profileLib, 'function payrollProfileBoolean')
    && str_contains($profileLib, "['0', 'false', 'no', 'n', 'off', '']"));

$check('run employee selection is exact-cycle scoped',
    str_contains($payrollLib, 'p.cycle_id = :cycle')
    && str_contains($payrollLib, 'SELECT COUNT(*) FROM payroll_pay_cycles'));
$check('run creation returns the existing same-type run',
    str_contains($runs, 'status <> \'voided\'')
    && str_contains($runs, "'existing' => true"));
$check('compute fails loudly instead of silently dropping employees',
    str_contains($runs, 'is missing an active compensation, federal tax, or payroll profile record'));
$check('cycle advance records the creating user on the draft',
    str_contains((string) file_get_contents("{$root}/modules/payroll/lib/cycles.php"), "'created_by_user_id' => \$actorUserId"));
$check('period API exposes cycle and existing run',
    str_contains($periods, 'pc.name AS cycle_name')
    && str_contains($periods, 'latest_run_id'));
$check('period UI opens an existing run instead of creating a duplicate',
    str_contains($periodUi, 'p.latest_run_id')
    && str_contains($periodUi, 'payroll-period-open-run'));
$check('payroll register can populate the existing draft from run detail',
    str_contains($runUi, 'Import a completed payroll register')
    && str_contains($runUi, 'Download employee template'));

$check('profile UI offers filtered export and bulk import',
    str_contains($profileUi, 'payroll-profiles-import-csv')
    && str_contains($profileUi, 'payroll-profiles-export-csv'));
$check('profile UI paginates large employee rosters',
    str_contains($profileUi, 'payroll-profiles-pagination')
    && str_contains($profileUi, 'payroll-profiles-result-count')
    && str_contains($profileUi, 'const [perPage, setPerPage]'));
$check('profile editor displays dollars and percentages',
    str_contains($profileEdit, 'retirement_percent')
    && str_contains($profileEdit, 'health_premium')
    && str_contains($profileEdit, 'retirement_pretax_bps: Math.round'));
$check('manifest registers profile CSV audit events',
    str_contains($manifest, "'payroll.profile.csv_imported'")
    && str_contains($manifest, "'payroll.profile.csv_exported'"));

$phpFiles = array_filter($files, static fn(string $file): bool => str_ends_with($file, '.php'));
foreach ($phpFiles as $file) {
    $output = [];
    $code = 0;
    exec('php -l ' . escapeshellarg("{$root}/{$file}") . ' 2>&1', $output, $code);
    $check("{$file} lints", $code === 0);
}

echo PHP_EOL . count($checks) . ' checks, ' . count($failures) . ' failed.' . PHP_EOL;
if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
