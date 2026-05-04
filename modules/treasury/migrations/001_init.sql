-- =======================================================================
-- Treasury — 001_init
-- -----------------------------------------------------------------------
-- Treasury is a thin layer on top of accounting: deposit accounts re-use
-- the existing `accounting_bank_accounts` table, and liability accounts
-- hang off `accounting_accounts` via this companion table.
--
-- The companion table holds treasury-specific metadata (card last4, APR,
-- credit limit, statement day, autopay routing) that doesn't belong in
-- the core COA model.
-- =======================================================================

CREATE TABLE IF NOT EXISTS treasury_liability_accounts (
    id                              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id                       INT UNSIGNED NOT NULL,
    account_id                      INT UNSIGNED NOT NULL,            -- FK → accounting_accounts.id
    subtype                         ENUM('credit_card','loan','line_of_credit','other_liability')
                                     NOT NULL DEFAULT 'other_liability',
    institution_name                VARCHAR(160) NULL,
    last4                           VARCHAR(4) NULL,
    credit_limit_cents              BIGINT NULL,                       -- revolving limit; NULL for term loans
    apr_bps                         INT NULL,                          -- basis points (1% = 100)
    statement_day                   TINYINT UNSIGNED NULL,             -- day-of-month statement closes (1..31)
    autopay_from_bank_account_id    INT UNSIGNED NULL,                 -- FK → accounting_bank_accounts.id
    notes                           VARCHAR(500) NULL,
    created_at                      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                      TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tla_tenant_account (tenant_id, account_id),
    INDEX idx_tla_tenant_subtype (tenant_id, subtype)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
