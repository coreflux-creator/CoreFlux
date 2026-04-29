-- Time Module — Phase A schema (SPEC §13)
-- Idempotent, utf8mb4_unicode_ci (Cloudways-compat).

CREATE TABLE IF NOT EXISTS time_periods (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    period_type ENUM('weekly','biweekly','semimonthly','monthly') NOT NULL DEFAULT 'weekly',
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    label VARCHAR(40) NOT NULL,
    status ENUM('open','locked','closed') NOT NULL DEFAULT 'open',
    closed_at DATETIME NULL,
    closed_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tp_tenant_start_end (tenant_id, start_date, end_date),
    INDEX idx_tp_tenant_status (tenant_id, status),
    INDEX idx_tp_tenant_label (tenant_id, label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tenant_time_categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(40) NOT NULL,
    label VARCHAR(120) NOT NULL,
    parent_bucket ENUM('billable','nonbillable','pto','unpaid') NOT NULL,
    is_overtime BOOLEAN NOT NULL DEFAULT 0,
    active BOOLEAN NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ttc_tenant_code (tenant_id, code),
    INDEX idx_ttc_tenant_active (tenant_id, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS time_entries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    placement_id BIGINT UNSIGNED NOT NULL,
    person_id BIGINT UNSIGNED NOT NULL,
    period_id BIGINT UNSIGNED NOT NULL,
    work_date DATE NOT NULL,
    category ENUM('regular_billable','regular_nonbillable','OT_billable','OT_nonbillable','holiday','vacation','sick','bereavement','unpaid_leave','custom') NOT NULL,
    custom_category_id BIGINT UNSIGNED NULL,
    hours DECIMAL(6,2) NOT NULL,
    description VARCHAR(500) NULL,
    source ENUM('ai_inbox','bulk_upload','manual_entry','client_portal_paste') NOT NULL DEFAULT 'manual_entry',
    source_ref_id BIGINT UNSIGNED NULL,
    status ENUM('draft','pending_review','approved','rejected','superseded') NOT NULL DEFAULT 'draft',
    rate_snapshot_id BIGINT UNSIGNED NULL,
    approved_by_user_id BIGINT UNSIGNED NULL,
    approved_at DATETIME NULL,
    approved_via ENUM('manual','tokenized_client_email','bulk_pre_approved') NULL,
    client_approver_email VARCHAR(255) NULL,
    rejected_reason VARCHAR(500) NULL,
    superseded_by_id BIGINT UNSIGNED NULL,
    correction_reason VARCHAR(500) NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_te_tenant_period_status (tenant_id, period_id, status),
    INDEX idx_te_tenant_placement_date (tenant_id, placement_id, work_date),
    INDEX idx_te_tenant_person_date (tenant_id, person_id, work_date),
    INDEX idx_te_status_source (status, source),
    INDEX idx_te_superseded (superseded_by_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS time_downstream_feed (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    period_id BIGINT UNSIGNED NOT NULL,
    placement_id BIGINT UNSIGNED NOT NULL,
    bundle_type ENUM('ar','ap','payroll','revrec') NOT NULL,
    entries_json LONGTEXT NULL,
    rate_snapshot_id BIGINT UNSIGNED NULL,
    total_hours_billable DECIMAL(10,2) NOT NULL DEFAULT 0,
    total_hours_nonbillable DECIMAL(10,2) NOT NULL DEFAULT 0,
    total_hours_pto DECIMAL(10,2) NOT NULL DEFAULT 0,
    total_amount_bill DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_amount_pay DECIMAL(12,2) NOT NULL DEFAULT 0,
    status ENUM('ready','consumed','locked','superseded') NOT NULL DEFAULT 'ready',
    consumed_at DATETIME NULL,
    consumed_by_module VARCHAR(40) NULL,
    consumed_ref_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tdf_period_placement_type (period_id, placement_id, bundle_type),
    INDEX idx_tdf_tenant_status (tenant_id, status),
    INDEX idx_tdf_consumed (consumed_by_module, consumed_ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
