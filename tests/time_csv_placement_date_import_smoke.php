<?php
/**
 * Time CSV import by Placement ID + date.
 *
 * Locks the operator contract: a simple placement/date/hours file resolves
 * the worker, joins the weekly staffing workflow, and remains safe to rerun.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/core/CsvImportService.php';
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$pass = 0;
$fail = 0;
$assert = static function (string $label, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    {$label}\n"; }
    else { $fail++; echo "  FAIL  {$label}\n"; }
};

$api = $read('modules/time/api/csv_import.php');
$ui = $read('modules/time/ui/CsvImport.jsx');
$list = $read('modules/staffing/ui/TimesheetsList.jsx');
$nav = $read('modules/time/ui/TimeWorkspaceNav.jsx');
$shared = $read('dashboard/src/components/CsvImportPage.jsx');
$export = $read('modules/time/api/csv_export.php');
$rbac = $read('core/rbac/legacy_map.php');

echo "Time import schema and downloads\n";
$assert('Placement ID is a typed import field',
    str_contains($api, "'placement_id'          => ['label' => 'Placement ID', 'type' => 'integer']"));
$assert('legacy placement external ID remains accepted',
    str_contains($api, "'label' => 'Source placement ID (optional)'")
    && str_contains($api, "'aliases' => ['Placement external ID']"));
$assert('template contains exactly placement/date/hours',
    str_contains($api, "timeCsvStream('time_import_by_placement.csv', [\n        'Placement ID', 'Work date', 'Hours',\n    ]);"));
$assert('sample uses the same three-column contract',
    str_contains($api, "timeCsvStream('time_import_sample.csv', [\n        'Placement ID', 'Work date', 'Hours',\n    ], \$sampleRows);"));
$assert('downloaded samples use displayed PL IDs',
    str_contains($api, "timeCsvPlacementCode(\$placement['placement_id'])"));
$assert('placement reference download is available',
    str_contains($api, "action === 'placement_reference'") && str_contains($api, 'placement_id_reference_'));
$assert('blank time type defaults to regular',
    str_contains($api, "if (\$hourType === '') \$hourType = timeCsvCategoryToHourType"));
$assert('weekly aggregate rows are accepted',
    str_contains($api, 'if ($hours > 168)'));

\Core\CsvImportService::registerSchema('__time_id_contract', [
    'fields' => [
        'placement_id' => ['label' => 'Placement ID', 'type' => 'integer'],
        'placement_external_id' => [
            'label' => 'Source placement ID (optional)',
            'aliases' => ['Placement external ID'],
        ],
        'work_date' => ['label' => 'Work date', 'required' => true, 'type' => 'date'],
        'hours' => ['label' => 'Hours', 'required' => true, 'type' => 'number'],
    ],
]);
$parsed = \Core\CsvImportService::dryRun(
    '__time_id_contract',
    "Placement ID,Work date,Hours\nPL-760,2026-09-19,8\n41,2026-09-19,7.5\n"
);
$assert('PL-prefixed Placement ID resolves to the internal integer',
    ($parsed['rows'][2]['placement_id'] ?? null) === 760 && !isset($parsed['errors'][2]));
$assert('bare numeric Placement ID remains backwards compatible',
    ($parsed['rows'][3]['placement_id'] ?? null) === 41 && !isset($parsed['errors'][3]));
$legacyInspect = \Core\CsvImportService::inspect(
    '__time_id_contract',
    "Placement external ID,Work date,Hours\nJD-760,2026-09-19,8\n"
);
$assert('legacy Placement external ID header still auto-maps',
    ($legacyInspect['auto_map'][0] ?? null) === 'placement_external_id');

echo "\nPlacement resolution and workflow linkage\n";
$assert('placement lookup uses shared placement scope',
    str_contains($api, "effectiveTenantIdForModule('placements')"));
$assert('preview resolves person and client',
    str_contains($api, "\$row['person_name']") && str_contains($api, "\$row['end_client_name']"));
$assert('date is validated against placement range',
    str_contains($api, 'before placement start date') && str_contains($api, 'after placement end date'));
$assert('duplicate placement/date/type rows are rejected',
    str_contains($api, 'Duplicate placement/date/time type in this file'));
$assert('missing periods are created automatically',
    str_contains($api, 'timeOpenPeriodIdForDate') && str_contains($api, 'timeCsvEnsurePeriod'));
$assert('weekly staffing header is created or reused',
    str_contains($api, 'staffingTimesheetUpsert'));
$assert('entries are linked to the staffing timesheet',
    str_contains($api, "'timesheet_id'  => \$timesheetId"));
$assert('timesheet totals and status are refreshed',
    str_contains($api, 'timeCsvRefreshTimesheet') && str_contains($api, 'timeReconcileTimesheetHeader'));
$assert('repeat import supports source-row and composite matching',
    str_contains($api, 'source_system = :s AND external_id = :e')
    && str_contains($api, 'placement_id = :pl AND person_id = :p'));

echo "\nOperator UI\n";
$assert('time workflow names the importer plainly',
    str_contains($nav, "label: 'Import time'") && str_contains($nav, "to: '/modules/time/bulk'"));
$assert('timesheet list has a prominent import action',
    str_contains($list, 'data-testid="timesheets-list-import"') && str_contains($list, 'Import time'));
$assert('import page explains placement/date behavior',
    str_contains($ui, 'PL-760') && str_contains($ui, 'exactly three columns'));
$assert('preview keeps the displayed PL-prefixed ID',
    str_contains($ui, "key: 'placement_display_id'"));
$assert('import page links placement reference',
    str_contains($ui, 'action=placement_reference'));
$assert('time reruns update existing rows by default',
    str_contains($ui, 'defaultUpdateExisting'));
$assert('commit errors remain visible to operator',
    str_contains($shared, 'Rows needing attention') && str_contains($shared, 'committedErrors'));

echo "\nRound trip and permissions\n";
$assert('time export includes internal placement ID',
    str_contains($export, "'placement_id'          => 'Placement ID'"));
$assert('time export includes type and source identity',
    str_contains($export, "'hour_type'             => 'Time type'")
    && str_contains($export, "'external_id'           => 'External ID (source row)'"));
$assert('staffing write aliases map to staffing module',
    str_contains($rbac, "'staffing.time.create'") && str_contains($rbac, "'staffing.timesheets.write'"));

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
