<?php
/** Regression guard for the weekly timesheet bulk-review workflow. */
declare(strict_types=1);

$pass = 0;
$fail = 0;
$assert = function (string $label, bool $ok) use (&$pass, &$fail): void {
    if ($ok) {
        $pass++;
        echo "[PASS] {$label}\n";
        return;
    }
    $fail++;
    echo "[FAIL] {$label}\n";
};
$read = static fn (string $path): string => (string) file_get_contents($path);

$lib = $read(__DIR__ . '/../modules/staffing/lib/timesheets.php');
$api = $read(__DIR__ . '/../modules/staffing/api/timesheets.php');
$ui = $read(__DIR__ . '/../modules/staffing/ui/StaffingApprovals.jsx');

echo "Bulk approval service\n";
$assert('normalizes and caps selected ids',
    str_contains($lib, 'function staffingTimesheetBulkIds')
    && str_contains($lib, 'at most 500 timesheets'));
$assert('locks every selected header before validation',
    str_contains($lib, 'function staffingTimesheetLockHeaders')
    && str_contains($lib, 'FOR UPDATE'));
$assert('approval rejects time entered or imported by the reviewer',
    str_contains($lib, "created_by_user_id'] ?? 0) === \$userId")
    && str_contains($lib, 'requires a different approver'));
$assert('approval resolves an approved rate for every row',
    str_contains($lib, 'timeResolveRateSnapshot(')
    && str_contains($lib, 'has no approved rate covering'));
$assert('bulk approval validates every week before applying updates',
    (bool) preg_match(
        '/function staffingTimesheetBulkApprove.*?\$plans\[\(int\) \$header\[\'id\'\]\] = staffingTimesheetApprovalPlan.*?foreach \(\$plans as \$headerId => \$snapshots\).*?staffingTimesheetApplyApproval/s',
        $lib
    ));
$assert('entry updates detect concurrent changes',
    str_contains($lib, '$upd->rowCount() !== 1')
    && str_contains($lib, 'changed while the timesheet was being approved'));
$assert('accounting events run only after the approval transaction commits',
    (bool) preg_match('/function staffingTimesheetBulkApprove.*?cf_tx_commit\(\$pdo, \$ownsTxn\).*?staffingEmitWorkerHoursApprovedEvent/s', $lib));
$assert('bulk rejection is atomic and requires one reason',
    str_contains($lib, 'function staffingTimesheetBulkReject')
    && str_contains($lib, 'A rejection reason is required.')
    && str_contains($lib, "status = 'rejected', rejected_reason = :reason"));

echo "\nAPI and readiness\n";
$assert('API exposes bulk approve and reject actions',
    str_contains($api, "['bulk_approve', 'bulk_reject']")
    && str_contains($api, 'staffingTimesheetBulkApprove(')
    && str_contains($api, 'staffingTimesheetBulkReject('));
$assert('bulk actions retain distinct approval and rejection permissions',
    (bool) preg_match("/bulk_approve.*?staffing\.time\.approve.*?staffing\.time\.reject/s", $api));
$assert('approval queue reports self-review and rate blockers',
    str_contains($api, 'reviewer_entry_count')
    && str_contains($api, 'missing_rate_count')
    && str_contains($api, 'pending_entry_count'));

echo "\nApproval queue UX\n";
$assert('queue supports select-all and per-row selection',
    str_contains($ui, 'staffing-approvals-select-all')
    && str_contains($ui, 'staffing-approval-select-'));
$assert('queue exposes bulk approve and reject controls',
    str_contains($ui, 'staffing-approvals-bulk-approve')
    && str_contains($ui, 'staffing-approvals-bulk-reject-confirm'));
$assert('queue posts to both bulk endpoints',
    str_contains($ui, "runBulk('bulk_approve')")
    && str_contains($ui, "runBulk('bulk_reject')"));
$assert('blocked weeks explain why they cannot be approved',
    str_contains($ui, 'function approvalBlocker')
    && str_contains($ui, 'staffing-approval-blocker-'));
$assert('reviewers can open a selected timesheet directly',
    str_contains($ui, 'to={`../timesheets/${row.id}`}'));
$assert('actions report inline instead of browser popups',
    !str_contains($ui, 'alert(')
    && !str_contains($ui, 'confirm(')
    && str_contains($ui, 'staffing-approvals-result'));

echo "\nStaffing bulk approval: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
