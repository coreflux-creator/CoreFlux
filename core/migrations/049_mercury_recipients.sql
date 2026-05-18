-- =======================================================================
-- Core migration 049 — Mercury Slice 2: Recipient Vault + Funding Source
-- -----------------------------------------------------------------------
-- Builds on migration 048. Models BOTH outgoing payment recipients
-- (vendors → Mercury "counterparties") AND tenant-owned external funding
-- accounts (the bank Mercury debits to credit itself before pushing out an
-- ACH to a vendor — Mercury "external accounts").
--
-- Spec'd payments workflow (full implementation in Slice 3):
--   1. AP approves payment with a vendor recipient.
--   2. Slice 3 calls Mercury to DEBIT the tenant's designated
--      default_funding_recipient (external_account kind) → CREDIT the
--      Mercury operating account.
--   3. Slice 3 polls Mercury until that specific funding transfer is
--      `settled` / cleared.
--   4. Only THEN Slice 3 originates the outbound ACH to the vendor.
--
-- This migration scaffolds the recipient persistence + the funding-source
-- designation that step 2 will consume. Soft-delete via `deleted_at`.
-- Encrypted bank methods via VARBINARY (AES-256-GCM, encryptField()).
--
-- Idempotent. Cloudways MySQL 5.7+ compatible. utf8mb4_unicode_ci.
-- =======================================================================

CREATE TABLE IF NOT EXISTS mercury_recipients (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    kind            ENUM('vendor','funding_source') NOT NULL,
    name            VARCHAR(160) NOT NULL,
    email           VARCHAR(190) NULL,
    payment_method  ENUM('ach','wire','check') NOT NULL DEFAULT 'ach',
    status          ENUM('draft','active','revoked') NOT NULL DEFAULT 'draft',
    notes           VARCHAR(500) NULL,
    created_by_user_id INT UNSIGNED NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    deleted_at      DATETIME NULL,
    KEY ix_mr_tenant_kind   (tenant_id, kind, status),
    KEY ix_mr_tenant_name   (tenant_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mercury_recipient_bank_methods (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id            INT UNSIGNED NOT NULL,
    recipient_id         INT UNSIGNED NOT NULL,
    routing_number_ct    VARBINARY(512) NOT NULL,        -- AES-256-GCM
    account_number_ct    VARBINARY(512) NOT NULL,        -- AES-256-GCM
    account_number_last4 VARCHAR(8)  NOT NULL,           -- masked display
    account_type         ENUM('checking','savings') NOT NULL DEFAULT 'checking',
    nickname             VARCHAR(120) NULL,
    is_default           TINYINT(1) NOT NULL DEFAULT 0,  -- per-recipient default method
    created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    deleted_at           DATETIME NULL,
    KEY ix_mrbm_recipient (tenant_id, recipient_id, is_default)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Maps a local recipient to Mercury's identifier. `mercury_kind`
-- discriminates because vendors are pushed as Mercury "counterparties"
-- while funding sources live as Mercury "external_accounts" — same local
-- recipient could conceivably have both mappings (rare; modeled for
-- completeness via the composite UNIQUE).
CREATE TABLE IF NOT EXISTS mercury_recipient_mappings (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id            INT UNSIGNED NOT NULL,
    recipient_id         INT UNSIGNED NOT NULL,
    mercury_id           VARCHAR(80) NOT NULL,          -- counterparty / external account id
    mercury_kind         ENUM('counterparty','external_account') NOT NULL,
    pushed_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_synced_at       DATETIME NULL,
    last_sync_error      VARCHAR(255) NULL,
    UNIQUE KEY uq_mrm_recipient_kind (tenant_id, recipient_id, mercury_kind),
    KEY        ix_mrm_lookup         (tenant_id, mercury_kind, mercury_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-tenant designation of which funding_source recipient Mercury should
-- pull from when AP needs to top up the operating account. Slice 3 consumes
-- this in the payment-approval → funding-pull step.
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mercury_connections' AND COLUMN_NAME='default_funding_recipient_id');
SET @sql := IF(@col=0,
  'ALTER TABLE mercury_connections ADD COLUMN default_funding_recipient_id INT UNSIGNED NULL AFTER workspace_name',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mercury_connections' AND COLUMN_NAME='default_mercury_account_id');
SET @sql := IF(@col=0,
  'ALTER TABLE mercury_connections ADD COLUMN default_mercury_account_id VARCHAR(80) NULL AFTER default_funding_recipient_id',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
