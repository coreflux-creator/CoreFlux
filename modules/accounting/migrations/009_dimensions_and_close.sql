-- Accounting v1.0 — Phase 2 (Sprint 2 of holistic plan)
-- B2 Dimensions engine + B4 Close-workflow checklist + close packet artifact metadata.
-- All vertical-agnostic per the CORE/INDUSTRY architecture: dimension keys are
-- tenant-defined so a hospitality tenant adds `shift`/`service_period`,
-- a staffing tenant adds `placement`/`worker_class`, etc.
--
-- Idempotent. utf8mb4_unicode_ci. Cloudways MySQL 5.7+ compatible.

-- =======================================================================
-- B2: Dimensions registry
-- =======================================================================
CREATE TABLE IF NOT EXISTS accounting_dimensions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    dim_key VARCHAR(64) NOT NULL,
    label VARCHAR(120) NOT NULL,
    data_type ENUM('text','reference','enum') NOT NULL DEFAULT 'text',
    reference_table VARCHAR(64) NULL,
    description VARCHAR(500) NULL,
    required_default TINYINT(1) NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_acc_dim_tenant_key (tenant_id, dim_key),
    INDEX idx_acc_dim_tenant_active (tenant_id, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional value whitelist (per dim_key). When empty, dimension accepts any text.
CREATE TABLE IF NOT EXISTS accounting_dimension_values (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    dimension_id BIGINT UNSIGNED NOT NULL,
    value_code VARCHAR(120) NOT NULL,
    value_label VARCHAR(255) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_acc_dim_val (tenant_id, dimension_id, value_code),
    INDEX idx_acc_dim_val_dim (dimension_id, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-account rules: which dimensions are required when posting to this account.
CREATE TABLE IF NOT EXISTS accounting_account_dim_rules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    dimension_id BIGINT UNSIGNED NOT NULL,
    requirement ENUM('required','optional','blocked') NOT NULL DEFAULT 'required',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_acc_dim_rule (tenant_id, account_id, dimension_id),
    INDEX idx_acc_dim_rule_acct (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =======================================================================
-- B4: Close-workflow checklist (per period instance)
-- =======================================================================
CREATE TABLE IF NOT EXISTS accounting_close_tasks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    period_id BIGINT UNSIGNED NOT NULL,
    task_key VARCHAR(80) NOT NULL,
    title VARCHAR(255) NOT NULL,
    description VARCHAR(1000) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    assignee_user_id BIGINT UNSIGNED NULL,
    due_date DATE NULL,
    status ENUM('pending','in_progress','done','skipped','blocked') NOT NULL DEFAULT 'pending',
    completed_at DATETIME NULL,
    completed_by_user_id BIGINT UNSIGNED NULL,
    notes VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_acc_close_task (period_id, task_key),
    INDEX idx_acc_close_task_period (period_id, status),
    INDEX idx_acc_close_task_tenant (tenant_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Close packet artifacts (generated PDF/HTML stored against a closed period).
CREATE TABLE IF NOT EXISTS accounting_close_packets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    period_id BIGINT UNSIGNED NOT NULL,
    storage_object_id BIGINT UNSIGNED NULL,
    file_format ENUM('html','pdf') NOT NULL DEFAULT 'html',
    summary_json TEXT NULL,
    built_by_user_id BIGINT UNSIGNED NULL,
    built_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_acc_close_packet_period (period_id),
    INDEX idx_acc_close_packet_tenant (tenant_id, built_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
