<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = 0;
$assert = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failures++;
};

$migration = (string) file_get_contents($root . '/modules/accounting/migrations/026_bank_account_interest_terms.sql');
$library = (string) file_get_contents($root . '/modules/accounting/lib/account_interest.php');
$bankApi = (string) file_get_contents($root . '/modules/accounting/api/bank_accounts.php');
$reconApi = (string) file_get_contents($root . '/modules/accounting/api/reconciliations.php');
$packet = (string) file_get_contents($root . '/modules/accounting/lib/reconciliation_packet.php');
$ui = (string) file_get_contents($root . '/modules/accounting/ui/BankReconciliation.jsx');

$assert('terms table is tenant and account scoped', str_contains($migration, 'accounting_bank_account_terms') && str_contains($migration, 'uq_abait_tenant_account'));
$assert('interest runs are unique per account period', str_contains($migration, 'uq_abir_account_period'));
$assert('run stores calculation snapshot and journal link', str_contains($migration, 'balance_basis_amount') && str_contains($migration, 'journal_entry_id'));
$assert('posting uses the central journal-entry service', str_contains($library, 'accountingPostJe('));
$assert('posting has a stable account-period idempotency key', str_contains($library, "'account_interest:' . (int) \$recon['bank_account_id'] . ':' . \$periodEnd"));
$assert('reconciliation close invokes interest processing', str_contains($reconApi, 'accountInterestProcessReconciliation('));
$assert('bank account API exposes an interest-terms action', str_contains($bankApi, "\$action === 'terms'"));
$assert('packet includes the interest calculation snapshot', str_contains($packet, "'interest_run'"));
$assert('account screen exposes the account-terms editor', str_contains($ui, 'accounting-interest-terms'));

require_once $root . '/modules/accounting/lib/account_interest.php';
$assert('inclusive day count covers a 31-day statement', accountInterestDayCount('2026-01-01', '2026-01-31') === 31);
$assert('actual/365 interest rounds to cents', accountInterestCalculate(1000, 12, '2026-01-01', '2026-01-31', 'actual_365') === 10.19);
$assert('actual/360 interest rounds to cents', accountInterestCalculate(1000, 12, '2026-01-01', '2026-01-30', 'actual_360') === 10.0);
$average = accountInterestAverageDailyBalance(1000, '2026-01-01', '2026-01-03', [
    ['posted_date' => '2026-01-02', 'amount' => -300],
    ['posted_date' => '2026-01-03', 'amount' => 100],
]);
$assert('average daily balance uses each end-of-day balance', abs($average - 833.333333) < 0.000001);

if ($failures > 0) exit(1);
echo "Accounting bank-interest smoke passed.\n";
