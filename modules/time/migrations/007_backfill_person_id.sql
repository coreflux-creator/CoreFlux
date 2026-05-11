-- Time module — guarantee `time_entries.person_id` column + index exist.
--
-- History: older deploys created `time_entries` with `placement_id` only.
-- The runtime code (modules/time/api/timesheets.php, reports.php,
-- lib/settlement_create.php, lib/time.php, lib/settlement.php) hard-codes
-- `te.person_id`, so an older schema throws "Unknown column".
--
-- v3 (2026-02): rewritten as ONE-STATEMENT-PER-LINE so the migration
-- runner's `preg_split('/;\s*\R/m')` cleanly separates every PREPARE /
-- EXECUTE / DEALLOCATE into its own PDO::exec() call. The v2 layout
-- collapsed all three onto a single line, which fails on stock PDO
-- (no multi-statement) — and that failure put the row in `_migrations`
-- as applied, so it never retried.
--
-- Idempotent: every conditional DDL is guarded by an information_schema
-- lookup so re-running is always safe.

SET @col_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'time_entries' AND column_name = 'person_id')
;
SET @table_exists := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'time_entries')
;
SET @sql := IF(@table_exists = 1 AND @col_exists = 0, 'ALTER TABLE time_entries ADD COLUMN person_id BIGINT UNSIGNED NULL AFTER placement_id', 'SELECT "time_entries.person_id already exists or table missing — skipping ADD COLUMN" AS note')
;
PREPARE stmt FROM @sql
;
EXECUTE stmt
;
DEALLOCATE PREPARE stmt
;

-- Backfill via placements.worker_id (the conventional join target).
SET @placements_exists := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'placements')
;
SET @worker_id_exists := IF(@placements_exists = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'placements' AND column_name = 'worker_id'), 0)
;
SET @sql := IF(@table_exists = 1 AND @placements_exists = 1 AND @worker_id_exists = 1, 'UPDATE time_entries te LEFT JOIN placements p ON p.id = te.placement_id AND p.tenant_id = te.tenant_id SET te.person_id = p.worker_id WHERE te.person_id IS NULL', 'SELECT "placements.worker_id not available — skipping person_id backfill" AS note')
;
PREPARE stmt FROM @sql
;
EXECUTE stmt
;
DEALLOCATE PREPARE stmt
;

-- Helpful composite index for tenant + person + date queries.
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'time_entries' AND index_name = 'idx_te_tenant_person_date')
;
SET @sql := IF(@table_exists = 1 AND @idx_exists = 0, 'ALTER TABLE time_entries ADD INDEX idx_te_tenant_person_date (tenant_id, person_id, work_date)', 'SELECT "idx_te_tenant_person_date already exists — skipping" AS note')
;
PREPARE stmt FROM @sql
;
EXECUTE stmt
;
DEALLOCATE PREPARE stmt
;
