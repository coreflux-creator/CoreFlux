-- =======================================================================
-- Core migration 051 — Mercury Slice 4: Reconciliation
-- -----------------------------------------------------------------------
-- Matches payment_instructions (Slice 3) ↔ mercury_transactions (Slice 1)
-- via payout_mercury_txn_id, then advances Settled → Reconciled.
--
-- reconciliation_matches captures EVERY attempt — successful matches,
-- amount/currency discrepancies (flagged for manual review), and
-- still-pending lookups where the payment is Settled but the sync cron
-- hasn't pulled the matching mercury_transactions row yet.
--
-- Idempotent. Cloudways MySQL 5.7+ compatible. utf8mb4_unicode_ci.
-- =======================================================================

CREATE TABLE IF NOT EXISTS reconciliation_matches (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id         INT UNSIGNED NOT NULL,
    instruction_id    INT UNSIGNED NOT NULL,
    mercury_txn_pk    BIGINT UNSIGNED NULL,                  -- → mercury_transactions.id (null when not yet found)
    mercury_txn_id    VARCHAR(80) NULL,                       -- Mercury's transaction id (denormalized for fast lookups)
    leg               ENUM('funding','payout') NOT NULL DEFAULT 'payout',
    outcome           ENUM('matched','discrepancy','missing_mercury_txn') NOT NULL,
    expected_amount_cents  BIGINT NULL,
    observed_amount_cents  BIGINT NULL,
    discrepancy_reason     VARCHAR(255) NULL,
    matched_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rm_instruction_leg (tenant_id, instruction_id, leg, outcome, mercury_txn_pk),
    KEY        ix_rm_outcome         (tenant_id, outcome, matched_at),
    KEY        ix_rm_txn             (tenant_id, mercury_txn_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional: denormalized funding-leg ledger view, used by the analytics
-- panel (Slice 4 ships an empty table; future Slice 4.5 can backfill
-- from payment_instructions). Tracks each funding pull as a distinct row
-- for treasury-ops reporting (rather than buried in payment_instructions).
CREATE TABLE IF NOT EXISTS funding_transfers (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           INT UNSIGNED NOT NULL,
    instruction_id      INT UNSIGNED NOT NULL,
    funding_recipient_id INT UNSIGNED NULL,
    mercury_account_id  VARCHAR(80) NULL,
    mercury_txn_id      VARCHAR(80) NULL,
    amount_cents        BIGINT NOT NULL,
    status              VARCHAR(40) NULL,                     -- mirrors funding_mercury_status
    initiated_at        DATETIME NULL,
    settled_at          DATETIME NULL,
    reconciled_at       DATETIME NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ft_instruction (tenant_id, instruction_id),
    KEY        ix_ft_status       (tenant_id, status, initiated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Track when a payment_instruction reached the Reconciled state so the
-- reconciliation dashboard can show throughput / lag without scanning
-- the full audit table.
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_instructions' AND COLUMN_NAME='reconciled_at');
SET @sql := IF(@col=0,
  'ALTER TABLE payment_instructions ADD COLUMN reconciled_at DATETIME NULL AFTER payout_settled_at',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
