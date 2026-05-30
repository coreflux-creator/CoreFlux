-- =======================================================================
-- Core migration 082 — Airtable Slice 2: Real entity linkages
-- -----------------------------------------------------------------------
-- Slice 1 (migration 063) used a synthetic internal_entity_id derived
-- from sha256(external_id). That satisfied the NOT NULL constraint but
-- left every Airtable record orphaned — never linked to a real
-- placements.id / companies.id / ap_vendors_index.id row.
--
-- Slice 2 introduces a 4-column linkage policy on every mapping:
--
--   • link_strategy ∈ {external_id, match_column, manual, none}
--   • link_match_airtable_field   — which Airtable col carries the natural key
--   • link_match_internal_column  — which CoreFlux col holds the match value
--   • link_unmatched_action ∈ {skip, park, create_stub}
--
-- Per-entity defaults are applied at airtableMappingUpsert() time when
-- the operator hasn't picked an explicit strategy — see
-- AIRTABLE_ENTITY_LINK_DEFAULTS in core/airtable/sync.php:
--
--   placement → external_id  (placements.external_id)
--   vendor    → match_column (ap_vendors_index.vendor_name)
--   company   → match_column (companies.name)
--   customer  → match_column (companies.name)
--   contact   → match_column (people.email_primary)
--   others    → none         (preserves Slice-1 synthetic behaviour)
--
-- Park flow: a record that doesn't resolve to a real row lands in
-- external_entity_mappings with sync_status='unmatched' (instead of the
-- previous 'ok'). The ENUM is widened below to carry both 'unmatched'
-- and 'ambiguous' (two+ matches).
--
-- Also seeds integration_writable_targets for 'airtable' so the
-- generalised FieldMappingStudio + suggester pick up Airtable as a
-- first-class source.
--
-- Idempotent. Cloudways MySQL 5.7+ compatible.
-- =======================================================================

-- ── 1. Extend airtable_table_mappings with linkage policy columns ──
-- Defensive — only add columns when missing so re-running is safe.
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'airtable_table_mappings'
       AND COLUMN_NAME  = 'link_strategy'
);
SET @stmt := IF(@col_exists = 0,
  'ALTER TABLE airtable_table_mappings
     ADD COLUMN link_strategy ENUM("external_id","match_column","manual","none")
       NOT NULL DEFAULT "none" AFTER primary_field,
     ADD COLUMN link_match_airtable_field   VARCHAR(120) NULL AFTER link_strategy,
     ADD COLUMN link_match_internal_column  VARCHAR(120) NULL AFTER link_match_airtable_field,
     ADD COLUMN link_unmatched_action ENUM("skip","park","create_stub")
       NOT NULL DEFAULT "park" AFTER link_match_internal_column',
  'SELECT 1'
);
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- ── 2. Widen sync_status on external_entity_mappings ──
-- Adds 'unmatched' and 'ambiguous'. We re-issue ALTER conditionally — if
-- the new values are already present the column statement is a noop.
SET @has_unmatched := (
    SELECT LOCATE('unmatched', COLUMN_TYPE) > 0
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'external_entity_mappings'
       AND COLUMN_NAME  = 'sync_status'
);
SET @stmt := IF(IFNULL(@has_unmatched, 0) = 0,
  'ALTER TABLE external_entity_mappings
     MODIFY COLUMN sync_status
       ENUM("ok","stale","error","deleted_in_source","unmatched","ambiguous")
       NOT NULL DEFAULT "ok"',
  'SELECT 1'
);
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- ── 3. Seed integration_writable_targets for 'airtable' source ──
-- Slice 2 makes Airtable a first-class source in the generalised
-- FieldMappingStudio. The targets table is global; we don't insert
-- airtable-specific rows here because writable targets are SOURCE-
-- agnostic (any source can map TO companies.name). What we DO need is
-- to ensure the targets covering companies / placements / ap_vendors
-- exist — they were already seeded in migration 078 for jobdiva but
-- the same rows serve Airtable too. We add the two specifically
-- needed for Airtable matching that may be missing.
INSERT IGNORE INTO integration_writable_targets
    (target_module, target_table, target_column, value_type, description, default_linked_entity)
VALUES
    ('ap',       'ap_vendors_index', 'vendor_name', 'string', 'Vendor display name (natural key for Airtable linkage)', 'self'),
    ('ap',       'ap_vendors_index', 'tax_id_last4','string', 'Vendor EIN/SSN last 4 (alt natural key)',                 'self'),
    ('people',   'companies',        'name',        'string', 'Company name (natural key for Airtable linkage)',         'self'),
    ('people',   'companies',        'duns',        'string', 'DUNS number (alt natural key)',                            'self');
