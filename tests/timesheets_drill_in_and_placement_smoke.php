<?php
/**
 * Smoke — Time + Placements UX Batch 2 (2026-02).
 *
 * Locks the timesheet drill-in + placement-scoped timesheet history
 * shipped in Batch 2:
 *   - modules/staffing/api/timesheets.php (detail, list_for_placement,
 *     detail_for_placement actions)
 *   - modules/staffing/ui/TimesheetsList.jsx (list page)
 *   - modules/staffing/ui/TimesheetDetail.jsx (drill-in)
 *   - modules/placements/ui/PlacementTimesheetsTab.jsx (placement tab)
 *   - modules/staffing/ui/StaffingModule.jsx (route sub-router)
 *   - modules/placements/ui/PlacementDetail.jsx (Timesheets tab wired)
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};

// ──────────────────────────────────────────────────────────────────────
// 1) API additions
// ──────────────────────────────────────────────────────────────────────
echo "\n── API endpoints ──\n";
$api = file_get_contents('/app/modules/staffing/api/timesheets.php');
$a('detail action wired',
    str_contains($api, "'GET' && \$action === 'detail'"));
$a('list_for_placement action wired',
    str_contains($api, "'GET' && \$action === 'list_for_placement'"));
$a('detail_for_placement action wired',
    str_contains($api, "'GET' && \$action === 'detail_for_placement'"));

// detail action surface
$a('detail joins people for worker name',
    preg_match("/'GET' && \\\$action === 'detail'[\s\S]{0,800}LEFT JOIN people p ON p\\.id = t\\.person_id/", $api) === 1);
$a('detail returns entries joined to placements',
    preg_match("/'GET' && \\\$action === 'detail'[\s\S]{0,1400}LEFT JOIN placements pl ON pl\\.id = te\\.placement_id/", $api) === 1);
$a('detail filters out superseded entries',
    preg_match("/'GET' && \\\$action === 'detail'[\s\S]{0,1400}te\\.status != 'superseded'/", $api) === 1);
$a('detail 404s when timesheet not found',
    str_contains($api, "api_error('timesheet not found', 404)"));

// list_for_placement surface
$a('list_for_placement requires placement_id',
    preg_match("/'GET' && \\\$action === 'list_for_placement'[\s\S]{0,400}placement_id required/", $api) === 1);
$a('list_for_placement filters by te.placement_id',
    str_contains($api, "'te.placement_id = :plid'"));
$a('list_for_placement aggregates placement_hours via SUM(te.hours)',
    str_contains($api, 'SUM(te.hours) AS placement_hours'));
$a('list_for_placement aggregates billable_hours separately',
    str_contains($api, "SUM(CASE WHEN te.billable = 1 THEN te.hours ELSE 0 END) AS billable_hours"));
$a('list_for_placement accepts status filter',
    preg_match("/'GET' && \\\$action === 'list_for_placement'[\s\S]{0,800}\\\$_GET\\['status'\\][\s\S]{0,200}\\\$params\\['s'\\]/", $api) === 1);

// detail_for_placement surface
$a('detail_for_placement requires both id and placement_id',
    preg_match("/'GET' && \\\$action === 'detail_for_placement'[\s\S]{0,400}id required[\s\S]{0,400}placement_id required/", $api) === 1);
$a('detail_for_placement scopes entries to one placement',
    preg_match("/'GET' && \\\$action === 'detail_for_placement'[\s\S]{0,1400}te\\.placement_id = :plid/", $api) === 1);
$a('detail_for_placement returns aggregated placement_hours',
    preg_match("/'GET' && \\\$action === 'detail_for_placement'[\s\S]{0,1800}'placement_hours'/", $api) === 1);

// ──────────────────────────────────────────────────────────────────────
// 2) React: TimesheetsList
// ──────────────────────────────────────────────────────────────────────
echo "\n── TimesheetsList.jsx ──\n";
$list = file_get_contents('/app/modules/staffing/ui/TimesheetsList.jsx');
$a('uses /modules/staffing/api/timesheets.php?action=list',
    str_contains($list, "/modules/staffing/api/timesheets.php?"));
$a('lists action=list',
    str_contains($list, "action: 'list'"));
foreach ([
    'timesheets-list-page',
    'timesheets-list-current-week',
    'timesheets-list-filter-status',
    'timesheets-list-filter-period-start',
    'timesheets-list-filter-period-end',
    'timesheets-list-filter-person',
    'timesheets-list-reload',
    'timesheets-list-empty',
    'timesheets-list-table',
] as $tid) {
    $a("testid '{$tid}' present", str_contains($list, "data-testid=\"{$tid}\""));
}
foreach ([
    'timesheets-list-row-${r.id}',
    'timesheets-list-open-${r.id}',
    'timesheet-status-${status}',
] as $template) {
    $a("template testid '{$template}' present",
        str_contains($list, "data-testid={`{$template}`}"));
}

// ──────────────────────────────────────────────────────────────────────
// 3) React: TimesheetDetail
// ──────────────────────────────────────────────────────────────────────
echo "\n── TimesheetDetail.jsx ──\n";
$det = file_get_contents('/app/modules/staffing/ui/TimesheetDetail.jsx');
$a('uses ?action=detail',
    str_contains($det, '?action=detail'));
$a('uses ?action=detail_for_placement when placement_id is set',
    str_contains($det, '?action=detail_for_placement&id=${id}&placement_id=${placementId}'));
$a('approve action calls POST timesheets ?action=approve',
    str_contains($det, "'/modules/staffing/api/timesheets.php?action=' + action")
    || str_contains($det, "api.post(`/modules/staffing/api/timesheets.php?action=${action}"));
$a('reject action requires a reason in form',
    str_contains($det, 'Rejection reason'));
foreach ([
    'timesheet-detail-page',
    'timesheet-detail-loading',
    'timesheet-detail-error',
    'timesheet-detail-empty',
    'timesheet-detail-title',
    'timesheet-detail-meta',
    'timesheet-detail-back',
    'timesheet-detail-edit',
    'timesheet-detail-approve',
    'timesheet-detail-reject-open',
    'timesheet-detail-reject-form',
    'timesheet-detail-reject-reason',
    'timesheet-detail-reject-confirm',
    'timesheet-detail-entries',
    'timesheet-detail-entries-empty',
    'timesheet-detail-by-placement',
] as $tid) {
    $a("testid '{$tid}' present", str_contains($det, "data-testid=\"{$tid}\""));
}
foreach ([
    'timesheet-detail-entry-${e.id}',
    'timesheet-detail-by-placement-row-${k}',
    'timesheet-status-${status}',
] as $template) {
    $a("template testid '{$template}' present",
        str_contains($det, "data-testid={`{$template}`}"));
}

// ──────────────────────────────────────────────────────────────────────
// 4) React: PlacementTimesheetsTab
// ──────────────────────────────────────────────────────────────────────
echo "\n── PlacementTimesheetsTab.jsx ──\n";
$ptab = file_get_contents('/app/modules/placements/ui/PlacementTimesheetsTab.jsx');
$a('hits list_for_placement endpoint',
    str_contains($ptab, '?action=list_for_placement&placement_id=${pid}'));
$a('splits rows into pending vs history',
    str_contains($ptab, "r.status === 'submitted'")
    && str_contains($ptab, "r.status !== 'submitted'"));
$a('renders create-new CTA linking to timesheet week',
    str_contains($ptab, 'to="/modules/staffing/timesheets/week"'));
foreach ([
    'placement-timesheets-tab',
    'placement-timesheets-create-new',
    'placement-timesheets-pending-empty',
    'placement-timesheets-history-empty',
] as $tid) {
    $a("testid '{$tid}' present", str_contains($ptab, "data-testid=\"{$tid}\""));
}
foreach ([
    'placement-timesheets-${mode}-table',
    'placement-timesheets-${mode}-row-${r.id}',
    'placement-timesheets-open-${r.id}',
] as $template) {
    $a("template testid '{$template}' present",
        str_contains($ptab, "data-testid={`{$template}`}"));
}

// ──────────────────────────────────────────────────────────────────────
// 5) StaffingModule routes
// ──────────────────────────────────────────────────────────────────────
echo "\n── StaffingModule wiring ──\n";
$smod = file_get_contents('/app/modules/staffing/ui/StaffingModule.jsx');
$a('imports TimesheetsList',
    str_contains($smod, "import TimesheetsList from './TimesheetsList'"));
$a('imports TimesheetDetail',
    str_contains($smod, "import TimesheetDetail from './TimesheetDetail'"));
$a('timesheets index route → TimesheetsList',
    preg_match('/path="timesheets"\s*element=\{<TimesheetsList/', $smod) === 1);
$a('timesheets/week route → TimesheetWeek',
    preg_match('/path="timesheets\/week"\s*element=\{<TimesheetWeek/', $smod) === 1);
$a('timesheets/:id route → TimesheetDetail',
    preg_match('/path="timesheets\/:id"\s*element=\{<TimesheetDetail/', $smod) === 1);

// ──────────────────────────────────────────────────────────────────────
// 6) PlacementDetail wiring
// ──────────────────────────────────────────────────────────────────────
echo "\n── PlacementDetail wiring ──\n";
$pdet = file_get_contents('/app/modules/placements/ui/PlacementDetail.jsx');
$a('imports PlacementTimesheetsTab',
    str_contains($pdet, "import PlacementTimesheetsTab from './PlacementTimesheetsTab'"));
$a('Timesheets tab slug present in TABS array',
    str_contains($pdet, "{ slug: 'timesheets',  label: 'Timesheets' }"));
$a('timesheets route mounted to PlacementTimesheetsTab',
    preg_match('/path="timesheets" element=\{<PlacementTimesheetsTab/', $pdet) === 1);

// ──────────────────────────────────────────────────────────────────────
echo "\n=========================================\n";
echo "Time/Placements UX Batch 2 smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
