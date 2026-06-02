-- =============================================================================
-- Migration 099 — Zoho Books per-entity + sync_config copy + per-provider audit
-- =============================================================================
-- Brings Zoho Books to parity with Jaz:
--   • Each (tenant, sub_tenant) pair gets its own zoho_books_connections row,
--     instead of one row per master tenant. Re-uses the existing schema by
--     just adding a `sub_tenant_id` column and swapping the UNIQUE key.
--   • OAuth state nonces also carry the sub_tenant_id so the callback can
--     write the connection to the right entity.
--   • Backfills existing rows with sub_tenant_id = tenant_id (the "parent
--     self-entity" row), so today's connections keep working unchanged.
--
-- Notes:
--   • Zoho Books account / contact mappings reuse the provider-neutral
--     `accounting_account_mappings` table introduced in migration 098 via
--     `provider = 'zoho_books'`. No new mapping table needed.
--   • Sync workers continue to read one connection per (tenant, sub_tenant)
--     row — when invoked at the master-tenant level they iterate.
-- =============================================================================

-- 1) zoho_books_connections — add sub_tenant_id (idempotent).
SET @has_st := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'zoho_books_connections'
      AND COLUMN_NAME = 'sub_tenant_id'
);
SET @stmt := IF(@has_st = 0,
    "ALTER TABLE zoho_books_connections
       ADD COLUMN `sub_tenant_id` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `tenant_id`",
    'SELECT 1');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- Backfill: sub_tenant_id := tenant_id where still 0 (legacy rows).
UPDATE zoho_books_connections SET sub_tenant_id = tenant_id WHERE sub_tenant_id = 0;

-- Swap the UNIQUE key: drop tenant-only, add composite. Idempotent via
-- information_schema guard.
SET @has_old := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'zoho_books_connections'
      AND INDEX_NAME = 'uq_zoho_tenant'
);
SET @stmt := IF(@has_old = 1,
    "ALTER TABLE zoho_books_connections DROP INDEX uq_zoho_tenant",
    'SELECT 1');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_new := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'zoho_books_connections'
      AND INDEX_NAME = 'uq_zoho_tenant_sub'
);
SET @stmt := IF(@has_new = 0,
    "ALTER TABLE zoho_books_connections ADD UNIQUE KEY uq_zoho_tenant_sub (tenant_id, sub_tenant_id)",
    'SELECT 1');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- 2) zoho_books_oauth_state — carry sub_tenant_id through the round-trip.
SET @has_st2 := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'zoho_books_oauth_state'
      AND COLUMN_NAME = 'sub_tenant_id'
);
SET @stmt := IF(@has_st2 = 0,
    "ALTER TABLE zoho_books_oauth_state
       ADD COLUMN `sub_tenant_id` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `tenant_id`",
    'SELECT 1');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- 3) zoho_books_sync_audit also gets sub_tenant_id so per-entity audit
-- queries don't need a JOIN. Idempotent.
SET @has_st3 := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'zoho_books_sync_audit'
      AND COLUMN_NAME = 'sub_tenant_id'
);
SET @stmt := IF(@has_st3 = 0,
    "ALTER TABLE zoho_books_sync_audit
       ADD COLUMN `sub_tenant_id` INT UNSIGNED NULL AFTER `tenant_id`,
       ADD INDEX `ix_zoho_audit_entity` (`tenant_id`, `sub_tenant_id`, `occurred_at`)",
    'SELECT 1');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
