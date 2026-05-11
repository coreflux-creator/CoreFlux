-- Billing — Dunning (overdue invoice escalation).
-- Idempotent. utf8mb4_unicode_ci. MySQL 5.7+.
--
-- Two new tables + 4 columns on billing_invoices + a client-level contact
-- table that's reused for escalation lookups.

-- ── 1. Tenant-level dunning policy ──────────────────────────────────
CREATE TABLE IF NOT EXISTS tenant_dunning_policy (
    tenant_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    -- JSON: [{"days_overdue": 3, "template_key": "soft", "cc_client_contact": false},
    --       {"days_overdue": 14, "template_key": "firm", "cc_client_contact": true},
    --       {"days_overdue": 30, "template_key": "final", "cc_client_contact": true, "cc_owner": true}]
    schedule_json TEXT NOT NULL,
    max_attempts INT UNSIGNED NOT NULL DEFAULT 3,
    cadence_days INT UNSIGNED NOT NULL DEFAULT 7,        -- min gap between subsequent sends
    skip_weekends TINYINT(1) NOT NULL DEFAULT 1,
    -- After N successful sends with no payment, also CC the client-level
    -- contact (looked up in billing_client_contacts). 0 = never escalate
    -- to client-level contact automatically.
    escalate_to_client_contact_after_attempts INT UNSIGNED NOT NULL DEFAULT 2,
    paused_until DATE NULL,
    -- JSON array of client_name strings that must never receive dunning.
    do_not_contact_json TEXT NULL,
    updated_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. Per-client AR contact roster ─────────────────────────────────
-- Used both by dunning (escalation contact) and any future "send AR
-- statement to client X" surface.
CREATE TABLE IF NOT EXISTS billing_client_contacts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id   BIGINT UNSIGNED NOT NULL,
    client_name VARCHAR(255) NOT NULL,
    -- Primary "always notify" AR contact for this client (used as fallback
    -- when an invoice has no `bill_to_json.email`).
    ar_primary_email     VARCHAR(255) NULL,
    -- Escalation contact (CFO / controller / legal) — only emailed once
    -- the dunning engine crosses the escalation threshold.
    ar_escalation_email  VARCHAR(255) NULL,
    notes VARCHAR(500) NULL,
    updated_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_bcc_tenant_client (tenant_id, client_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. Per-invoice dunning state ────────────────────────────────────
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='billing_invoices' AND COLUMN_NAME='dunning_stage');
SET @sql := IF(@col=0, 'ALTER TABLE billing_invoices ADD COLUMN dunning_stage INT UNSIGNED NULL DEFAULT NULL', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='billing_invoices' AND COLUMN_NAME='dunning_attempts');
SET @sql := IF(@col=0, 'ALTER TABLE billing_invoices ADD COLUMN dunning_attempts INT UNSIGNED NOT NULL DEFAULT 0', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='billing_invoices' AND COLUMN_NAME='dunning_last_sent_at');
SET @sql := IF(@col=0, 'ALTER TABLE billing_invoices ADD COLUMN dunning_last_sent_at DATETIME NULL', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='billing_invoices' AND COLUMN_NAME='dunning_paused_until');
SET @sql := IF(@col=0, 'ALTER TABLE billing_invoices ADD COLUMN dunning_paused_until DATE NULL', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='billing_invoices' AND INDEX_NAME='idx_bi_dunning');
SET @sql := IF(@idx=0,
  'ALTER TABLE billing_invoices ADD INDEX idx_bi_dunning (tenant_id, status, dunning_paused_until, dunning_last_sent_at)',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── 4. Per-send log (one row per dunning email dispatched) ──────────
CREATE TABLE IF NOT EXISTS billing_dunning_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id  BIGINT UNSIGNED NOT NULL,
    invoice_id BIGINT UNSIGNED NOT NULL,
    stage      INT UNSIGNED NOT NULL,
    template_key VARCHAR(40) NOT NULL,
    sent_to_email VARCHAR(255) NOT NULL,
    cc_emails_json TEXT NULL,                    -- escalation CC list
    status   ENUM('sent','failed','suppressed') NOT NULL DEFAULT 'sent',
    error_text VARCHAR(500) NULL,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_bdl_tenant_invoice (tenant_id, invoice_id),
    INDEX idx_bdl_sent_at (tenant_id, sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
