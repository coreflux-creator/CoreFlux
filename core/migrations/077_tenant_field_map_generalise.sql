-- Migration 077 — Generalise tenant_integration_field_map for cross-
-- module + custom-field mapping (Phase 2 of the field-mapping rebuild).
--
-- Adds five columns that promote the mapping from "(integration,
-- entity_type, internal_field)" to a full
-- "(source_path) → (target_module, target_table, target_column,
--  linked_entity)" address. Old rows are backfilled so the existing
-- syncer keeps working unchanged during cutover; new rows can target
-- any writable column on any module (people, placements, companies,
-- ap_vendors, billing_invoices, etc.) including the custom-field
-- subsystem via target_table='custom_field_values'.
--
-- Decision log (user-locked in 2026-02 spec re-audit follow-up):
--   • (a) Cross-module identity: `linked_entity` join hint
--   • (b) Scope: all integrations
--   • (c) Custom field targets: yes
--   • (d) Conflict semantics: tenant mapping ALWAYS wins
--   • (e) Sequencing: JobDiva-first then replicate

-- 1. New columns (NULL during cutover so backfill is non-blocking).
ALTER TABLE tenant_integration_field_map
    ADD COLUMN source_path    VARCHAR(255) NULL  AFTER external_field,
    ADD COLUMN target_module  VARCHAR(64)  NULL  AFTER internal_field,
    ADD COLUMN target_table   VARCHAR(64)  NULL  AFTER target_module,
    ADD COLUMN target_column  VARCHAR(96)  NULL  AFTER target_table,
    ADD COLUMN linked_entity  VARCHAR(64)  NULL  AFTER target_column;

-- source_path — dotted JSON path inside the enriched payload.
--   Old `external_field` (flat key like 'jobTitle') is preserved as
--   the legacy shallow lookup; new mappings write the dotted form
--   (e.g. `_jd_candidate.firstName`, `_jd_customer.address.city`).
--   The resolver tries source_path first, then falls back to
--   external_field if source_path is NULL.

-- target_module — CoreFlux module slug ('people', 'placements',
--   'companies', 'ap', 'billing', 'accounting', 'time', 'treasury').
--   Drives the writable-targets allow-list lookup in
--   integration_writable_targets (migration 078).

-- target_table — physical table name (`people`, `placements`,
--   `companies`, `ap_vendors`, `ap_bills`, `billing_invoices`,
--   `placement_rates`, `custom_field_values`, etc.).
--   Multiple tables per module is intentional — e.g. 'placements'
--   module spans `placements` + `placement_rates` + `placement_corp_details`.

-- target_column — physical column name on target_table. For
--   target_table='custom_field_values', this stores the
--   custom_fields.code so the apply step can resolve the field row.

-- linked_entity — join hint that tells the apply step WHICH row in
--   target_table to write to relative to the entity being synced.
--   Slugs:
--     'self'                  — the entity being upserted (placement→placement, person→person)
--     'person'                — placement's linked person row
--     'end_client_company'    — placement's resolved end-client company
--     'vendor_company'        — placement's resolved vendor (employer/MSP) company
--     'placement_rates'       — sibling rates row for the placement
--     'placement_corp_details'— sibling corp-details row for the placement
--   The apply step builds a context map (slug → internal_id) per
--   synced record and resolves linked_entity through it.

-- 2. Backfill from existing rows — every pre-Phase-2 mapping targets
--    the canonical (module, table, column) for its entity_type.
-- placement entity → placements table (mostly) + handful of rates fields.
UPDATE tenant_integration_field_map
   SET target_module = 'placements',
       target_table  = 'placements',
       target_column = internal_field,
       linked_entity = 'self',
       source_path   = external_field
 WHERE entity_type = 'placement'
   AND target_module IS NULL;
-- Now correct the rate fields — they live in placement_rates, not placements.
UPDATE tenant_integration_field_map
   SET target_table  = 'placement_rates',
       linked_entity = 'placement_rates'
 WHERE entity_type = 'placement'
   AND internal_field IN ('bill_rate', 'bill_rate_unit', 'pay_rate', 'pay_rate_unit',
                          'currency', 'ot_multiplier', 'dt_multiplier');

UPDATE tenant_integration_field_map
   SET target_module = 'people',
       target_table  = 'people',
       target_column = internal_field,
       linked_entity = 'self',
       source_path   = external_field
 WHERE entity_type = 'person'
   AND target_module IS NULL;

UPDATE tenant_integration_field_map
   SET target_module = 'companies',
       target_table  = 'companies',
       target_column = internal_field,
       linked_entity = 'self',
       source_path   = external_field
 WHERE entity_type = 'company'
   AND target_module IS NULL;

UPDATE tenant_integration_field_map
   SET target_module = 'people',
       target_table  = 'people_contacts',
       target_column = internal_field,
       linked_entity = 'self',
       source_path   = external_field
 WHERE entity_type = 'contact'
   AND target_module IS NULL;

-- 3. Convenience index — the apply step queries by (tenant_id,
--    integration, target_module) to fan out writes per module.
ALTER TABLE tenant_integration_field_map
    ADD KEY ix_target_lookup (tenant_id, integration, target_module, target_table);
