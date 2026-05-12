<?php
/**
 * Smoke: Staffing one-tap external approver email flow.
 *
 * Pins:
 *   • core/staffing_email_approval.php helper (mint/consume + body HTML).
 *   • api/staffing/approve_timesheet_by_email.php public landing page
 *     (token + note prompt + receipt page).
 *   • modules/staffing/api/timesheet_email_approver.php admin mint endpoint.
 *   • migrations/004_external_approver_columns.sql adds the four new
 *     header columns.
 *   • api_bootstrap.php self-heal recipes mirror the migration so a tenant
 *     missing the migration row gets the columns auto-added at request time.
 *   • UI exposes the "Email approver" button + form in StaffingApprovals.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};
$read = fn (string $p) => (string) file_get_contents($p);

echo "Helper library\n";
$hf = $read(__DIR__ . '/../core/staffing_email_approval.php');
$a('requires generic approval_tokens primitive', str_contains($hf, "require_once __DIR__ . '/approval_tokens.php'"));
foreach (['staffingEmailApprovalMint','staffingEmailApprovalConsume','staffingEmailApprovalBodyHtml','staffingEmailApprovalBaseUrl'] as $fn) {
    $a("defines {$fn}",                          str_contains($hf, "function {$fn}("));
}
$a('mint binds subject_type=staffing_timesheet',  str_contains($hf, "'staffing_timesheet'"));
$a('mint issues approve+reject actions',         str_contains($hf, "['approve', 'reject']"));
$a('mint defaults to 72h TTL',                   str_contains($hf, 'int $ttlHours = 72'));
$a('consume rejects unknown actions',            str_contains($hf, "Invalid action"));
$a('consume verifies subject is staffing_timesheet', str_contains($hf, "Token is not for a staffing_timesheet"));
$a('approve path stamps external_approver_email + approval_note', str_contains($hf, 'external_approver_email = :em') && str_contains($hf, 'approval_note = :n'));
$a('approve path sets approved_via=external_email', str_contains($hf, "approved_via = 'external_email'"));
$a('approve cascades to time_entries pending_review', str_contains($hf, "time_entries") && str_contains($hf, "status = 'approved'") && str_contains($hf, "approved_via = 'external_email'"));
$a('reject path stamps rejection_reason + status',  str_contains($hf, "status = 'rejected'") && str_contains($hf, 'rejection_reason = :r'));
$a('reject path cascades to time_entries',         str_contains($hf, "UPDATE time_entries\n                    SET status = 'rejected'"));
$a('best-effort accounting event emit on approve', str_contains($hf, 'staffingEmitWorkerHoursApprovedEvent($tenantId, $headerId)'));
$a('failure of accounting emit logged not raised', str_contains($hf, '[staffing-email-approval] accounting emit failed'));
$a('blocks consumption when status != submitted',  str_contains($hf, "Timesheet is {\$header['status']}"));

echo "\nPublic landing endpoint\n";
$ep = $read(__DIR__ . '/../api/staffing/approve_timesheet_by_email.php');
$a('no auth required',                            !str_contains($ep, 'api_require_auth'));
$a('rejects non-hex64 tokens',                    str_contains($ep, "preg_match('/^[a-f0-9]{64}\$/'"));
$a('limits action to approve|reject',             str_contains($ep, "['approve', 'reject']"));
$a('two-step UX with confirm=1 gate',             str_contains($ep, "\$confirm !== '1'"));
$a('reject always passes through note prompt',    str_contains($ep, "\$action === 'reject'") && str_contains($ep, 'cf_staffing_email_approval_render_note_prompt'));
$a('renders note prompt with form testid',        str_contains($ep, "data-testid=\"staffing-email-approval-note-prompt\""));
$a('renders receipt page with testid',            str_contains($ep, "data-testid=\"staffing-email-approval-receipt\""));
$a('receipt links back to staffing approvals',    str_contains($ep, '/#/modules/staffing/approvals'));
$a('noindex header set',                          str_contains($ep, "X-Robots-Tag: noindex"));

echo "\nAdmin mint endpoint\n";
$mint = $read(__DIR__ . '/../modules/staffing/api/timesheet_email_approver.php');
$a('requires auth',                               str_contains($mint, 'api_require_auth'));
$a('POST only',                                   str_contains($mint, "\$method !== 'POST'"));
$a('validates email',                             str_contains($mint, "FILTER_VALIDATE_EMAIL"));
$a('only allows submitted timesheets',            str_contains($mint, "only submitted timesheets can be sent"));
$a('mints token via helper',                      str_contains($mint, 'staffingEmailApprovalMint('));
$a('builds body via helper',                      str_contains($mint, 'staffingEmailApprovalBodyHtml('));
$a('uses mailerSend when available',              str_contains($mint, "function_exists('mailerSend')"));
$a('surfaces approve_url for QA fallback',        str_contains($mint, "'approve_url'    => \$tokens['approve_url']"));
$a('computes total hours + revenue for email body', str_contains($mint, 'SUM(te.hours)') && str_contains($mint, 'placement_rates pr'));

echo "\nMigration + self-heal\n";
$mig = $read(__DIR__ . '/../modules/staffing/migrations/004_external_approver_columns.sql');
foreach (['approved_via','external_approver_email','external_approver_name','approval_note'] as $col) {
    $a("migration adds {$col}",                   str_contains($mig, "ADD COLUMN IF NOT EXISTS {$col}"));
}
$boot = $read(__DIR__ . '/../core/api_bootstrap.php');
$a('self-heal table staffing_timesheets registered', str_contains($boot, "'staffing_timesheets' => ["));
foreach (['approved_via','external_approver_email','external_approver_name','approval_note'] as $col) {
    $a("self-heal recipe for {$col}",             str_contains($boot, "'{$col}'"));
}

echo "\nApprovals UI integration\n";
$ui = $read(__DIR__ . '/../modules/staffing/ui/StaffingApprovals.jsx');
$a('Email approver button rendered',              str_contains($ui, 'data-testid={`staffing-email-approver-${r.id}`}'));
$a('Inline form for approver email + name',       str_contains($ui, 'staffing-email-approver-email-') && str_contains($ui, 'staffing-email-approver-name-'));
$a('Sends to admin mint endpoint',                str_contains($ui, "/modules/staffing/api/timesheet_email_approver.php"));
$a('Renders dispatch result',                     str_contains($ui, 'staffing-email-approver-result-'));
$a('Fallback link shown when mailer offline',     str_contains($ui, 'staffing-email-approver-fallback-'));

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
