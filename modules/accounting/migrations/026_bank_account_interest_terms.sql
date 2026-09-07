-- Account-level statement and interest terms, plus an audit snapshot for
-- each statement period. Interest runs are unique per account and period so
-- retrying reconciliation close cannot create a duplicate journal entry.

CREATE TABLE IF NOT EXISTS accounting_bank_account_terms (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id               INT UNSIGNED NOT NULL,
    bank_account_id         INT UNSIGNED NOT NULL,
    statement_cadence       ENUM('monthly','quarterly','annual','custom') NOT NULL DEFAULT 'monthly',
    interest_enabled        TINYINT(1) NOT NULL DEFAULT 0,
    interest_direction      ENUM('earned','charged') NOT NULL DEFAULT 'earned',
    annual_rate_percent     DECIMAL(9,6) NOT NULL DEFAULT 0,
    balance_method          ENUM('average_daily_balance','closing_balance') NOT NULL DEFAULT 'average_daily_balance',
    day_count_basis         ENUM('actual_365','actual_360') NOT NULL DEFAULT 'actual_365',
    offset_account_id       INT UNSIGNED NULL,
    effective_from          DATE NULL,
    maturity_date           DATE NULL,
    terms_note              TEXT NULL,
    created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_abait_tenant_account (tenant_id, bank_account_id),
    INDEX idx_abait_offset_account (tenant_id, offset_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounting_bank_interest_runs (
    id                        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id                 INT UNSIGNED NOT NULL,
    bank_account_id           INT UNSIGNED NOT NULL,
    reconciliation_id         INT UNSIGNED NOT NULL,
    period_start              DATE NOT NULL,
    period_end                DATE NOT NULL,
    statement_balance         DECIMAL(18,2) NOT NULL,
    balance_basis_amount      DECIMAL(18,6) NOT NULL,
    annual_rate_percent       DECIMAL(9,6) NOT NULL,
    day_count                 INT UNSIGNED NOT NULL,
    day_count_basis           ENUM('actual_365','actual_360') NOT NULL,
    balance_method            ENUM('average_daily_balance','closing_balance') NOT NULL,
    interest_direction        ENUM('earned','charged') NOT NULL,
    interest_amount           DECIMAL(18,2) NOT NULL,
    offset_account_id         INT UNSIGNED NULL,
    journal_entry_id          INT UNSIGNED NULL,
    matched_statement_line_id INT UNSIGNED NULL,
    status                    ENUM('posted','skipped') NOT NULL,
    status_reason             VARCHAR(255) NULL,
    created_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_abir_reconciliation (tenant_id, reconciliation_id),
    UNIQUE KEY uq_abir_account_period (tenant_id, bank_account_id, period_end),
    INDEX idx_abir_journal_entry (tenant_id, journal_entry_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
