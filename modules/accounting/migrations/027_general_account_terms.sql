-- Ledger-account terms and scheduled interest accruals. Terms belong to a
-- CoreFlux account and legal entity, never to a bank feed or reconciliation.

CREATE TABLE IF NOT EXISTS accounting_account_terms (
    id                          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id                   BIGINT UNSIGNED NOT NULL,
    account_id                  BIGINT UNSIGNED NOT NULL,
    entity_id                   BIGINT UNSIGNED NOT NULL,
    interest_enabled            TINYINT(1) NOT NULL DEFAULT 0,
    interest_direction          ENUM('earned','charged') NOT NULL DEFAULT 'earned',
    annual_rate_percent         DECIMAL(9,6) NOT NULL DEFAULT 0,
    balance_method              ENUM('average_daily_balance','closing_balance') NOT NULL DEFAULT 'average_daily_balance',
    day_count_basis             ENUM('actual_365','actual_360') NOT NULL DEFAULT 'actual_365',
    posting_cadence             ENUM('weekly','monthly','quarterly','annual') NOT NULL DEFAULT 'monthly',
    next_post_date              DATE NULL,
    auto_post                   TINYINT(1) NOT NULL DEFAULT 1,
    posting_account_id          BIGINT UNSIGNED NULL,
    offset_account_id           BIGINT UNSIGNED NULL,
    effective_from              DATE NULL,
    maturity_date               DATE NULL,
    counterparty_name           VARCHAR(180) NULL,
    agreement_reference         VARCHAR(120) NULL,
    terms_note                  TEXT NULL,
    last_run_at                 DATETIME NULL,
    last_run_je_id              BIGINT UNSIGNED NULL,
    created_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_aat_tenant_account_entity (tenant_id, account_id, entity_id),
    INDEX idx_aat_due (interest_enabled, next_post_date, tenant_id),
    INDEX idx_aat_offset (tenant_id, offset_account_id),
    INDEX idx_aat_posting (tenant_id, posting_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounting_account_interest_runs (
    id                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id                BIGINT UNSIGNED NOT NULL,
    account_terms_id         BIGINT UNSIGNED NOT NULL,
    account_id               BIGINT UNSIGNED NOT NULL,
    entity_id                BIGINT UNSIGNED NOT NULL,
    period_start             DATE NOT NULL,
    period_end               DATE NOT NULL,
    opening_balance          DECIMAL(18,6) NOT NULL,
    closing_balance          DECIMAL(18,6) NOT NULL,
    balance_basis_amount     DECIMAL(18,6) NOT NULL,
    annual_rate_percent      DECIMAL(9,6) NOT NULL,
    day_count                INT UNSIGNED NOT NULL,
    day_count_basis          ENUM('actual_365','actual_360') NOT NULL,
    balance_method           ENUM('average_daily_balance','closing_balance') NOT NULL,
    interest_direction       ENUM('earned','charged') NOT NULL,
    interest_amount          DECIMAL(18,2) NOT NULL,
    posting_account_id       BIGINT UNSIGNED NOT NULL,
    offset_account_id        BIGINT UNSIGNED NOT NULL,
    journal_entry_id         BIGINT UNSIGNED NULL,
    status                   ENUM('posted','draft','skipped') NOT NULL,
    status_reason            VARCHAR(255) NULL,
    created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_aair_account_period (tenant_id, account_id, entity_id, period_end),
    INDEX idx_aair_terms (tenant_id, account_terms_id, period_end),
    INDEX idx_aair_je (tenant_id, journal_entry_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Preserve previously entered bank terms. A next posting date is deliberately
-- not invented during migration, so no unsolicited JE can be generated.
INSERT IGNORE INTO accounting_account_terms
    (tenant_id, account_id, entity_id, interest_enabled, interest_direction,
     annual_rate_percent, balance_method, day_count_basis, posting_cadence,
     next_post_date, auto_post, posting_account_id, offset_account_id,
     effective_from, maturity_date, terms_note, created_at, updated_at)
SELECT bt.tenant_id, aa.id, COALESCE(ba.entity_id, entity_default.entity_id),
       bt.interest_enabled, bt.interest_direction, bt.annual_rate_percent,
       bt.balance_method, bt.day_count_basis,
       CASE bt.statement_cadence WHEN 'quarterly' THEN 'quarterly' WHEN 'annual' THEN 'annual' ELSE 'monthly' END,
       NULL, 1, aa.id, bt.offset_account_id, bt.effective_from,
       bt.maturity_date, bt.terms_note, bt.created_at, COALESCE(bt.updated_at, bt.created_at)
  FROM accounting_bank_account_terms bt
  JOIN accounting_bank_accounts ba
    ON ba.tenant_id = bt.tenant_id AND ba.id = bt.bank_account_id
  JOIN accounting_accounts aa
    ON aa.tenant_id = ba.tenant_id AND aa.code = ba.gl_account_code
  LEFT JOIN (
      SELECT tenant_id, MIN(id) AS entity_id
        FROM accounting_entities WHERE active = 1 GROUP BY tenant_id
  ) entity_default ON entity_default.tenant_id = bt.tenant_id
 WHERE COALESCE(ba.entity_id, entity_default.entity_id) IS NOT NULL;

-- Preserve the immutable calculation history already created for bank terms.
INSERT IGNORE INTO accounting_account_interest_runs
    (tenant_id, account_terms_id, account_id, entity_id, period_start, period_end,
     opening_balance, closing_balance, balance_basis_amount, annual_rate_percent,
     day_count, day_count_basis, balance_method, interest_direction,
     interest_amount, posting_account_id, offset_account_id, journal_entry_id,
     status, status_reason, created_at, updated_at)
SELECT br.tenant_id, at.id, at.account_id, at.entity_id, br.period_start,
       br.period_end, br.statement_balance, br.statement_balance,
       br.balance_basis_amount, br.annual_rate_percent, br.day_count,
       br.day_count_basis, br.balance_method, br.interest_direction,
       br.interest_amount, COALESCE(at.posting_account_id, at.account_id),
       br.offset_account_id, br.journal_entry_id, br.status,
       br.status_reason, br.created_at, COALESCE(br.updated_at, br.created_at)
  FROM accounting_bank_interest_runs br
  JOIN accounting_bank_accounts ba
    ON ba.tenant_id = br.tenant_id AND ba.id = br.bank_account_id
  JOIN accounting_accounts aa
    ON aa.tenant_id = ba.tenant_id AND aa.code = ba.gl_account_code
  LEFT JOIN (
      SELECT tenant_id, MIN(id) AS entity_id
        FROM accounting_entities WHERE active = 1 GROUP BY tenant_id
  ) entity_default ON entity_default.tenant_id = br.tenant_id
  JOIN accounting_account_terms at
    ON at.tenant_id = aa.tenant_id AND at.account_id = aa.id
   AND at.entity_id = COALESCE(ba.entity_id, entity_default.entity_id)
 WHERE br.offset_account_id IS NOT NULL;
