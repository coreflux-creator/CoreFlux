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
$timeExport = $read('modules/time/api/csv_export.php');
$timeMyTime = $read('modules/time/ui/MyTime.jsx');
$exportDatasets = $read('core/export_datasets.php');
$timeImportUi = $read('modules/time/ui/CsvImport.jsx');
$timesheets = $read('modules/staffing/api/timesheets.php');
$timesheetsUi = $read('modules/staffing/ui/TimesheetsList.jsx');
$staffingTimesheetLib = $read('modules/staffing/lib/timesheets.php');
$payrollRuns = $read('modules/payroll/ui/PayrollRuns.jsx');
$placementList = $read('modules/placements/ui/List.jsx');
$placementDetail = $read('modules/placements/ui/PlacementDetail.jsx');
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
foreach (['Timesheets','Upload','Review','Settlement','Import time','Weekly periods','Reports'] as $label) {
    $assert("time navigation includes {$label}", str_contains($timeNav, "label: '{$label}'"));
}
$assert('time module renders shared workflow navigation', str_contains($timeModule, '<TimeWorkspaceNav />'));
$assert('legacy timesheet list renders the same workflow navigation', str_contains($timesheetsUi, '<TimeWorkspaceNav />'));
$assert('review API supports bulk approval', str_contains($timeEntries, "\$action === 'bulk_approve'"));
$assert('review API supports bulk rejection', str_contains($timeEntries, "\$action === 'bulk_reject'"));
$assert('weekly time submission uses one atomic bulk endpoint',
    str_contains($timeEntries, "\$action === 'bulk_submit'")
    && str_contains($timeLib, 'function timeSubmitEntries')
    && str_contains($timeMyTime, 'action=bulk_submit')
    && !str_contains($timeMyTime, 'Promise.allSettled'));
$assert('time submission validates every row before its bulk update',
    str_contains($timeLib, 'Only draft entries can be submitted:')
    && str_contains($timeLib, 'FOR UPDATE')
    && str_contains($timeLib, 'Time entries changed while being submitted'));
$assert('self-service time submission cannot submit another person\'s entries',
    str_contains($timeLib, 'function timePersonIdForUser')
    && str_contains($timeLib, 'You can only submit your own time entries')
    && str_contains($timeEntries, "rbac_legacy_can(\$user, 'time.entry.manage')"));
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
$assert('zero-hour timesheets are hidden by default',
    str_contains($timesheets, "empty(\$_GET['include_empty'])")
    && str_contains($timesheets, 't.total_hours > 0')
    && str_contains($timesheetsUi, 'Show zero-hour records'));
$assert('timesheet permissions preserve the resolved platform-admin context',
    str_contains($timesheets, 'function staffingApiCanPermission(array $ctx')
    && str_contains($timesheets, "api_require_legacy_permission(\$ctx, 'staffing.time.view')")
    && !str_contains($timesheets, 'rbac_legacy_require($user')
    && !str_contains($timesheets, 'rbac_legacy_can($user'));
$assert('empty weeks cannot be submitted or approved',
    str_contains($staffingTimesheetLib, "staffingTimesheetRequirePositiveEntries(\$headerId, 'submit')")
    && str_contains($staffingTimesheetLib, "staffingTimesheetRequirePositiveEntries(\$headerId, 'approve', \$tenantId)")
    && str_contains($staffingTimesheetLib, 'Add at least one positive-hour entry'));

echo "\nTime CSV safeguards\n";
$assert('time CSV accepts Placement ID for export/import round trips',
    str_contains($timeImport, "'placement_id'")
    && str_contains($timeImportUi, "key: 'placement_display_id'")
    && str_contains($timeExport, "'placement_id'          => 'Placement ID'"));
$assert('time CSV carries Entry ID and source keys for deterministic updates',
    str_contains($timeImport, "'entry_id'")
    && str_contains($timeExport, "'entry_id'              => 'Entry ID'")
    && str_contains($timeExport, "'external_id'           => 'External ID (source row)'")
    && str_contains($timeExport, "'source_system'         => 'Source system'"));
$assert('time CSV Entry ID updates are explicit and reject locked rows',
    str_contains($timeImport, 'enable Update existing rows')
    && str_contains($timeImport, 'has already been settled and cannot be updated by CSV')
    && str_contains($timeImport, "['draft', 'pending_review', 'rejected']"));
$assert('time CSV resolves the Placements module tenant explicitly',
    str_contains($timeImport, "effectiveTenantIdForModule('placements'"));
$assert('time export resolves People and Placements module tenants explicitly',
    str_contains($exportDatasets, "effectiveTenantIdForModule('placements', \$tenantId)")
    && str_contains($exportDatasets, "effectiveTenantIdForModule('people', \$tenantId)")
    && str_contains($exportDatasets, 'pl.tenant_id = :placements_tenant_id')
    && str_contains($exportDatasets, 'pe.tenant_id = :people_tenant_id'));
$assert('time CSV validates placement start and end dates',
    str_contains($timeImport, 'Work date is before placement start date')
    && str_contains($timeImport, 'Work date is after placement end date'));
$assert('time CSV enforces per-row and person/day hour limits',
    str_contains($timeImport, 'Hours must be greater than zero')
    && str_contains($timeImport, 'Total daily time on')
    && str_contains($timeImport, 'would exceed 24 hours'));
$assert('time CSV is atomic by default and partial import stays opt-in',
    str_contains($timeImport, 'cf_tx_begin($pdo)')
    && str_contains($timeImport, 'cf_tx_rollback($pdo, $ownsTxn)')
    && str_contains($timeImport, 'if (!$skipInvalid && $errors)'));
$assert('time CSV creates weekly containers and links every imported row',
    str_contains($timeImport, 'timeOpenPeriodIdForDate(')
    && str_contains($timeImport, 'staffingTimesheetUpsert(')
    && str_contains($timeImport, "'timesheet_id'  => \$timesheetId"));
$assert('row state changes reconcile the weekly header',
    str_contains($timeLib, 'function timeReconcileTimesheetHeader')
    && str_contains($timeLib, 'timeReconcileTimesheetHeader((int)'));
$assert('time import success links directly to review and settlement',
    str_contains($timeImportUi, "to: '../review'")
    && str_contains($timeImportUi, "to: '../settlement'"));
$assert('time import success links to the weekly timesheet workspace',
    str_contains($timeImportUi, "to: '/modules/staffing/timesheets'"));
$assert('weekly time workspace exposes its matching CSV export',
    str_contains($timeMyTime, 'data-testid="time-my-time-csv-export-link"')
    && str_contains($timeMyTime, 'csv-export?from=${from}&to=${to}'));

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
$assert('internal GraphQL pilot is not exposed on a placement record',
    !str_contains($placementDetail, 'placement-detail-switch-gql')
    && !str_contains($placementDetail, '>⚡ GraphQL pilot</Link>'));

echo "\nPayroll empty state\n";
$assert('empty runs link to pay periods', str_contains($payrollRuns, 'data-testid="payroll-runs-open-periods"'));
$assert('empty runs link to pay schedules', str_contains($payrollRuns, 'data-testid="payroll-runs-open-schedules"'));

echo "\nPHP syntax\n";
foreach ([
    'api/index.php',
    'modules/time/api/entries.php',
    'modules/time/api/csv_import.php',
    'modules/time/api/csv_export.php',
    'modules/time/lib/time.php',
    'modules/staffing/api/timesheets.php',
    'core/export_datasets.php',
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
