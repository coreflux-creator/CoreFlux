-- Keep journal-entry lines tenant-scoped just like their parent entries.
-- Migration 012 introduced these compatibility columns, but the central JE
-- writer did not populate tenant_id, causing valid posted lines to disappear
-- from GL Detail and other tenant-filtered accounting reports.

SET @tenant_col := (
    SELECT COUNT(*)
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'accounting_journal_entry_lines'
       AND COLUMN_NAME = 'tenant_id'
);
SET @sql := IF(
    @tenant_col = 0,
    'ALTER TABLE accounting_journal_entry_lines ADD COLUMN tenant_id BIGINT UNSIGNED NULL AFTER id',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @description_col := (
    SELECT COUNT(*)
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'accounting_journal_entry_lines'
       AND COLUMN_NAME = 'description'
);
SET @sql := IF(
    @description_col = 0,
    'ALTER TABLE accounting_journal_entry_lines ADD COLUMN description VARCHAR(500) NULL AFTER memo',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE accounting_journal_entry_lines jl
JOIN accounting_journal_entries je ON je.id = jl.je_id
   SET jl.tenant_id = je.tenant_id
 WHERE jl.tenant_id IS NULL OR jl.tenant_id <> je.tenant_id;

UPDATE accounting_journal_entry_lines
   SET description = memo
 WHERE description IS NULL AND memo IS NOT NULL;

ALTER TABLE accounting_journal_entry_lines
    MODIFY COLUMN tenant_id BIGINT UNSIGNED NOT NULL;

SET @tenant_idx := (
    SELECT COUNT(*)
      FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'accounting_journal_entry_lines'
       AND INDEX_NAME = 'idx_ajel_tenant_account_je'
);
SET @sql := IF(
    @tenant_idx = 0,
    'CREATE INDEX idx_ajel_tenant_account_je ON accounting_journal_entry_lines (tenant_id, account_id, je_id)',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
