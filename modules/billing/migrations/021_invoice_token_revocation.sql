-- A customer invoice link may be disabled without changing the invoice or its ledger.
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'billing_invoice_tokens'
               AND COLUMN_NAME = 'revoked_at');
SET @sql := IF(@col = 0,
    'ALTER TABLE billing_invoice_tokens ADD COLUMN revoked_at DATETIME NULL AFTER expires_at',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'billing_invoice_tokens'
               AND COLUMN_NAME = 'revoked_by_user_id');
SET @sql := IF(@col = 0,
    'ALTER TABLE billing_invoice_tokens ADD COLUMN revoked_by_user_id BIGINT UNSIGNED NULL AFTER revoked_at',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
