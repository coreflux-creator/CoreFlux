<?php
/**
 * System-account seeding helper (Sprint 7a, spec §7).
 *
 * The spec requires 17 named system accounts to exist in every tenant's
 * Chart of Accounts so that posting rules + AI agents have stable targets:
 *   Cash, Clearing Accounts, Receivable, Accounts Payable, Payroll Liability,
 *   Sales Tax Payable, Retained Earnings, Opening Balance Equity, Suspense,
 *   Uncategorized Income, Uncategorized Expense, Rounding Gain/Loss,
 *   Intercompany Receivable, Intercompany Payable, Bank Fees Expense,
 *   Interest Income, Interest Expense.
 *
 * Idempotent: insert ON DUPLICATE KEY UPDATE re-stamps is_system_account=1
 * but never overwrites a tenant's existing custom name/parent/sort_order.
 *
 * Codes follow the standard 4-digit US convention (1000s asset, 2000s
 * liability, 3000s equity, 4000s revenue, 5000s COGS, 6000s expense,
 * 7000s other income, 9000s other expense). Tenants may renumber later.
 */
declare(strict_types=1);

const ACCOUNTING_SYSTEM_ACCOUNTS = [
    // Assets
    ['code' => '1000', 'name' => 'Cash',                       'type' => 'asset',     'side' => 'debit',  'subtype' => 'current_asset',     'section' => 'current_assets',     'sort' => 100],
    ['code' => '1010', 'name' => 'Clearing Accounts',          'type' => 'asset',     'side' => 'debit',  'subtype' => 'current_asset',     'section' => 'current_assets',     'sort' => 110],
    ['code' => '1100', 'name' => 'Accounts Receivable',        'type' => 'asset',     'side' => 'debit',  'subtype' => 'current_asset',     'section' => 'current_assets',     'sort' => 200],
    ['code' => '1150', 'name' => 'Unbilled Receivable',        'type' => 'asset',     'side' => 'debit',  'subtype' => 'current_asset',     'section' => 'current_assets',     'sort' => 250],
    ['code' => '1500', 'name' => 'Intercompany Receivable',    'type' => 'asset',     'side' => 'debit',  'subtype' => 'current_asset',     'section' => 'current_assets',     'sort' => 500],
    ['code' => '1900', 'name' => 'Suspense',                   'type' => 'asset',     'side' => 'debit',  'subtype' => 'current_asset',     'section' => 'current_assets',     'sort' => 900],
    // Liabilities
    ['code' => '2000', 'name' => 'Accounts Payable',           'type' => 'liability','side' => 'credit', 'subtype' => 'current_liability', 'section' => 'current_liabilities','sort' => 100],
    ['code' => '2050', 'name' => 'Accrued AP',                 'type' => 'liability','side' => 'credit', 'subtype' => 'current_liability', 'section' => 'current_liabilities','sort' => 150],
    ['code' => '2100', 'name' => 'Payroll Liability',          'type' => 'liability','side' => 'credit', 'subtype' => 'current_liability', 'section' => 'current_liabilities','sort' => 200],
    ['code' => '2150', 'name' => 'Accrued Payroll',            'type' => 'liability','side' => 'credit', 'subtype' => 'current_liability', 'section' => 'current_liabilities','sort' => 250],
    ['code' => '2200', 'name' => 'Sales Tax Payable',          'type' => 'liability','side' => 'credit', 'subtype' => 'current_liability', 'section' => 'current_liabilities','sort' => 300],
    ['code' => '2500', 'name' => 'Intercompany Payable',       'type' => 'liability','side' => 'credit', 'subtype' => 'current_liability', 'section' => 'current_liabilities','sort' => 500],
    // Equity
    ['code' => '3000', 'name' => 'Opening Balance Equity',     'type' => 'equity',    'side' => 'credit', 'subtype' => 'equity',            'section' => 'equity',             'sort' => 100],
    ['code' => '3900', 'name' => 'Retained Earnings',          'type' => 'equity',    'side' => 'credit', 'subtype' => 'equity',            'section' => 'equity',             'sort' => 900],
    // Revenue / other income / uncategorized
    ['code' => '4000', 'name' => 'Service Revenue',            'type' => 'revenue',   'side' => 'credit', 'subtype' => 'operating_revenue', 'section' => 'revenue',            'sort' => 100],
    ['code' => '4990', 'name' => 'Uncategorized Income',       'type' => 'revenue',   'side' => 'credit', 'subtype' => 'operating_revenue', 'section' => 'revenue',            'sort' => 990],
    ['code' => '7100', 'name' => 'Interest Income',            'type' => 'other_income','side' => 'credit','subtype' => 'other_income',     'section' => 'other_income',       'sort' => 100],
    // Expense / COGS / other expense / rounding
    ['code' => '5000', 'name' => 'Direct Labor Expense',       'type' => 'expense',   'side' => 'debit',  'subtype' => 'cogs',              'section' => 'cogs',               'sort' => 100],
    ['code' => '5010', 'name' => 'Subcontractor Expense',      'type' => 'expense',   'side' => 'debit',  'subtype' => 'cogs',              'section' => 'cogs',               'sort' => 110],
    ['code' => '6100', 'name' => 'Bank Fees Expense',          'type' => 'expense',   'side' => 'debit',  'subtype' => 'operating_expense', 'section' => 'operating_expenses', 'sort' => 100],
    ['code' => '6990', 'name' => 'Uncategorized Expense',      'type' => 'expense',   'side' => 'debit',  'subtype' => 'operating_expense', 'section' => 'operating_expenses', 'sort' => 990],
    ['code' => '9100', 'name' => 'Interest Expense',           'type' => 'other_expense','side' => 'debit','subtype' => 'other_expense',    'section' => 'other_expense',      'sort' => 100],
    ['code' => '9900', 'name' => 'Rounding Gain/Loss',         'type' => 'other_expense','side' => 'debit','subtype' => 'other_expense',    'section' => 'other_expense',      'sort' => 900],
];

