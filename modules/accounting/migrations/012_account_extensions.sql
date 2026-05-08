-- Sprint 7a — chart-of-account schema brought up to spec §7.
-- Adds is_system_account, subtype, tax_mapping_id, statement_section, sort_order.
-- Extends account_type enum with contra_revenue, cost_of_goods_sold,
-- other_income, other_expense.
--
-- Idempotent: information_schema guards. MySQL 5.7+/8.0.

-- 1. Extend the account_type enum
ALTER TABLE accounting_accounts
    MODIFY COLUMN account_type
    ENUM('asset','liability','equity','revenue','contra_revenue','expense',
         'cost_of_goods_sold','other_income','other_expense')
    NOT NULL;

-- 2. Add is_system_account flag (spec §7)
SET @c := (SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema = DATABASE() AND table_name = 'accounting_accounts' AND column_name = 'is_system_account');
SET @sql := IF(@c = 0,
    'ALTER TABLE accounting_accounts ADD COLUMN is_system_account TINYINT(1) NOT NULL DEFAULT 0 AFTER is_postable',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 3. Add subtype (free-form, e.g. "current_asset", "long_term_liability")
SET @c := (SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema = DATABASE() AND table_name = 'accounting_accounts' AND column_name = 'subtype');
SET @sql := IF(@c = 0,
    'ALTER TABLE accounting_accounts ADD COLUMN subtype VARCHAR(80) NULL AFTER account_type',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 4. Add tax_mapping_id FK (Sprint 7f wires the tax_mappings table)
SET @c := (SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema = DATABASE() AND table_name = 'accounting_accounts' AND column_name = 'tax_mapping_id');
SET @sql := IF(@c = 0,
    'ALTER TABLE accounting_accounts ADD COLUMN tax_mapping_id BIGINT UNSIGNED NULL AFTER subtype',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 5. Add statement_section + sort_order for ordered financial-statement rendering
SET @c := (SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema = DATABASE() AND table_name = 'accounting_accounts' AND column_name = 'statement_section');
SET @sql := IF(@c = 0,
    'ALTER TABLE accounting_accounts ADD COLUMN statement_section VARCHAR(80) NULL AFTER tax_mapping_id',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema = DATABASE() AND table_name = 'accounting_accounts' AND column_name = 'sort_order');
SET @sql := IF(@c = 0,
    'ALTER TABLE accounting_accounts ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER statement_section',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
