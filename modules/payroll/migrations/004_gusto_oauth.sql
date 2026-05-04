-- =======================================================================
-- Payroll migration 004 — Gusto OAuth integration
-- -----------------------------------------------------------------------
-- One row per tenant ↔ Gusto company link. A tenant can hold multiple
-- companies in Gusto; each company is its own row. Tokens are encrypted
-- at rest using core/encryption.php (AES-256-GCM, COREFLUX_DATA_KEY).
--
-- Webhook subscriptions belong to the application (not per-tenant), but
-- we store the resolved subscription_uuid + verification status for ops
-- visibility.
--
-- All migrations are additive + idempotent.
-- =======================================================================

CREATE TABLE IF NOT EXISTS tenant_gusto_connections (
    id                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id                   INT UNSIGNED NOT NULL,
    company_uuid                VARCHAR(64) NOT NULL,
    company_name                VARCHAR(200) NULL,
    -- Encrypted bearer + refresh tokens (AES-256-GCM ciphertext).
    access_token_ct             TEXT NOT NULL,
    refresh_token_ct            TEXT NOT NULL,
    token_type                  VARCHAR(20) NOT NULL DEFAULT 'bearer',
    -- Token expiration (access_token TTL is 7200s on issue).
    access_token_expires_at     DATETIME NOT NULL,
    -- Scopes granted at authorization time (space-separated, as Gusto returns).
    scopes                      VARCHAR(500) NULL,
    -- Environment this connection lives in: 'sandbox' or 'production'.
    env                         VARCHAR(20) NOT NULL DEFAULT 'sandbox',
    status                      ENUM('active','revoked','error') NOT NULL DEFAULT 'active',
    -- Tracking metadata.
    connected_by_user_id        INT UNSIGNED NULL,
    connected_at                DATETIME NOT NULL,
    last_refreshed_at           DATETIME NULL,
    last_used_at                DATETIME NULL,
    last_error                  VARCHAR(500) NULL,
    created_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_gconn_tenant_company (tenant_id, company_uuid),
    INDEX idx_gconn_tenant_status (tenant_id, status),
    INDEX idx_gconn_expires (access_token_expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add Gusto-API submission tracking columns to payroll_runs (idempotent).
-- These coexist with the existing CSV-paste columns (gusto_run_id, etc.) —
-- the OAuth-API flow stamps gusto_payroll_uuid + gusto_submission_status,
-- and re-uses gusto_run_id for the human-friendly identifier.
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'payroll_runs'
               AND COLUMN_NAME = 'gusto_payroll_uuid');
SET @sql := IF(@col = 0,
    'ALTER TABLE payroll_runs
        ADD COLUMN gusto_payroll_uuid       VARCHAR(64) NULL AFTER gusto_run_id,
        ADD COLUMN gusto_submission_status  VARCHAR(40) NULL AFTER gusto_payroll_uuid,
        ADD COLUMN gusto_submitted_at       DATETIME    NULL AFTER gusto_submission_status,
        ADD COLUMN gusto_submitted_by_user_id INT UNSIGNED NULL AFTER gusto_submitted_at,
        ADD COLUMN gusto_submission_error   VARCHAR(500) NULL AFTER gusto_submitted_by_user_id,
        ADD INDEX idx_run_gusto_pp_uuid (tenant_id, gusto_payroll_uuid)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
