-- Sprint 8a / Slice A2 — Integration-agnostic external entity mappings.
--
-- Universal mapping pipeline for ANY 3rd-party integration (JobDiva today,
-- Bullhorn / Greenhouse / etc. tomorrow). Lets us tie an external system's
-- record id to the internal CoreFlux record (companies, contacts,
-- placements, …) WITHOUT polluting core tables with integration-specific
-- columns (no `jobdiva_company_id` on `companies`, etc.).
--
-- Design rules:
--   - Source-system agnostic: `source_system` is a free-form short slug
--     (e.g. 'jobdiva', 'bullhorn'). No enum so adding a new integration
--     does NOT require a DDL change.
--   - Bidirectional unique keys:
--       (tenant, source_system, entity_type, external_id)  → resolve external→internal
--       (tenant, source_system, entity_type, internal_id)  → resolve internal→external
--     Both must be unique; one external maps to exactly one internal per
--     entity_type, and vice versa.
--   - `payload_snapshot` JSON keeps the last raw payload seen from the
--     source for debug/replay/dirty-check audits (per user A2 design).
--   - `content_hash` is sha256 hex of canonicalised payload — cheap
--     dirty-check so syncs can short-circuit unchanged rows.
--   - Direction snapshot lets the row remember the sync mode at the time
--     of the last sync (pull / push / two_way / off) for forensics; it is
--     NOT the live policy (that lives in `jobdiva_connections.sync_config`).
--
-- Idempotent. Cloudways MySQL 5.7 + 8 compatible.

CREATE TABLE IF NOT EXISTS external_entity_mappings (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id            BIGINT UNSIGNED NOT NULL,
    -- Source slug, e.g. 'jobdiva'. Free-form — adding 'bullhorn' later
    -- does NOT require an enum migration.
    source_system        VARCHAR(64)  NOT NULL,
    -- Internal entity type slug, e.g. 'company', 'contact', 'placement'.
    -- Names match the canonical CoreFlux module/table noun.
    internal_entity_type VARCHAR(64)  NOT NULL,
    -- ID inside the source system. Stored as VARCHAR so we can carry
    -- both numeric and GUID-style identifiers without coercion.
    external_id          VARCHAR(128) NOT NULL,
    internal_entity_id   BIGINT UNSIGNED NOT NULL,
    -- Last raw payload seen from the source, for debug + replay.
    payload_snapshot     JSON         DEFAULT NULL,
    -- sha256 hex of canonicalised payload (sorted JSON). 64 chars.
    content_hash         CHAR(64)     DEFAULT NULL,
    -- Direction at the time of the LAST sync — informational only.
    direction            ENUM('pull','push','two_way','off') NOT NULL DEFAULT 'pull',
    sync_status          ENUM('ok','stale','error','deleted_in_source') NOT NULL DEFAULT 'ok',
    last_error           VARCHAR(500) DEFAULT NULL,
    last_synced_at       TIMESTAMP    NULL DEFAULT NULL,
    last_seen_at         TIMESTAMP    NULL DEFAULT NULL,
    created_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- One external id maps to exactly one internal record (per tenant+source+type).
    UNIQUE KEY uk_external (tenant_id, source_system, internal_entity_type, external_id),
    -- And vice versa: one internal record has at most one external id per source+type.
    UNIQUE KEY uk_internal (tenant_id, source_system, internal_entity_type, internal_entity_id),
    -- Reverse-lookup support for "what external systems know about this internal record?".
    KEY ix_internal_lookup (tenant_id, internal_entity_type, internal_entity_id),
    -- "What was last synced from this source?" worker driver.
    KEY ix_source_last_sync (tenant_id, source_system, last_synced_at),
    KEY ix_status (tenant_id, sync_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
