-- Bank-line ignore, rule application, and unmatched repairs use scopedUpdate(),
-- which stamps updated_at. The original bank-line table omitted this column.
SET @sql := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE accounting_bank_statement_lines ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        'DO 0')
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'accounting_bank_statement_lines'
      AND column_name = 'updated_at'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
