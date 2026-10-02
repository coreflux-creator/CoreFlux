-- Preserve receipt and allocation history when a bank match is corrected.
SET @sql := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE billing_payments ADD COLUMN voided_at DATETIME NULL', 'DO 0')
    FROM information_schema.columns WHERE table_schema = DATABASE()
      AND table_name = 'billing_payments' AND column_name = 'voided_at');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE billing_payments ADD COLUMN void_reason VARCHAR(500) NULL', 'DO 0')
    FROM information_schema.columns WHERE table_schema = DATABASE()
      AND table_name = 'billing_payments' AND column_name = 'void_reason');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE billing_payments ADD COLUMN voided_by_user_id BIGINT UNSIGNED NULL', 'DO 0')
    FROM information_schema.columns WHERE table_schema = DATABASE()
      AND table_name = 'billing_payments' AND column_name = 'voided_by_user_id');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE billing_payments ADD COLUMN void_je_id BIGINT UNSIGNED NULL', 'DO 0')
    FROM information_schema.columns WHERE table_schema = DATABASE()
      AND table_name = 'billing_payments' AND column_name = 'void_je_id');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE billing_payment_allocations ADD COLUMN reversed_at DATETIME NULL', 'DO 0')
    FROM information_schema.columns WHERE table_schema = DATABASE()
      AND table_name = 'billing_payment_allocations' AND column_name = 'reversed_at');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE billing_payment_allocations ADD COLUMN reversal_je_id BIGINT UNSIGNED NULL', 'DO 0')
    FROM information_schema.columns WHERE table_schema = DATABASE()
      AND table_name = 'billing_payment_allocations' AND column_name = 'reversal_je_id');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE billing_payment_allocations ADD COLUMN reversed_by_user_id BIGINT UNSIGNED NULL', 'DO 0')
    FROM information_schema.columns WHERE table_schema = DATABASE()
      AND table_name = 'billing_payment_allocations' AND column_name = 'reversed_by_user_id');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS billing_receipt_corrections (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    bank_line_id BIGINT UNSIGNED NOT NULL,
    original_je_id BIGINT UNSIGNED NOT NULL,
    reversal_je_id BIGINT UNSIGNED NOT NULL,
    reason VARCHAR(500) NOT NULL,
    corrected_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_brc_original (tenant_id, original_je_id),
    INDEX idx_brc_line (tenant_id, bank_line_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
