<?php
/** Accounts whose balances must follow their source document or settlement workflow. */
declare(strict_types=1);

const ACCOUNTING_SOURCE_OWNED_CONTROL_CODES = [
    '1010', '1100', '1150', '1310', '1500', '2000', '2050', '2100',
    '2150', '2200', '2210', '2220', '2300', '2500',
];

function accountingDirectCategoryIssue(array $account): ?string
{
    if ((int) ($account['is_postable'] ?? 0) !== 1) {
        return 'Choose a postable account';
    }
    if (($account['account_type'] ?? null) === 'equity') {
        return 'Use a reviewed journal or opening-balance workflow for equity accounts';
    }
    if (in_array((string) ($account['code'] ?? ''), ACCOUNTING_SOURCE_OWNED_CONTROL_CODES, true)) {
        return 'This account is managed by its source workflow. Match the invoice, bill, payment, or transfer instead.';
    }
    if (!empty($account['linked_bank_id']) || !empty($account['is_bank_linked'])) {
        return 'Use a transfer to move money between bank accounts';
    }
    if (!empty($account['currency']) && strtoupper((string) $account['currency']) !== 'USD') {
        return 'This posting path supports USD accounts only';
    }
    return null;
}
