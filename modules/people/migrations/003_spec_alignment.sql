-- People Module — SPEC alignment migration
-- See /app/modules/people/SPEC.md (locked) §3 Data model.
--
-- This migration is ADDITIVE — it does NOT alter the legacy people_employees
-- schema (preserved per HARD_RULES R1 + user decision: "first making a legacy
-- copy and then refactoring existing code"). New SPEC-aligned tables are
-- created with the names mandated by the SPEC. Both schemas coexist.
--
-- New tables created here:
--   people                          (talent-pool model, classification enum)
--   people_emergency_contacts
--   people_skills
--   people_skill_taxonomy
--   people_documents                (storage_object_id refs Core StorageService)
--   people_banking                  (encrypted; one row per person)
--   people_tax                      (one row per person)
--   people_pipeline_stages          (hybrid model — top-level enum + sub-stage)
--   tenant_pipeline_substages       (per-tenant)
--   people_custom_field_defs        (per-tenant)
--   people_custom_field_values
--   people_pii_access_log           (SOC2 self-serve audit, visible to tenant_admin)

-- =======================================================================
-- 1. Core person record (talent pool)
-- =======================================================================
CREATE TABLE IF NOT EXISTS people (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    external_id VARCHAR(64) NULL,

    first_name      VARCHAR(100) NOT NULL,
    middle_name     VARCHAR(100) NULL,
    last_name       VARCHAR(100) NOT NULL,
    preferred_name  VARCHAR(100) NULL,

    email_primary   VARCHAR(255) NOT NULL,
    email_secondary VARCHAR(255) NULL,
    phone_primary   VARCHAR(40) NULL,
    phone_secondary VARCHAR(40) NULL,

    classification ENUM('w2','1099','c2c','temp','perm','candidate','alumni') NOT NULL DEFAULT 'candidate',
    status         ENUM('active','bench','inactive','do_not_rehire') NOT NULL DEFAULT 'active',

    work_auth_status ENUM('citizen','green_card','h1b','opt','cpt','tn','other','unknown') NOT NULL DEFAULT 'unknown',
    work_auth_expiry DATE NULL,
    requires_sponsorship BOOLEAN NOT NULL DEFAULT 0,

    -- PII (cleartext last-4 + cipher fields are gated by people.pii.view)
    dob       DATE NULL,
    ssn_last4 CHAR(4) NULL,

    home_address_line1 VARCHAR(255) NULL,
    home_address_line2 VARCHAR(255) NULL,
    home_city          VARCHAR(120) NULL,
    home_state         VARCHAR(60)  NULL,
    home_postal_code   VARCHAR(20)  NULL,
    home_country       CHAR(2)      NULL,

    linkedin_url VARCHAR(255) NULL,
    resume_storage_object_id BIGINT UNSIGNED NULL,
    recruiter_notes TEXT NULL,

    source                VARCHAR(120) NULL,
    referred_by_person_id BIGINT UNSIGNED NULL,

    created_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,

    UNIQUE KEY uq_people_tenant_email (tenant_id, email_primary),
    INDEX idx_people_tenant_status (tenant_id, status),
    INDEX idx_people_tenant_classification (tenant_id, classification),
    INDEX idx_people_tenant_workauth_expiry (tenant_id, work_auth_expiry),
    INDEX idx_people_tenant_external (tenant_id, external_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =======================================================================
-- 2. Emergency contacts
-- =======================================================================
CREATE TABLE IF NOT EXISTS people_emergency_contacts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    person_id BIGINT UNSIGNED NOT NULL,
    name         VARCHAR(200) NOT NULL,
    relationship VARCHAR(60)  NOT NULL,
    phone        VARCHAR(40)  NOT NULL,
    email        VARCHAR(255) NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pec_person (person_id),
    INDEX idx_pec_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =======================================================================
-- 3. Skills (+ tenant taxonomy)
-- =======================================================================
CREATE TABLE IF NOT EXISTS people_skills (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    person_id BIGINT UNSIGNED NOT NULL,
    skill            VARCHAR(120) NOT NULL,
    years_experience DECIMAL(4,1) NULL,
    proficiency      ENUM('beginner','intermediate','advanced','expert') NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ps_person (person_id),
    INDEX idx_ps_tenant_skill (tenant_id, skill)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS people_skill_taxonomy (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    skill VARCHAR(120) NOT NULL,
    category VARCHAR(80) NULL,
    UNIQUE KEY uq_pst_tenant_skill (tenant_id, skill)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =======================================================================
-- 4. Documents (refs storage_objects from Core StorageService)
-- =======================================================================
CREATE TABLE IF NOT EXISTS people_documents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    person_id BIGINT UNSIGNED NOT NULL,
    doc_type ENUM('resume','offer','i9','w4','w9','nda','contract','passport','visa','license','other') NOT NULL,
    storage_object_id BIGINT UNSIGNED NOT NULL,
    file_name  VARCHAR(255) NULL,
    signed     BOOLEAN NOT NULL DEFAULT 0,
    signed_at  DATETIME NULL,
    expires_at DATETIME NULL,
    uploaded_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    INDEX idx_pd_person (person_id),
    INDEX idx_pd_tenant_type (tenant_id, doc_type),
    INDEX idx_pd_expires (tenant_id, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =======================================================================
-- 5. Banking (encrypted) — one row per person per SPEC §3.1
-- =======================================================================
CREATE TABLE IF NOT EXISTS people_banking (
    person_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,

    account_holder_name_ct VARBINARY(512) NULL,
    routing_number_ct      VARBINARY(256) NULL,
    account_number_ct      VARBINARY(512) NULL,

    account_holder_name_last4 CHAR(4) NULL,
    routing_number_last4      CHAR(4) NULL,
    account_number_last4      CHAR(4) NULL,

    account_type ENUM('checking','savings') NULL,

    kms_key_version VARCHAR(64) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by_user_id BIGINT UNSIGNED NULL,
    INDEX idx_pb_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =======================================================================
-- 6. Tax (one row per person per SPEC §3.1)
-- =======================================================================
CREATE TABLE IF NOT EXISTS people_tax (
    person_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,

    filing_status ENUM('single','mfj','mfs','hoh','qw') NULL,
    dependents INT NULL,
    additional_withholding DECIMAL(10,2) NULL,
    state VARCHAR(60) NULL,

    ssn_full_ct VARBINARY(256) NULL,
    kms_key_version VARCHAR(64) NULL,
    w4_doc_id BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_pt_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =======================================================================
-- 7. Hiring pipeline (hybrid: enum top-level + tenant sub-stage)
-- =======================================================================
CREATE TABLE IF NOT EXISTS people_pipeline_stages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    person_id BIGINT UNSIGNED NOT NULL,
    stage ENUM('sourced','screened','submitted','interview','offer','placed','bench','terminated','rejected') NOT NULL,
    substage_id BIGINT UNSIGNED NULL,
    entered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    entered_by_user_id BIGINT UNSIGNED NULL,
    note TEXT NULL,
    placement_id BIGINT UNSIGNED NULL,
    INDEX idx_pps_person_entered (person_id, entered_at),
    INDEX idx_pps_tenant_stage (tenant_id, stage)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tenant_pipeline_substages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    parent_stage ENUM('sourced','screened','submitted','interview','offer','placed','bench','terminated','rejected') NOT NULL,
    label VARCHAR(120) NOT NULL,
    order_index INT NOT NULL DEFAULT 0,
    active BOOLEAN NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tps_tenant_parent_label (tenant_id, parent_stage, label),
    INDEX idx_tps_tenant_parent (tenant_id, parent_stage, order_index)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =======================================================================
-- 8. Custom fields (per-tenant defs + values)
-- =======================================================================
CREATE TABLE IF NOT EXISTS people_custom_field_defs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    field_key VARCHAR(80) NOT NULL,
    field_label VARCHAR(200) NOT NULL,
    field_type ENUM('text','number','date','boolean','select','multiselect') NOT NULL,
    options_json TEXT NULL,
    required BOOLEAN NOT NULL DEFAULT 0,
    pii BOOLEAN NOT NULL DEFAULT 0,
    visible_to_roles_json TEXT NULL,
    editable_by_roles_json TEXT NULL,
    order_index INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    UNIQUE KEY uq_pcfd_tenant_key (tenant_id, field_key),
    INDEX idx_pcfd_tenant (tenant_id, order_index)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS people_custom_field_values (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    person_id BIGINT UNSIGNED NOT NULL,
    field_def_id BIGINT UNSIGNED NOT NULL,
    value_text    TEXT          NULL,
    value_number  DECIMAL(20,6) NULL,
    value_date    DATE          NULL,
    value_boolean BOOLEAN       NULL,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pcfv_person_field (person_id, field_def_id),
    INDEX idx_pcfv_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =======================================================================
-- 9. PII access log — SOC2 self-serve, tenant_admin visible
-- =======================================================================
CREATE TABLE IF NOT EXISTS people_pii_access_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    actor_user_id BIGINT UNSIGNED NOT NULL,
    target_person_id BIGINT UNSIGNED NULL,
    event_type ENUM('pii.viewed','banking.viewed','banking.updated','tax.viewed','tax.updated','ssn.revealed','document.downloaded') NOT NULL,
    fields_json TEXT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    request_id VARCHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ppal_tenant_created (tenant_id, created_at),
    INDEX idx_ppal_actor (tenant_id, actor_user_id, created_at),
    INDEX idx_ppal_target (tenant_id, target_person_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
