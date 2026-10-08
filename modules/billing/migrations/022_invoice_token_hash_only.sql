-- Existing emailed URLs keep working through token_hash; the raw secret is not retained.
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'billing_invoice_tokens'
               AND INDEX_NAME = 'uq_bit_token_hash' AND NON_UNIQUE = 0);
SET @sql := IF(@idx = 0,
    'ALTER TABLE billing_invoice_tokens ADD UNIQUE KEY uq_bit_token_hash (token_hash)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'billing_invoice_tokens'
               AND INDEX_NAME = 'uq_bit_token');
SET @sql := IF(@idx > 0,
    'ALTER TABLE billing_invoice_tokens DROP INDEX uq_bit_token',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'billing_invoice_tokens'
               AND COLUMN_NAME = 'token');
SET @sql := IF(@col > 0,
    'ALTER TABLE billing_invoice_tokens DROP COLUMN token',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
