-- =======================================================================
-- Accounting Phase 2 — Cash Flow Statement, Recurring JEs, Bank Rec
-- -----------------------------------------------------------------------
-- Per /app/modules/accounting/SPEC.md Phase A v1.0:
--   - Cash Flow Statement (indirect method) — needs cash_flow_tag on COA
--   - Recurring journal entries (rent, depreciation, prepaid amortization)
--   - Bank reconciliation (statement import + matching engine)
--
-- Drill-through from IS/BS already works via existing source_module +
-- source_ref_id columns on accounting_journal_entries — UI just needs to
-- render the link. No schema change needed for that.
--
-- All ALTERs idempotent / nullable / back-compat. utf8mb4_unicode_ci.
-- =======================================================================

-- ---- 1. Cash Flow Statement support (cash_flow_tag on COA) ----
ALTER TABLE accounting_accounts
    ADD COLUMN cash_flow_tag VARCHAR(60) NULL AFTER normal_side,
    ADD INDEX idx_aa_cashflow (tenant_id, cash_flow_tag);

-- ---- 2. Recurring journal entries (templates + auto-post schedule) ----
CREATE TABLE IF NOT EXISTS accounting_recurring_journal_entries (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id             INT UNSIGNED NOT NULL,
    entity_id             INT UNSIGNED NULL,
    name                  VARCHAR(160) NOT NULL,
    memo                  VARCHAR(255) NULL,
    cadence               ENUM('weekly','biweekly','monthly','quarterly','yearly') NOT NULL DEFAULT 'monthly',
    next_run_date         DATE NOT NULL,
    end_date              DATE NULL,
    auto_post             TINYINT(1) NOT NULL DEFAULT 1,        -- 0 = stage as draft for review
    status                ENUM('active','paused','ended') NOT NULL DEFAULT 'active',
    last_run_at           TIMESTAMP NULL DEFAULT NULL,
    last_run_je_id        INT UNSIGNED NULL,
    created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_arj_tenant_status (tenant_id, status, next_run_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounting_recurring_je_lines (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id             INT UNSIGNED NOT NULL,
    recurring_je_id       INT UNSIGNED NOT NULL,
    line_no               INT UNSIGNED NOT NULL,
    account_code          VARCHAR(40) NOT NULL,
    debit                 DECIMAL(18,2) NOT NULL DEFAULT 0,
    credit                DECIMAL(18,2) NOT NULL DEFAULT 0,
    description           VARCHAR(255) NULL,
    created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_arjl_recurring (recurring_je_id, line_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- 3. Bank Reconciliation tables ----
CREATE TABLE IF NOT EXISTS accounting_bank_accounts (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id             INT UNSIGNED NOT NULL,
    entity_id             INT UNSIGNED NULL,
    name                  VARCHAR(120) NOT NULL,                 -- "Operating Chase ...4421"
    gl_account_code       VARCHAR(40) NOT NULL,                  -- e.g. "1010"
    bank_name             VARCHAR(120) NULL,
    routing_number        VARCHAR(9) NULL,
    last4                 VARCHAR(4) NULL,
    currency              CHAR(3) NOT NULL DEFAULT 'USD',
    feed_provider         VARCHAR(40) NULL,                      -- 'plaid_transactions' | 'manual_csv' | NULL
    plaid_access_token_ct VARBINARY(512) NULL,                   -- encrypted, Phase A.2
    plaid_account_id      VARCHAR(80) NULL,
    last_feed_synced_at   TIMESTAMP NULL,
    status                ENUM('active','closed') NOT NULL DEFAULT 'active',
    created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_aba_tenant_gl (tenant_id, gl_account_code),
    INDEX idx_aba_tenant (tenant_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounting_bank_statement_imports (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id             INT UNSIGNED NOT NULL,
    bank_account_id       INT UNSIGNED NOT NULL,
    source                ENUM('csv','ofx','qfx','plaid') NOT NULL,
    statement_from        DATE NULL,
    statement_to          DATE NULL,
    opening_balance       DECIMAL(18,2) NULL,
    closing_balance       DECIMAL(18,2) NULL,
    line_count            INT NOT NULL DEFAULT 0,
    created_by_user_id    INT UNSIGNED NULL,
    created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_absi_tenant_acct (tenant_id, bank_account_id, statement_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounting_bank_statement_lines (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id             INT UNSIGNED NOT NULL,
    bank_account_id       INT UNSIGNED NOT NULL,
    import_id             INT UNSIGNED NULL,
    posted_date           DATE NOT NULL,
    description           VARCHAR(255) NULL,
    amount                DECIMAL(18,2) NOT NULL,                -- signed: + = credit, - = debit
    bank_reference        VARCHAR(120) NULL,
    fitid                 VARCHAR(120) NULL,                     -- OFX FITID for de-dup
    match_status          ENUM('unmatched','matched','ignored') NOT NULL DEFAULT 'unmatched',
    matched_je_id         INT UNSIGNED NULL,
    matched_at            TIMESTAMP NULL,
    matched_by_user_id    INT UNSIGNED NULL,
    created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_absl_fitid (tenant_id, bank_account_id, fitid),
    INDEX idx_absl_unmatched (tenant_id, bank_account_id, match_status, posted_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounting_reconciliations (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id             INT UNSIGNED NOT NULL,
    bank_account_id       INT UNSIGNED NOT NULL,
    period_end            DATE NOT NULL,
    statement_balance     DECIMAL(18,2) NOT NULL,
    gl_balance            DECIMAL(18,2) NOT NULL,
    difference            DECIMAL(18,2) NOT NULL DEFAULT 0,
    status                ENUM('open','closed','reopened') NOT NULL DEFAULT 'open',
    closed_at             TIMESTAMP NULL,
    closed_by_user_id     INT UNSIGNED NULL,
    notes                 TEXT NULL,
    created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_arec_tenant_acct (tenant_id, bank_account_id, period_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
