-- One durable delivery attempt per client request. Raw customer tokens stay hash-only.
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'billing_invoice_tokens'
               AND COLUMN_NAME = 'delivery_request_id');
SET @sql := IF(@col = 0,
    'ALTER TABLE billing_invoice_tokens ADD COLUMN delivery_request_id CHAR(36) NULL AFTER revoked_by_user_id',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'billing_invoice_tokens'
               AND COLUMN_NAME = 'delivery_status');
SET @sql := IF(@col = 0,
    'ALTER TABLE billing_invoice_tokens ADD COLUMN delivery_status ENUM(''manual'',''pending'',''sent'',''failed'',''uncertain'') NOT NULL DEFAULT ''manual'' AFTER delivery_request_id',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'billing_invoice_tokens'
               AND COLUMN_NAME = 'delivery_recipient');
SET @sql := IF(@col = 0,
    'ALTER TABLE billing_invoice_tokens ADD COLUMN delivery_recipient VARCHAR(255) NULL AFTER delivery_status',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'billing_invoice_tokens'
               AND COLUMN_NAME = 'delivery_provider_id');
SET @sql := IF(@col = 0,
    'ALTER TABLE billing_invoice_tokens ADD COLUMN delivery_provider_id VARCHAR(255) NULL AFTER delivery_recipient',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'billing_invoice_tokens'
               AND COLUMN_NAME = 'delivery_error');
SET @sql := IF(@col = 0,
    'ALTER TABLE billing_invoice_tokens ADD COLUMN delivery_error VARCHAR(500) NULL AFTER delivery_provider_id',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'billing_invoice_tokens'
               AND COLUMN_NAME = 'delivery_started_at');
SET @sql := IF(@col = 0,
    'ALTER TABLE billing_invoice_tokens ADD COLUMN delivery_started_at DATETIME NULL AFTER delivery_error',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'billing_invoice_tokens'
               AND COLUMN_NAME = 'delivery_finished_at');
SET @sql := IF(@col = 0,
    'ALTER TABLE billing_invoice_tokens ADD COLUMN delivery_finished_at DATETIME NULL AFTER delivery_started_at',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'billing_invoice_tokens'
               AND INDEX_NAME = 'uq_bit_delivery_request');
SET @sql := IF(@idx = 0,
    'ALTER TABLE billing_invoice_tokens ADD UNIQUE KEY uq_bit_delivery_request (tenant_id, invoice_id, delivery_request_id)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'billing_invoice_tokens'
               AND INDEX_NAME = 'idx_bit_invoice_delivery_status');
SET @sql := IF(@idx = 0,
    'ALTER TABLE billing_invoice_tokens ADD INDEX idx_bit_invoice_delivery_status (tenant_id, invoice_id, delivery_status)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
