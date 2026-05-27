-- Migration 076 — Integration Payload Field Index (Phase 1 of the
-- generalised field-mapping rebuild).
--
-- Per-tenant catalog of every JSON path observed across recently-
-- synced payloads. The Integration Settings UI uses this table as
-- the source-of-truth for "what fields are available to map from
-- this integration?" — no more guessing whether JobDiva calls a
-- field `jobTitle` or `job_title`, no more shipping a new sync to
-- pick up a field the integration started returning last week.
--
-- Rows are produced by `integrationPayloadFieldIndexRecord()` —
-- called from every payload persist step (mappingUpsert) AFTER
-- enrichment has grafted the joined records (`_jd_candidate`,
-- `_jd_job`, `_jd_customer`, `_jd_contact`, `_jd_start`).
--
-- Why a separate table instead of querying external_entity_mappings.
-- payload_snapshot at request time:
--   • The picker needs O(1) lookup of "what paths exist on jobdiva
--     placement?" — a JSON-shred over thousands of payload_snapshots
--     would be too slow.
--   • Sample values per path are useful in the UI (so the operator
--     sees "_jd_job.title → 'Service Desk Analyst'"). Storing one
--     sample + occurrence count makes that cheap.
--   • The index is upsert-on-sight so it stays current as payloads
--     evolve. Old paths that stop appearing fade as last_seen_at
--     ages — UI can grey them.

CREATE TABLE IF NOT EXISTS integration_payload_field_index (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id            BIGINT UNSIGNED NOT NULL,
    -- Source slug: 'jobdiva' / 'quickbooks' / 'zoho' / 'airtable' / etc.
    integration          VARCHAR(64)  NOT NULL,
    -- External entity type slug ('placement', 'person', 'company',
    -- 'contact'). Matches external_entity_mappings.internal_entity_type
    -- since that's our normalised source-of-truth for the source
    -- entity shape.
    entity_type          VARCHAR(64)  NOT NULL,
    -- Dotted JSON path inside the enriched payload. Examples:
    --   `jobTitle`
    --   `_jd_candidate.firstName`
    --   `_jd_job.title`
    --   `_jd_customer.name`
    --   `_jd_contact.email`
    --   `_jd_start.dailyBillRate`
    -- Array elements get `[]` (e.g. `_jd_candidate.skills[].name`).
    -- Path stays human-readable so it can be shown verbatim in the
    -- Field Mapping UI.
    source_path          VARCHAR(255) NOT NULL,
    -- Coarse value type hint (`string`, `number`, `boolean`,
    -- `object`, `array`, `null`). Lets the UI surface a transform
    -- picker appropriate to the type (e.g. show `cents_to_dollars`
    -- only for numbers).
    value_type           VARCHAR(16)  NOT NULL DEFAULT 'string',
    -- Most-recent sample value (truncated to 200 chars). Useful in
    -- the picker so the operator sees what JobDiva is actually
    -- returning for that path.
    sample_value         VARCHAR(200) DEFAULT NULL,
    -- How many times this path has been observed across sync runs.
    -- High count = stable field; low count = sometimes-null. The UI
    -- can rank picker options by occurrence.
    occurrence_count     BIGINT UNSIGNED NOT NULL DEFAULT 1,
    first_seen_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tenant_integration_entity_path (tenant_id, integration, entity_type, source_path),
    KEY ix_tenant_integration_entity (tenant_id, integration, entity_type, last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
