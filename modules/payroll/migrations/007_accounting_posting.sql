-- Payroll migration 007 -- accounting mappings and durable GL links.

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll_settings'
               AND COLUMN_NAME = 'wage_expense_account_code');
SET @sql := IF(@col = 0,
    'ALTER TABLE payroll_settings ADD COLUMN wage_expense_account_code VARCHAR(64) NOT NULL DEFAULT ''5000'' AFTER futa_credit_rate_bps',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll_settings'
               AND COLUMN_NAME = 'payroll_tax_expense_account_code');
SET @sql := IF(@col = 0,
    'ALTER TABLE payroll_settings ADD COLUMN payroll_tax_expense_account_code VARCHAR(64) NOT NULL DEFAULT ''5020'' AFTER wage_expense_account_code',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll_settings'
               AND COLUMN_NAME = 'payroll_payable_account_code');
SET @sql := IF(@col = 0,
    'ALTER TABLE payroll_settings ADD COLUMN payroll_payable_account_code VARCHAR(64) NOT NULL DEFAULT ''2200'' AFTER payroll_tax_expense_account_code',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll_settings'
               AND COLUMN_NAME = 'payroll_tax_payable_account_code');
SET @sql := IF(@col = 0,
    'ALTER TABLE payroll_settings ADD COLUMN payroll_tax_payable_account_code VARCHAR(64) NOT NULL DEFAULT ''2210'' AFTER payroll_payable_account_code',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll_settings'
               AND COLUMN_NAME = 'payroll_deduction_payable_account_code');
SET @sql := IF(@col = 0,
    'ALTER TABLE payroll_settings ADD COLUMN payroll_deduction_payable_account_code VARCHAR(64) NOT NULL DEFAULT ''2220'' AFTER payroll_tax_payable_account_code',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll_settings'
               AND COLUMN_NAME = 'payroll_cash_account_code');
SET @sql := IF(@col = 0,
    'ALTER TABLE payroll_settings ADD COLUMN payroll_cash_account_code VARCHAR(64) NOT NULL DEFAULT ''1000'' AFTER payroll_deduction_payable_account_code',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll_settings'
               AND COLUMN_NAME = 'auto_post_to_ledger');
SET @sql := IF(@col = 0,
    'ALTER TABLE payroll_settings ADD COLUMN auto_post_to_ledger TINYINT(1) NOT NULL DEFAULT 1 AFTER payroll_cash_account_code',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll_runs'
               AND COLUMN_NAME = 'journal_entry_id');
SET @sql := IF(@col = 0,
    'ALTER TABLE payroll_runs ADD COLUMN journal_entry_id BIGINT UNSIGNED NULL AFTER employer_taxes_cents',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll_runs'
               AND COLUMN_NAME = 'cash_journal_entry_id');
SET @sql := IF(@col = 0,
    'ALTER TABLE payroll_runs ADD COLUMN cash_journal_entry_id BIGINT UNSIGNED NULL AFTER journal_entry_id',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll_runs'
               AND INDEX_NAME = 'idx_run_journal');
SET @sql := IF(@idx = 0,
    'ALTER TABLE payroll_runs ADD INDEX idx_run_journal (tenant_id, journal_entry_id, cash_journal_entry_id)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
