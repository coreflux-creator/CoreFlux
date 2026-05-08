-- Sprint 7a — entity accounting_basis (spec §5).
-- Each entity declares whether its books are kept on cash, accrual, or
-- modified-cash basis. Drives reporting cutoffs and AI agent assumptions.
--
-- Idempotent: information_schema guard.

SET @c := (SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema = DATABASE() AND table_name = 'accounting_entities' AND column_name = 'accounting_basis');
SET @sql := IF(@c = 0,
    "ALTER TABLE accounting_entities ADD COLUMN accounting_basis ENUM('cash','accrual','modified_cash') NOT NULL DEFAULT 'accrual' AFTER base_currency",
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema = DATABASE() AND table_name = 'accounting_entities' AND column_name = 'fiscal_year_start_month');
SET @sql := IF(@c = 0,
    'ALTER TABLE accounting_entities ADD COLUMN fiscal_year_start_month TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER accounting_basis',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema = DATABASE() AND table_name = 'accounting_entities' AND column_name = 'entity_type');
SET @sql := IF(@c = 0,
    "ALTER TABLE accounting_entities ADD COLUMN entity_type ENUM('llc','corporation','partnership','sole_prop','nonprofit','other') NOT NULL DEFAULT 'llc' AFTER country",
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
