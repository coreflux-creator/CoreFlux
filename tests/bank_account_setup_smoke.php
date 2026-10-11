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
    if (str_contains($sql, 'FROM accounting_accounts')) return $mockAccount;
    if (!$mockLinked || (!empty($params['exclude_id']) && $params['exclude_id'] === $mockLinked['id'])) return null;
    return $mockLinked['entity_id'] === null || $mockLinked['entity_id'] === $params['entity_id']
        ? $mockLinked : null;
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
    bankAccountValidateLedger('1000', 'USD', 10);
    return true;
})());
$mockAccount = array_replace($cash, ['code' => '1100']);
$check('API refuses receivable as a bank ledger', $failsWith(422, static fn() => bankAccountValidateLedger('1100', 'USD', 10)));
$mockAccount = array_replace($cash, ['currency' => 'EUR']);
$check('API refuses mismatched currency', $failsWith(422, static fn() => bankAccountValidateLedger('1000', 'USD', 10)));
$mockAccount = $cash;
$mockLinked = ['id' => 4, 'entity_id' => 10];
$check('API refuses duplicate bank linkage in the same entity', $failsWith(409, static fn() => bankAccountValidateLedger('1000', 'USD', 10)));
$check('another legal entity may reuse the same chart cash code', (static function (): bool {
    bankAccountValidateLedger('1000', 'USD', 20);
    return true;
})());
$check('an existing bank may keep its unchanged mapping', (static function (): bool {
    bankAccountValidateLedger('1000', 'USD', 10, 4);
    return true;
})());
$mockLinked = ['id' => 5, 'entity_id' => null];
$check('unowned legacy bank reserves its cash code pending review', $failsWith(409, static fn() => bankAccountValidateLedger('1000', 'USD', 20)));
$check('bank setup requires a legal entity', $failsWith(422, static fn() => bankAccountValidateLedger('1000', 'USD', 0)));

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
    && str_contains($treasuryApi, "bankAccountValidateLedger(\$code, \$currency, (int) \$entity['id'])"));
$migration = $read('modules/accounting/migrations/043_bank_account_entity_cash_code.sql');
$cashPosition = $read('api/treasury_cash_position.php');
$cashForecast = $read('core/ai/cash_forecast.php');
$cashVariance = $read('api/treasury_recommendations.php');
$check('bank cash code uniqueness is per entity, with a reserved key for unowned banks',
    str_contains($migration, 'COALESCE(entity_id, 0)')
    && str_contains($migration, 'uq_aba_tenant_entity_gl (tenant_id, entity_scope_id, gl_account_code)'));
$check('cash position, forecast and variance require matching journal entity',
    str_contains($cashPosition, 'je.entity_id = :entity_id')
    && str_contains($cashForecast, 'j.entity_id = b.entity_id')
    && str_contains($cashForecast, "j.status IN ('posted','reversed')")
    && str_contains($cashVariance, 'je.entity_id = ba.entity_id'));
$check('account list exposes cash designation and system flag to the picker',
    str_contains($accountsApi, 'cash_flow_tag, is_system_account'));
$check('chart can designate a custom bank or cash account',
    str_contains($chartUi, 'accounting-accounts-bank-cash')
    && str_contains($chartUi, "'cash_and_equivalents'"));
$check('new bank cash ledger keeps legal entity and returns to setup',
    str_contains($bankUi, 'accounts?entity_id=${entityId}&bank_setup=1')
    && str_contains($chartUi, "searchParams.get('bank_setup') === '1'")
    && str_contains($chartUi, "cash_flow_tag: bankSetupPath ? 'cash_and_equivalents' : ''")
    && str_contains($chartUi, 'navigate(bankSetupPath, { replace: true })'));
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
