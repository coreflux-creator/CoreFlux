<?php
/**
 * Smoke — Timesheet inline-edit + weekly-grid URL anchor (2026-02).
 *
 * Locks the fix for "we're back to issues with timesheets. I see where
 * they're available individually, but they don't do what they need to,
 * can't edit them. go back through the logic and fix it."
 *
 * Two changes shipped:
 *   1. TimesheetDetail.jsx now hosts an inline row editor + add-entry
 *      form wired to the existing entry_save / entry_delete endpoints
 *      (which auto-reopen submitted/approved sheets server-side).
 *   2. TimesheetWeek.jsx now reads ?period_start=YYYY-MM-DD&person_id=N
 *      from the URL — so the deep-link from TimesheetDetail's "Open
 *      weekly grid" button lands on THAT week for THAT worker, not on
 *      the logged-in user's current week (which was the actual bug).
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};

// ──────────────────────────────────────────────────────────────────────
// 1) TimesheetDetail.jsx — inline edit surface
// ──────────────────────────────────────────────────────────────────────
echo "\n── TimesheetDetail inline edit ──\n";
$det = file_get_contents('/app/modules/staffing/ui/TimesheetDetail.jsx');

$a('exists', $det !== false && strlen($det) > 100);

// API plumbing — must POST to entry_save & entry_delete (the existing
// auto-reopening endpoints) for inline edits.
$a('POSTs entry_save for inline row edits',
    str_contains($det, "?action=entry_save"));
$a('POSTs entry_delete for row deletion',
    str_contains($det, "?action=entry_delete"));
$a('POSTs reopen for the approved-state re-open button',
    str_contains($det, "?action=reopen"));
$a('POSTs submit for draft/rejected → submit transition',
    str_contains($det, "act('submit')"));

// "Open weekly grid" link must carry period_start + person_id so
// TimesheetWeek lands on the right context.
$a('Open weekly grid link includes period_start + person_id query params',
    str_contains($det, '../week?period_start=${ts.period_start}&person_id=${ts.person_id}'));
$a('Open weekly grid uses ghost-button styling',
    str_contains($det, 'data-testid="timesheet-detail-open-week"'));

// Read-only vs editable status branches.
$a('treats locked/payroll_ready/billing_ready as read-only',
    str_contains($det, "READ_ONLY_STATUSES = ['locked', 'payroll_ready', 'billing_ready']"));
$a('approved requires explicit re-open before edits',
    str_contains($det, 'isApproved')
    && str_contains($det, 'canEditRows = !isReadOnly && !isApproved'));
$a('reopen confirmation notice for approved sheets',
    str_contains($det, 'timesheet-detail-approved-notice'));
$a('readonly notice for locked downstream sheets',
    str_contains($det, 'timesheet-detail-readonly-notice'));

// Per-row testids — required so the testing/QA flows can target each row.
foreach ([
    'timesheet-detail-save-error',
    'timesheet-detail-submit',
    'timesheet-detail-reopen',
    'timesheet-detail-open-week',
    'timesheet-detail-add-entry-open',
    'timesheet-detail-add-entry-form',
    'timesheet-detail-add-entry-placement',
    'timesheet-detail-add-entry-date',
    'timesheet-detail-add-entry-hour-type',
    'timesheet-detail-add-entry-hours',
    'timesheet-detail-add-entry-description',
    'timesheet-detail-add-entry-save',
    'timesheet-detail-add-entry-cancel',
] as $tid) {
    $a("testid '{$tid}' present", str_contains($det, "data-testid=\"{$tid}\""));
}
foreach ([
    'timesheet-detail-entry-${e.id}-date',
    'timesheet-detail-entry-${e.id}-placement',
    'timesheet-detail-entry-${e.id}-hour-type',
    'timesheet-detail-entry-${e.id}-hours',
    'timesheet-detail-entry-${e.id}-description',
    'timesheet-detail-entry-${e.id}-save',
    'timesheet-detail-entry-${e.id}-delete',
] as $template) {
    $a("template testid '{$template}' present",
        str_contains($det, "data-testid={`{$template}`}"));
}

// Add-entry form must default the date inside the period and constrain
// the date input to the period range (prevents off-week typos).
$a('add-entry form constrains date to the period',
    str_contains($det, 'min={timesheet.period_start} max={timesheet.period_end}'));
$a('add-entry seeds default work_date to period_start',
    str_contains($det, 'work_date:    timesheet.period_start'));

// Inline edit dirty tracking + per-row save button.
$a('dirty row highlights via background',
    str_contains($det, "background: dirty ? '#fffbeb' : undefined"));
$a('save button disabled until row is dirty',
    str_contains($det, 'disabled={!dirty || saving}'));

// ──────────────────────────────────────────────────────────────────────
// Live running total — updates as the operator edits, before save.
// ──────────────────────────────────────────────────────────────────────
echo "\n── Live running totals ──\n";
$a('liveTotal computed from merged (edit + server) rows',
    str_contains($det, 'const row = { ...e, ...(edits[e.id] || {}) }')
    && str_contains($det, 'total += h'));
$a('liveByCategory splits billable vs non-billable from HOUR_TYPES table',
    str_contains($det, "byCat[ht.billable ? 'billable' : 'nonbillable']"));
$a('liveByHourType aggregates per hour_type',
    str_contains($det, 'byHt[row.hour_type] = (byHt[row.hour_type] || 0) + h'));
$a('useMemo dependency includes edits (live updates as user types)',
    str_contains($det, '[entries, edits]'));
$a('delta computed against server total_hours',
    str_contains($det, 'const delta = liveTotal - serverTotal'));
$a('hasUnsavedEdits flag tracks whether dirtyCount is non-zero',
    str_contains($det, 'const hasUnsavedEdits = dirtyCount > 0'));

// Card surface — `LiveCell` passes testId via prop, the wrapping div
// uses data-testid directly. Accept either form for the assertion.
$liveCellMatch = function(string $tid) use ($det): bool {
    return str_contains($det, "data-testid=\"{$tid}\"")
        || str_contains($det, "testId=\"{$tid}\"");
};
foreach ([
    'timesheet-detail-live-totals',
    'timesheet-detail-live-total',
    'timesheet-detail-live-billable',
    'timesheet-detail-live-nonbillable',
    'timesheet-detail-live-entry-count',
    'timesheet-detail-live-delta',
    'timesheet-detail-live-by-hour-type',
    'timesheet-detail-header-total',
] as $tid) {
    $a("live-total testid '{$tid}' present", $liveCellMatch($tid));
}
foreach ([
    'timesheet-detail-live-ht-${ht}',
] as $template) {
    $a("template testid '{$template}' present",
        str_contains($det, "data-testid={`{$template}`}"));
}

// The pending-save chip surfaces only when there is an unsaved delta.
$a('header surfaces pending-save chip when delta exists',
    str_contains($det, 'timesheet-detail-header-total-pending'));
$a('delta-only render is gated on Math.abs(delta) >= 0.005',
    str_contains($det, 'Math.abs(delta) >= 0.005')
    || str_contains($det, 'Math.abs(delta) < 0.005'));

// LiveCell helper component must exist + accept the standard props.
$a('LiveCell helper component declared',
    preg_match('/function LiveCell\(\s*\{\s*label,\s*value,\s*testId,\s*emphasis,\s*color\s*\}/', $det) === 1);

// ──────────────────────────────────────────────────────────────────────
// Save-all — bulk dispatch into entries_bulk_save (one round-trip).
// ──────────────────────────────────────────────────────────────────────
echo "\n── Save-all bulk dispatch ──\n";
$a('saveAll handler declared',
    str_contains($det, 'const saveAll = async ()'));
$a('saveAll POSTs to entries_bulk_save',
    str_contains($det, '?action=entries_bulk_save'));
$a('saveAll builds rows from edits buffer (merged with server row)',
    str_contains($det, 'const merged = entry ? { ...entry, ...edits[idStr] }'));
$a('saveAll preserves edits buffer for failed rows (no data loss)',
    str_contains($det, 'failedIdxSet = new Set((result.errors || []).map')
    && str_contains($det, 'if (!failedIdxSet.has(i)) delete next[idStr]'));
$a('bulkSaving state declared',
    str_contains($det, 'const [bulkSaving, setBulkSaving]'));
$a('bulkResult state captures saved + errors envelope',
    str_contains($det, 'const [bulkResult, setBulkResult]'));
$a('dirtyCount exposed for the CTA label',
    str_contains($det, 'const dirtyCount = dirtyIds.length'));
$a('per-row saving flag honours bulkSaving (no double-fire)',
    str_contains($det, 'const saving = savingRowId === e.id || bulkSaving'));
$a('bulkResult resets on timesheet reload',
    str_contains($det, 'setEdits({}); setBulkResult(null)'));

// CTA + feedback surface
foreach ([
    'timesheet-detail-save-all',
    'timesheet-detail-bulk-result',
    'timesheet-detail-bulk-errors',
] as $tid) {
    $a("save-all testid '{$tid}' present", str_contains($det, "data-testid=\"{$tid}\""));
}
foreach ([
    'timesheet-detail-bulk-error-${i}',
] as $template) {
    $a("template testid '{$template}' present",
        str_contains($det, "data-testid={`{$template}`}"));
}
$a('Save-all button label includes the dirty count',
    str_contains($det, 'Save all changes (${dirtyCount})'));
$a('Save-all button only renders when canEditRows && hasUnsavedEdits',
    str_contains($det, '{canEditRows && hasUnsavedEdits && ('));

// Placements dropdown is keyed off ts.person_id so we ALWAYS fetch the
// owner's placements (not the logged-in operator's).
$a('placements fetch keyed off ts.person_id (not session user)',
    str_contains($det, 'placements/api/placements.php?person_id=${ts.person_id}'));

// Defensive fallback when the entry's placement isn't in the active
// list (historical / ended placements should still render).
$a('inactive-placement fallback option keeps the row editable',
    str_contains($det, 'inactive'));

// ──────────────────────────────────────────────────────────────────────
// 2) TimesheetWeek.jsx — URL anchor fix
// ──────────────────────────────────────────────────────────────────────
echo "\n── TimesheetWeek URL anchor ──\n";
$week = file_get_contents('/app/modules/staffing/ui/TimesheetWeek.jsx');

$a('reads URL search params at module entry',
    str_contains($week, 'new URLSearchParams(window.location.search)'));
$a('parses urlPersonId from query string',
    str_contains($week, "urlParams.get('person_id')"));
$a('parses urlPeriodStart from query string',
    str_contains($week, "urlParams.get('period_start')"));
$a('urlPersonId overrides session user when valid',
    str_contains($week, 'Number.isFinite(urlPersonId) && urlPersonId > 0'));
$a('anchor initialised from urlPeriodStart when present',
    str_contains($week, '/^\d{4}-\d{2}-\d{2}$/.test(urlPeriodStart)'));
$a('YYYY-MM-DD parsed as LOCAL midnight (no timezone drift)',
    str_contains($week, 'new Date(y, m - 1, d)'));

// Regression guard — the old hard-coded line is gone.
$a('NO LONGER hard-codes anchor to today only',
    !preg_match('/const \[anchor, setAnchor\] = useState\(new Date\(\)\);/', $week));
$a('NO LONGER hard-codes personId to session.user only',
    !preg_match('/const personId = session\?\.user\?\.person_id \|\| session\?\.user\?\.id \|\| 1;/', $week));

// ──────────────────────────────────────────────────────────────────────
// 3) Backend wiring sanity — entry_save / entry_delete / reopen are
//     all still gated on staffing.timesheets.write and route through
//     the auto-reopening lib functions (don't accidentally regress).
// ──────────────────────────────────────────────────────────────────────
echo "\n── Backend gates ──\n";
$api = file_get_contents('/app/modules/staffing/api/timesheets.php');
$a('entry_save is RBAC-gated on staffing.timesheets.write',
    preg_match("/'POST' && \\\$action === 'entry_save'[\s\S]{0,200}rbac_legacy_require\\(\\\$user, 'staffing\\.timesheets\\.write'\\)/", $api) === 1);
$a('entry_delete is RBAC-gated on staffing.timesheets.write',
    preg_match("/'POST' && \\\$action === 'entry_delete'[\s\S]{0,200}rbac_legacy_require\\(\\\$user, 'staffing\\.timesheets\\.write'\\)/", $api) === 1);
$a('reopen is RBAC-gated on staffing.timesheets.write',
    preg_match("/'POST' && \\\$action === 'reopen'[\s\S]{0,200}rbac_legacy_require\\(\\\$user, 'staffing\\.timesheets\\.write'\\)/", $api) === 1);
$a('entries_bulk_save is RBAC-gated on staffing.timesheets.write',
    preg_match("/'POST' && \\\$action === 'entries_bulk_save'[\s\S]{0,200}rbac_legacy_require\\(\\\$user, 'staffing\\.timesheets\\.write'\\)/", $api) === 1);
$a('entries_bulk_save returns {saved, errors[], rows[]} envelope',
    str_contains($api, "'saved'  => count(\$results)")
    && str_contains($api, "'errors' => \$errors"));

// ──────────────────────────────────────────────────────────────────────
// Optimistic merge — skip the reload flash on the happy path.
// ──────────────────────────────────────────────────────────────────────
echo "\n── Optimistic merge ──\n";
$apiLib = file_get_contents('/app/dashboard/src/lib/api.js');
$a('useApi exposes a `mutate` setter for optimistic patches',
    str_contains($apiLib, 'const mutate = useCallback((updater) =>')
    && preg_match("/return \{ data, error, loading, elapsedMs, reload: load, mutate \}/", $apiLib) === 1);
$a('mutate accepts value-or-updater (matches setState semantics)',
    str_contains($apiLib, "typeof updater === 'function' ? updater(prev) : updater"));

$a('TimesheetDetail destructures `mutate` from useApi',
    str_contains($det, "useApi(apiPath, [apiPath]);")
    && preg_match('/const \{ data, loading, error, reload, mutate \} = useApi/', $det) === 1);
$a('applyEntryUpdate helper preserves JOIN columns (placement_title etc.)',
    str_contains($det, 'placement_title: existing.placement_title')
    && str_contains($det, 'client_name:     existing.client_name'));
$a('applyEntryUpdate sorts entries by work_date',
    str_contains($det, "nextEntries.sort((a, b) => (a.work_date || '').localeCompare"));
$a('applyEntryDelete drops the row + optionally patches header',
    str_contains($det, 'const applyEntryDelete = (deletedId, newHeader)')
    && str_contains($det, 'filter(e => e.id !== deletedId)'));
$a('applyHeaderUpdate patches only the timesheet header',
    str_contains($det, 'const applyHeaderUpdate = (newHeader)'));

// saveRow / deleteRow / saveAll / act all skip reload on the happy path.
$a('saveRow applies optimistic patch when result.entry present',
    str_contains($det, 'if (result?.entry) applyEntryUpdate(result.entry, result.timesheet)'));
$a('deleteRow applies optimistic patch when result.deleted present',
    str_contains($det, 'if (result?.deleted) {')
    && str_contains($det, 'applyEntryDelete(entry.id, null)'));
$a('deleteRow recomputes total_hours locally after delete',
    str_contains($det, 'const total = (prev.entries || []).reduce')
    && str_contains($det, 'timesheet: { ...prev.timesheet, total_hours: total }'));
$a('act() (submit/approve/reject) patches header without reload',
    str_contains($det, 'if (result?.timesheet) applyHeaderUpdate(result.timesheet)'));
$a('reopenForEdit patches header without reload',
    str_contains($det, "?action=reopen")
    && preg_match('/const reopenForEdit = async \(\) => \{[\s\S]{0,500}applyHeaderUpdate\(result\.timesheet\)/', $det) === 1);
$a('saveAll applies bulk optimistic merge from result.rows',
    str_contains($det, 'Array.isArray(result.rows) && result.rows.length > 0')
    && str_contains($det, 'result.rows.at(-1)?.timesheet'));
$a('AddEntryRow forwards the server result to onSaved (no auto-reload)',
    str_contains($det, 'onSaved(result)'));
$a('AddEntryRow enriches new row with placement labels for instant display',
    str_contains($det, 'const p = placements.find(pp => pp.id === result.entry.placement_id)'));

// Regression guard — edit-buffer reset effect must no longer fire on
// `entries.length` change (that wiped failed-row edits after bulk save).
$a('edit buffer reset keyed ONLY on timesheet id (not entries.length)',
    str_contains($det, 'useEffect(() => { setEdits({}); setBulkResult(null); }, [data?.timesheet?.id]);'));

$lib = file_get_contents('/app/modules/staffing/lib/timesheets.php');
$a('staffingTimeEntrySave auto-reopens non-draft sheets',
    preg_match("/function staffingTimeEntrySave[\s\S]{0,2000}staffingTimesheetReopen\\(\\\$userId, \\\$tsId, 'edited inline'\\)/", $lib) === 1);
$a('staffingTimeEntryDelete auto-reopens non-draft sheets',
    preg_match("/function staffingTimeEntryDelete[\s\S]{0,800}staffingTimesheetReopen\\(\\\$userId, \\\$tsId, 'entry deleted inline'\\)/", $lib) === 1);

// ──────────────────────────────────────────────────────────────────────
echo "\n=========================================\n";
echo "Timesheet inline-edit smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
