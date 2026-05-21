-- =======================================================================
-- Core migration 063 — Airtable integration (Slice 1: Foundation)
-- -----------------------------------------------------------------------
-- Per-tenant Airtable connection using a Personal Access Token (PAT).
-- Tokens are AES-256-GCM encrypted at rest via encryptField().
--
-- Slice 1 scope: PAT vault + per-mapping table config + audit trail +
-- table-mapping pipeline. Pull worker reads Airtable records → upserts
-- into CoreFlux entities via external_entity_mappings. Push is parked
-- for a follow-on slice.
--
-- Idempotent. Cloudways MySQL 5.7+ compatible. utf8mb4_unicode_ci.
-- =======================================================================

CREATE TABLE IF NOT EXISTS airtable_connections (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id            INT UNSIGNED NOT NULL,
    pat_ct               VARBINARY(2048) NOT NULL,      -- AES-256-GCM Personal Access Token
    pat_last4            VARCHAR(8) NULL,               -- display hint, never the full token
    workspace_label      VARCHAR(255) NULL,             -- user-supplied label ("Ops Sidecar")
    scopes               VARCHAR(255) NULL,             -- comma list captured at connect via /meta/whoami
    status               ENUM('active','revoked','error') NOT NULL DEFAULT 'active',
    last_probe_at        DATETIME NULL,
    last_probe_error     VARCHAR(500) NULL,
    created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    connected_by_user_id INT UNSIGNED NULL,
    updated_at           TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_airtable_tenant (tenant_id)            -- one connection per tenant in MVP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-mapping configuration — every (base, table) pair that the tenant
-- wants pulled into a specific CoreFlux entity. field_map is a JSON
-- object: { "<airtable_field_name>": "<coreflux_field_or_dot_path>" }.
-- The internal_entity matches an existing entity type known to
-- external_entity_mappings (e.g. 'company', 'vendor', 'placement').
CREATE TABLE IF NOT EXISTS airtable_table_mappings (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    base_id         VARCHAR(40) NOT NULL,               -- e.g. 'appXXXXXXXXXXXXXX'
    base_name       VARCHAR(255) NULL,
    table_id        VARCHAR(40) NOT NULL,               -- e.g. 'tblXXXXXXXXXXXXXX'
    table_name      VARCHAR(255) NULL,
    internal_entity VARCHAR(60)  NOT NULL,              -- coreflux entity_type key
    direction       ENUM('pull','off') NOT NULL DEFAULT 'pull',
    field_map       JSON NULL,                          -- { airtable_field => coreflux_field }
    primary_field   VARCHAR(120) NULL,                  -- airtable field used for upsert match
    last_sync_at    DATETIME NULL,
    last_sync_error VARCHAR(500) NULL,
    last_records    INT NOT NULL DEFAULT 0,             -- record count from last sync
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by_user_id INT UNSIGNED NULL,
    updated_at      TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_airtable_map (tenant_id, base_id, table_id),
    KEY ix_airtable_map_tenant (tenant_id, direction)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Audit log — every connect/disconnect/sync/probe lands a row.
-- Mirrors qbo_sync_audit shape so the UI activity component is reusable.
CREATE TABLE IF NOT EXISTS airtable_sync_audit (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    action          VARCHAR(60) NOT NULL,               -- connect/disconnect/ping/sync_table/list_bases/...
    base_id         VARCHAR(40) NULL,
    table_id        VARCHAR(40) NULL,
    direction       ENUM('push','pull','two_way','none') NOT NULL DEFAULT 'none',
    ok              TINYINT(1) NOT NULL DEFAULT 1,
    items_processed INT NOT NULL DEFAULT 0,
    items_skipped   INT NOT NULL DEFAULT 0,
    items_failed    INT NOT NULL DEFAULT 0,
    detail          JSON NULL,
    actor_user_id   INT UNSIGNED NULL,
    occurred_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_airtable_audit_tenant_time (tenant_id, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
