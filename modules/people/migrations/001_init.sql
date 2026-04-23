-- People Module — Initial Schema (MVP)
-- Full scope: identity, contact, employment, compensation, tax, banking,
-- documents, time off, org (derived from manager_id), plus audit tables.
--
-- Run against the CoreFlux MySQL database ONCE. Idempotent via IF NOT EXISTS.
-- All tables prefixed `people_*` per module convention. All enforce tenant_id.

-- =======================================================================
-- 1. Identity + Employment (the canonical employee record)
-- =======================================================================
CREATE TABLE IF NOT EXISTS people_employees (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id               INT UNSIGNED NOT NULL,
    user_id                 INT UNSIGNED NULL,             -- FK -> core users.id, nullable until invited
    employee_number         VARCHAR(32)  NOT NULL,         -- tenant-unique; set by People module
    -- Identity
    legal_first_name        VARCHAR(80)  NOT NULL,
    legal_middle_name       VARCHAR(80)  NULL,
    legal_last_name         VARCHAR(80)  NOT NULL,
    preferred_name          VARCHAR(80)  NULL,
    date_of_birth           DATE         NULL,
    ssn_cipher              VARBINARY(512) NULL,           -- encrypted SSN/SIN
    ssn_last4               CHAR(4)      NULL,
    ssn_hash                CHAR(64)     NULL,             -- HMAC-SHA256 for dup detection
    gender                  VARCHAR(40)  NULL,
    marital_status          VARCHAR(40)  NULL,
    citizenship_status      VARCHAR(40)  NULL,
    work_auth_status        VARCHAR(40)  NULL,
    photo_url               VARCHAR(512) NULL,
    personal_email          VARCHAR(255) NULL,
    -- Employment
    status                  ENUM('pending','active','on_leave','terminated') NOT NULL DEFAULT 'pending',
    employment_type         ENUM('full_time','part_time','contractor','intern','temp') NOT NULL DEFAULT 'full_time',
    flsa_class              ENUM('exempt','non_exempt') NOT NULL DEFAULT 'non_exempt',
    hire_date               DATE         NULL,
    start_date              DATE         NULL,
    terminated_at           DATE         NULL,
    termination_reason      VARCHAR(255) NULL,
    manager_id              INT UNSIGNED NULL,             -- self-reference -> people_employees.id
    department              VARCHAR(120) NULL,
    location                VARCHAR(120) NULL,
    job_title               VARCHAR(150) NULL,
    work_email              VARCHAR(255) NULL,
    created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY uq_people_tenant_number (tenant_id, employee_number),
    UNIQUE KEY uq_people_tenant_ssnhash (tenant_id, ssn_hash),
    INDEX idx_people_tenant_status (tenant_id, status),
    INDEX idx_people_tenant_mgr    (tenant_id, manager_id),
    INDEX idx_people_tenant_last   (tenant_id, legal_last_name, legal_first_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =======================================================================
-- 2. Contact
-- =======================================================================
CREATE TABLE IF NOT EXISTS people_addresses (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    employee_id     INT UNSIGNED NOT NULL,
    kind            ENUM('home','mailing','work','other') NOT NULL DEFAULT 'home',
    street1         VARCHAR(255) NOT NULL,
    street2         VARCHAR(255) NULL,
    city            VARCHAR(120) NOT NULL,
    region          VARCHAR(80)  NULL,     -- US state / CA province
    postal_code     VARCHAR(20)  NULL,
    country         CHAR(2)      NOT NULL DEFAULT 'US',
    effective_from  DATE         NULL,
    effective_to    DATE         NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_addr_tenant_emp (tenant_id, employee_id),
    INDEX idx_addr_tenant_kind (tenant_id, kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS people_phones (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    employee_id     INT UNSIGNED NOT NULL,
    kind            ENUM('mobile','home','work','other') NOT NULL DEFAULT 'mobile',
    number          VARCHAR(40)  NOT NULL,
    is_preferred    TINYINT(1)   NOT NULL DEFAULT 0,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_phone_tenant_emp (tenant_id, employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS people_emergency_contacts (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    employee_id     INT UNSIGNED NOT NULL,
    priority        TINYINT UNSIGNED NOT NULL DEFAULT 1,
    name            VARCHAR(160) NOT NULL,
    relationship    VARCHAR(80)  NULL,
    phone           VARCHAR(40)  NULL,
    email           VARCHAR(255) NULL,
    notes           TEXT         NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_ec_tenant_emp (tenant_id, employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =======================================================================
-- 3. Employment history (transfers, promotions, status changes)
-- =======================================================================
CREATE TABLE IF NOT EXISTS people_employment_history (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    employee_id     INT UNSIGNED NOT NULL,
    event_type      ENUM('hire','transfer','promotion','demotion','status_change','termination','rehire') NOT NULL,
    effective_date  DATE         NOT NULL,
    job_title       VARCHAR(150) NULL,
    department      VARCHAR(120) NULL,
    location        VARCHAR(120) NULL,
    manager_id      INT UNSIGNED NULL,
    status          VARCHAR(40)  NULL,     -- stored snapshot of people_employees.status at event time
    reason          VARCHAR(255) NULL,
    notes           TEXT         NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by      INT UNSIGNED NULL,
    INDEX idx_emphist_tenant_emp (tenant_id, employee_id, effective_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =======================================================================
-- 4. Compensation (history-aware; cents everywhere)
-- =======================================================================
CREATE TABLE IF NOT EXISTS people_compensation (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    employee_id     INT UNSIGNED NOT NULL,
    pay_type        ENUM('salary','hourly') NOT NULL,
    pay_rate_cents  BIGINT NOT NULL,                -- annualized for salary; per-hour for hourly
    pay_frequency   ENUM('weekly','biweekly','semimonthly','monthly') NOT NULL,
    currency        CHAR(3) NOT NULL DEFAULT 'USD',
    bonus_target_cents BIGINT NULL,
    effective_from  DATE NOT NULL,
    effective_to    DATE NULL,                      -- NULL = current
    reason          VARCHAR(80)  NULL,              -- 'promotion','merit','market','new_hire','correction'
    notes           TEXT         NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by      INT UNSIGNED NULL,
    INDEX idx_comp_tenant_emp_eff (tenant_id, employee_id, effective_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =======================================================================
-- 5. Tax
-- =======================================================================
CREATE TABLE IF NOT EXISTS people_tax_federal (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id               INT UNSIGNED NOT NULL,
    employee_id             INT UNSIGNED NOT NULL,
    form_version            VARCHAR(10) NOT NULL DEFAULT 'W4-2020',
    filing_status           ENUM('single','married_filing_jointly','head_of_household') NOT NULL,
    multiple_jobs           TINYINT(1) NOT NULL DEFAULT 0,
    dependents_amount_cents BIGINT NOT NULL DEFAULT 0,
    other_income_cents      BIGINT NOT NULL DEFAULT 0,
    deductions_cents        BIGINT NOT NULL DEFAULT 0,
    extra_withholding_cents BIGINT NOT NULL DEFAULT 0,
    effective_date          DATE NOT NULL,
    signed_at               TIMESTAMP NULL DEFAULT NULL,
    notes                   TEXT NULL,
    created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by              INT UNSIGNED NULL,
    INDEX idx_tf_tenant_emp_eff (tenant_id, employee_id, effective_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS people_tax_state (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id               INT UNSIGNED NOT NULL,
    employee_id             INT UNSIGNED NOT NULL,
    state_code              CHAR(2) NOT NULL,
    filing_status           VARCHAR(40) NULL,
    allowances              SMALLINT NOT NULL DEFAULT 0,
    extra_withholding_cents BIGINT NOT NULL DEFAULT 0,
    effective_date          DATE NOT NULL,
    extra_fields_json       JSON NULL,              -- state-specific quirks
    created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by              INT UNSIGNED NULL,
    INDEX idx_ts_tenant_emp_state (tenant_id, employee_id, state_code, effective_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS people_i9 (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           INT UNSIGNED NOT NULL,
    employee_id         INT UNSIGNED NOT NULL,
    status              ENUM('pending','verified','rejected','expired') NOT NULL DEFAULT 'pending',
    list_a_document     VARCHAR(120) NULL,
    list_b_document     VARCHAR(120) NULL,
    list_c_document     VARCHAR(120) NULL,
    verified_at         DATE NULL,
    verifier_user_id    INT UNSIGNED NULL,
    reverify_due        DATE NULL,
    notes               TEXT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY uq_i9_tenant_emp (tenant_id, employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =======================================================================
-- 6. Banking (direct deposit)
-- =======================================================================
CREATE TABLE IF NOT EXISTS people_bank_accounts (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id            INT UNSIGNED NOT NULL,
    employee_id          INT UNSIGNED NOT NULL,
    priority             TINYINT UNSIGNED NOT NULL DEFAULT 1,
    allocation_type      ENUM('percent','remainder','fixed_amount') NOT NULL DEFAULT 'remainder',
    allocation_value     BIGINT NULL,                      -- basis points if percent; cents if fixed
    account_type         ENUM('checking','savings') NOT NULL DEFAULT 'checking',
    bank_name            VARCHAR(160) NULL,
    routing_cipher       VARBINARY(512) NOT NULL,
    routing_last4        CHAR(4) NULL,
    routing_hash         CHAR(64) NULL,
    account_cipher       VARBINARY(512) NOT NULL,
    account_last4        CHAR(4) NULL,
    account_hash         CHAR(64) NULL,
    status               ENUM('active','pending_verified','closed') NOT NULL DEFAULT 'active',
    effective_from       DATE NULL,
    closed_at            DATE NULL,
    created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_bank_tenant_emp (tenant_id, employee_id, priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =======================================================================
-- 7. Documents (storage_path is relative to a module-owned directory)
-- =======================================================================
CREATE TABLE IF NOT EXISTS people_documents (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    employee_id     INT UNSIGNED NOT NULL,
    kind            ENUM('offer_letter','w4','i9','state_withholding','direct_deposit','handbook_ack','termination','other') NOT NULL,
    filename        VARCHAR(255) NOT NULL,
    mime_type       VARCHAR(120) NOT NULL,
    size_bytes      BIGINT NOT NULL,
    storage_path    VARCHAR(512) NOT NULL,          -- opaque path managed by the module
    uploaded_by     INT UNSIGNED NULL,
    uploaded_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    signed_at       TIMESTAMP NULL DEFAULT NULL,
    signed_by       INT UNSIGNED NULL,
    notes           TEXT NULL,
    INDEX idx_docs_tenant_emp (tenant_id, employee_id, kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =======================================================================
-- 8. Time off
-- =======================================================================
CREATE TABLE IF NOT EXISTS people_leave_policies (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id         INT UNSIGNED NOT NULL,
    name              VARCHAR(120) NOT NULL,
    category          ENUM('vacation','sick','personal','parental','bereavement','jury_duty','unpaid','other') NOT NULL,
    unit              ENUM('hours','days') NOT NULL DEFAULT 'hours',
    accrual_rate      DECIMAL(10,4) NOT NULL DEFAULT 0,  -- units per pay period
    accrual_period    ENUM('per_pay_period','per_month','per_year','lump_sum','per_worked_hour') NOT NULL DEFAULT 'per_pay_period',
    max_balance       DECIMAL(10,2) NULL,               -- cap; NULL = unlimited
    carryover_cap     DECIMAL(10,2) NULL,
    effective_from    DATE NOT NULL,
    active            TINYINT(1) NOT NULL DEFAULT 1,
    notes             TEXT NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_lp_tenant (tenant_id, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS people_leave_balances (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id         INT UNSIGNED NOT NULL,
    employee_id       INT UNSIGNED NOT NULL,
    policy_id         INT UNSIGNED NOT NULL,
    balance_units     DECIMAL(10,4) NOT NULL DEFAULT 0,
    used_units        DECIMAL(10,4) NOT NULL DEFAULT 0,
    pending_units     DECIMAL(10,4) NOT NULL DEFAULT 0,
    last_accrued_at   TIMESTAMP NULL DEFAULT NULL,
    updated_at        TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY uq_lb_tenant_emp_policy (tenant_id, employee_id, policy_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS people_leave_requests (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id         INT UNSIGNED NOT NULL,
    employee_id       INT UNSIGNED NOT NULL,
    policy_id         INT UNSIGNED NOT NULL,
    start_date        DATE NOT NULL,
    end_date          DATE NOT NULL,
    requested_units   DECIMAL(10,4) NOT NULL,
    status            ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    reviewer_id       INT UNSIGNED NULL,
    reviewed_at       TIMESTAMP NULL DEFAULT NULL,
    review_notes      TEXT NULL,
    employee_notes    TEXT NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_lr_tenant_emp_status (tenant_id, employee_id, status),
    INDEX idx_lr_tenant_status_date (tenant_id, status, start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =======================================================================
-- 9. Audit: PII access + change log
-- =======================================================================
CREATE TABLE IF NOT EXISTS people_pii_access_log (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id         INT UNSIGNED NOT NULL,
    user_id           INT UNSIGNED NULL,
    employee_id       INT UNSIGNED NOT NULL,
    field             VARCHAR(80) NOT NULL,              -- 'ssn','bank.routing','bank.account'
    action            ENUM('view_full','decrypt') NOT NULL DEFAULT 'view_full',
    ip_address        VARCHAR(45) NULL,
    user_agent        VARCHAR(255) NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pii_tenant_emp_time (tenant_id, employee_id, created_at),
    INDEX idx_pii_tenant_user_time (tenant_id, user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS people_change_log (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id         INT UNSIGNED NOT NULL,
    user_id           INT UNSIGNED NULL,
    employee_id       INT UNSIGNED NOT NULL,
    entity            VARCHAR(60) NOT NULL,              -- 'employee','address','comp','tax_federal','tax_state','bank_account', ...
    entity_id         BIGINT UNSIGNED NULL,
    action            ENUM('create','update','delete','terminate','rehire') NOT NULL,
    fields_changed    JSON NULL,
    before_hash       CHAR(64) NULL,                     -- sha256 of before snapshot (PII-safe)
    after_hash        CHAR(64) NULL,
    ip_address        VARCHAR(45) NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pcl_tenant_emp_time (tenant_id, employee_id, created_at),
    INDEX idx_pcl_tenant_entity   (tenant_id, entity, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
