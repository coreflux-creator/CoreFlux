<?php
/** Shared cash-ledger eligibility for Accounting and Treasury bank setup. */
declare(strict_types=1);

function accountingBankCashLedgerEligible(array $account): bool
{
    return (int) ($account['active'] ?? 0) === 1
        && (int) ($account['is_postable'] ?? 0) === 1
        && ($account['account_type'] ?? '') === 'asset'
        && ($account['normal_side'] ?? '') === 'debit'
        && (
            ((int) ($account['is_system_account'] ?? 0) === 0
                && ($account['cash_flow_tag'] ?? '') === 'cash_and_equivalents')
            || (($account['code'] ?? '') === '1000' && (int) ($account['is_system_account'] ?? 0) === 1)
        );
}

function bankAccountValidateLedger(string $code, string $currency, ?int $excludeId = null): void
{
    $account = scopedFind(
        'SELECT code, account_type, normal_side, is_postable, active, currency,
                cash_flow_tag, is_system_account
           FROM accounting_accounts WHERE tenant_id = :tenant_id AND code = :code',
        ['code' => $code]
    );
    if (!$account || !accountingBankCashLedgerEligible($account)) {
        api_error('Choose an active cash or bank asset account from the chart of accounts', 422);
    }
    if (!empty($account['currency']) && strcasecmp((string) $account['currency'], $currency) !== 0) {
        api_error('The bank and cash ledger account must use the same currency', 422);
    }
    $usedSql = 'SELECT id FROM accounting_bank_accounts
                 WHERE tenant_id = :tenant_id AND gl_account_code = :code';
    $usedParams = ['code' => $code];
    if ($excludeId !== null) {
        $usedSql .= ' AND id <> :exclude_id';
        $usedParams['exclude_id'] = $excludeId;
    }
    $used = scopedFind($usedSql . ' LIMIT 1', $usedParams);
    if ($used) api_error('This cash ledger account is already linked to a bank account. Choose a different cash account.', 409);
}
