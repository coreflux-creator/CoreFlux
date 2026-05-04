-- =======================================================================
-- Payroll migration 003 — Pay Cycles (cohort layer above pay schedules)
-- -----------------------------------------------------------------------
-- A "schedule" defines the cadence (weekly/biweekly/etc.). A "cycle" is
-- a concrete cohort that runs on that cadence — there can be MANY cycles
-- on the same schedule, e.g.:
--    Schedule "Bi-weekly" + Cycle "BW-NY engineers" (work_state=NY)
--    Schedule "Bi-weekly" + Cycle "BW-CA sales"     (work_state=CA, Mon-Sun)
--    Schedule "Bi-weekly" + Cycle "BW-FL contractors" (engagement=1099)
--
-- Profiles bind to a cycle (cycle_id is primary). pay_periods are now
-- generated PER cycle, not per schedule, so each cohort has its own
-- calendar that can be advanced independently.
--
-- All migrations are additive + idempotent. Existing data is auto-backfilled
-- into a default cycle named after each existing schedule so nothing breaks.
-- =======================================================================

CREATE TABLE IF NOT EXISTS payroll_pay_cycles (
    id                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id                   INT UNSIGNED NOT NULL,
    name                        VARCHAR(120) NOT NULL,
    schedule_id                 INT UNSIGNED NOT NULL,
    -- Optional cohort filter (JSON): { work_state: "NY" } or { department: "Eng" }
    -- — the cycle-advance engine pulls only profiles matching this filter.
    cohort_filter_json          VARCHAR(1000) NULL,
    anchor_date_override        DATE NULL,
    pay_date_offset_days_override TINYINT NULL,
    next_period_number          INT UNSIGNED NOT NULL DEFAULT 1,
    last_advanced_at            DATETIME NULL,
    last_run_id                 INT UNSIGNED NULL,
    active                      TINYINT(1) NOT NULL DEFAULT 1,
    notes                       TEXT NULL,
    created_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pcyc_tenant_name (tenant_id, name),
    INDEX idx_pcyc_tenant_active (tenant_id, active),
    INDEX idx_pcyc_schedule (tenant_id, schedule_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add cycle_id to payroll_pay_periods (idempotent).
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='payroll_pay_periods' AND COLUMN_NAME='cycle_id');
SET @sql := IF(@col=0,
    'ALTER TABLE payroll_pay_periods
        ADD COLUMN cycle_id INT UNSIGNED NULL AFTER schedule_id,
        ADD INDEX idx_period_cycle (tenant_id, cycle_id, status)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add cycle_id to payroll_profiles (idempotent). schedule_id stays for back-compat.
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='payroll_profiles' AND COLUMN_NAME='cycle_id');
SET @sql := IF(@col=0,
    'ALTER TABLE payroll_profiles
        ADD COLUMN cycle_id INT UNSIGNED NULL AFTER schedule_id,
        ADD INDEX idx_pprof_cycle (tenant_id, cycle_id, enabled)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Auto-backfill: for each tenant × schedule with no cycle yet, create a
-- "Default Cycle — <schedule.name>" so existing periods/profiles roll over
-- without breaking anything.
INSERT IGNORE INTO payroll_pay_cycles (tenant_id, name, schedule_id, active, notes)
SELECT s.tenant_id,
       CONCAT('Default — ', s.name),
       s.id,
       s.active,
       'Auto-created during migration 003. Edit or split as needed.'
FROM payroll_pay_schedules s
LEFT JOIN payroll_pay_cycles c
       ON c.tenant_id = s.tenant_id AND c.schedule_id = s.id
WHERE c.id IS NULL;

-- Backfill existing periods + profiles to point at their default cycle.
UPDATE payroll_pay_periods p
JOIN payroll_pay_cycles c
  ON c.tenant_id = p.tenant_id AND c.schedule_id = p.schedule_id
SET p.cycle_id = c.id
WHERE p.cycle_id IS NULL;

UPDATE payroll_profiles pp
JOIN payroll_pay_cycles c
  ON c.tenant_id = pp.tenant_id AND c.schedule_id = pp.schedule_id
SET pp.cycle_id = c.id
WHERE pp.cycle_id IS NULL AND pp.schedule_id IS NOT NULL;

-- Anomaly snapshot table — feeds AI cross-check + makes results auditable.
CREATE TABLE IF NOT EXISTS payroll_anomaly_findings (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    run_id          INT UNSIGNED NULL,
    period_id       INT UNSIGNED NULL,
    cycle_id        INT UNSIGNED NULL,
    employee_id     INT UNSIGNED NOT NULL,
    severity        ENUM('info','warning','critical') NOT NULL DEFAULT 'warning',
    code            VARCHAR(60) NOT NULL,    -- 'hours_drift' | 'missing_time' | 'rate_change' | ...
    message         TEXT NOT NULL,
    expected_value  VARCHAR(120) NULL,
    actual_value    VARCHAR(120) NULL,
    ai_used         TINYINT(1) NOT NULL DEFAULT 0,
    acknowledged_at DATETIME NULL,
    acknowledged_by_user_id INT UNSIGNED NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pafd_run (tenant_id, run_id),
    INDEX idx_pafd_emp (tenant_id, employee_id, code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
