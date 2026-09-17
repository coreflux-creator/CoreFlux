<?php
declare(strict_types=1);

$passed = 0;
$failed = 0;
function payroll_lifecycle_assert(string $label, bool $condition): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  ok  {$label}\n";
        return;
    }
    $failed++;
    echo "FAIL  {$label}\n";
}

$root = dirname(__DIR__);
$migration = (string) file_get_contents($root . '/modules/payroll/migrations/007_accounting_posting.sql');
$posting = (string) file_get_contents($root . '/modules/payroll/lib/accounting_posting.php');
$runs = (string) file_get_contents($root . '/modules/payroll/api/runs.php');
$settingsApi = (string) file_get_contents($root . '/modules/payroll/api/settings.php');
$settingsUi = (string) file_get_contents($root . '/modules/payroll/ui/PayrollSettings.jsx');
$runUi = (string) file_get_contents($root . '/modules/payroll/ui/PayrollRunDetail.jsx');
$preflight = (string) file_get_contents($root . '/modules/payroll/api/preflight.php');

echo "Payroll accounting migration\n";
payroll_lifecycle_assert('adds six account mappings',
    substr_count($migration, '_account_code') >= 12);
payroll_lifecycle_assert('adds automatic posting preference',
    str_contains($migration, 'auto_post_to_ledger'));
payroll_lifecycle_assert('adds durable accrual and cash JE links',
    str_contains($migration, 'journal_entry_id') && str_contains($migration, 'cash_journal_entry_id'));

echo "\nPosting bridge\n";
payroll_lifecycle_assert('accrual uses stable event identity',
    str_contains($posting, "'event_type' => 'payroll.run.approved'")
    && str_contains($posting, "'source_record_id' => 'payroll_run:' . \$runId . ':accrual'"));
payroll_lifecycle_assert('cash leg uses stable event identity',
    str_contains($posting, "'event_type' => 'payroll.cash.disbursed'")
    && str_contains($posting, "'source_record_id' => 'payroll_run:' . \$runId . ':cash'"));
payroll_lifecycle_assert('accrual recognizes wage and employer-tax expense',
    str_contains($posting, "wage_expense_account_code")
    && str_contains($posting, "payroll_tax_expense_account_code"));
payroll_lifecycle_assert('cash leg clears net-pay payable',
    str_contains($posting, "payrollPostRunCash")
    && str_contains($posting, "payroll_payable_account_code")
    && str_contains($posting, "payroll_cash_account_code"));
payroll_lifecycle_assert('mapping readiness validates account types',
    str_contains($posting, "account_type'] !== \$expectation['type']"));

echo "\nRun lifecycle\n";
payroll_lifecycle_assert('manual post action is permission gated',
    str_contains($runs, "if (\$action === 'post')")
    && str_contains($runs, "rbac_legacy_require(\$user, 'payroll.run.post')"));
payroll_lifecycle_assert('approval attempts automatic accrual posting',
    str_contains($runs, 'payrollPostRunAccrual'));
payroll_lifecycle_assert('paid transition attempts cash posting',
    str_contains($runs, 'payrollPostRunCash'));
payroll_lifecycle_assert('Gusto synced actor uses authenticated user',
    str_contains($runs, "'gusto_synced_by'   => \$user['id'] ?? null"));
payroll_lifecycle_assert('run detail exposes accounting readiness',
    str_contains($runs, "'accounting' => payrollAccountingPostingReadiness"));

echo "\nOperator UI\n";
payroll_lifecycle_assert('settings API accepts accounting mappings',
    str_contains($settingsApi, "'wage_expense_account_code'")
    && str_contains($settingsApi, "'auto_post_to_ledger'"));
payroll_lifecycle_assert('settings UI uses account selectors',
    str_contains($settingsUi, '<LedgerAccountSelect')
    && str_contains($settingsUi, 'payroll-settings-accounting'));
payroll_lifecycle_assert('mark paid requires explicit confirmation',
    str_contains($runUi, 'window.confirm(`Mark payroll run'));
payroll_lifecycle_assert('run UI exposes retryable ledger posting',
    str_contains($runUi, 'payroll-run-post-ledger')
    && str_contains($runUi, 'payroll-accounting-retry'));
payroll_lifecycle_assert('compute waits for preflight readiness',
    str_contains($runUi, 'preflightBlocksRun')
    && str_contains($preflight, 'count($emps) > 0 && $totalBlockers === 0'));

echo "\n--- {$passed} passed, {$failed} failed ---\n";
exit($failed === 0 ? 0 : 1);
