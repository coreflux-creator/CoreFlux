-- Migration 008: AP vendor categories, payment details, per-line receipt attachments.
--
-- Two vendor categories (per SPEC §3 / business clarification):
--   • hourly_labor      — must be fully onboarded (linked to people / placement chain)
--   • service_provider  — basics only (name, payment details). E.g. Microsoft, AWS,
--                         the building lessor, a SaaS subscription. No people record needed.
--
-- Idempotent. utf8mb4_unicode_ci. Cloudways MySQL 5.7+.

-- ─── ap_vendors_index.vendor_category
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ap_vendors_index' AND COLUMN_NAME='vendor_category');
SET @sql := IF(@col=0,
  'ALTER TABLE ap_vendors_index ADD COLUMN vendor_category ENUM("hourly_labor","service_provider") NOT NULL DEFAULT "service_provider" AFTER vendor_type',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Sensible backfill: 1099 individuals + C2C corps almost always represent
-- staffed labor in this product; flag them as hourly_labor.
UPDATE ap_vendors_index
   SET vendor_category = 'hourly_labor'
 WHERE vendor_type IN ('1099_individual','c2c_corp')
   AND vendor_category = 'service_provider';

-- ─── Payment-detail columns. The encrypted bank account number is opt-in
-- and stored under a *separate* kms key version field from tax_id_full_ct
-- so rotation can move at different speeds.
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ap_vendors_index' AND COLUMN_NAME='payment_method');
SET @sql := IF(@col=0,
  'ALTER TABLE ap_vendors_index ADD COLUMN payment_method ENUM("ach","wire","check","card","cash","plaid","other") NULL AFTER vendor_category',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ap_vendors_index' AND COLUMN_NAME='remit_to_email');
SET @sql := IF(@col=0,
  'ALTER TABLE ap_vendors_index ADD COLUMN remit_to_email VARCHAR(255) NULL AFTER payment_method',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ap_vendors_index' AND COLUMN_NAME='remit_to_phone');
SET @sql := IF(@col=0,
  'ALTER TABLE ap_vendors_index ADD COLUMN remit_to_phone VARCHAR(40) NULL AFTER remit_to_email',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ap_vendors_index' AND COLUMN_NAME='payment_account_last4');
SET @sql := IF(@col=0,
  'ALTER TABLE ap_vendors_index ADD COLUMN payment_account_last4 CHAR(4) NULL AFTER remit_to_phone',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ap_vendors_index' AND COLUMN_NAME='payment_account_ct');
SET @sql := IF(@col=0,
  'ALTER TABLE ap_vendors_index ADD COLUMN payment_account_ct VARBINARY(512) NULL AFTER payment_account_last4',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ap_vendors_index' AND COLUMN_NAME='kms_key_version_payment');
SET @sql := IF(@col=0,
  'ALTER TABLE ap_vendors_index ADD COLUMN kms_key_version_payment VARCHAR(64) NULL AFTER payment_account_ct',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─── Per-line receipt attachment.
-- ap_bills.attachment_storage_object_id already exists (mig 001) for the
-- vendor's invoice PDF. This new column attaches receipts to *individual*
-- expense / reimbursement / materials lines so audit defense is line-precise.
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ap_bill_lines' AND COLUMN_NAME='attachment_storage_object_id');
SET @sql := IF(@col=0,
  'ALTER TABLE ap_bill_lines ADD COLUMN attachment_storage_object_id BIGINT UNSIGNED NULL AFTER gl_expense_account_code',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Index for vendor-category browsing.
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ap_vendors_index' AND INDEX_NAME='idx_apv_tenant_category');
SET @sql := IF(@idx=0,'CREATE INDEX idx_apv_tenant_category ON ap_vendors_index (tenant_id, vendor_category)','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
