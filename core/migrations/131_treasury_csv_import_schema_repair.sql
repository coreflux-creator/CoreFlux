-- 131_treasury_csv_import_schema_repair.sql
--
-- Some production databases received the original bank statement table before
-- the CSV audit columns in migration 096. Reassert those columns under a new
-- migration id and use VARCHAR for source_system so future integrations do not
-- depend on extending an enum before they can write a row.

SET @has_table := (
    SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'accounting_bank_statement_lines'
);

SET @has_external_id := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'accounting_bank_statement_lines'
       AND COLUMN_NAME = 'external_id'
);
SET @sql := IF(@has_table > 0 AND @has_external_id = 0,
    'ALTER TABLE accounting_bank_statement_lines ADD COLUMN external_id VARCHAR(128) NULL AFTER bank_reference',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_source_system := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'accounting_bank_statement_lines'
       AND COLUMN_NAME = 'source_system'
);
SET @sql := IF(@has_table > 0 AND @has_source_system = 0,
    'ALTER TABLE accounting_bank_statement_lines ADD COLUMN source_system VARCHAR(32) NOT NULL DEFAULT ''manual'' AFTER external_id',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @source_is_enum := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'accounting_bank_statement_lines'
       AND COLUMN_NAME = 'source_system'
       AND DATA_TYPE = 'enum'
);
SET @sql := IF(@has_table > 0 AND @source_is_enum > 0,
    'ALTER TABLE accounting_bank_statement_lines MODIFY COLUMN source_system VARCHAR(32) NOT NULL DEFAULT ''manual''',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_source_index := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'accounting_bank_statement_lines'
       AND INDEX_NAME = 'idx_bsl_tenant_source_ext'
);
SET @sql := IF(@has_table > 0 AND @has_source_index = 0,
    'ALTER TABLE accounting_bank_statement_lines ADD INDEX idx_bsl_tenant_source_ext (tenant_id, source_system, external_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
