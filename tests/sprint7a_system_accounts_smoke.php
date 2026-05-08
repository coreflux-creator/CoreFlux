<?php
/**
 * Sprint 7a smoke — System accounts seed (spec §7).
 *
 * Verifies:
 *   - migration 012 extends accounting_accounts with system + tax columns
 *   - core/accounting/system_accounts.php defines all 17 spec accounts
 *   - migration 013 adds entity.accounting_basis
 *   - migration 014 adds JE.soft_close_override_reason
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok) use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}\n"; $fail++; }
};
$ROOT = realpath(__DIR__ . '/..');

echo "Migration 012 — accounting_accounts extensions\n";
$mig = (string) file_get_contents("{$ROOT}/modules/accounting/migrations/012_account_extensions.sql");
$assert('migration exists',                       strlen($mig) > 0);
$assert("account_type enum: contra_revenue",      stripos($mig, "'contra_revenue'") !== false);
$assert("account_type enum: cost_of_goods_sold",  stripos($mig, "'cost_of_goods_sold'") !== false);
$assert("account_type enum: other_income",        stripos($mig, "'other_income'") !== false);
$assert("account_type enum: other_expense",       stripos($mig, "'other_expense'") !== false);
$assert('is_system_account column',               stripos($mig, "column_name = 'is_system_account'") !== false);
$assert('subtype column',                         stripos($mig, "column_name = 'subtype'") !== false);
$assert('tax_mapping_id column',                  stripos($mig, "column_name = 'tax_mapping_id'") !== false);
$assert('statement_section column',               stripos($mig, "column_name = 'statement_section'") !== false);
$assert('sort_order column',                      stripos($mig, "column_name = 'sort_order'") !== false);

echo "\ncore/accounting/system_accounts.php — 17 system accounts\n";
$helper = (string) file_get_contents("{$ROOT}/core/accounting/system_accounts.php");
$assert('helper file exists',                     strlen($helper) > 0);

$required = [
    'Cash', 'Clearing Accounts', 'Accounts Receivable', 'Accounts Payable',
    'Payroll Liability', 'Sales Tax Payable', 'Retained Earnings',
    'Opening Balance Equity', 'Suspense', 'Uncategorized Income',
    'Uncategorized Expense', 'Rounding Gain/Loss', 'Intercompany Receivable',
    'Intercompany Payable', 'Bank Fees Expense', 'Interest Income',
    'Interest Expense',
];
foreach ($required as $name) {
    $assert("system account defined: {$name}",   strpos($helper, "'name' => '{$name}'") !== false);
}
// And the count is exactly 17 — guards against drift
$assert('exactly 17 system accounts',             substr_count($helper, "'name' =>") === 17);

$assert('seed function exposed',                  strpos($helper, 'function accountingSeedSystemAccounts') !== false);
$assert('lookup function exposed',                strpos($helper, 'function accountingSystemAccountId') !== false);
$assert('idempotent — checks existing first',     stripos($helper, 'WHERE tenant_id = :t AND code = :c') !== false);
$assert('stamps is_system_account on existing',   stripos($helper, 'SET is_system_account = 1') !== false);

echo "\nMigration 013 — entity.accounting_basis\n";
$m13 = (string) file_get_contents("{$ROOT}/modules/accounting/migrations/013_entity_accounting_basis.sql");
$assert('migration exists',                       strlen($m13) > 0);
$assert('accounting_basis column guard',          stripos($m13, "column_name = 'accounting_basis'") !== false);
$assert('enum: cash/accrual/modified_cash',       stripos($m13, "ENUM('cash','accrual','modified_cash')") !== false);
$assert('fiscal_year_start_month column',         stripos($m13, "column_name = 'fiscal_year_start_month'") !== false);
$assert('entity_type column',                     stripos($m13, "column_name = 'entity_type'") !== false);

echo "\nMigration 014 — JE.soft_close_override_reason\n";
$m14 = (string) file_get_contents("{$ROOT}/modules/accounting/migrations/014_je_soft_close_override.sql");
$assert('migration exists',                       strlen($m14) > 0);
$assert('soft_close_override_reason column guard', stripos($m14, "column_name = 'soft_close_override_reason'") !== false);
$assert('column type is TEXT',                    stripos($m14, 'TEXT NULL') !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
