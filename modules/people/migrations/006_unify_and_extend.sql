-- Unification + extension migration:
--   Part A: AP vendors → companies FK + backfill
--   Part B: Billing customers → companies FK + backfill
--   Part C: Placement chain extension (submittal_id, vms_job_id, encrypted portal creds)
--   Part D: People — additional fields (only adds; values already supported)
--
-- Idempotent. utf8mb4_unicode_ci. Cloudways MySQL 5.7+.
--
-- Note: AP `vendor_name` and Billing `client_name` are KEPT as display fallback
-- columns. The new FK is the source of truth; the name string remains for
-- human-readable rendering when JOINing isn't available (e.g. exports).

-- ──────────────────────────────────────────────────────────────────────
-- Part A: AP unification (ap_vendors_index, ap_bills, ap_payments, ap_1099_ledger)
-- ──────────────────────────────────────────────────────────────────────

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ap_vendors_index' AND COLUMN_NAME='company_id');
SET @sql := IF(@col=0,'ALTER TABLE ap_vendors_index ADD COLUMN company_id BIGINT UNSIGNED NULL AFTER vendor_name','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ap_bills' AND COLUMN_NAME='vendor_company_id');
SET @sql := IF(@col=0,'ALTER TABLE ap_bills ADD COLUMN vendor_company_id BIGINT UNSIGNED NULL AFTER vendor_name','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ap_payments' AND COLUMN_NAME='vendor_company_id');
SET @sql := IF(@col=0,'ALTER TABLE ap_payments ADD COLUMN vendor_company_id BIGINT UNSIGNED NULL AFTER vendor_name','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ap_1099_ledger' AND COLUMN_NAME='vendor_company_id');
SET @sql := IF(@col=0,'ALTER TABLE ap_1099_ledger ADD COLUMN vendor_company_id BIGINT UNSIGNED NULL AFTER vendor_name','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill: every distinct AP vendor_name → companies (role=vendor for business
-- vendors, role=referrer is left untouched).  1099 individuals are typically
-- people, not companies — we DO NOT auto-create a company row for them; they
-- stay as people-side records.  Only c2c_corp / w9_business / utility / other
-- get unified into companies here.
INSERT IGNORE INTO companies (tenant_id, name, country)
SELECT DISTINCT tenant_id, vendor_name, 'US' FROM ap_vendors_index
WHERE vendor_name IS NOT NULL AND vendor_name <> ''
  AND vendor_type IN ('c2c_corp','w9_business','utility','other')
  AND NOT EXISTS (SELECT 1 FROM companies c WHERE c.tenant_id = ap_vendors_index.tenant_id AND c.name = ap_vendors_index.vendor_name AND c.deleted_at IS NULL);

INSERT IGNORE INTO company_roles (company_id, role)
SELECT DISTINCT c.id, 'vendor'
FROM companies c
JOIN ap_vendors_index avi ON avi.tenant_id = c.tenant_id AND avi.vendor_name = c.name
WHERE avi.vendor_type IN ('c2c_corp','w9_business','utility','other');

UPDATE ap_vendors_index avi
JOIN companies c ON c.tenant_id = avi.tenant_id AND c.name = avi.vendor_name AND c.deleted_at IS NULL
SET avi.company_id = c.id
WHERE avi.company_id IS NULL
  AND avi.vendor_type IN ('c2c_corp','w9_business','utility','other');

UPDATE ap_bills b
JOIN companies c ON c.tenant_id = b.tenant_id AND c.name = b.vendor_name AND c.deleted_at IS NULL
SET b.vendor_company_id = c.id
WHERE b.vendor_company_id IS NULL
  AND b.vendor_type IN ('c2c_corp','w9_business','utility','other');

UPDATE ap_payments p
JOIN companies c ON c.tenant_id = p.tenant_id AND c.name = p.vendor_name AND c.deleted_at IS NULL
SET p.vendor_company_id = c.id
WHERE p.vendor_company_id IS NULL;

UPDATE ap_1099_ledger l
JOIN companies c ON c.tenant_id = l.tenant_id AND c.name = l.vendor_name AND c.deleted_at IS NULL
SET l.vendor_company_id = c.id
WHERE l.vendor_company_id IS NULL
  AND l.vendor_type = 'c2c_corp';

