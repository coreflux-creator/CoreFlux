<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failures++;
};
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);

require_once $root . '/modules/accounting/lib/bank_account_ledger.php';

$cash = [
    'code' => '1000', 'account_type' => 'asset', 'normal_side' => 'debit',
    'active' => 1, 'is_postable' => 1, 'is_system_account' => 1,
    'cash_flow_tag' => null, 'currency' => null,
];
$check('legacy system Cash account remains eligible', accountingBankCashLedgerEligible($cash));
$custom = array_replace($cash, [
    'code' => '1000-OPERATING', 'is_system_account' => 0,
    'cash_flow_tag' => 'cash_and_equivalents',
]);
$check('designated custom cash account is eligible', accountingBankCashLedgerEligible($custom));
$check('ordinary receivable is not a bank ledger', !accountingBankCashLedgerEligible(
    array_replace($cash, ['code' => '1100', 'cash_flow_tag' => null])
));
$check('clearing account is not a bank ledger', !accountingBankCashLedgerEligible(
    array_replace($cash, ['code' => '1010', 'cash_flow_tag' => null])
));
$check('custom account cannot borrow the system Cash code', !accountingBankCashLedgerEligible(
    array_replace($cash, ['is_system_account' => 0])
));
$check('system receivable cannot be designated as cash', !accountingBankCashLedgerEligible(
    array_replace($cash, ['code' => '1100', 'cash_flow_tag' => 'cash_and_equivalents'])
));
$check('inactive account is not a bank ledger', !accountingBankCashLedgerEligible(
    array_replace($custom, ['active' => 0])
));
$check('non-asset cannot be designated as a bank ledger', !accountingBankCashLedgerEligible(
    array_replace($custom, ['account_type' => 'liability'])
));

$mockAccount = $cash;
$mockLinked = null;
function scopedFind(string $sql, array $params): ?array
{
    global $mockAccount, $mockLinked;
    return str_contains($sql, 'FROM accounting_accounts') ? $mockAccount : $mockLinked;
}
function api_error(string $message, int $status): never
{
    throw new RuntimeException($status . ':' . $message);
}
$failsWith = static function (int $status, callable $action): bool {
    try {
        $action();
    } catch (RuntimeException $e) {
        return str_starts_with($e->getMessage(), $status . ':');
    }
    return false;
};
$check('valid Cash ledger passes API validation', (static function (): bool {
    bankAccountValidateLedger('1000', 'USD');
    return true;
})());
$mockAccount = array_replace($cash, ['code' => '1100']);
$check('API refuses receivable as a bank ledger', $failsWith(422, static fn() => bankAccountValidateLedger('1100', 'USD')));
$mockAccount = array_replace($cash, ['currency' => 'EUR']);
$check('API refuses mismatched currency', $failsWith(422, static fn() => bankAccountValidateLedger('1000', 'USD')));
$mockAccount = $cash;
$mockLinked = ['id' => 4];
$check('API refuses duplicate bank linkage', $failsWith(409, static fn() => bankAccountValidateLedger('1000', 'USD')));

$accountingApi = $read('modules/accounting/api/bank_accounts.php');
$treasuryApi = $read('modules/treasury/api/deposit_accounts.php');
$accountsApi = $read('modules/accounting/api/accounts.php');
$bankUi = $read('modules/accounting/ui/BankReconciliation.jsx');
$treasuryUi = $read('modules/treasury/ui/DepositAccounts.jsx');
$chartUi = $read('modules/accounting/ui/ChartOfAccounts.jsx');
$accountDetailUi = $read('modules/accounting/ui/AccountDetail.jsx');
$overview = $read('dashboard/src/pages/BookkeepingOverview.jsx');
$worklist = $read('dashboard/src/pages/TransactionsToReview.jsx');
$check('Accounting and Treasury use shared bank-ledger validation',
    str_contains($accountingApi, "require_once __DIR__ . '/../lib/bank_account_ledger.php'")
    && str_contains($treasuryApi, "require_once __DIR__ . '/../../accounting/lib/bank_account_ledger.php'")
    && str_contains($treasuryApi, 'bankAccountValidateLedger($code, $currency)'));
$check('account list exposes cash designation and system flag to the picker',
    str_contains($accountsApi, 'cash_flow_tag, is_system_account'));
$check('chart can designate a custom bank or cash account',
    str_contains($chartUi, 'accounting-accounts-bank-cash')
    && str_contains($chartUi, "'cash_and_equivalents'"));
$check('existing asset can be designated without CSV',
    str_contains($accountDetailUi, 'accounting-account-edit-bank-cash')
    && str_contains($accountDetailUi, 'cash_flow_tag: accountEdit.cash_flow_tag'));
$check('bank defaults to the legal entity currency',
    str_contains($accountingApi, "\$body['currency'] ?? \$entity['base_currency']"));
$check('first-bank prompt opens the validated setup form',
    str_contains($overview, '/modules/accounting/bank-rec?new=1')
    && str_contains($bankUi, "searchParams.get('new') === '1'"));
$check('Treasury routes creation to the same setup form',
    str_contains($treasuryUi, '/modules/accounting/bank-rec?entity_id=')
    && !str_contains($treasuryUi, 'NewDepositForm'));
$check('empty review queue distinguishes no bank from no unmatched lines',
    str_contains($worklist, 'hasBankAccounts')
    && str_contains($worklist, 'Add a bank account to get started'));

if ($failures) exit(1);
echo "Bank account setup smoke passed.\n";
