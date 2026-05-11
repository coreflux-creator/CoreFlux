-- Billing — Recurring invoice contracts (flat-fee MRR).
-- Idempotent. utf8mb4_unicode_ci. MySQL 5.7+ compatible.
--
-- A "contract" is a recipe for an AR invoice that auto-generates on a
-- schedule. Today: flat-fee only. Hours-overage / variable amounts are
-- explicitly deferred (see /app/memory/PRD.md §Recurring Contracts).

CREATE TABLE IF NOT EXISTS billing_invoice_contracts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id   BIGINT UNSIGNED NOT NULL,

    client_name   VARCHAR(255) NOT NULL,
    contract_name VARCHAR(255) NOT NULL,
    description   TEXT NULL,

    frequency     ENUM('monthly','quarterly','annual') NOT NULL DEFAULT 'monthly',
    day_of_period INT UNSIGNED NOT NULL DEFAULT 1,    -- 1..31; clamped to month-end when needed

    amount        DECIMAL(12,2) NOT NULL,
    currency      CHAR(3) NOT NULL DEFAULT 'USD',
    gl_account_id BIGINT UNSIGNED NULL,

    start_date    DATE NOT NULL,
    end_date      DATE NULL,                          -- NULL = open-ended

    status            ENUM('active','paused','ended') NOT NULL DEFAULT 'active',
    proration_policy  ENUM('full','prorate','skip_first') NOT NULL DEFAULT 'full',

    bill_to_email VARCHAR(255) NULL,                  -- denormalized: AR primary contact for this contract
    bill_to_json  TEXT NULL,                          -- bill-to address copied onto each generated invoice
    po_number     VARCHAR(80) NULL,
    notes_internal TEXT NULL,

    last_generated_at         DATETIME NULL,
    last_generated_invoice_id BIGINT UNSIGNED NULL,
    next_due_at               DATE NULL,              -- materialised by the generator; first run computes from start_date

    created_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_bic_tenant_status_next (tenant_id, status, next_due_at),
    INDEX idx_bic_tenant_client      (tenant_id, client_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Each generated invoice gets a back-pointer so we can:
--  (a) prevent double-generation for the same period (idempotency guard)
--  (b) audit which invoice came from which contract on the inspect page
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='billing_invoices' AND COLUMN_NAME='source_contract_id');
SET @sql := IF(@col=0,
  'ALTER TABLE billing_invoices ADD COLUMN source_contract_id BIGINT UNSIGNED NULL AFTER aggregation',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='billing_invoices' AND INDEX_NAME='idx_bi_source_contract');
SET @sql := IF(@idx=0,
  'ALTER TABLE billing_invoices ADD INDEX idx_bi_source_contract (source_contract_id, period_start)',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
