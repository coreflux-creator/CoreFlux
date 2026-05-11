-- Time module — guarantee `time_entries.person_id` column + index exist.
--
-- History: older deploys created `time_entries` with `placement_id` only.
-- The runtime code (modules/time/api/timesheets.php, reports.php,
-- lib/settlement_create.php, lib/time.php, lib/settlement.php) hard-codes
-- `te.person_id`, so an older schema throws "Unknown column".
--
-- v4 (2026-02): every conditional branch uses `DO 0` (no result set) as
-- the no-op, instead of `SELECT '…' AS note` which left an unconsumed
-- cursor open and broke the very next PDO query with
-- "SQLSTATE[HY000]: 2014 Cannot execute queries while other unbuffered
-- queries are active". `DO` evaluates without emitting rows.
--
-- Idempotent: every conditional DDL is guarded by an information_schema
-- lookup so re-running is always safe. One statement per line so the
-- migration runner's split-on-`;`-newline parser sends each individually.

SET @col_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'time_entries' AND column_name = 'person_id')
;
SET @table_exists := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'time_entries')
;
SET @sql := IF(@table_exists = 1 AND @col_exists = 0, 'ALTER TABLE time_entries ADD COLUMN person_id BIGINT UNSIGNED NULL AFTER placement_id', 'DO 0')
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
SET @sql := IF(@table_exists = 1 AND @placements_exists = 1 AND @worker_id_exists = 1, 'UPDATE time_entries te LEFT JOIN placements p ON p.id = te.placement_id AND p.tenant_id = te.tenant_id SET te.person_id = p.worker_id WHERE te.person_id IS NULL', 'DO 0')
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
SET @sql := IF(@table_exists = 1 AND @idx_exists = 0, 'ALTER TABLE time_entries ADD INDEX idx_te_tenant_person_date (tenant_id, person_id, work_date)', 'DO 0')
;
PREPARE stmt FROM @sql
;
EXECUTE stmt
;
DEALLOCATE PREPARE stmt
;
