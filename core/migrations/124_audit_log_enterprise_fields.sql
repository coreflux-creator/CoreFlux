-- Enterprise audit-log metadata fields.
--
-- The platform audit contract includes actor type/email, object type, before
-- and after snapshots, request id, source, and user agent. Existing modules
-- already write some of these fields opportunistically; these guarded alters
-- make the canonical audit_log table able to receive and filter them.

SET @tbl := (
    SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_log'
);

SET @col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_log' AND COLUMN_NAME = 'actor_type'
);
SET @sql := IF(@tbl = 1 AND @col = 0,
    'ALTER TABLE audit_log ADD COLUMN actor_type VARCHAR(40) NULL AFTER actor_user_id',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_log' AND COLUMN_NAME = 'actor_email'
);
SET @sql := IF(@tbl = 1 AND @col = 0,
    'ALTER TABLE audit_log ADD COLUMN actor_email VARCHAR(255) NULL AFTER actor_type',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_log' AND COLUMN_NAME = 'object_type'
);
SET @sql := IF(@tbl = 1 AND @col = 0,
    'ALTER TABLE audit_log ADD COLUMN object_type VARCHAR(80) NULL AFTER target_id',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_log' AND COLUMN_NAME = 'before_json'
);
SET @sql := IF(@tbl = 1 AND @col = 0,
    'ALTER TABLE audit_log ADD COLUMN before_json MEDIUMTEXT NULL AFTER object_type',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_log' AND COLUMN_NAME = 'after_json'
);
SET @sql := IF(@tbl = 1 AND @col = 0,
    'ALTER TABLE audit_log ADD COLUMN after_json MEDIUMTEXT NULL AFTER before_json',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_log' AND COLUMN_NAME = 'request_id'
);
SET @sql := IF(@tbl = 1 AND @col = 0,
    'ALTER TABLE audit_log ADD COLUMN request_id VARCHAR(80) NULL AFTER ip_address',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_log' AND COLUMN_NAME = 'source'
);
SET @sql := IF(@tbl = 1 AND @col = 0,
    'ALTER TABLE audit_log ADD COLUMN source VARCHAR(80) NULL AFTER request_id',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_log' AND COLUMN_NAME = 'user_agent'
);
SET @sql := IF(@tbl = 1 AND @col = 0,
    'ALTER TABLE audit_log ADD COLUMN user_agent VARCHAR(255) NULL AFTER source',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_log' AND INDEX_NAME = 'idx_audit_request_id'
);
SET @sql := IF(@tbl = 1 AND @idx = 0,
    'ALTER TABLE audit_log ADD INDEX idx_audit_request_id (request_id)',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