-- Index for the JOIN paths AP queries use.
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ap_bills' AND INDEX_NAME='idx_apb_vendor_company');
SET @sql := IF(@idx=0,'CREATE INDEX idx_apb_vendor_company ON ap_bills (vendor_company_id)','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ap_payments' AND INDEX_NAME='idx_app_vendor_company');
SET @sql := IF(@idx=0,'CREATE INDEX idx_app_vendor_company ON ap_payments (vendor_company_id)','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ──────────────────────────────────────────────────────────────────────
-- Part B: Billing unification (billing_invoices)
-- ──────────────────────────────────────────────────────────────────────

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='billing_invoices' AND COLUMN_NAME='client_company_id');
SET @sql := IF(@col=0,'ALTER TABLE billing_invoices ADD COLUMN client_company_id BIGINT UNSIGNED NULL AFTER client_name','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO companies (tenant_id, name, country)
SELECT DISTINCT tenant_id, client_name, 'US' FROM billing_invoices
WHERE client_name IS NOT NULL AND client_name <> ''
  AND NOT EXISTS (SELECT 1 FROM companies c WHERE c.tenant_id = billing_invoices.tenant_id AND c.name = billing_invoices.client_name AND c.deleted_at IS NULL);

INSERT IGNORE INTO company_roles (company_id, role)
SELECT DISTINCT c.id, 'client'
FROM companies c
JOIN billing_invoices bi ON bi.tenant_id = c.tenant_id AND bi.client_name = c.name;

UPDATE billing_invoices bi
JOIN companies c ON c.tenant_id = bi.tenant_id AND c.name = bi.client_name AND c.deleted_at IS NULL
SET bi.client_company_id = c.id
WHERE bi.client_company_id IS NULL;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='billing_invoices' AND INDEX_NAME='idx_bi_client_company');
SET @sql := IF(@idx=0,'CREATE INDEX idx_bi_client_company ON billing_invoices (client_company_id)','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ──────────────────────────────────────────────────────────────────────
-- Part C: Placement chain extension — VMS job tracking + encrypted creds
-- ──────────────────────────────────────────────────────────────────────

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_client_chain' AND COLUMN_NAME='submittal_id');
SET @sql := IF(@col=0,'ALTER TABLE placement_client_chain ADD COLUMN submittal_id VARCHAR(120) NULL AFTER vendor_portal_id','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_client_chain' AND COLUMN_NAME='vms_job_id');
SET @sql := IF(@col=0,'ALTER TABLE placement_client_chain ADD COLUMN vms_job_id VARCHAR(120) NULL AFTER submittal_id','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_client_chain' AND COLUMN_NAME='portal_credentials_ct');
SET @sql := IF(@col=0,'ALTER TABLE placement_client_chain ADD COLUMN portal_credentials_ct VARBINARY(2048) NULL AFTER vms_job_id','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_client_chain' AND COLUMN_NAME='kms_key_version');
SET @sql := IF(@col=0,'ALTER TABLE placement_client_chain ADD COLUMN kms_key_version VARCHAR(64) NULL AFTER portal_credentials_ct','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ──────────────────────────────────────────────────────────────────────
-- Part D: People extension — hire/termination dates, mailing address,
-- pay frequency, gender/marital, explicit employment_type (distinct from
-- talent-pool classification: one person can be classification=w2 with
-- employment_type=full_time|part_time|temp, etc.).
-- Additive + idempotent via information_schema.
-- ──────────────────────────────────────────────────────────────────────

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='people' AND COLUMN_NAME='employment_type');
SET @sql := IF(@col=0,'ALTER TABLE people ADD COLUMN employment_type ENUM("full_time","part_time","contractor","intern","temp") NULL AFTER classification','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='people' AND COLUMN_NAME='hire_date');
SET @sql := IF(@col=0,'ALTER TABLE people ADD COLUMN hire_date DATE NULL AFTER employment_type','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='people' AND COLUMN_NAME='termination_date');
SET @sql := IF(@col=0,'ALTER TABLE people ADD COLUMN termination_date DATE NULL AFTER hire_date','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='people' AND COLUMN_NAME='pay_frequency');
SET @sql := IF(@col=0,'ALTER TABLE people ADD COLUMN pay_frequency ENUM("weekly","biweekly","semimonthly","monthly") NULL AFTER termination_date','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='people' AND COLUMN_NAME='gender');
SET @sql := IF(@col=0,'ALTER TABLE people ADD COLUMN gender VARCHAR(40) NULL AFTER pay_frequency','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='people' AND COLUMN_NAME='marital_status');
SET @sql := IF(@col=0,'ALTER TABLE people ADD COLUMN marital_status VARCHAR(40) NULL AFTER gender','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='people' AND COLUMN_NAME='mailing_address_line1');
SET @sql := IF(@col=0,'ALTER TABLE people ADD COLUMN mailing_address_line1 VARCHAR(255) NULL AFTER home_country','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='people' AND COLUMN_NAME='mailing_address_line2');
SET @sql := IF(@col=0,'ALTER TABLE people ADD COLUMN mailing_address_line2 VARCHAR(255) NULL AFTER mailing_address_line1','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='people' AND COLUMN_NAME='mailing_city');
SET @sql := IF(@col=0,'ALTER TABLE people ADD COLUMN mailing_city VARCHAR(120) NULL AFTER mailing_address_line2','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='people' AND COLUMN_NAME='mailing_state');
SET @sql := IF(@col=0,'ALTER TABLE people ADD COLUMN mailing_state VARCHAR(60) NULL AFTER mailing_city','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='people' AND COLUMN_NAME='mailing_postal_code');
SET @sql := IF(@col=0,'ALTER TABLE people ADD COLUMN mailing_postal_code VARCHAR(20) NULL AFTER mailing_state','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='people' AND COLUMN_NAME='mailing_country');
SET @sql := IF(@col=0,'ALTER TABLE people ADD COLUMN mailing_country CHAR(2) NULL AFTER mailing_postal_code','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Index for reports that filter by active/hire ranges (common HR dashboards).
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='people' AND INDEX_NAME='idx_people_tenant_hire');
SET @sql := IF(@idx=0,'CREATE INDEX idx_people_tenant_hire ON people (tenant_id, hire_date)','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
