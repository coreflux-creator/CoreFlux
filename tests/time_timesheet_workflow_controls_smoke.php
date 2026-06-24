<?php
/**
 * Time timesheet workflow controls smoke.
 *
 * Locks the P2 control-hardening rule that weekly timesheet approval is a
 * Time-owned workflow subject, even when the legacy operating UI is under
 * Staffing.
 */
declare(strict_types=1);

$ROOT = realpath(__DIR__ . '/..');
$pass = 0;
$fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail): void {
    if ($cond) { echo "  OK  {$msg}\n"; $pass++; }
    else       { echo "  BAD {$msg}\n"; $fail++; }
};
$lint = function (string $rel) use ($ROOT): bool {
    $out = [];
    $rc = 0;
    exec('php -l ' . escapeshellarg("{$ROOT}/{$rel}") . ' 2>&1', $out, $rc);
    return $rc === 0;
};

$workflow = (string) file_get_contents("{$ROOT}/core/workflow_engine.php");
$workflowMig = (string) file_get_contents("{$ROOT}/core/migrations/019_workflow_engine.sql");
$workflowRestartMig = (string) file_get_contents("{$ROOT}/core/migrations/118_workflow_instances_restartable_subjects.sql");
$staffing = (string) file_get_contents("{$ROOT}/modules/staffing/lib/timesheets.php");
$sync = (string) file_get_contents("{$ROOT}/modules/time/lib/workflow_sync.php");
$timeManifest = (string) file_get_contents("{$ROOT}/modules/time/manifest.php");
$timeMig = (string) file_get_contents("{$ROOT}/modules/time/migrations/001_init.sql");
$timeEnsure = (string) file_get_contents("{$ROOT}/modules/time/migrations/008_ensure_columns.sql");
$emailApproval = (string) file_get_contents("{$ROOT}/core/staffing_email_approval.php");
$approvalMix = (string) file_get_contents("{$ROOT}/modules/time/api/approval_mix.php");

echo "Files parse\n";
foreach ([
    'core/workflow_engine.php',
    'modules/staffing/lib/timesheets.php',
    'modules/time/lib/workflow_sync.php',
    'modules/time/api/approval_mix.php',
    'core/staffing_email_approval.php',
] as $rel) {
    $a("php -l {$rel}", $lint($rel));
}

echo "\nWorkflowEngine subject contract\n";
$a('workflowStart reuses only pending instances',
    str_contains($workflow, "AND status = 'pending'")
    && str_contains($workflow, 'ORDER BY id DESC'));
$a('workflow_instances allow restart history after completion',
    !str_contains($workflowMig, 'UNIQUE KEY uq_wfi_subject')
    && str_contains($workflowMig, 'INDEX idx_wfi_subject_status'));
$a('existing installs drop old subject uniqueness',
    str_contains($workflowRestartMig, 'DROP INDEX uq_wfi_subject')
    && str_contains($workflowRestartMig, 'ADD INDEX idx_wfi_subject_status'));
$a('workflow subject sync routes time_timesheet',
    str_contains($workflow, "\$subjectType === 'time_timesheet'")
    && str_contains($workflow, 'timeSyncTimesheetFromWorkflow('));
$a('subject sync passes decision comments',
    str_contains($workflow, '?string $comment = null')
    && str_contains($workflow, 'WORKFLOW_STATUS_REJECTED, $comment'));

echo "\nStaffing legacy UI consumes Time workflow controls\n";
$a('staffing helper requires domain People Graph',
    str_contains($staffing, "core/domain_people_graph.php"));
$a('staffing helper starts time_timesheet workflow on submit',
    str_contains($staffing, 'function staffingTimesheetWorkflowStart(')
    && str_contains($staffing, "'time_timesheet'"));
$a('workflow steps use Time People Graph approval policy',
    str_contains($staffing, "domainPeopleGraphWorkflowApproverResolution('time', 'timesheet'"));
