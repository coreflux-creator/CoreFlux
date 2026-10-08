<?php
/** Accounts whose balances must follow their source document or settlement workflow. */
declare(strict_types=1);

const ACCOUNTING_SOURCE_OWNED_CONTROL_CODES = [
    '1010', '1100', '1150', '1310', '1500', '2000', '2050', '2100',
    '2150', '2200', '2210', '2220', '2300', '2500',
];

/** Include tenant-configured control accounts, not just the seeded codes. */
function accountingSourceOwnedControlCodes(int $tenantId, PDO $pdo): array
{
    $codes = array_fill_keys(ACCOUNTING_SOURCE_OWNED_CONTROL_CODES, true);
    $tables = [
        'accounting_bank_accounts', 'treasury_liability_accounts',
        'payroll_settings', 'accounting_settings', 'accounting_intercompany_mappings',
    ];
    $columns = [];
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        foreach ($tables as $table) {
            foreach ($pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC) as $column) {
                $columns[$table][$column['name']] = true;
            }
        }
    } else {
        $quoted = implode(', ', array_map(static fn(string $table): string => $pdo->quote($table), $tables));
        $stmt = $pdo->query(
            "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$quoted})"
        );
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $columns[$column['TABLE_NAME']][$column['COLUMN_NAME']] = true;
        }
    }

    $add = static function (string $query) use ($pdo, $tenantId, &$codes): void {
        $stmt = $pdo->prepare($query);
        $stmt->execute(['t' => $tenantId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $code) {
            $code = trim((string) $code);
            if ($code !== '') $codes[$code] = true;
        }
    };
    $has = static function (string $table, array $required) use (&$columns): bool {
        return !array_diff($required, array_keys($columns[$table] ?? []));
    };

    if ($has('accounting_bank_accounts', ['tenant_id', 'gl_account_code'])) {
        $add('SELECT gl_account_code FROM accounting_bank_accounts WHERE tenant_id = :t');
    }
    if ($has('treasury_liability_accounts', ['tenant_id', 'account_id'])) {
        $add('SELECT a.code FROM treasury_liability_accounts l
                JOIN accounting_accounts a ON a.tenant_id = l.tenant_id AND a.id = l.account_id
               WHERE l.tenant_id = :t');
    }
    if ($has('payroll_settings', ['tenant_id'])) {
        foreach (['payroll_payable_account_code', 'payroll_tax_payable_account_code',
                  'payroll_deduction_payable_account_code'] as $column) {
            if ($has('payroll_settings', [$column])) {
                $add("SELECT {$column} FROM payroll_settings WHERE tenant_id = :t");
            }
        }
    }
    if ($has('accounting_settings', ['tenant_id', 'multi_period_split_enabled'])) {
        foreach (['ar_unbilled_account_code', 'ap_accrued_account_code'] as $column) {
            if ($has('accounting_settings', [$column])) {
                $add("SELECT {$column} FROM accounting_settings
                       WHERE tenant_id = :t AND multi_period_split_enabled = 1");
            }
        }
    }
    if ($has('accounting_intercompany_mappings',
        ['tenant_id', 'active', 'due_from_account_code', 'due_to_account_code'])) {
        $add('SELECT due_from_account_code FROM accounting_intercompany_mappings
               WHERE tenant_id = :t AND active = 1');
        $add('SELECT due_to_account_code FROM accounting_intercompany_mappings
               WHERE tenant_id = :t AND active = 1');
    }
    return array_keys($codes);
}

function accountingDirectCategoryIssue(array $account, array $protectedCodes = ACCOUNTING_SOURCE_OWNED_CONTROL_CODES): ?string
{
    if ((int) ($account['is_postable'] ?? 0) !== 1) {
        return 'Choose a postable account';
    }
    if (($account['account_type'] ?? null) === 'equity') {
        return 'Use a reviewed journal or opening-balance workflow for equity accounts';
    }
    if (in_array((string) ($account['code'] ?? ''), $protectedCodes, true)) {
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
