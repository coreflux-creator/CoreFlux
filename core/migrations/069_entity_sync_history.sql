-- Migration 069 — Entity Sync History (per-record change log).
--
-- Captures one row each time mappingUpsert() detects an actual content
-- change (content_hash drift). Unchanged-but-re-touched syncs do NOT
-- write here — we only want CHANGES so the UI history view is
-- signal-only.
--
-- Wired in core/integrations/entity_mappings.php#mappingUpsert(); see
-- the new entitySyncHistoryRecord() helper alongside it.
--
-- Read side: /api/integrations/sync_history.php?entity_type=placement&internal_id=N
-- powers the "Sync history" drawer on placement / person / company
-- detail pages.
--
-- Pruning: we keep the most recent N rows per (tenant, entity_type,
-- internal_entity_id, source_system) and trim older ones via a nightly
-- cron (cron/entity_sync_history_prune.php). Default retention = 50
-- rows per record per source.
CREATE TABLE IF NOT EXISTS entity_sync_history (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id         BIGINT UNSIGNED NOT NULL,
    source_system     VARCHAR(64)  NOT NULL,
    -- The CoreFlux-side entity this snapshot pertains to.
    internal_entity_type VARCHAR(64) NOT NULL,
    internal_entity_id   BIGINT UNSIGNED NOT NULL,
    -- The source-side ID (e.g. JobDiva Start ID). Stored alongside
    -- internal_entity_id because operators often think in terms of
    -- "what did JobDiva have for this Start" and we want the lookup
    -- to be cheap from either direction.
    external_id       VARCHAR(128) NOT NULL,
    -- direction at the time of this sync (pull / push / two_way / off).
    direction         VARCHAR(16)  NOT NULL DEFAULT 'pull',
    -- Payload snapshot BEFORE this sync (the previous version of the
    -- external_entity_mappings.payload_snapshot, captured BEFORE the
    -- UPDATE). NULL for first-ever record (no prior state).
    payload_before    LONGTEXT     DEFAULT NULL,
    -- Payload snapshot AFTER this sync (i.e. the new value written
    -- into external_entity_mappings.payload_snapshot).
    payload_after     LONGTEXT     NOT NULL,
    -- Captured for fast lookup; matches external_entity_mappings.
    content_hash_before VARCHAR(64) DEFAULT NULL,
    content_hash_after  VARCHAR(64) NOT NULL,
    -- Who triggered this sync. NULL for cron / system-driven syncs;
    -- populated for manual "Sync now" button presses.
    actor_user_id     BIGINT UNSIGNED DEFAULT NULL,
    -- When this sync happened.
    created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- Lookup path: "show me the last N changes for this CoreFlux
    -- record" — that's the drawer query, indexed for fast paging.
    KEY ix_entity_recent (tenant_id, internal_entity_type, internal_entity_id, created_at),
    -- Secondary: "all changes from this source across all records"
    -- (for system-wide audit views, used by Slice 5+).
    KEY ix_source_recent (tenant_id, source_system, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