$a('workflow definition strips per-timesheet ids',
    str_contains($staffing, "unset(\$resolution['resource_id'], \$resolution['object_id']);"));
$a('workflow payload identifies Time timesheet resource',
    str_contains($staffing, "'resource_module' => 'time'")
    && str_contains($staffing, "'resource_type' => 'timesheet'"));
$a('workflow payload carries SoD blockers',
    str_contains($staffing, 'staffingTimesheetWorkflowSodBlockedUserIds(')
    && str_contains($staffing, "'sod_blocked_user_ids' => \$blocked"));
$a('submit fails if workflow cannot start',
    str_contains($staffing, 'Could not start timesheet approval workflow'));
$a('approve/reject delegates through WorkflowEngine',
    str_contains($staffing, "staffingTimesheetWorkflowAct(currentTenantId(), \$header, \$userId, 'approve'")
    && str_contains($staffing, "staffingTimesheetWorkflowAct(currentTenantId(), \$header, \$userId, 'reject', \$reason)"));
$a('blocked workflow approvals are audited',
    str_contains($staffing, "timeAudit('time.timesheet.approval_blocked'"));
$a('staffing decision path fails closed without workflow',
    str_contains($staffing, 'No pending WorkflowEngine approval exists for this timesheet'));
$a('staffing decision path no longer owns local approval writes',
    !str_contains($staffing, 'workflowDecisionApplied')
    && !str_contains($staffing, "'source' => 'legacy_staffing'")
    && !str_contains($staffing, 'staffingEmitWorkerHoursApprovedEvent(currentTenantId(), $headerId)'));

echo "\nTime workflow sync and auditability\n";
$a('time workflow sync exists',
    str_contains($sync, 'function timeSyncTimesheetFromWorkflow('));
$a('workflow approve syncs staffing header and entries',
    str_contains($sync, "status = 'approved'")
    && str_contains($sync, "approved_by_user_id = COALESCE"));
$a('workflow reject syncs header and entries',
    str_contains($sync, "status = 'rejected'")
    && str_contains($sync, 'rejected_by_user_id = :u'));
$a('workflow sync emits per-entry approval audit',
    str_contains($sync, "timeEntryApprovedEmit((int) \$entry['id'], \$entry, 'manual'"));
$a('workflow sync emits timesheet audit',
    str_contains($sync, "timeAudit('time.timesheet.approved'")
    && str_contains($sync, "timeAudit('time.timesheet.rejected'"));
$a('workflow approve path emits staffing accounting event once',
    str_contains($sync, 'staffingEmitWorkerHoursApprovedEvent($tenantId, $timesheetId)'));

echo "\nExternal approval channel is explicit\n";
$a('external email helper emits per-entry Time audit',
    str_contains($emailApproval, "timeEntryApprovedEmit((int) \$approved['id'], \$approved, 'external_email'"));
$a('external email helper emits timesheet audit',
    str_contains($emailApproval, "timeAudit('time.timesheet.approved'")
    && str_contains($emailApproval, "timeAudit('time.timesheet.rejected'"));
$a('approved_via enum includes external_email in init',
    str_contains($timeMig, "ENUM('manual','tokenized_client_email','bulk_pre_approved','external_email')"));
$a('approved_via ensure migration includes external_email',
    str_contains($timeEnsure, "bulk_pre_approved','external_email"));
$a('approval mix reports external_email as known channel',
    str_contains($approvalMix, "'manual', 'tokenized_client_email', 'bulk_pre_approved', 'external_email'"));

echo "\nManifest events\n";
foreach ([
    'time.timesheet.workflow_started',
    'time.timesheet.submitted',
    'time.timesheet.approved',
    'time.timesheet.rejected',
    'time.timesheet.approval_blocked',
] as $event) {
    $a("declares {$event}", str_contains($timeManifest, "'{$event}'"));
}

echo "\nTime timesheet workflow controls smoke: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
