-- Preserve application/refund history while allowing source-level corrections.
SET @sql := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE billing_deposit_applications ADD COLUMN reversed_at DATETIME NULL', 'DO 0')
    FROM information_schema.columns WHERE table_schema = DATABASE()
      AND table_name = 'billing_deposit_applications' AND column_name = 'reversed_at');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE billing_deposit_applications ADD COLUMN reversal_je_id BIGINT UNSIGNED NULL', 'DO 0')
    FROM information_schema.columns WHERE table_schema = DATABASE()
      AND table_name = 'billing_deposit_applications' AND column_name = 'reversal_je_id');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE billing_deposit_applications ADD COLUMN reversal_reason VARCHAR(500) NULL', 'DO 0')
    FROM information_schema.columns WHERE table_schema = DATABASE()
      AND table_name = 'billing_deposit_applications' AND column_name = 'reversal_reason');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE billing_deposit_applications ADD COLUMN reversed_by_user_id BIGINT UNSIGNED NULL', 'DO 0')
    FROM information_schema.columns WHERE table_schema = DATABASE()
      AND table_name = 'billing_deposit_applications' AND column_name = 'reversed_by_user_id');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE billing_deposit_refunds ADD COLUMN reversed_at DATETIME NULL', 'DO 0')
    FROM information_schema.columns WHERE table_schema = DATABASE()
      AND table_name = 'billing_deposit_refunds' AND column_name = 'reversed_at');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE billing_deposit_refunds ADD COLUMN reversal_je_id BIGINT UNSIGNED NULL', 'DO 0')
    FROM information_schema.columns WHERE table_schema = DATABASE()
      AND table_name = 'billing_deposit_refunds' AND column_name = 'reversal_je_id');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE billing_deposit_refunds ADD COLUMN reversal_reason VARCHAR(500) NULL', 'DO 0')
    FROM information_schema.columns WHERE table_schema = DATABASE()
      AND table_name = 'billing_deposit_refunds' AND column_name = 'reversal_reason');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE billing_deposit_refunds ADD COLUMN reversed_by_user_id BIGINT UNSIGNED NULL', 'DO 0')
    FROM information_schema.columns WHERE table_schema = DATABASE()
      AND table_name = 'billing_deposit_refunds' AND column_name = 'reversed_by_user_id');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
