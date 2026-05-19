-- =======================================================================
-- Core migration 054 — Safe back-fill of users.created_at / updated_at
-- -----------------------------------------------------------------------
-- Migration 013 declared these columns inside CREATE TABLE IF NOT EXISTS
-- users(...), so production instances that had the users table BEFORE
-- migration 013 ran never picked them up. The /api/users.php endpoint
-- selects u.created_at directly, returning a 500 with
-- "Database column 'u.created_at' is missing — migration probably needs
-- to run".
--
-- This migration is idempotent: it queries information_schema and runs
-- ALTER TABLE only when the column is absent.
-- =======================================================================

-- created_at
SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE users ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'DO 0'
    )
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name   = 'users'
      AND column_name  = 'created_at'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- updated_at
SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE users ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP',
        'DO 0'
    )
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name   = 'users'
      AND column_name  = 'updated_at'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
