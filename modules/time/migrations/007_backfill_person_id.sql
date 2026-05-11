-- Time module — guarantee `time_entries.person_id` column + index exist.
--
-- History: older deploys created `time_entries` with `placement_id` only.
-- The runtime code (modules/time/api/timesheets.php, reports.php,
-- lib/settlement_create.php, lib/time.php, lib/settlement.php) hard-codes
-- `te.person_id`, so an older schema throws "Unknown column".
--
-- v2 (2026-02): rewritten as SQL-level idempotent. The previous version
-- relied on the migration runner's "Duplicate column name" tolerance,
-- which meant the file was recorded as applied on the FIRST run — even
-- if `time_entries` didn't exist yet for that tenant. Any tenant whose
-- `time_entries` was lazily-created later then ran the 001 schema (no
-- person_id) without ever re-running 007. This version uses
-- `information_schema` lookups so re-running it is always safe and the
-- runner records a clean no-op every pass.
--
-- Idempotent. Safe to bump this file's hash to force re-run on every
-- tenant — it will silently no-op on tenants already in the target state.

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name   = 'time_entries'
       AND column_name  = 'person_id'
);
SET @table_exists := (
    SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'time_entries'
);
SET @sql := IF(
    @table_exists = 1 AND @col_exists = 0,
    'ALTER TABLE time_entries ADD COLUMN person_id BIGINT UNSIGNED NULL AFTER placement_id',
    'SELECT "time_entries.person_id already exists or table missing — skipping ADD COLUMN" AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill via placements.worker_id (the conventional join target).
-- Wrapped in an existence check so tenants without `placements` are skipped.
SET @placements_exists := (
    SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'placements'
);
SET @worker_id_exists := IF(@placements_exists = 1,
    (SELECT COUNT(*) FROM information_schema.columns
       WHERE table_schema = DATABASE()
         AND table_name   = 'placements'
         AND column_name  = 'worker_id'), 0);
SET @sql := IF(
    @table_exists = 1 AND @placements_exists = 1 AND @worker_id_exists = 1,
    'UPDATE time_entries te
        LEFT JOIN placements p ON p.id = te.placement_id AND p.tenant_id = te.tenant_id
        SET te.person_id = p.worker_id
      WHERE te.person_id IS NULL',
    'SELECT "placements.worker_id not available — skipping person_id backfill" AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Helpful composite index for tenant + person + date queries.
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name   = 'time_entries'
       AND index_name   = 'idx_te_tenant_person_date'
);
SET @sql := IF(
    @table_exists = 1 AND @idx_exists = 0,
    'ALTER TABLE time_entries ADD INDEX idx_te_tenant_person_date (tenant_id, person_id, work_date)',
    'SELECT "idx_te_tenant_person_date already exists — skipping" AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
