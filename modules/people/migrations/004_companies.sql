-- People module — Companies directory (Phase 1)
-- Tenant-scoped first-class company records that placements/referrals/AP/billing
-- can FK to. Replaces the scattered free-text party_name / vendor_name fields.
-- Idempotent. utf8mb4_unicode_ci. Cloudways MySQL 5.7+ compatible.

CREATE TABLE IF NOT EXISTS companies (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    name           VARCHAR(255) NOT NULL,
    legal_name     VARCHAR(255) NULL,
    ein_last4      CHAR(4)      NULL,
    ein_full_ct    VARBINARY(512) NULL,
    duns           VARCHAR(20)  NULL,
    website        VARCHAR(255) NULL,
    phone          VARCHAR(40)  NULL,
    primary_contact_name  VARCHAR(200) NULL,
    primary_contact_email VARCHAR(255) NULL,
    primary_contact_phone VARCHAR(40)  NULL,
    address_line1  VARCHAR(255) NULL,
    address_line2  VARCHAR(255) NULL,
    city           VARCHAR(120) NULL,
    state          VARCHAR(80)  NULL,
    postal_code    VARCHAR(20)  NULL,
    country        CHAR(2)      NOT NULL DEFAULT 'US',
    msa_signed_at  DATE         NULL,
    msa_storage_object_id BIGINT UNSIGNED NULL,
    notes          TEXT         NULL,
    use_count      INT UNSIGNED NOT NULL DEFAULT 0,
    last_used_at   DATETIME     NULL,
    deleted_at     DATETIME     NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_companies_tenant_name (tenant_id, name),
    INDEX idx_companies_tenant_state (tenant_id, state),
    INDEX idx_companies_tenant_use (tenant_id, last_used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Roles: many-to-many. A single company can be both 'prime_vendor' AND 'referrer'.
CREATE TABLE IF NOT EXISTS company_roles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    role ENUM('client','customer','vendor','msp','prime_vendor','sub_vendor','referrer','partner') NOT NULL,
    granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_company_role (company_id, role),
    INDEX idx_company_roles_role (role, company_id),
    CONSTRAINT fk_company_roles_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Multiple per company (recruiter, billing AP contact, technical lead, etc.)
CREATE TABLE IF NOT EXISTS company_contacts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    name        VARCHAR(200) NOT NULL,
    title       VARCHAR(150) NULL,
    email       VARCHAR(255) NULL,
    phone       VARCHAR(40)  NULL,
    contact_role ENUM('account_mgr','recruiter','ap','ar','approver','technical','executive','other') NOT NULL DEFAULT 'other',
    is_primary  TINYINT(1) NOT NULL DEFAULT 0,
    notes       VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cc_company (company_id),
    INDEX idx_cc_tenant_role (tenant_id, contact_role),
    CONSTRAINT fk_company_contacts_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Wire placements. Adds FK columns; legacy free-text columns kept for display
-- fallback + zero-downtime migration. ALTER TABLE guarded via information_schema.
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placements' AND COLUMN_NAME='end_client_company_id');
SET @sql := IF(@col=0, 'ALTER TABLE placements ADD COLUMN end_client_company_id BIGINT UNSIGNED NULL AFTER end_client_name', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_client_chain' AND COLUMN_NAME='company_id');
SET @sql := IF(@col=0, 'ALTER TABLE placement_client_chain ADD COLUMN company_id BIGINT UNSIGNED NULL AFTER party_role', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_referrals' AND COLUMN_NAME='referrer_company_id');
SET @sql := IF(@col=0, 'ALTER TABLE placement_referrals ADD COLUMN referrer_company_id BIGINT UNSIGNED NULL AFTER referrer_vendor_name', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill: every distinct free-text vendor/client name in existing data becomes
-- a company row with the appropriate role tag. Idempotent via UNIQUE name.
INSERT IGNORE INTO companies (tenant_id, name)
SELECT tenant_id, party_name FROM placement_client_chain
WHERE party_name IS NOT NULL AND party_name <> ''
  AND NOT EXISTS (SELECT 1 FROM companies c WHERE c.tenant_id = placement_client_chain.tenant_id AND c.name = placement_client_chain.party_name);

INSERT IGNORE INTO companies (tenant_id, name)
SELECT tenant_id, end_client_name FROM placements
WHERE end_client_name IS NOT NULL AND end_client_name <> ''
  AND NOT EXISTS (SELECT 1 FROM companies c WHERE c.tenant_id = placements.tenant_id AND c.name = placements.end_client_name);

INSERT IGNORE INTO companies (tenant_id, name)
SELECT tenant_id, referrer_vendor_name FROM placement_referrals
WHERE referrer_vendor_name IS NOT NULL AND referrer_vendor_name <> ''
  AND NOT EXISTS (SELECT 1 FROM companies c WHERE c.tenant_id = placement_referrals.tenant_id AND c.name = placement_referrals.referrer_vendor_name);

-- Tag roles on backfilled companies based on where they came from.
INSERT IGNORE INTO company_roles (company_id, role)
SELECT DISTINCT c.id, 'client'
FROM companies c
JOIN placements p ON p.tenant_id = c.tenant_id AND p.end_client_name = c.name
WHERE p.end_client_name IS NOT NULL;

INSERT IGNORE INTO company_roles (company_id, role)
SELECT DISTINCT c.id,
    CASE pcc.party_role
        WHEN 'end_client'    THEN 'client'
        WHEN 'msp'           THEN 'msp'
        WHEN 'prime_vendor'  THEN 'prime_vendor'
        WHEN 'sub_vendor'    THEN 'sub_vendor'
        WHEN 'direct'        THEN 'client'
        ELSE 'vendor'
    END
FROM companies c
JOIN placement_client_chain pcc ON pcc.tenant_id = c.tenant_id AND pcc.party_name = c.name;

INSERT IGNORE INTO company_roles (company_id, role)
SELECT DISTINCT c.id, 'referrer'
FROM companies c
JOIN placement_referrals pr ON pr.tenant_id = c.tenant_id AND pr.referrer_vendor_name = c.name;

-- Wire FK on the legacy rows so subsequent reads can resolve via JOIN.
UPDATE placements p
JOIN companies c ON c.tenant_id = p.tenant_id AND c.name = p.end_client_name
SET p.end_client_company_id = c.id
WHERE p.end_client_company_id IS NULL AND p.end_client_name IS NOT NULL;

UPDATE placement_client_chain pcc
JOIN companies c ON c.tenant_id = pcc.tenant_id AND c.name = pcc.party_name
SET pcc.company_id = c.id
WHERE pcc.company_id IS NULL AND pcc.party_name IS NOT NULL;

UPDATE placement_referrals pr
JOIN companies c ON c.tenant_id = pr.tenant_id AND c.name = pr.referrer_vendor_name
SET pr.referrer_company_id = c.id
WHERE pr.referrer_company_id IS NULL AND pr.referrer_vendor_name IS NOT NULL;
