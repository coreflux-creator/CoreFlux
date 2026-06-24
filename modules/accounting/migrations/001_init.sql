-- Accounting v1.0 — Phase 0 init (foundation)
-- Subset of SPEC §3 needed for subledgers to post into a real GL.
-- Idempotent. utf8mb4_unicode_ci. Cloudways MySQL 5.7+ compatible.
--
-- Covers:
--   • accounting_entities              (legal entities)
--   • accounting_fiscal_calendars      (calendars)
--   • accounting_periods               (open/closed periods per entity)
--   • accounting_accounts              (chart of accounts; tenant-shared)
--   • accounting_journal_entries      (headers; immutable once posted)
--   • accounting_journal_entry_lines  (lines; debits = credits)
--   • accounting_posting_idempotency  (subledger dedupe)
--
-- OUT OF SCOPE for Phase 0 (SPEC §3 items deferred):
--   • Dimensions (projects/departments/segments)
--   • Close workflows / tasks / packets
--   • Consolidation / entity groups / ownership
--   • Intercompany auto-balancing
--   • HMAC webhooks / external integrations
--   • Multi-currency revaluation — currency fields present but conversion
--     math is single-functional-currency for now.

CREATE TABLE IF NOT EXISTS accounting_entities (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(40) NOT NULL,
    legal_name VARCHAR(255) NOT NULL,
    country CHAR(2) NOT NULL DEFAULT 'US',
    base_currency CHAR(3) NOT NULL DEFAULT 'USD',
    parent_entity_id BIGINT UNSIGNED NULL,
    tax_id_ct VARBINARY(256) NULL,
    kms_key_version VARCHAR(64) NULL,
    address_json TEXT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ae_tenant_code (tenant_id, code),
    INDEX idx_ae_tenant_active (tenant_id, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounting_fiscal_calendars (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    entity_id BIGINT UNSIGNED NULL,
    name VARCHAR(120) NOT NULL,
    calendar_type ENUM('calendar_year','custom_fiscal','4_4_5','13_period') NOT NULL DEFAULT 'calendar_year',
    start_date DATE NOT NULL,
    end_date   DATE NOT NULL,
    period_count INT NOT NULL DEFAULT 12,
    period_definition_json TEXT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_afc_tenant_entity (tenant_id, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounting_periods (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    calendar_id BIGINT UNSIGNED NULL,
    period_number INT NOT NULL,
    start_date DATE NOT NULL,
    end_date   DATE NOT NULL,
    status ENUM('future','open','soft_closed','closed','reopened') NOT NULL DEFAULT 'open',
    closed_at DATETIME NULL,
    closed_by_user_id BIGINT UNSIGNED NULL,
    reopened_at DATETIME NULL,
    reopened_by_user_id BIGINT UNSIGNED NULL,
    reopen_reason VARCHAR(500) NULL,
    UNIQUE KEY uq_ap_tenant_entity_period (tenant_id, entity_id, period_number, start_date),
    INDEX idx_ap_entity_status (entity_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounting_accounts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(40) NOT NULL,
    name VARCHAR(255) NOT NULL,
    account_type ENUM('asset','liability','equity','revenue','expense') NOT NULL,
    normal_side ENUM('debit','credit') NOT NULL,
    parent_account_id BIGINT UNSIGNED NULL,
    is_postable TINYINT(1) NOT NULL DEFAULT 1,
    currency CHAR(3) NULL,
    description VARCHAR(500) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_aa_tenant_code (tenant_id, code),
    INDEX idx_aa_tenant_type (tenant_id, account_type, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounting_journal_entries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    period_id BIGINT UNSIGNED NOT NULL,
    je_number VARCHAR(40) NOT NULL,
    posting_date DATE NOT NULL,
    source_module ENUM('manual','ap','billing','payroll','revrec','system','reversal') NOT NULL DEFAULT 'manual',
    source_ref_type VARCHAR(60) NULL,
    source_ref_id BIGINT UNSIGNED NULL,
    idempotency_key VARCHAR(128) NULL,
    status ENUM('draft','posted','reversed','void') NOT NULL DEFAULT 'draft',
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    total_debit DECIMAL(14,2) NOT NULL DEFAULT 0,
    total_credit DECIMAL(14,2) NOT NULL DEFAULT 0,
    memo VARCHAR(500) NULL,
    reverses_je_id BIGINT UNSIGNED NULL,
    reversed_by_je_id BIGINT UNSIGNED NULL,
    posted_at DATETIME NULL,
    posted_by_user_id BIGINT UNSIGNED NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    approval_state ENUM('draft','pending_approval','approved','rejected') NOT NULL DEFAULT 'draft',
    requires_approval TINYINT(1) NOT NULL DEFAULT 0,
    workflow_instance_id BIGINT UNSIGNED NULL,
    submitted_by_user_id BIGINT UNSIGNED NULL,
    submitted_at DATETIME NULL,
    approved_by_user_id BIGINT UNSIGNED NULL,
    approved_at DATETIME NULL,
    rejected_by_user_id BIGINT UNSIGNED NULL,
    rejected_at DATETIME NULL,
    rejection_reason VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_aje_tenant_number (tenant_id, je_number),
    INDEX idx_aje_tenant_period_status (tenant_id, period_id, status),
    INDEX idx_aje_tenant_source (tenant_id, source_module, source_ref_type, source_ref_id),
    INDEX idx_aje_tenant_idempotency (tenant_id, idempotency_key),
    INDEX idx_aje_tenant_approval_state (tenant_id, approval_state),
    INDEX idx_aje_workflow (tenant_id, workflow_instance_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounting_journal_entry_lines (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    je_id BIGINT UNSIGNED NOT NULL,
    line_no INT UNSIGNED NOT NULL DEFAULT 1,
    account_id BIGINT UNSIGNED NOT NULL,
    debit  DECIMAL(14,2) NOT NULL DEFAULT 0,
    credit DECIMAL(14,2) NOT NULL DEFAULT 0,
    memo VARCHAR(500) NULL,
    counterparty_company_id BIGINT UNSIGNED NULL,
    counterparty_person_id  BIGINT UNSIGNED NULL,
    dim_json TEXT NULL,
    INDEX idx_ajel_je (je_id),
    INDEX idx_ajel_account (account_id),
    CONSTRAINT fk_ajel_je FOREIGN KEY (je_id) REFERENCES accounting_journal_entries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Subledger posting idempotency — look up prior result by idempotency_key.
CREATE TABLE IF NOT EXISTS accounting_posting_idempotency (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    idempotency_key VARCHAR(128) NOT NULL,
    je_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_api_tenant_key (tenant_id, idempotency_key),
    CONSTRAINT fk_api_je FOREIGN KEY (je_id) REFERENCES accounting_journal_entries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tenant config — JE number prefix + next sequence.
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tenants' AND COLUMN_NAME='accounting_je_prefix');
SET @sql := IF(@col=0,'ALTER TABLE tenants ADD COLUMN accounting_je_prefix VARCHAR(20) NULL DEFAULT "JE"','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tenants' AND COLUMN_NAME='accounting_next_je_seq');
SET @sql := IF(@col=0,'ALTER TABLE tenants ADD COLUMN accounting_next_je_seq INT UNSIGNED NOT NULL DEFAULT 1','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
