-- Employer payroll taxes must not post to the subcontractor-cost account.
-- Preserve deliberate tenant mappings; migrate only the shipped legacy
-- default and update the column default for future settings rows.

ALTER TABLE payroll_settings
    MODIFY COLUMN payroll_tax_expense_account_code VARCHAR(64) NOT NULL DEFAULT '5020';

UPDATE payroll_settings s
  JOIN accounting_accounts a
    ON a.tenant_id = s.tenant_id
   AND a.code = '5010'
   AND a.name = 'Subcontractor Expense'
   AND a.is_system_account = 1
   SET s.payroll_tax_expense_account_code = '5020'
 WHERE s.payroll_tax_expense_account_code = '5010';
