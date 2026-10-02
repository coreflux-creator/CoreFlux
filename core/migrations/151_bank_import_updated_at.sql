-- scopedUpdate() touches updated_at, but the original bank-import table
-- omitted it. Without this column, a CSV can insert lines and then fail
-- while finalizing its import record.
SET @sql := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE accounting_bank_statement_imports ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        'DO 0')
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'accounting_bank_statement_imports'
      AND column_name = 'updated_at'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
