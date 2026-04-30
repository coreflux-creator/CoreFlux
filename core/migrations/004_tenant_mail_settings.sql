-- Model B tenant mail settings (Phase 6 Slice 1.5)
-- Adds reply-to + display-name override per tenant. Model C columns
-- (from_email, from_verified) bolt on later in a 005_ migration.
-- Idempotent via information_schema guard; utf8mb4_unicode_ci compatible.

-- mail_reply_to
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'mail_reply_to'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE tenants ADD COLUMN mail_reply_to VARCHAR(255) NULL AFTER name',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- mail_from_name_override
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'mail_from_name_override'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE tenants ADD COLUMN mail_from_name_override VARCHAR(120) NULL AFTER mail_reply_to',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
