-- =============================================================================
-- Migration 097 — audit_log schema parity
-- =============================================================================
-- Operator reported that `audit_log.event` is missing from production —
-- legacy installs created the table with `entity` + `action` columns (see
-- core/views/admin/audit_log.php which still SELECTs `entity` and `action`),
-- but every module's audit helper (apAudit, billingAudit, accountingAudit,
-- mercuryAudit, etc.) writes to columns
-- `(tenant_id, actor_user_id, event, target_id, meta_json, ip_address, created_at)`.
-- On a legacy install the INSERTs fail silently because of the missing
-- column → the user sees no audit trail.
--
-- This migration:
--   1) Creates `audit_log` if it doesn't exist (using the canonical shape).
--   2) Adds any missing columns idempotently (information_schema guards).
--   3) Backfills legacy `action` rows into `event` if the legacy column is
--      still around so historical entries don't get hidden.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `audit_log` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` INT UNSIGNED NULL,
    `actor_user_id` INT UNSIGNED NULL,
    `actor_type` VARCHAR(40) NULL,
    `actor_email` VARCHAR(255) NULL,
    `user_id` INT UNSIGNED NULL,
    `event` VARCHAR(128) NOT NULL DEFAULT '',
    `target_id` BIGINT UNSIGNED NULL,
    `object_type` VARCHAR(80) NULL,
    `before_json` MEDIUMTEXT NULL,
    `after_json` MEDIUMTEXT NULL,
    `entity` VARCHAR(64) NULL,
    `entity_id` BIGINT UNSIGNED NULL,
    `action` VARCHAR(64) NULL,
    `meta_json` MEDIUMTEXT NULL,
    `ip_address` VARCHAR(45) NULL,
    `request_id` VARCHAR(80) NULL,
    `source` VARCHAR(80) NULL,
    `user_agent` VARCHAR(255) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_audit_tenant_created` (`tenant_id`, `created_at`),
    KEY `idx_audit_actor` (`actor_user_id`),
    KEY `idx_audit_event` (`event`),
    KEY `idx_audit_request_id` (`request_id`),
    KEY `idx_audit_target` (`target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Idempotently add `event` column to an existing legacy table.
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_log' AND COLUMN_NAME = 'event'
);
SET @stmt := IF(@col_exists = 0,
    "ALTER TABLE audit_log ADD COLUMN `event` VARCHAR(128) NOT NULL DEFAULT '' AFTER `actor_user_id`",
    'SELECT 1');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- Backfill `event` from legacy `action` column if it exists.
SET @action_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_log' AND COLUMN_NAME = 'action'
);
SET @stmt := IF(@action_exists = 1,
    "UPDATE audit_log SET event = COALESCE(NULLIF(event,''), action) WHERE (event IS NULL OR event = '') AND action IS NOT NULL",
    'SELECT 1');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- Idempotently add `target_id` if a legacy install only has `entity_id`.
SET @tcol := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_log' AND COLUMN_NAME = 'target_id'
);
SET @stmt := IF(@tcol = 0,
    "ALTER TABLE audit_log ADD COLUMN `target_id` BIGINT UNSIGNED NULL AFTER `event`",
    'SELECT 1');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- Idempotently add `actor_user_id` for legacy installs that only had `user_id`.
SET @acol := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_log' AND COLUMN_NAME = 'actor_user_id'
);
SET @stmt := IF(@acol = 0,
    "ALTER TABLE audit_log ADD COLUMN `actor_user_id` INT UNSIGNED NULL AFTER `tenant_id`",
    'SELECT 1');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- Idempotently add `meta_json`.
SET @mcol := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_log' AND COLUMN_NAME = 'meta_json'
);
SET @stmt := IF(@mcol = 0,
    "ALTER TABLE audit_log ADD COLUMN `meta_json` MEDIUMTEXT NULL AFTER `target_id`",
    'SELECT 1');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- Idempotently add `ip_address`.
SET @icol := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_log' AND COLUMN_NAME = 'ip_address'
);
SET @stmt := IF(@icol = 0,
    "ALTER TABLE audit_log ADD COLUMN `ip_address` VARCHAR(45) NULL AFTER `meta_json`",
    'SELECT 1');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- Index for tenant lookups (idempotent — ignore the duplicate-key error).
-- MySQL doesn't have IF NOT EXISTS on indexes, so we use information_schema.
SET @ikey := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_log' AND INDEX_NAME = 'idx_audit_tenant_created'
);
SET @stmt := IF(@ikey = 0,
    "ALTER TABLE audit_log ADD INDEX idx_audit_tenant_created (tenant_id, created_at)",
    'SELECT 1');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

SET @ikey := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_log' AND INDEX_NAME = 'idx_audit_event'
);
SET @stmt := IF(@ikey = 0,
    "ALTER TABLE audit_log ADD INDEX idx_audit_event (event)",
    'SELECT 1');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
