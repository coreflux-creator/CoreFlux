-- 084_airtable_push_direction.sql
--
-- Slice 4.1 — Bi-directional Airtable mappings.
--
-- Existing schema treats Airtable as pull-only; the `direction` column
-- accepts 'push' but the sync engine ignored it. This migration:
--
--   1. Adds a dedicated `reverse_field_map` JSON column for push so
--      operators don't have to invert their pull-side mappings (and
--      the two semantics can diverge — e.g. push only writes a
--      subset of columns back).
--
--   2. Adds `push_unmatched_action` controlling what happens when a
--      CoreFlux row has no linked Airtable record yet:
--          create_new  – POST a new Airtable record (default)
--          update_only – skip + log (safer for production data)
--          error       – mark the row as failed
--
--   3. Adds `last_push_at` / `last_push_error` / `last_push_records`
--      so the Health panel can surface push runs separately from
--      pull runs.

ALTER TABLE airtable_table_mappings
  ADD COLUMN IF NOT EXISTS reverse_field_map JSON NULL
    COMMENT 'CoreFlux column -> Airtable field map for push direction. Independent of pull-side field_map.'
    AFTER field_map;

ALTER TABLE airtable_table_mappings
  ADD COLUMN IF NOT EXISTS push_unmatched_action VARCHAR(16) NOT NULL DEFAULT 'create_new'
    COMMENT 'Behaviour when pushing a CoreFlux row that has no linked Airtable record: create_new | update_only | error'
    AFTER reverse_field_map;

ALTER TABLE airtable_table_mappings
  ADD COLUMN IF NOT EXISTS last_push_at DATETIME NULL
    COMMENT 'Most recent successful push run timestamp (independent of last_sync_at which tracks pull).'
    AFTER last_sync_at;

ALTER TABLE airtable_table_mappings
  ADD COLUMN IF NOT EXISTS last_push_error TEXT NULL
    COMMENT 'Error text from the most recent push run (NULL on success).'
    AFTER last_push_at;

ALTER TABLE airtable_table_mappings
  ADD COLUMN IF NOT EXISTS last_push_records INT UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'Record count from the most recent push run.'
    AFTER last_push_error;

-- Surface allowed direction values consistently. v1 was 'pull' / 'push';
-- 'both' is new in Slice 4.1 meaning the cron will run pull AND push.
-- No schema change required (existing VARCHAR holds 'both' fine) — this
-- comment is documentation for the operator.
