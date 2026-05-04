-- Treasury — 003 — liability statement lines + CoA hierarchy support
-- Idempotent. utf8mb4_unicode_ci. Cloudways MySQL 5.7+ compatible.
--
-- Adds:
--   1. treasury_liability_statement_lines — credit-card / loan transactions
--      pulled from Plaid /transactions/sync, mirrored shape of
--      accounting_bank_statement_lines but keyed on liability_account_id
--      (FK → accounting_accounts.id where account_type='liability').
--
--   2. Ensures accounting_accounts.parent_account_id index for fast
--      hierarchical queries — column already exists in 001_init but the
--      idx_aa_tenant_parent index speeds up the tree-render endpoint.

CREATE TABLE IF NOT EXISTS treasury_liability_statement_lines (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id             INT UNSIGNED NOT NULL,
    liability_account_id  BIGINT UNSIGNED NOT NULL,                 -- FK accounting_accounts.id
    posted_date           DATE NOT NULL,
    description           VARCHAR(255) NULL,
    amount                DECIMAL(18,2) NOT NULL,                   -- signed: + = payment, - = charge
    merchant_name         VARCHAR(255) NULL,
    category              VARCHAR(120) NULL,
    bank_reference        VARCHAR(120) NULL,
    fitid                 VARCHAR(120) NULL,                        -- Plaid transaction_id, for dedupe
    match_status          ENUM('unmatched','matched','ignored') NOT NULL DEFAULT 'unmatched',
    created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tlsl_fitid (tenant_id, liability_account_id, fitid),
    INDEX idx_tlsl_acct_date (tenant_id, liability_account_id, posted_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Hierarchy index. Existing column accounting_accounts.parent_account_id was
-- defined in 001_init but never indexed; tree-render queries scan otherwise.
SELECT COUNT(*) INTO @idx_exists
  FROM information_schema.statistics
 WHERE table_schema = DATABASE()
   AND table_name   = 'accounting_accounts'
   AND index_name   = 'idx_aa_tenant_parent';
SET @sql := IF(@idx_exists = 0,
  'CREATE INDEX idx_aa_tenant_parent ON accounting_accounts (tenant_id, parent_account_id)',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
