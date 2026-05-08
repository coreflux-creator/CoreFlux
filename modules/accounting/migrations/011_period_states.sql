-- Sprint 7a — period state machine extension (spec §6).
-- Adds 'locked' to accounting_periods.status enum and the 4 audit columns
-- the spec requires for soft-close / lock workflows.
--
-- Idempotent: information_schema guards. MySQL 5.7+/8.0 compatible.

-- 1. Extend the status enum to add 'locked'
ALTER TABLE accounting_periods
    MODIFY COLUMN status
    ENUM('future','open','soft_closed','closed','reopened','locked')
    NOT NULL DEFAULT 'open';

-- 2. Add audit timestamps + actor user ids if missing.
SET @c := (SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema = DATABASE() AND table_name = 'accounting_periods' AND column_name = 'closed_at');
SET @sql := IF(@c = 0,
    'ALTER TABLE accounting_periods ADD COLUMN closed_at DATETIME NULL AFTER status',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema = DATABASE() AND table_name = 'accounting_periods' AND column_name = 'closed_by_user_id');
SET @sql := IF(@c = 0,
    'ALTER TABLE accounting_periods ADD COLUMN closed_by_user_id BIGINT UNSIGNED NULL AFTER closed_at',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema = DATABASE() AND table_name = 'accounting_periods' AND column_name = 'locked_at');
SET @sql := IF(@c = 0,
    'ALTER TABLE accounting_periods ADD COLUMN locked_at DATETIME NULL AFTER closed_by_user_id',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema = DATABASE() AND table_name = 'accounting_periods' AND column_name = 'locked_by_user_id');
SET @sql := IF(@c = 0,
    'ALTER TABLE accounting_periods ADD COLUMN locked_by_user_id BIGINT UNSIGNED NULL AFTER locked_at',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