/**
 * Seed (or stamp) the 17 system accounts for a tenant. Returns the count
 * of rows inserted (existing ones are left alone but stamped is_system=1).
 */
function accountingSeedSystemAccounts(int $tenantId): array {
    $pdo = getDB();
    if (!$pdo) throw new \RuntimeException('No DB');

    $inserted = 0; $stamped = 0;
    $sel = $pdo->prepare(
        'SELECT id, is_system_account FROM accounting_accounts
          WHERE tenant_id = :t AND code = :c'
    );
    $ins = $pdo->prepare(
        'INSERT INTO accounting_accounts
            (tenant_id, code, name, account_type, subtype, normal_side,
             is_postable, is_system_account, statement_section, sort_order, active)
         VALUES (:t, :code, :name, :type, :subtype, :side,
             1, 1, :section, :sort, 1)'
    );
    $stamp = $pdo->prepare(
        'UPDATE accounting_accounts
            SET is_system_account = 1
          WHERE id = :id AND is_system_account = 0'
    );

    foreach (ACCOUNTING_SYSTEM_ACCOUNTS as $a) {
        $sel->execute(['t' => $tenantId, 'c' => $a['code']]);
        $row = $sel->fetch(\PDO::FETCH_ASSOC);
        if ($row) {
            if ((int) $row['is_system_account'] === 0) {
                $stamp->execute(['id' => (int) $row['id']]);
                $stamped++;
            }
            continue;
        }
        $ins->execute([
            't'       => $tenantId,
            'code'    => $a['code'],
            'name'    => $a['name'],
            'type'    => $a['type'],
            'subtype' => $a['subtype'],
            'side'    => $a['side'],
            'section' => $a['section'],
            'sort'    => $a['sort'],
        ]);
        $inserted++;
    }
    return ['inserted' => $inserted, 'stamped' => $stamped, 'total' => count(ACCOUNTING_SYSTEM_ACCOUNTS)];
}

/**
 * Lookup a system account id by name for a tenant. Returns null if the
 * tenant hasn't been seeded yet — caller should call seed first.
 */
function accountingSystemAccountId(int $tenantId, string $name): ?int {
    $pdo = getDB();
    if (!$pdo) return null;
    $st = $pdo->prepare(
        'SELECT id FROM accounting_accounts
          WHERE tenant_id = :t AND name = :n AND is_system_account = 1
          LIMIT 1'
    );
    $st->execute(['t' => $tenantId, 'n' => $name]);
    $id = $st->fetchColumn();
    return $id ? (int) $id : null;
}
