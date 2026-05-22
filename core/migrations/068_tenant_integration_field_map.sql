-- Migration 068 — Tenant Integration Field Map (Slice 3 scaffolding)
--
-- Per-tenant registry of "which external field maps to which internal
-- field" for each (integration, entity_type) pair. Lets the tenant_admin
-- override the syncer's hard-coded field choices without a code change.
--
-- WIRING STATUS: scaffolding only — the syncer doesn't consult this
-- table yet. The /api/admin/integrations/field_map.php admin API and
-- field_map.php lib are functional (read/write/list); next slice wires
-- the read into jobdivaSyncUpsertPlacement / etc.
--
-- Composite uniqueness: (tenant_id, integration, entity_type, internal_field)
-- — each internal field has at most ONE external field mapped to it
-- per tenant per integration. The reverse direction (one external →
-- multiple internal) is intentionally allowed (e.g. JobDiva `companyName`
-- could map to BOTH `end_client_name` AND `notes`).
CREATE TABLE IF NOT EXISTS tenant_integration_field_map (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id       BIGINT UNSIGNED NOT NULL,
    -- Source slug, matches external_entity_mappings.source_system
    -- (`jobdiva`, `bullhorn`, `quickbooks`, …).
    integration     VARCHAR(64)  NOT NULL,
    -- Internal entity type slug (`placement`, `person`, `company`, …).
    entity_type     VARCHAR(64)  NOT NULL,
    -- The external (source-side) field key, e.g. `jobTitle`, `dailyBillRate`.
    -- Stored verbatim — the syncer normalises case/separators at lookup.
    external_field  VARCHAR(128) NOT NULL,
    -- The CoreFlux column the value is written to, e.g. `title`,
    -- `bill_rate`. Validated by the lib against an allow-list per
    -- entity_type so a misconfiguration can't write to arbitrary columns.
    internal_field  VARCHAR(64)  NOT NULL,
    -- Optional transform hint: `none`, `date_normalise`, `lowercase`,
    -- `cents_to_dollars`, etc. The syncer applies this AFTER pluck.
    transform       VARCHAR(32)  NOT NULL DEFAULT 'none',
    -- Soft-disable without deleting the row (preserves audit trail).
    enabled         BOOLEAN      NOT NULL DEFAULT 1,
    -- Free-text note for the admin (e.g. "Mapped per client X
    -- requirement 2026-02-25").
    notes           VARCHAR(500) DEFAULT NULL,
    -- Audit: who configured this mapping.
    updated_by_user_id BIGINT UNSIGNED NULL,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tenant_integration_entity_internal (tenant_id, integration, entity_type, internal_field),
    KEY ix_tenant_integration (tenant_id, integration, entity_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
