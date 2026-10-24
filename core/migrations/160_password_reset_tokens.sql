-- Password recovery is part of a usable standalone install. Repair older
-- Laravel-style tables during guarded migration, never on a public request.
CREATE TABLE IF NOT EXISTS password_resets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    email VARCHAR(255) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_password_resets_email_created (email, created_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @sql := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE password_resets ADD COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE FIRST',
        'DO 0')
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'password_resets' AND column_name = 'id'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE password_resets ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER id',
        'DO 0')
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'password_resets' AND column_name = 'user_id'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE password_resets ADD COLUMN email VARCHAR(255) NULL AFTER user_id',
        'DO 0')
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'password_resets' AND column_name = 'email'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE password_resets ADD COLUMN token_hash CHAR(64) NULL AFTER email',
        'DO 0')
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'password_resets' AND column_name = 'token_hash'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE password_resets ADD COLUMN expires_at DATETIME NULL AFTER token_hash',
        'DO 0')
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'password_resets' AND column_name = 'expires_at'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE password_resets ADD COLUMN used_at DATETIME NULL AFTER expires_at',
        'DO 0')
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'password_resets' AND column_name = 'used_at'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE password_resets ADD COLUMN created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP AFTER used_at',
        'DO 0')
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'password_resets' AND column_name = 'created_at'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- A legacy email-only primary/unique key would allow only one request per
-- address. The generated id is already unique, so remove that constraint.
SET @email_unique_key := (
    SELECT index_name FROM (
        SELECT index_name, COUNT(*) AS column_count, MAX(column_name) AS only_column
        FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = 'password_resets'
          AND non_unique = 0
        GROUP BY index_name
    ) AS reset_unique_keys
    WHERE column_count = 1 AND only_column = 'email'
    ORDER BY (index_name = 'PRIMARY') DESC, index_name
    LIMIT 1
);
SET @sql := IF(@email_unique_key IS NULL, 'DO 0',
    IF(@email_unique_key = 'PRIMARY',
        'ALTER TABLE password_resets DROP PRIMARY KEY',
        CONCAT('ALTER TABLE password_resets DROP INDEX `',
            REPLACE(@email_unique_key, '`', '``'), '`')));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE password_resets ADD INDEX idx_password_resets_email_created (email, created_at, id)',
        'DO 0')
    FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'password_resets'
      AND index_name = 'idx_password_resets_email_created'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
