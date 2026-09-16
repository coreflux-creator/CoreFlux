<?php
declare(strict_types=1);

$pass = 0;
$fail = 0;
$assert = static function (string $label, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
};
$root = dirname(__DIR__);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$router = $read('api/index.php');
$timeModule = $read('modules/time/ui/TimeModule.jsx');
$timeNav = $read('modules/time/ui/TimeWorkspaceNav.jsx');
$timeReview = $read('modules/time/ui/ReviewQueue.jsx');
$timeEntries = $read('modules/time/api/entries.php');
$timeLib = $read('modules/time/lib/time.php');
$timeImport = $read('modules/time/api/csv_import.php');
$timeImportUi = $read('modules/time/ui/CsvImport.jsx');
$timesheets = $read('modules/staffing/api/timesheets.php');
$timesheetsUi = $read('modules/staffing/ui/TimesheetsList.jsx');
$payrollRuns = $read('modules/payroll/ui/PayrollRuns.jsx');
$placementList = $read('modules/placements/ui/List.jsx');
$placementExport = $read('modules/placements/api/csv_export.php');
$placementImport = $read('modules/placements/api/csv_import.php');
$placementImportUi = $read('modules/placements/ui/CsvImport.jsx');

echo "Central API router scope\n";
$compatPos = strpos($router, 'apiRouterApplyV1Compatibility($parsed)');
$scopePos = strpos($router, "setRequestModuleScope(\$parsed['module_id'])");
$authPos = strpos($router, '$authCtx = api_require_auth()');
$assert('v1 compatibility is applied by the router', $compatPos !== false);
$assert('module tenant scope is pinned by the router', $scopePos !== false);
$assert('compatibility and scope are applied before auth/dispatch',
    $compatPos !== false && $scopePos !== false && $authPos !== false
    && $compatPos < $authPos && $scopePos < $authPos);
$assert('router uses alias-aware base permission helper', str_contains($router, 'apiRouterBasePermission($parsed)'));

echo "\nTime workspace and bulk review\n";
foreach (['Timesheets','Upload','Review','Settlement','CSV import','Pay periods','Reports'] as $label) {
    $assert("time navigation includes {$label}", str_contains($timeNav, "label: '{$label}'"));
}
$assert('time module renders shared workflow navigation', str_contains($timeModule, '<TimeWorkspaceNav />'));
$assert('legacy timesheet list renders the same workflow navigation', str_contains($timesheetsUi, '<TimeWorkspaceNav />'));
$assert('review API supports bulk approval', str_contains($timeEntries, "\$action === 'bulk_approve'"));
$assert('review API supports bulk rejection', str_contains($timeEntries, "\$action === 'bulk_reject'"));
$assert('bulk review caps requests at 500 entries', str_contains($timeEntries, 'Too many ids (max 500 per call)'));
$assert('single and bulk approval share one helper',
    str_contains($timeLib, 'function timeApproveEntry')
    && substr_count($timeEntries, 'timeApproveEntry(') >= 2);
$assert('bulk failures return row reasons',
    str_contains($timeEntries, "'results' => \$results")
    && str_contains($timeReview, 'failedRows.slice(0, 3)'));
$assert('review UI can select all and approve or reject selected entries',
    str_contains($timeReview, 'data-testid="time-review-select-all"')
    && str_contains($timeReview, 'data-testid="time-review-approve-selected"')
    && str_contains($timeReview, 'data-testid="time-review-reject-selected"'));
$assert('empty draft timesheets are hidden by default',
    str_contains($timesheets, "empty(\$_GET['include_empty'])")
    && str_contains($timesheetsUi, 'Show empty drafts'));

echo "\nTime CSV safeguards\n";
$assert('time CSV accepts Placement ID for export/import round trips',
    str_contains($timeImport, "'placement_id'")
    && str_contains($timeImportUi, "key: 'placement_id'"));
$assert('time CSV resolves the Placements module tenant explicitly',
    str_contains($timeImport, "effectiveTenantIdForModule('placements'"));
$assert('time CSV validates placement start and end dates',
    str_contains($timeImport, 'work_date precedes placement start_date')
    && str_contains($timeImport, 'work_date is after placement end_date'));
$assert('time CSV enforces per-row and person/day hour limits',
    str_contains($timeImport, 'hours must be greater than 0 and no more than 24')
    && str_contains($timeImport, 'total hours for this person and work date would exceed 24'));
$assert('time import success links directly to review and settlement',
    str_contains($timeImportUi, "to: '../review'")
    && str_contains($timeImportUi, "to: '../settlement'"));

echo "\nPlacement CSV round trip\n";
$assert('placement import accepts exported status values',
    str_contains($placementImport, "'status'            => ['label' => 'Status'"));
$assert('placement import updates matched status through readiness checks',
    str_contains($placementImport, 'placementsRequireActiveReady(')
    && str_contains($placementImport, "'via' => 'csv_import'"));
$assert('placement import defaults to updating matching IDs',
    str_contains($placementImportUi, 'defaultUpdateExisting'));
$assert('placement export follows search and client filters',
    str_contains($placementList, "params.set('q', q)")
    && str_contains($placementList, "params.set('end_client_company_id', endClientCompanyId)")
    && str_contains($placementExport, "\$_GET['end_client_company_id']")
    && str_contains($placementExport, "\$_GET['q']"));
$assert('placement export joins People under People tenant scope',
    str_contains($placementExport, "effectiveTenantIdForModule('people'")
    && str_contains($placementExport, 'pe.tenant_id = :people_tenant_id'));
$assert('internal GraphQL pilot is not exposed in the placement menu',
    !str_contains($placementList, 'placements-try-graphql-btn'));

echo "\nPayroll empty state\n";
$assert('empty runs link to pay periods', str_contains($payrollRuns, 'data-testid="payroll-runs-open-periods"'));
$assert('empty runs link to pay schedules', str_contains($payrollRuns, 'data-testid="payroll-runs-open-schedules"'));

echo "\nPHP syntax\n";
foreach ([
    'api/index.php',
    'modules/time/api/entries.php',
    'modules/time/api/csv_import.php',
    'modules/time/lib/time.php',
    'modules/placements/api/csv_export.php',
    'modules/placements/api/csv_import.php',
    'modules/placements/lib/rate_approve.php',
] as $path) {
    $output = [];
    $rc = 0;
    exec('php -l ' . escapeshellarg($root . '/' . $path) . ' 2>&1', $output, $rc);
    $assert("php -l {$path}", $rc === 0);
}

echo "\nOperational workflow smoke: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
