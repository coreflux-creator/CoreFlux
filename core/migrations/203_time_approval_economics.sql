-- Private, immutable economic evidence; never joined into public time responses.
CREATE TABLE IF NOT EXISTS time_approval_rates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    time_entry_id BIGINT UNSIGNED NOT NULL,
    placement_id BIGINT UNSIGNED NOT NULL,
    rate_owner_tenant_id BIGINT UNSIGNED NOT NULL,
    rate_id BIGINT UNSIGNED NOT NULL,
    source_rate_hash CHAR(64) NOT NULL,
    entry_facts_hash CHAR(64) NOT NULL,
    rate_json LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_time_approval_rate (tenant_id,time_entry_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Approval and its accounting intent commit together. Processing never sends
-- provider payments or payroll; it invokes the existing internal posting engine.
CREATE TABLE IF NOT EXISTS time_accounting_handoffs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    timesheet_id BIGINT UNSIGNED NOT NULL,
    accounting_tenant_id BIGINT UNSIGNED NOT NULL,
    events_hash CHAR(64) NOT NULL,
    events_json LONGTEXT NOT NULL,
    status ENUM('pending','posted','failed') NOT NULL DEFAULT 'pending',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    last_error TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    UNIQUE KEY uq_time_accounting_handoff (tenant_id,timesheet_id),
    KEY idx_time_accounting_pending (tenant_id,status,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
