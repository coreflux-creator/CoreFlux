-- Migration 009: Encrypted vendor routing number for ACH payment origination.
-- Pairs with payment_account_ct (mig 008). Required so paymentRails::originate()
-- can build a NACHA / Plaid Transfer batch for AP outbound.
-- Idempotent. utf8mb4_unicode_ci. Cloudways MySQL 5.7+.

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ap_vendors_index' AND COLUMN_NAME='payment_routing_ct');
SET @sql := IF(@col=0,
  'ALTER TABLE ap_vendors_index ADD COLUMN payment_routing_ct VARBINARY(512) NULL AFTER payment_account_ct',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ap_vendors_index' AND COLUMN_NAME='payment_routing_last4');
SET @sql := IF(@col=0,
  'ALTER TABLE ap_vendors_index ADD COLUMN payment_routing_last4 CHAR(4) NULL AFTER payment_routing_ct',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ap_vendors_index' AND COLUMN_NAME='payment_account_type');
SET @sql := IF(@col=0,
  'ALTER TABLE ap_vendors_index ADD COLUMN payment_account_type ENUM("checking","savings") NULL AFTER payment_routing_last4',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
