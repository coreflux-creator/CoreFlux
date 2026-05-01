-- Companies v2 hardening — adds the workflow fields a real staffing
-- platform needs. Idempotent. utf8mb4_unicode_ci.

-- ──────────────────────────────────────────────────────────────────────
-- New child table: company_addresses (multiple addresses per company,
-- distinguished by kind: HQ, billing-to, remit-to, worksite, mailing).
-- One row per kind can be is_primary=1.
-- ──────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS company_addresses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    kind ENUM('hq','billing','remit_to','worksite','mailing') NOT NULL DEFAULT 'hq',
    label VARCHAR(80) NULL,
    line1   VARCHAR(255) NOT NULL,
    line2   VARCHAR(255) NULL,
    city    VARCHAR(120) NOT NULL,
    state   VARCHAR(80)  NULL,
    postal_code VARCHAR(20) NULL,
    country CHAR(2) NOT NULL DEFAULT 'US',
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    notes VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ca_company (company_id),
    INDEX idx_ca_tenant_kind (tenant_id, kind),
    CONSTRAINT fk_ca_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────────────
-- Additive columns on companies (idempotent via information_schema).
-- ──────────────────────────────────────────────────────────────────────
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='companies' AND COLUMN_NAME='account_manager_user_id');
SET @sql := IF(@col=0,'ALTER TABLE companies ADD COLUMN account_manager_user_id BIGINT UNSIGNED NULL AFTER created_by_user_id','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='companies' AND COLUMN_NAME='default_terms');
SET @sql := IF(@col=0,'ALTER TABLE companies ADD COLUMN default_terms VARCHAR(40) NOT NULL DEFAULT "NET30" AFTER notes','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='companies' AND COLUMN_NAME='currency');
SET @sql := IF(@col=0,'ALTER TABLE companies ADD COLUMN currency CHAR(3) NOT NULL DEFAULT "USD" AFTER default_terms','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='companies' AND COLUMN_NAME='status');
SET @sql := IF(@col=0,'ALTER TABLE companies ADD COLUMN status ENUM("prospect","active","inactive","blacklisted") NOT NULL DEFAULT "active" AFTER currency','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='companies' AND COLUMN_NAME='tax_classification');
SET @sql := IF(@col=0,'ALTER TABLE companies ADD COLUMN tax_classification ENUM("c_corp","s_corp","llc","partnership","sole_prop","nonprofit","government","other") NULL AFTER status','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='companies' AND COLUMN_NAME='industry');
SET @sql := IF(@col=0,'ALTER TABLE companies ADD COLUMN industry VARCHAR(120) NULL AFTER tax_classification','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='companies' AND COLUMN_NAME='employee_size_range');
SET @sql := IF(@col=0,'ALTER TABLE companies ADD COLUMN employee_size_range VARCHAR(40) NULL AFTER industry','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='companies' AND COLUMN_NAME='w9_on_file');
SET @sql := IF(@col=0,'ALTER TABLE companies ADD COLUMN w9_on_file TINYINT(1) NOT NULL DEFAULT 0 AFTER employee_size_range','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='companies' AND COLUMN_NAME='w9_expires_on');
SET @sql := IF(@col=0,'ALTER TABLE companies ADD COLUMN w9_expires_on DATE NULL AFTER w9_on_file','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='companies' AND COLUMN_NAME='w9_storage_object_id');
SET @sql := IF(@col=0,'ALTER TABLE companies ADD COLUMN w9_storage_object_id BIGINT UNSIGNED NULL AFTER w9_expires_on','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='companies' AND COLUMN_NAME='coi_on_file');
SET @sql := IF(@col=0,'ALTER TABLE companies ADD COLUMN coi_on_file TINYINT(1) NOT NULL DEFAULT 0 AFTER w9_storage_object_id','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='companies' AND COLUMN_NAME='coi_expires_on');
SET @sql := IF(@col=0,'ALTER TABLE companies ADD COLUMN coi_expires_on DATE NULL AFTER coi_on_file','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='companies' AND COLUMN_NAME='coi_storage_object_id');
SET @sql := IF(@col=0,'ALTER TABLE companies ADD COLUMN coi_storage_object_id BIGINT UNSIGNED NULL AFTER coi_expires_on','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='companies' AND COLUMN_NAME='tags_json');
SET @sql := IF(@col=0,'ALTER TABLE companies ADD COLUMN tags_json TEXT NULL AFTER coi_storage_object_id','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Index for the most common filter (account manager dashboard, status filter, expiring W-9/COI).
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='companies' AND INDEX_NAME='idx_companies_tenant_status');
SET @sql := IF(@idx=0,'CREATE INDEX idx_companies_tenant_status ON companies (tenant_id, status)','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='companies' AND INDEX_NAME='idx_companies_tenant_acctmgr');
SET @sql := IF(@idx=0,'CREATE INDEX idx_companies_tenant_acctmgr ON companies (tenant_id, account_manager_user_id)','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ──────────────────────────────────────────────────────────────────────
-- Additive columns on company_contacts.
-- ──────────────────────────────────────────────────────────────────────
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='company_contacts' AND COLUMN_NAME='mobile_phone');
SET @sql := IF(@col=0,'ALTER TABLE company_contacts ADD COLUMN mobile_phone VARCHAR(40) NULL AFTER phone','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='company_contacts' AND COLUMN_NAME='linkedin_url');
SET @sql := IF(@col=0,'ALTER TABLE company_contacts ADD COLUMN linkedin_url VARCHAR(255) NULL AFTER mobile_phone','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='company_contacts' AND COLUMN_NAME='department');
SET @sql := IF(@col=0,'ALTER TABLE company_contacts ADD COLUMN department VARCHAR(120) NULL AFTER contact_role','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='company_contacts' AND COLUMN_NAME='decision_role');
SET @sql := IF(@col=0,'ALTER TABLE company_contacts ADD COLUMN decision_role ENUM("decision_maker","champion","influencer","blocker","gatekeeper","unknown") NOT NULL DEFAULT "unknown" AFTER department','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='company_contacts' AND COLUMN_NAME='is_active');
SET @sql := IF(@col=0,'ALTER TABLE company_contacts ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER decision_role','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
