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

$schedules = $read('modules/payroll/ui/PaySchedules.jsx');
$groups = $read('modules/payroll/ui/PayCyclesPanel.jsx');
$payrollModule = $read('modules/payroll/ui/PayrollModule.jsx');
$placement = $read('modules/placements/ui/PlacementDetail.jsx');
$expenses = $read('modules/ap/ui/ExpensesList.jsx');
$expensesApi = $read('modules/ap/api/expenses.php');
$timePeriods = $read('modules/time/ui/Periods.jsx');
$timeNav = $read('modules/time/ui/TimeWorkspaceNav.jsx');
$timesheetList = $read('modules/staffing/ui/TimesheetsList.jsx');
$timesheetDetail = $read('modules/staffing/ui/TimesheetDetail.jsx');
$timeTokenModal = $read('modules/time/ui/TokenIssueModal.jsx');
$timeReview = $read('modules/time/ui/ReviewQueue.jsx');
$timeSettlement = $read('modules/time/ui/TimeSettlement.jsx');
$csvImport = $read('dashboard/src/components/CsvImportPage.jsx');
$csvBulkImport = $read('dashboard/src/pages/CsvBulkImport.jsx');
$placementList = $read('modules/placements/ui/List.jsx');
$peopleDirectory = $read('modules/people/ui/Directory.jsx');
$treasuryPayments = $read('modules/treasury/ui/PlaidTransferSettings.jsx');
$treasuryOverview = $read('modules/treasury/ui/TreasuryOverview.jsx');
$gusto = $read('modules/payroll/ui/GustoConnectCard.jsx');

echo "Payroll setup usability\n";
$assert('schedules explain that initial periods are automatic',
    str_contains($schedules, 'The first six pay periods are created automatically.'));
$assert('advanced pay groups are linked instead of duplicated on schedules',
    str_contains($schedules, 'data-testid="payroll-schedules-groups-link"')
    && str_contains($schedules, 'Manage pay groups')
    && !str_contains($schedules, '<PayCyclesPanel'));
$assert('payroll navigation uses the user-facing pay groups label',
    str_contains($payrollModule, "label: 'Pay groups'"));
$assert('pay group form hides routing JSON in advanced controls',
    str_contains($groups, 'data-testid="payroll-cycle-advanced"')
    && str_contains($groups, 'Advanced date and routing overrides'));
$assert('opening pay periods returns a clear result',
    str_contains($groups, 'data-testid="payroll-cycles-notice"')
    && str_contains($groups, 'created draft pay run #'));
$assert('Gusto sync uses shared request handling and user-facing results',
    str_contains($gusto, "api.post(`/modules/payroll/api/gusto_sync.php?action=")
    && str_contains($gusto, 'Gusto synchronization')
    && !str_contains($gusto, 'JSON.stringify(result.data'));

echo "\nPlacement clarity\n";
$assert('approval tab is named for its setup purpose',
    str_contains($placement, "label: 'Approval setup'")
    && str_contains($placement, 'Timesheet approval setup'));
$assert('source-system metadata is collapsed behind a disclosure',
    str_contains($placement, 'data-testid="placement-source-system-details"')
    && str_contains($placement, 'Source system links'));
$assert('contract readiness tells the operator what remains',
    str_contains($placement, 'Still needed:')
    && !str_contains($placement, '>Complete: {readinessProblems'));
$assert('placement enums and phone numbers are humanized',
    str_contains($placement, 'function humanizeValue')
    && str_contains($placement, 'function formatPhone'));

echo "\nExpense report bulk workflow\n";
$assert('expense list resolves submitter names',
    str_contains($expensesApi, 'AS submitter_name')
    && str_contains($expenses, 'r.submitter_name'));
$assert('draft expense reports can be submitted in bulk',
    str_contains($expenses, 'data-testid="ap-expenses-bulk-submit"')
    && str_contains($expenses, "runBulk('submit'"));
$assert('submitted expense reports can be approved in bulk',
    str_contains($expenses, 'data-testid="ap-expenses-bulk-approve"')
    && str_contains($expenses, "runBulk('approve'"));
