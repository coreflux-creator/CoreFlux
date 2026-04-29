-- Placements Module — Phase A schema (SPEC §13 MVP cut list)
-- See /app/modules/placements/SPEC.md §3 for the full data model.
-- Idempotent (CREATE TABLE IF NOT EXISTS). Uses utf8mb4_unicode_ci to match
-- your existing CoreFlux DB collation (Cloudways MySQL 5.7 / MariaDB compat).

-- =======================================================================
-- 1. Tenant taxonomies (vendor portals + end-client typeahead)
-- =======================================================================
CREATE TABLE IF NOT EXISTS tenant_vendor_portals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    description TEXT NULL,
    default_fee_pct DECIMAL(6,4) NULL,
    active BOOLEAN NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tvp_tenant_name (tenant_id, name),
    INDEX idx_tvp_tenant_active (tenant_id, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tenant_end_clients (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    client_name VARCHAR(255) NOT NULL,
    use_count INT NOT NULL DEFAULT 0,
    last_used_at DATETIME NULL,
    UNIQUE KEY uq_tec_tenant_name (tenant_id, client_name),
    INDEX idx_tec_tenant_used (tenant_id, last_used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =======================================================================
-- 2. placements (one row per engagement) + denormalized approval contact
-- =======================================================================
CREATE TABLE IF NOT EXISTS placements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    person_id BIGINT UNSIGNED NOT NULL,
    external_id VARCHAR(64) NULL,

    status ENUM('draft','pending_start','active','on_hold','ended','cancelled') NOT NULL DEFAULT 'draft',
    start_date DATE NOT NULL,
    end_date DATE NULL,
    actual_end_date DATE NULL,
    due_date DATE NULL,

    engagement_type ENUM('w2','1099','c2c','temp_to_perm','direct_hire') NOT NULL DEFAULT 'w2',
    worksite_state VARCHAR(60) NULL,
    worksite_country CHAR(2) NULL,
    remote_policy ENUM('onsite','hybrid','remote') NULL,

    title VARCHAR(200) NOT NULL,
    end_client_name VARCHAR(255) NULL,
    notes TEXT NULL,

    -- Denormalized approval contact (SPEC §3.10)
    client_approver_name VARCHAR(200) NULL,
    client_approver_email VARCHAR(255) NULL,
    tokenized_email_approval_enabled BOOLEAN NOT NULL DEFAULT 0,
    bulk_uploads_can_be_pre_approved BOOLEAN NOT NULL DEFAULT 0,

    created_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,

    INDEX idx_pl_tenant_person_status (tenant_id, person_id, status),
    INDEX idx_pl_tenant_status (tenant_id, status),
    INDEX idx_pl_tenant_end (tenant_id, end_date),
    INDEX idx_pl_tenant_due (tenant_id, due_date),
    INDEX idx_pl_tenant_external (tenant_id, external_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =======================================================================
-- 3. placement_client_chain (multi-tier vendor stack)
-- =======================================================================
CREATE TABLE IF NOT EXISTS placement_client_chain (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    placement_id BIGINT UNSIGNED NOT NULL,
    position TINYINT UNSIGNED NOT NULL,
    party_name VARCHAR(255) NOT NULL,
    party_role ENUM('end_client','msp','prime_vendor','sub_vendor','direct') NOT NULL,
    vendor_portal_id BIGINT UNSIGNED NULL,
    portal_fee_pct  DECIMAL(6,4) NULL,
    portal_fee_flat DECIMAL(10,2) NULL,
    contract_storage_object_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pcc_placement_position (placement_id, position),
    INDEX idx_pcc_tenant (tenant_id),
    INDEX idx_pcc_portal (vendor_portal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =======================================================================
-- 4. placement_rates (effective-dated, append-only, snapshot lock)
-- =======================================================================
CREATE TABLE IF NOT EXISTS placement_rates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    placement_id BIGINT UNSIGNED NOT NULL,

    effective_from DATE NOT NULL,
    effective_to DATE NULL,

    bill_rate DECIMAL(10,4) NOT NULL,
    bill_rate_unit ENUM('hour','day','week','month','project') NOT NULL DEFAULT 'hour',
    pay_rate DECIMAL(10,4) NOT NULL,
    pay_rate_unit ENUM('hour','day','week','month','project') NOT NULL DEFAULT 'hour',
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    ot_multiplier DECIMAL(4,2) NOT NULL DEFAULT 1.50,
    dt_multiplier DECIMAL(4,2) NOT NULL DEFAULT 2.00,
    adder_pct DECIMAL(6,4) NULL,

    -- Computed at approval and frozen (SPEC §4)
    adjusted_bill_rate DECIMAL(10,4) NULL,
    net_to_vendor      DECIMAL(10,4) NULL,
    background_fee_total DECIMAL(10,2) NULL,

    approved_by_user_id BIGINT UNSIGNED NULL,
    approved_at DATETIME NULL,
    is_correction BOOLEAN NOT NULL DEFAULT 0,
    correction_reason TEXT NULL,
    superseded_by BIGINT UNSIGNED NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_prt_placement_eff (placement_id, effective_from),
    INDEX idx_prt_tenant_approved (tenant_id, approved_at),
    INDEX idx_prt_superseded (superseded_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =======================================================================
-- 5. placement_commissions (per-placement, optional plan ref)
-- =======================================================================
CREATE TABLE IF NOT EXISTS placement_commissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    placement_id BIGINT UNSIGNED NOT NULL,
    plan_id BIGINT UNSIGNED NULL,
    role ENUM('account_manager','lead','recruiter','team','other') NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    split_pct DECIMAL(6,4) NULL,
    basis ENUM('net_margin','gross_margin','bill_rate','flat') NOT NULL DEFAULT 'net_margin',
    flat_amount DECIMAL(10,2) NULL,
    effective_from DATE NOT NULL,
    effective_to DATE NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_pc_placement (placement_id),
    INDEX idx_pc_tenant_user (tenant_id, user_id),
    INDEX idx_pc_role (placement_id, role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =======================================================================
-- 6. placement_referrals
-- =======================================================================
CREATE TABLE IF NOT EXISTS placement_referrals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    placement_id BIGINT UNSIGNED NOT NULL,
    referrer_type ENUM('vendor','person','user') NOT NULL,
    referrer_vendor_name VARCHAR(255) NULL,
    referrer_person_id BIGINT UNSIGNED NULL,
    referrer_user_id BIGINT UNSIGNED NULL,
    fee_pct DECIMAL(6,4) NULL,
    fee_flat DECIMAL(10,2) NULL,
    fee_basis ENUM('per_hour','per_invoice','one_time','pct_bill','pct_margin') NOT NULL,
    duration_months INT NULL,
    start_date DATE NOT NULL,
    end_date DATE NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pr_placement (placement_id),
    INDEX idx_pr_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =======================================================================
-- 7. placement_corp_details (C2C only, encrypted EIN)
-- =======================================================================
CREATE TABLE IF NOT EXISTS placement_corp_details (
    placement_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    corp_legal_name VARCHAR(255) NOT NULL,
    corp_ein_ct VARBINARY(256) NULL,
    corp_ein_last4 CHAR(4) NULL,
    kms_key_version VARCHAR(64) NULL,
    corp_address_line1 VARCHAR(255) NULL,
    corp_address_line2 VARCHAR(255) NULL,
    corp_city VARCHAR(120) NULL,
    corp_state VARCHAR(60) NULL,
    corp_postal_code VARCHAR(20) NULL,
    corp_country CHAR(2) NULL,
    corp_contact_name VARCHAR(200) NULL,
    corp_contact_email VARCHAR(255) NULL,
    corp_contact_phone VARCHAR(40) NULL,
    msa_storage_object_id BIGINT UNSIGNED NULL,
    coi_storage_object_id BIGINT UNSIGNED NULL,
    coi_expiry DATE NULL,
    w9_storage_object_id BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_pcd_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =======================================================================
-- 8. placement_documents
-- =======================================================================
CREATE TABLE IF NOT EXISTS placement_documents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    placement_id BIGINT UNSIGNED NOT NULL,
    doc_type ENUM('msa','sow','work_order','rate_sheet','timesheet_template','poc','noc','other') NOT NULL,
    storage_object_id BIGINT UNSIGNED NOT NULL,
    file_name VARCHAR(255) NULL,
    effective_from DATE NULL,
    effective_to DATE NULL,
    uploaded_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    INDEX idx_pld_placement (placement_id),
    INDEX idx_pld_tenant_type (tenant_id, doc_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
