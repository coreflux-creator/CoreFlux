<?php
/** Submission changes review state while retaining the original time evidence. */
declare(strict_types=1);
require_once __DIR__.'/native_entries.php';

function timeNativeValidateSubmission(array $entry): void
{
    if (!StaffingNativeTimeEdit::active()) throw new LogicException('Submission requires a protected time transaction.');
    $person = (int)$entry['person_id'];
    $date = timeNativeDate($entry['work_date']);
    timeNativePlacement((int)$entry['placement_id'], $date, $person);
    staffingTimeAssertOpenEntryPeriod($entry);
    $period = staffingTimeFind('SELECT * FROM time_periods WHERE tenant_id=:tenant_id AND id=:id', ['id'=>$entry['period_id']]);
    if ($date < $period['start_date'] || $date > $period['end_date']) {
        throw new RuntimeException('The entry date is outside its original time period. Review the entry before submitting.', 409);
    }
    staffingTimesheetAssertEntryNotSettled($entry);
    timeNativeAssertNoFinancialHistory($entry);
    staffingTimesheetAssertDailyHours($person, $date, timeNativeHours($entry['hours']), (int)$entry['id']);
    if (!empty($entry['custom_category_id']) && !staffingTimeFind(
        'SELECT id FROM tenant_time_categories WHERE tenant_id=:tenant_id AND id=:id AND active=1',
        ['id'=>$entry['custom_category_id']]
    )) throw new RuntimeException('The time category is no longer available in this workspace.', 409);
    if (!empty($entry['timesheet_id'])) {
        $header = staffingTimesheetRow(staffingTimeWorkspace(), (int)$entry['timesheet_id'], true);
        if (!$header || (int)$header['person_id'] !== $person
            || !in_array($header['status'], ['draft','submitted','rejected'], true)) {
            throw new RuntimeException('The original week is unavailable or already complete. Review its correction workflow.', 409);
        }
        staffingTimesheetValidateWeek($person, $header['period_start'], $header['period_end']);
        staffingTimesheetAssertWorkDateInWeek($date, $header['period_start'], $header['period_end']);
    }
}

/** Caller locks and authorizes the entire selected set before any write. */
function timeNativeApplySubmission(array $rows, int $actor): array
{
    if (!StaffingNativeTimeEdit::active()) throw new LogicException('Submission requires a protected time transaction.');
    if ($actor <= 0) throw new RuntimeException('Sign in before submitting time.', 403);
    if (!$rows) throw new RuntimeException('Add at least one positive-hour entry before submitting this timesheet.', 422);
    foreach ($rows as $row) {
        if (!in_array($row['status'], ['draft','rejected'], true)) throw new RuntimeException('The submission changed. Refresh and review it again.', 409);
        timeNativeValidateSubmission($row);
    }
    $stmt = getDB()->prepare('UPDATE time_entries SET status="pending_review", rejected_reason=NULL WHERE tenant_id=? AND id=? AND status=?');
    $ids = [];
    $headers = [];
    foreach ($rows as $row) {
        $id = (int)$row['id'];
        $stmt->execute([staffingTimeWorkspace(), $id, $row['status']]);
        if ($stmt->rowCount() !== 1) throw new RuntimeException('Time entries changed while being submitted. Refresh and try again.', 409);
        $ids[] = $id;
        if (!empty($row['timesheet_id'])) $headers[(int)$row['timesheet_id']][] = $id;
        timeNativeRequiredAudit('time.entry.submitted', $id, [
            'entry_id'=>$id, 'submitted_by_user_id'=>$actor, 'batch_size'=>count($rows),
            'prior_status'=>$row['status'], 'status'=>'pending_review', 'person_id'=>$row['person_id'],
            'placement_id'=>$row['placement_id'], 'period_id'=>$row['period_id'], 'timesheet_id'=>$row['timesheet_id'],
        ], $actor);
    }
    foreach ($headers as $headerId => $headerEntries) {
        timeReconcileTimesheetHeader($headerId, $actor);
        $header = staffingTimesheetRow(staffingTimeWorkspace(), $headerId, true);
        if ($header['status'] === 'submitted') staffingTimeUpdate('staffing_timesheets', $headerId, [
            'rejected_at'=>null, 'rejected_by_user_id'=>null, 'rejection_reason'=>null,
        ]);
        // Refresh the artifact after clearing a previous rejection as well.
        $meta = ['entry_ids'=>$headerEntries, 'status'=>$header['status']];
        staffingTimesheetRecordArtifactEvent($headerId, $header['status']==='submitted'?'timesheet.submitted':'timesheet.entries_submitted', $actor, $meta);
        timeNativeRequiredAudit('time.timesheet.submitted', $headerId, $meta, $actor);
    }
    return ['submitted'=>count($ids), 'entry_ids'=>$ids, 'workspace_id'=>staffingTimeWorkspace()];
}
