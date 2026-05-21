-- =======================================================================
-- Core migration 064 — Zoho Books integration (Slice 1: Foundation)
-- -----------------------------------------------------------------------
-- Per-tenant OAuth 2.0 connection to Zoho Books. Tenant admin clicks
-- "Connect" → CoreFlux redirects to Zoho Accounts → user authorises →
-- callback exchanges the authorization code for an access_token (1h)
-- + refresh_token (long-lived) and persists them AES-256-GCM encrypted
-- alongside the Zoho organization_id and the data-centre (DC).
--
-- DC AUTO-DETECT: Zoho's OAuth callback returns an `accounts-server`
-- parameter pointing at the user's regional accounts host (e.g.
-- `https://accounts.zoho.eu`). We parse that to derive the DC, then
-- pin every subsequent API call to the matching `www.zohoapis.{DC}`
-- host. Supported DCs: com, eu, in, com.au, jp, com.cn, sa.
--
-- Slice 1 scope: connection vault + OAuth state CSRF defence + per-
-- entity sync_config (direction picker stored on connection row) +
-- audit trail. Actual push/pull drivers ship in subsequent slices.
--
-- Idempotent. Cloudways MySQL 5.7+ compatible. utf8mb4_unicode_ci.
-- =======================================================================

CREATE TABLE IF NOT EXISTS zoho_books_connections (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           INT UNSIGNED NOT NULL,
    organization_id     VARCHAR(40)  NOT NULL,          -- Zoho "organization_id" (X-Header on every API call)
    organization_name   VARCHAR(255) NULL,
    dc                  VARCHAR(16)  NOT NULL DEFAULT 'com',  -- com|eu|in|com.au|jp|com.cn|sa
    access_token_ct     VARBINARY(2048) NOT NULL,       -- AES-256-GCM via encryptField()
    refresh_token_ct    VARBINARY(2048) NOT NULL,       -- ditto (long-lived, doesn't expire)
    access_token_exp    DATETIME NULL,
    scope               VARCHAR(255) NULL,              -- granted OAuth scopes
    status              ENUM('active','revoked','error') NOT NULL DEFAULT 'active',
    sync_config         JSON NULL,                      -- per-entity direction map
    last_probe_at       DATETIME NULL,
    last_probe_error    VARCHAR(500) NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    connected_by_user_id INT UNSIGNED NULL,
    updated_at          TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_zoho_tenant (tenant_id)                -- one connection per tenant in MVP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- OAuth state nonces — CSRF defence for the authorize → callback round-trip.
-- One row per "Connect" click, consumed at callback time. Rows older than
-- 30 minutes are stale and rejected.
CREATE TABLE IF NOT EXISTS zoho_books_oauth_state (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id    INT UNSIGNED NOT NULL,
    state_token  VARCHAR(64) NOT NULL,
    initiator_user_id INT UNSIGNED NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    consumed_at  DATETIME NULL,
    UNIQUE KEY uq_zoho_state (state_token),
    KEY        ix_zoho_state_tenant (tenant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Audit log — every connect / disconnect / refresh / sync / push / pull
-- lands a row. Mirrors qbo_sync_audit shape so UI rendering can reuse
-- the same component.
CREATE TABLE IF NOT EXISTS zoho_books_sync_audit (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    action          VARCHAR(60) NOT NULL,               -- connect/disconnect/refresh_token/ping/sync_je/...
    entity_type     VARCHAR(40) NULL,                   -- journal_entry / contact / invoice / bill / payment / coa
    direction       ENUM('push','pull','two_way','none') NOT NULL DEFAULT 'none',
    ok              TINYINT(1) NOT NULL DEFAULT 1,
    items_processed INT NOT NULL DEFAULT 0,
    items_skipped   INT NOT NULL DEFAULT 0,
    items_failed    INT NOT NULL DEFAULT 0,
    detail          JSON NULL,
    actor_user_id   INT UNSIGNED NULL,
    occurred_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_zoho_audit_tenant_time (tenant_id, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
