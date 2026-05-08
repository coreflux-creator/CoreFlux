-- Sprint 7b — journal_templates + lines (spec §13).
-- Each template renders into a balanced JE: multiple lines, each with an
-- account selector, a debit/credit formula, an optional description
-- template, and JSON dimensions. The formula syntax is restricted (see
-- core/posting_engine/formula.php).
--
-- Idempotent.

CREATE TABLE IF NOT EXISTS accounting_journal_templates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    description VARCHAR(500) NULL,
    memo_template VARCHAR(500) NULL,        -- e.g. "Bank fee on {payload.bank_account_name}"
    currency_source ENUM('payload','entity_default') NOT NULL DEFAULT 'payload',
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ajt_tenant_name (tenant_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounting_journal_template_lines (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    journal_template_id BIGINT UNSIGNED NOT NULL,
    line_no INT NOT NULL,
    account_selector VARCHAR(255) NOT NULL,    -- 'system:Cash' | 'code:1000' | 'payload.account_code'
    debit_formula  VARCHAR(500) NULL,          -- e.g. "payload.amount" or "0"
    credit_formula VARCHAR(500) NULL,
    description_template VARCHAR(500) NULL,    -- per-line memo with {payload.x} interpolation
    dimensions_json JSON NULL,                 -- {"department": "payload.department_id"}
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ajtl_tpl_line (journal_template_id, line_no),
    INDEX idx_ajtl_tenant_tpl (tenant_id, journal_template_id),
    CONSTRAINT fk_ajtl_template
        FOREIGN KEY (journal_template_id)
        REFERENCES accounting_journal_templates(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
