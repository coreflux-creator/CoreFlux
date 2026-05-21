-- =======================================================================
-- Core migration 052 — QuickBooks Online integration (Slice 1: Foundation)
-- -----------------------------------------------------------------------
-- Per-tenant OAuth 2.0 connection to Intuit QuickBooks Online. Tenant
-- admin clicks "Connect" → CoreFlux redirects to Intuit AppCenter → user
-- authorises → callback exchanges the authorization code for an
-- access_token (1h) + refresh_token (100d rolling) and persists them
-- AES-256-GCM encrypted alongside the QBO realmId.
--
-- Slice 1 scope: connection vault + OAuth state CSRF defence + per-entity
-- sync_config (direction picker stored on connection row) + audit trail.
-- Actual JE push / customer pull drivers live in subsequent slices.
--
-- Idempotent. Cloudways MySQL 5.7+ compatible. utf8mb4_unicode_ci.
-- =======================================================================

CREATE TABLE IF NOT EXISTS qbo_connections (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           INT UNSIGNED NOT NULL,
    realm_id            VARCHAR(40)  NOT NULL,          -- Intuit "Company ID"
    company_name        VARCHAR(255) NULL,
    environment         ENUM('sandbox','production') NOT NULL DEFAULT 'sandbox',
    access_token_ct     VARBINARY(4096) NOT NULL,       -- AES-256-GCM via encryptField() — widened by mig 067 for Intuit OAuth tokens up to ~2KB
    refresh_token_ct    VARBINARY(2048) NOT NULL,       -- ditto
    access_token_exp    DATETIME NULL,
    refresh_token_exp   DATETIME NULL,                  -- rolls every refresh
    scope               VARCHAR(255) NULL,              -- granted OAuth scopes
    status              ENUM('active','revoked','error') NOT NULL DEFAULT 'active',
    sync_config         JSON NULL,                      -- per-entity direction map
    last_probe_at       DATETIME NULL,
    last_probe_error    VARCHAR(500) NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    connected_by_user_id INT UNSIGNED NULL,
    updated_at          TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_qbo_tenant (tenant_id)                -- one connection per tenant in MVP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- OAuth state nonces — CSRF defence for the authorize → callback round-trip.
-- One row per "Connect" click, consumed at callback time. Rows older than
-- 30 minutes are stale and rejected. Purge job optional (small table).
CREATE TABLE IF NOT EXISTS qbo_oauth_state (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id    INT UNSIGNED NOT NULL,
    state_token  VARCHAR(64) NOT NULL,
    initiator_user_id INT UNSIGNED NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    consumed_at  DATETIME NULL,
    UNIQUE KEY uq_qbo_state (state_token),
    KEY        ix_qbo_state_tenant (tenant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Audit log — every connect / disconnect / refresh / sync / push / pull
-- lands a row. Mirrors the jobdiva_sync_audit shape so UI rendering can
-- reuse the same component.
CREATE TABLE IF NOT EXISTS qbo_sync_audit (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    action          VARCHAR(60) NOT NULL,               -- connect/disconnect/refresh_token/ping/sync_je/...
    entity_type     VARCHAR(40) NULL,                   -- journal_entry / customer / vendor / ...
    direction       ENUM('push','pull','two_way','none') NOT NULL DEFAULT 'none',
    ok              TINYINT(1) NOT NULL DEFAULT 1,
    items_processed INT NOT NULL DEFAULT 0,
    items_skipped   INT NOT NULL DEFAULT 0,
    items_failed    INT NOT NULL DEFAULT 0,
    detail          JSON NULL,
    actor_user_id   INT UNSIGNED NULL,
    occurred_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_qbo_audit_tenant_time (tenant_id, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