$assert('bulk failures stay visible instead of using browser alerts',
    str_contains($expenses, 'data-testid="ap-expenses-bulk-error"')
    && str_contains($expenses, 'succeeded.forEach((id) => next.delete(id))'));
$assert('expense status labels are human readable',
    str_contains($expenses, 'const statusLabel =')
    && str_contains($expenses, '{statusLabel(s)}</option>')
    && str_contains($expenses, '>{statusLabel(status)}</span>'));

echo "\nTime period clarity\n";
$assert('time and payroll periods have distinct labels',
    str_contains($timeNav, "label: 'Weekly periods'")
    && str_contains($timePeriods, 'Payroll periods are managed separately in Payroll.'));
$assert('weekly period actions report results inline',
    str_contains($timePeriods, 'data-testid="time-periods-notice"')
    && !str_contains($timePeriods, 'alert(`Generated')
    && !str_contains($timePeriods, 'alert(`Period closed'));
$assert('timesheet filters and badges hide internal lifecycle codes',
    str_contains($timesheetList, "{ value: 'payroll_ready', label: 'Ready for payroll' }")
    && str_contains($timesheetList, '>{STATUS_LABELS[status] || status}</span>')
    && str_contains($timesheetDetail, '>{STATUS_LABELS[status] || status}</span>'));
$assert('client approval guidance uses operator language',
    str_contains($timeTokenModal, 'placement&apos;s Approval setup tab')
    && !str_contains($timeTokenModal, '<code>tokenized_email_approval_enabled</code>')
    && !str_contains($timeTokenModal, '<code>client_approver_email</code>'));
$assert('email delivery failures direct operators to Connections without exposing configuration keys',
    str_contains($timeReview, 'Check the email connection under Connections')
    && !str_contains($timeReview, 'Configure RESEND_API_KEY'));
$assert('review queue explains separation of duties in plain language',
    str_contains($timeReview, 'Someone other than the submitter must approve it.')
    && !str_contains($timeReview, 'Two-eye control:'));
$assert('CSV imports use check and import language instead of transaction jargon',
    str_contains($csvImport, "'Checking…' : 'Check file'")
    && str_contains($csvImport, "'Importing…' : 'Import rows'")
    && !str_contains($csvImport, 'Validate (dry run)')
    && !str_contains($csvImport, 'Commit import'));
$assert('multi-file imports use the same plain-language actions',
    str_contains($csvBulkImport, '`Check all (${files.length})`')
    && str_contains($csvBulkImport, 'Import all ({totalRows - totalErrors} ready / {totalRows} total)')
    && str_contains($csvBulkImport, 'Ready to import</span>')
    && !str_contains($csvBulkImport, 'Commit all'));
$assert('time handoff labels describe schedules and readiness without extract jargon',
    str_contains($timeSettlement, '<th>Default schedule</th>')
    && str_contains($timeSettlement, '<th>Scheduled window</th>')
    && str_contains($timeSettlement, '<th>Readiness</th>')
    && str_contains($timeSettlement, 'No approved time is ready for'));
$assert('placement filters and rows hide internal enum values',
    str_contains($placementList, "w2: 'W-2 employee'")
    && str_contains($placementList, "pending_start: 'Starting soon'")
    && str_contains($placementList, '>{ETYPE_LABELS[p.engagement_type] || p.engagement_type}</span>')
    && str_contains($placementList, '>{STATUS_LABELS[p.status] || p.status}</span>'));
$assert('people filters, bulk edits, and rows share readable labels',
    str_contains($peopleDirectory, "do_not_rehire: 'Do not rehire'")
    && str_contains($peopleDirectory, "green_card: 'Permanent resident'")
    && str_contains($peopleDirectory, '>{CLASSIFICATION_LABELS[p.classification] || p.classification}</span>'));
$assert('Treasury leads with accounting tasks and hides provider ids in details',
    str_contains($treasuryPayments, '>Online vendor payments</h3>')
    && str_contains($treasuryPayments, '<summary style={{ cursor: \'pointer\' }}>Connection details</summary>')
    && str_contains($treasuryOverview, '>Connect accounts for balances and reconciliation</h3>')
    && str_contains($treasuryOverview, '>Online payments</h3>'));

echo "\nWorkflow usability polish: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
