-- Repair vendor payment defaults for tenants that predate AP migration 008.
-- Idempotent so it is safe on fully migrated tenants and partial installs.

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ap_vendors_index' AND COLUMN_NAME='vendor_category');
SET @sql := IF(@col=0,
  'ALTER TABLE ap_vendors_index ADD COLUMN vendor_category ENUM("hourly_labor","service_provider") NOT NULL DEFAULT "service_provider" AFTER vendor_type',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ap_vendors_index' AND COLUMN_NAME='payment_method');
SET @sql := IF(@col=0,
  'ALTER TABLE ap_vendors_index ADD COLUMN payment_method ENUM("ach","wire","check","card","cash","plaid","mercury","other") NULL AFTER vendor_category',
  'ALTER TABLE ap_vendors_index MODIFY COLUMN payment_method ENUM("ach","wire","check","card","cash","plaid","mercury","other") NULL');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ap_vendors_index' AND COLUMN_NAME='remit_to_email');
SET @sql := IF(@col=0, 'ALTER TABLE ap_vendors_index ADD COLUMN remit_to_email VARCHAR(255) NULL AFTER payment_method', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ap_vendors_index' AND COLUMN_NAME='remit_to_phone');
SET @sql := IF(@col=0, 'ALTER TABLE ap_vendors_index ADD COLUMN remit_to_phone VARCHAR(40) NULL AFTER remit_to_email', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ap_vendors_index' AND COLUMN_NAME='payment_account_last4');
SET @sql := IF(@col=0, 'ALTER TABLE ap_vendors_index ADD COLUMN payment_account_last4 CHAR(4) NULL AFTER remit_to_phone', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ap_vendors_index' AND COLUMN_NAME='payment_account_ct');
SET @sql := IF(@col=0, 'ALTER TABLE ap_vendors_index ADD COLUMN payment_account_ct VARBINARY(512) NULL AFTER payment_account_last4', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ap_vendors_index' AND COLUMN_NAME='kms_key_version_payment');
SET @sql := IF(@col=0, 'ALTER TABLE ap_vendors_index ADD COLUMN kms_key_version_payment VARCHAR(64) NULL AFTER payment_account_ct', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE ap_vendors_index
   SET vendor_category = 'hourly_labor'
 WHERE vendor_type IN ('1099_individual','c2c_corp')
   AND vendor_category = 'service_provider';

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ap_vendors_index' AND INDEX_NAME='idx_apv_tenant_category');
SET @sql := IF(@idx=0, 'CREATE INDEX idx_apv_tenant_category ON ap_vendors_index (tenant_id, vendor_category)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
