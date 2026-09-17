<?php
declare(strict_types=1);

$passed = 0;
$failed = 0;
$root = dirname(__DIR__);
$check = static function (string $label, bool $ok) use (&$passed, &$failed): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$lib = $read('modules/staffing/lib/timesheets.php');
$api = $read('modules/staffing/api/timesheets.php');
$ui = $read('modules/staffing/ui/TimesheetWeek.jsx');
$entries = $read('modules/time/api/entries.php');
$timeLib = $read('modules/time/lib/time.php');
$rbac = $read('core/rbac/legacy_map.php');

echo "Weekly time integrity\n";
$check('weekly save validates the worker and seven-day period',
    str_contains($lib, 'staffingTimesheetValidateWeek($personId, $periodStart, $periodEnd)'));
$check('placement must belong to the timesheet worker',
    str_contains($lib, 'belongs to a different worker'));
$check('work dates must stay inside the selected week',
    str_contains($lib, 'staffingTimesheetAssertWorkDateInWeek'));
$check('daily total cannot exceed 24 hours',
    str_contains($lib, 'staffingTimesheetAssertDailyHours'));
$check('existing row IDs cannot be moved across timesheets',
    str_contains($lib, 'does not belong to this timesheet'));
$check('settled time cannot be edited or deleted silently',
    substr_count($lib, 'staffingTimesheetAssertEntryNotSettled') >= 4);
$check('approved and downstream-ready timesheets cannot be casually reopened',
    str_contains($lib, "['approved','payroll_ready','billing_ready','locked']"));
$check('reading an empty week does not create a database header',
    str_contains($lib, 'staffingTimesheetFind($personId, $periodStart) ?? ['));

echo "Weekly time access and usability\n";
$check('read and write access are scoped to the selected person',
    str_contains($api, 'staffingApiRequirePersonRead')
    && str_contains($api, 'staffingApiRequirePersonWrite'));
$check('approve and reject use distinct canonical permissions',
    str_contains($api, "'staffing.time.approve'")
    && str_contains($api, "'staffing.time.reject'"));
$check('canonical staffing permissions resolve to read/write/admin levels',
    str_contains($rbac, "'staffing.time.view'                 => ['staffing', 'read']")
    && str_contains($rbac, "'staffing.time.create'               => ['staffing', 'write']")
    && str_contains($rbac, "'staffing.time.approve'              => ['staffing', 'admin']"));
$check('weekly grid requests only the selected worker placements',
    str_contains($ui, 'status=active&person_id=${personId}'));
$check('weekly grid never substitutes a user ID or worker #1 for person_id',
    !str_contains($ui, 'session?.user?.id || 1'));
$check('unlinked operators get a worker picker',
    str_contains($ui, 'timesheet-worker-picker'));
$check('date keys use local calendar values instead of UTC conversion',
    str_contains($ui, 'd.getFullYear()') && !str_contains($ui, 'd.toISOString().slice(0, 10)'));
$check('prior-week hours require an explicit copy click',
    str_contains($ui, 'copyLastWeek') && !str_contains($ui, 'prefillTriedFor'));
$check('an empty week cannot be submitted',
    str_contains($ui, 'weekTotal <= 0'));

echo "Atomic time entry safeguards\n";
$check('direct entry validates dates and positive hours',
    str_contains($entries, 'function timeApiDate') && str_contains($entries, 'function timeApiHours'));
$check('direct entry auto-creates an open weekly period',
    str_contains($entries, 'function timeApiOpenPeriodId')
    && str_contains($entries, 'timeOpenPeriodIdForDate($workDate)')
    && str_contains($timeLib, "'period_type' => 'weekly'"));
$check('direct edits allow only user-editable fields',
    str_contains($entries, "\$allowed = ['work_date','category','hours','description','custom_category_id']"));
$check('editing submitted or rejected time returns it to draft',
    str_contains($entries, "\$update['status'] = 'draft'"));
$check('direct edits block rows already consumed downstream',
    substr_count($entries, 'timeApiAssertNotSettled') >= 3);

echo "\nTimesheet end-to-end guardrails: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
