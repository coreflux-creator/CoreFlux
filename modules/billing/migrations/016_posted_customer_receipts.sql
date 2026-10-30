-- A manually recorded receipt has an explicit cash account and one canonical JE.
SET @sql := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE billing_payments ADD COLUMN bank_account_id INT UNSIGNED NULL', 'DO 0')
    FROM information_schema.columns WHERE table_schema = DATABASE()
      AND table_name = 'billing_payments' AND column_name = 'bank_account_id');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE billing_payments ADD COLUMN journal_entry_id BIGINT UNSIGNED NULL', 'DO 0')
    FROM information_schema.columns WHERE table_schema = DATABASE()
      AND table_name = 'billing_payments' AND column_name = 'journal_entry_id');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE billing_payments ADD COLUMN posted_at DATETIME NULL', 'DO 0')
    FROM information_schema.columns WHERE table_schema = DATABASE()
      AND table_name = 'billing_payments' AND column_name = 'posted_at');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE billing_payments ADD INDEX idx_bp_tenant_je (tenant_id, journal_entry_id)', 'DO 0')
    FROM information_schema.statistics WHERE table_schema = DATABASE()
      AND table_name = 'billing_payments' AND index_name = 'idx_bp_tenant_je');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
