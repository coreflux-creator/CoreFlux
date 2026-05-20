-- =======================================================================
-- Core migration 057 — widen jobdiva_connections.last_sync_error
-- -----------------------------------------------------------------------
-- The original VARCHAR(500) was sufficient for short messages, but the
-- JobDiva client now surfaces the full upstream response (HTTP status,
-- JobDiva's verbatim message, raw body snippet, li-uuid correlation id,
-- and an optional remediation footer) so the tenant admin can see exactly
-- what JobDiva said without having to query the audit table.
--
-- TEXT lets us hold the full payload (a few KB) without truncation drift.
-- Idempotent: only ALTERs when the column is still VARCHAR.
-- =======================================================================

SET @col_type := (
    SELECT DATA_TYPE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'jobdiva_connections'
       AND COLUMN_NAME  = 'last_sync_error'
);

SET @sql := IF(
    @col_type = 'varchar',
    'ALTER TABLE jobdiva_connections MODIFY COLUMN last_sync_error TEXT DEFAULT NULL',
    'DO 0'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
