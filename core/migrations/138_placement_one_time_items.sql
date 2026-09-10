-- 138_placement_one_time_items.sql
--
-- First-class, placement-level one-time economics. A named item can affect
-- the client invoice, a vendor bill, payroll, or margin only. Settlement
-- records use placement_economic_obligations so an item is consumed once.

CREATE TABLE IF NOT EXISTS placement_economic_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    placement_id BIGINT UNSIGNED NOT NULL,
    economic_party_id BIGINT UNSIGNED NULL,
    description VARCHAR(255) NOT NULL,
    settlement_channel ENUM('ar','ap','payroll','none') NOT NULL,
    direction ENUM('charge','credit') NOT NULL DEFAULT 'charge',
    amount DECIMAL(12,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    apply_on DATE NOT NULL,
    taxable TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('active','void') NOT NULL DEFAULT 'active',
    source_system VARCHAR(40) NULL,
    source_external_id VARCHAR(160) NULL,
    source_managed TINYINT(1) NOT NULL DEFAULT 0,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pei_source (tenant_id, placement_id, source_system, source_external_id),
    INDEX idx_pei_due (tenant_id, placement_id, settlement_channel, status, apply_on),
    INDEX idx_pei_party (tenant_id, economic_party_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The obligation source identifies the item itself. That makes the existing
-- unique source/party key the idempotency guard across repeated settlement
-- previews and retries.
SET @tbl := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_economic_obligations');
SET @sql := IF(
  @tbl>0,
  'ALTER TABLE placement_economic_obligations MODIFY COLUMN source_type ENUM(''time_bundle'',''time_entry'',''ar_invoice'',''manual'',''economic_item'') NOT NULL',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_economic_obligations' AND COLUMN_NAME='ar_invoice_id');
SET @sql := IF(@tbl>0 AND @col=0,'ALTER TABLE placement_economic_obligations ADD COLUMN ar_invoice_id BIGINT UNSIGNED NULL AFTER ap_bill_id','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_economic_obligations' AND COLUMN_NAME='ar_invoice_id');
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_economic_obligations' AND INDEX_NAME='idx_peo_ar_invoice');
SET @sql := IF(@tbl>0 AND @col>0 AND @idx=0,'CREATE INDEX idx_peo_ar_invoice ON placement_economic_obligations (tenant_id, ar_invoice_id)','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @tbl := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='billing_invoice_lines');
SET @sql := IF(
  @tbl>0,
  'ALTER TABLE billing_invoice_lines MODIFY COLUMN source_type ENUM(''time'',''time_entry'',''economic_item'',''manual'',''expense'',''recurring'',''milestone'') NOT NULL DEFAULT ''manual''',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @tbl := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ap_bill_lines');
SET @sql := IF(
  @tbl>0,
  'ALTER TABLE ap_bill_lines MODIFY COLUMN source_type ENUM(''time'',''time_entry'',''economic_party'',''economic_item'',''manual'',''recurring'',''expense'',''referral'') NOT NULL DEFAULT ''manual''',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
