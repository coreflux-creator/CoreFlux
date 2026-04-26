-- Payroll Module — Initial Schema (MVP)
-- Phase 1 scope: company settings, pay schedules, pay periods, payroll
-- profiles (per-employee setup that lives outside People), payroll runs,
-- and per-line-item earnings / taxes / deductions.
--
-- Conventions:
--   - All money stored as BIGINT cents (no floats anywhere).
--   - tenant_id is on every table; reads/writes go through scopedQuery/scopedInsert.
--   - Append-only history where applicable; status fields are ENUMs.
--   - Idempotent (IF NOT EXISTS) — applied via deploy/run_migrations.php.
--
-- Tables prefix: payroll_*

-- =======================================================================
-- 1. Company-level payroll settings (one row per tenant)
-- =======================================================================
CREATE TABLE IF NOT EXISTS payroll_settings (
    id                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id                   INT UNSIGNED NOT NULL,
    legal_name                  VARCHAR(200) NOT NULL,
    dba_name                    VARCHAR(200) NULL,
    ein                         VARCHAR(20)  NULL,           -- Federal EIN, masked in UI
    primary_state               CHAR(2)      NOT NULL DEFAULT 'CA',
    state_tax_id                VARCHAR(40)  NULL,
    address_street1             VARCHAR(255) NULL,
    address_street2             VARCHAR(255) NULL,
    address_city                VARCHAR(120) NULL,
    address_region              VARCHAR(80)  NULL,
    address_postal              VARCHAR(20)  NULL,
    address_country             CHAR(2)      NOT NULL DEFAULT 'US',
    default_pay_schedule_id     INT UNSIGNED NULL,
    suta_rate_bps               INT NOT NULL DEFAULT 340,    -- basis points (3.40%); state UI tax rate
    futa_credit_rate_bps        INT NOT NULL DEFAULT 540,    -- 5.40% standard SUTA credit
    ai_run_summary_enabled      TINYINT(1) NOT NULL DEFAULT 1,
    created_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY uq_pset_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =======================================================================
-- 2. Pay schedules — frequency + period anchor
-- =======================================================================
CREATE TABLE IF NOT EXISTS payroll_pay_schedules (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           INT UNSIGNED NOT NULL,
    name                VARCHAR(120) NOT NULL,
    frequency           ENUM('weekly','biweekly','semimonthly','monthly') NOT NULL,
    period_start_anchor DATE NOT NULL,                       -- the start of period #1
    pay_date_offset_days TINYINT NOT NULL DEFAULT 5,         -- pay date = period_end + offset
    timezone            VARCHAR(40) NOT NULL DEFAULT 'America/Los_Angeles',
    active              TINYINT(1) NOT NULL DEFAULT 1,
    notes               TEXT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_psch_tenant_active (tenant_id, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =======================================================================
-- 3. Pay periods — concrete instances generated from a schedule
-- =======================================================================
CREATE TABLE IF NOT EXISTS payroll_pay_periods (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           INT UNSIGNED NOT NULL,
    schedule_id         INT UNSIGNED NOT NULL,
    period_number       INT UNSIGNED NOT NULL,               -- index since schedule anchor
    period_start        DATE NOT NULL,
    period_end          DATE NOT NULL,
    pay_date            DATE NOT NULL,
    status              ENUM('draft','open','approved','paid','closed') NOT NULL DEFAULT 'draft',
    notes               TEXT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY uq_period_tenant_sched_num (tenant_id, schedule_id, period_number),
    INDEX idx_period_tenant_status (tenant_id, status, pay_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =======================================================================
-- 4. Payroll profiles — per-employee payroll setup
--    People owns identity/comp/tax/banking. Payroll owns schedule binding,
--    work-state, payment_method, deduction elections.
-- =======================================================================
CREATE TABLE IF NOT EXISTS payroll_profiles (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           INT UNSIGNED NOT NULL,
    employee_id         INT UNSIGNED NOT NULL,               -- FK -> people_employees.id (enforced in code)
    schedule_id         INT UNSIGNED NULL,                   -- FK -> payroll_pay_schedules.id
    work_state          CHAR(2) NOT NULL DEFAULT 'CA',
    payment_method      ENUM('direct_deposit','check') NOT NULL DEFAULT 'direct_deposit',
    default_hours_per_period DECIMAL(8,2) NULL,              -- only for hourly defaulting
    retirement_pretax_bps    INT NOT NULL DEFAULT 0,         -- 401k % (basis points: 500 = 5.00%)
    health_premium_cents     BIGINT NOT NULL DEFAULT 0,      -- per pay period, pre-tax
    hsa_pretax_cents         BIGINT NOT NULL DEFAULT 0,      -- per pay period, pre-tax
    extra_post_tax_cents     BIGINT NOT NULL DEFAULT 0,      -- per pay period, post-tax misc
    enabled             TINYINT(1) NOT NULL DEFAULT 1,
    notes               TEXT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY uq_pprof_tenant_emp (tenant_id, employee_id),
    INDEX idx_pprof_tenant_sched (tenant_id, schedule_id, enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =======================================================================
-- 5. Payroll runs — one row per (pay_period × execution).
--    A pay_period can have a regular run + off-cycle/correction runs.
-- =======================================================================
CREATE TABLE IF NOT EXISTS payroll_runs (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           INT UNSIGNED NOT NULL,
    pay_period_id       INT UNSIGNED NOT NULL,
    run_type            ENUM('regular','off_cycle','correction','final') NOT NULL DEFAULT 'regular',
    status              ENUM('draft','computed','approved','paid','voided') NOT NULL DEFAULT 'draft',
    employee_count      INT UNSIGNED NOT NULL DEFAULT 0,
    gross_total_cents   BIGINT NOT NULL DEFAULT 0,
    taxes_total_cents   BIGINT NOT NULL DEFAULT 0,
    deductions_total_cents BIGINT NOT NULL DEFAULT 0,
    net_total_cents     BIGINT NOT NULL DEFAULT 0,
    employer_taxes_cents BIGINT NOT NULL DEFAULT 0,
    computed_at         TIMESTAMP NULL DEFAULT NULL,
    approved_at         TIMESTAMP NULL DEFAULT NULL,
    approved_by         INT UNSIGNED NULL,
    paid_at             TIMESTAMP NULL DEFAULT NULL,
    notes               TEXT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_run_tenant_period (tenant_id, pay_period_id),
    INDEX idx_run_tenant_status (tenant_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =======================================================================
-- 6. Payroll line items — one per (run × employee) with rolled-up totals.
-- =======================================================================
CREATE TABLE IF NOT EXISTS payroll_line_items (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           INT UNSIGNED NOT NULL,
    run_id              INT UNSIGNED NOT NULL,
    employee_id         INT UNSIGNED NOT NULL,
    work_state          CHAR(2) NOT NULL,
    pay_type            ENUM('salary','hourly') NOT NULL,
    pay_rate_cents      BIGINT NOT NULL,
    pay_frequency       ENUM('weekly','biweekly','semimonthly','monthly') NOT NULL,
    hours_regular       DECIMAL(8,2) NOT NULL DEFAULT 0,
    hours_overtime      DECIMAL(8,2) NOT NULL DEFAULT 0,
    -- Roll-ups (computed from earnings/taxes/deductions rows below)
    gross_cents         BIGINT NOT NULL DEFAULT 0,
    pretax_cents        BIGINT NOT NULL DEFAULT 0,
    taxable_cents       BIGINT NOT NULL DEFAULT 0,
    employee_taxes_cents BIGINT NOT NULL DEFAULT 0,
    posttax_cents       BIGINT NOT NULL DEFAULT 0,
    net_cents           BIGINT NOT NULL DEFAULT 0,
    employer_taxes_cents BIGINT NOT NULL DEFAULT 0,
    payment_method      ENUM('direct_deposit','check') NOT NULL DEFAULT 'direct_deposit',
    status              ENUM('computed','approved','paid','voided') NOT NULL DEFAULT 'computed',
    notes               TEXT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY uq_line_run_emp (run_id, employee_id),
    INDEX idx_line_tenant_run (tenant_id, run_id),
    INDEX idx_line_tenant_emp (tenant_id, employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =======================================================================
-- 7. Earning components per line item
-- =======================================================================
CREATE TABLE IF NOT EXISTS payroll_earnings (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           INT UNSIGNED NOT NULL,
    line_item_id        INT UNSIGNED NOT NULL,
    code                ENUM('regular','overtime','double_time','holiday','pto','sick','bonus','commission','reimbursement') NOT NULL,
    hours               DECIMAL(8,2) NULL,
    rate_cents          BIGINT NULL,
    amount_cents        BIGINT NOT NULL,
    taxable             TINYINT(1) NOT NULL DEFAULT 1,
    notes               VARCHAR(255) NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_earn_tenant_line (tenant_id, line_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =======================================================================
-- 8. Tax components per line item
--    Federal (FIT, SS, Medicare, Medicare-additional, FUTA), state (SIT, SUTA),
--    local (placeholder for city/county). Each row records taxable wage + tax.
-- =======================================================================
CREATE TABLE IF NOT EXISTS payroll_taxes (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           INT UNSIGNED NOT NULL,
    line_item_id        INT UNSIGNED NOT NULL,
    code                ENUM(
        'fit','ss_employee','medicare_employee','medicare_addl_employee',
        'ss_employer','medicare_employer','futa','suta',
        'sit_ca','sdi_ca','local'
    ) NOT NULL,
    jurisdiction        VARCHAR(20) NULL,                 -- 'US','US-CA', etc.
    taxable_wage_cents  BIGINT NOT NULL DEFAULT 0,
    rate_bps            INT NULL,                         -- effective rate in basis points
    amount_cents        BIGINT NOT NULL DEFAULT 0,
    is_employer         TINYINT(1) NOT NULL DEFAULT 0,
    notes               VARCHAR(255) NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tax_tenant_line (tenant_id, line_item_id),
    INDEX idx_tax_tenant_code (tenant_id, code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =======================================================================
-- 9. Deduction components per line item
-- =======================================================================
CREATE TABLE IF NOT EXISTS payroll_deductions (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           INT UNSIGNED NOT NULL,
    line_item_id        INT UNSIGNED NOT NULL,
    code                ENUM('retirement_401k','health_premium','hsa','dental','vision','garnishment','loan','union_dues','other_pretax','other_posttax') NOT NULL,
    is_pretax           TINYINT(1) NOT NULL DEFAULT 1,
    amount_cents        BIGINT NOT NULL,
    notes               VARCHAR(255) NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ded_tenant_line (tenant_id, line_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
