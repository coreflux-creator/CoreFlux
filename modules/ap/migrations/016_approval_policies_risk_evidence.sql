-- C2 + C3 — Layered AP approval policies + Vendor risk rules
-- (Sprint 3 / Industry Layer 1: Staffing edition; the policy *engine* is
-- vertical-agnostic — staffing just supplies the typical rule shapes).
--
-- Multi-dimensional matching — a single bill is evaluated against every
-- active policy; the highest-priority match assigns the approver chain.
-- Dimensions: entity_id (exact or NULL=any), vendor_type (1099/c2c/eor/
-- recurring/anyone), amount tier (>= threshold), risk level (none/low/
-- medium/high), GL account.
--
-- Idempotent. utf8mb4_unicode_ci. Cloudways MySQL 5.7+ compatible.

CREATE TABLE IF NOT EXISTS ap_approval_policies (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(500) NULL,
    priority INT NOT NULL DEFAULT 100,
    -- match dimensions (NULL = wildcard)
    entity_id BIGINT UNSIGNED NULL,
    vendor_type VARCHAR(40) NULL,
    min_amount DECIMAL(14,2) NULL,
    max_amount DECIMAL(14,2) NULL,
    min_risk_level ENUM('none','low','medium','high') NULL,
    gl_account_code VARCHAR(40) NULL,
    -- routing payload (JSON list of approver steps)
    chain_json TEXT NOT NULL,
    quorum INT NULL,                  -- number of approvers needed at the highest tier (NULL = serial chain)
    sla_hours INT NULL,               -- escalate after N hours
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_apap_tenant_active (tenant_id, active, priority),
    INDEX idx_apap_tenant_vendor_type (tenant_id, vendor_type, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-bill evaluation log — append-only audit trail of which policy fired.
CREATE TABLE IF NOT EXISTS ap_approval_policy_evaluations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    bill_id BIGINT UNSIGNED NOT NULL,
    policy_id BIGINT UNSIGNED NULL,
    matched TINYINT(1) NOT NULL DEFAULT 0,
    chain_json TEXT NULL,
    risk_level ENUM('none','low','medium','high') NULL,
    risk_factors_json TEXT NULL,
    evaluated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_apape_bill (tenant_id, bill_id),
    INDEX idx_apape_policy (policy_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================================
-- C3 — Vendor risk
-- ====================================================================
-- Per-vendor risk score + factor list. Recomputed on save / banking change.
CREATE TABLE IF NOT EXISTS ap_vendor_risk (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    vendor_id BIGINT UNSIGNED NOT NULL,
    risk_level ENUM('none','low','medium','high') NOT NULL DEFAULT 'none',
    risk_score INT NOT NULL DEFAULT 0,
    factors_json TEXT NULL,
    requires_manual_review TINYINT(1) NOT NULL DEFAULT 0,
    last_evaluated_at DATETIME NULL,
    UNIQUE KEY uq_apvr_tenant_vendor (tenant_id, vendor_id),
    INDEX idx_apvr_tenant_level (tenant_id, risk_level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================================
-- C4 — Evidence bundles attached to bills
-- ====================================================================
CREATE TABLE IF NOT EXISTS ap_bill_evidence_bundles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    bill_id BIGINT UNSIGNED NOT NULL,
    timesheet_period_ids_json TEXT NULL,
    placement_ids_json TEXT NULL,
    approval_trail_json TEXT NULL,
    payroll_run_ids_json TEXT NULL,
    audit_hash CHAR(64) NULL,
    bundle_summary_json TEXT NULL,
    built_by_user_id BIGINT UNSIGNED NULL,
    built_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_apbeb_bill (tenant_id, bill_id),
    INDEX idx_apbeb_tenant (tenant_id, built_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
