-- Sprint 6h — backfill `time_entries.person_id` for tenants whose
-- migration 001 ran before the column was added (older deploys
-- created `time_entries` with placement_id only, expecting
-- person_id to be derivable via placements.worker_id). The runtime
-- code (modules/time/api/timesheets.php, reports.php) hard-codes
-- `te.person_id`, so an older schema throws "Unknown column".
--
-- This migration is idempotent — the migration runner now catches
-- "Duplicate column name" so re-running on a tenant that already
-- has the column is a no-op.

ALTER TABLE time_entries ADD COLUMN person_id BIGINT UNSIGNED NULL AFTER placement_id;

-- Backfill via placements.worker_id (the conventional join target).
-- Best-effort: silently skipped on any tenant without `placements`
-- table or `worker_id` column thanks to the new exception handling
-- in the migration runner.
UPDATE time_entries te
   LEFT JOIN placements p ON p.id = te.placement_id AND p.tenant_id = te.tenant_id
   SET te.person_id = p.worker_id
 WHERE te.person_id IS NULL;

ALTER TABLE time_entries ADD INDEX idx_te_tenant_person_date (tenant_id, person_id, work_date);
