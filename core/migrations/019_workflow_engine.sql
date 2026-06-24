-- WorkflowEngine (Sprint 4 / A1)
-- Generic n-step approval engine. Replaces today's per-module approval
-- tables for AP bills / Billing two-eye / period close tasks / time
-- approval. Per-module tables stay for backwards compatibility — the
-- engine writes to its own instance + actions tables and posts events
-- back to audit_log.
--
-- VERTICAL-AGNOSTIC. Tenant-scoped.

CREATE TABLE IF NOT EXISTS workflow_definitions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    def_key VARCHAR(80) NOT NULL,
    label VARCHAR(255) NOT NULL,
    description VARCHAR(500) NULL,
    subject_type VARCHAR(80) NOT NULL,                  -- e.g. 'ap_bill', 'billing_invoice', 'accounting_period_close', 'time_period'
    steps_json TEXT NOT NULL,                            -- ordered list: [{step:1,label:'Manager',approver_user_ids:[12,17],quorum:1,allow_email:true,sla_hours:24,escalate_to_user_id:3}, ...]
    notify_on_start TINYINT(1) NOT NULL DEFAULT 1,
    active TINYINT(1) NOT NULL DEFAULT 1,
    version INT NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_wfdef_tenant_key (tenant_id, def_key, version),
    INDEX idx_wfdef_tenant_subject (tenant_id, subject_type, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workflow_instances (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    definition_id BIGINT UNSIGNED NOT NULL,
    subject_type VARCHAR(80) NOT NULL,
    subject_id BIGINT UNSIGNED NOT NULL,
    status ENUM('pending','approved','rejected','cancelled','escalated','expired') NOT NULL DEFAULT 'pending',
    current_step INT NOT NULL DEFAULT 1,
    payload_json TEXT NULL,
    started_by_user_id BIGINT UNSIGNED NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    sla_due_at DATETIME NULL,
    last_activity_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_wfi_subject_status (tenant_id, subject_type, subject_id, status, started_at),
    INDEX idx_wfi_tenant_status (tenant_id, status, sla_due_at),
    INDEX idx_wfi_definition (definition_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workflow_step_actions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    instance_id BIGINT UNSIGNED NOT NULL,
    step_no INT NOT NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    actor_email VARCHAR(255) NULL,
    action ENUM('approve','reject','skip','delegate','comment','escalate') NOT NULL,
    delegated_to_user_id BIGINT UNSIGNED NULL,
    comment VARCHAR(2000) NULL,
    via ENUM('app','email','api','system') NOT NULL DEFAULT 'app',
    acted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_wfsa_instance (instance_id, step_no),
    INDEX idx_wfsa_tenant_actor (tenant_id, actor_user_id, acted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
