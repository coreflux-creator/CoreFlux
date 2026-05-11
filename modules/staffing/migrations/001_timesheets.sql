-- CoreStaffing Phase 1 — Weekly Timesheet header table.
--
-- One row per (tenant, worker, week). Aggregates the daily detail rows in
-- `time_entries` (which keep their existing schema; we just add a FK).
--
-- The header is the unit of submission/approval/rejection. The user clicks
-- "Submit Week" → ALL rows for that timesheet flip to pending_review
-- atomically. Approver acts on the header (approve/reject); approve cascades
-- to rows, reject sends the whole week back to draft per spec §10.6 + the
-- product owner's explicit preference (full re-submit on rejection).
--
-- v2 (2026-02): renamed from `timesheets` → `staffing_timesheets` to avoid
-- collision with the legacy `timesheets` table from sql/setup.sql (which
-- has a different schema: user_id, week_start, hours_worked) and is still
-- referenced by /app/timesheets/* and /app/people/* legacy code.
--
-- Idempotent via CREATE TABLE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS staffing_timesheets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    worker_user_id BIGINT UNSIGNED NULL,
    person_id BIGINT UNSIGNED NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    status ENUM('draft','submitted','approved','rejected','payroll_ready','billing_ready','locked') NOT NULL DEFAULT 'draft',
    submitted_at DATETIME NULL,
    approved_at DATETIME NULL,
    approved_by_user_id BIGINT UNSIGNED NULL,
    rejected_at DATETIME NULL,
    rejected_by_user_id BIGINT UNSIGNED NULL,
    rejection_reason VARCHAR(500) NULL,
    locked_at DATETIME NULL,
    total_hours DECIMAL(8,2) NOT NULL DEFAULT 0,
    notes VARCHAR(1000) NULL,
    workflow_instance_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sts_tenant_person_week (tenant_id, person_id, period_start),
    INDEX idx_sts_tenant_status (tenant_id, status),
    INDEX idx_sts_tenant_period (tenant_id, period_start, period_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tenant setting: week-start day (0=Sunday, 1=Monday). Default Monday.
CREATE TABLE IF NOT EXISTS tenant_staffing_settings (
    tenant_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    week_starts_on TINYINT NOT NULL DEFAULT 1,
    contracted_hours_per_week DECIMAL(5,2) NOT NULL DEFAULT 40.00,
    overtime_threshold DECIMAL(5,2) NOT NULL DEFAULT 40.00,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
