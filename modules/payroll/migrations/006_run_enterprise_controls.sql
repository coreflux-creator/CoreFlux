-- Payroll migration 006 -- run enterprise controls
--
-- Durable actor/workflow evidence for material payroll run transitions.
-- The API still emits audit_log rows, but these columns make the current
-- state reconstructable without inferring creator/computer/approver/payment
-- actors from event history.

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'payroll_runs'
               AND COLUMN_NAME = 'created_by_user_id');
SET @sql := IF(@col = 0,
    'ALTER TABLE payroll_runs ADD COLUMN created_by_user_id INT UNSIGNED NULL AFTER run_type',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'payroll_runs'
               AND COLUMN_NAME = 'computed_by_user_id');
SET @sql := IF(@col = 0,
    'ALTER TABLE payroll_runs ADD COLUMN computed_by_user_id INT UNSIGNED NULL AFTER computed_at',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'payroll_runs'
               AND COLUMN_NAME = 'workflow_instance_id');
SET @sql := IF(@col = 0,
    'ALTER TABLE payroll_runs ADD COLUMN workflow_instance_id BIGINT UNSIGNED NULL AFTER approved_by',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'payroll_runs'
               AND COLUMN_NAME = 'paid_by_user_id');
SET @sql := IF(@col = 0,
    'ALTER TABLE payroll_runs ADD COLUMN paid_by_user_id INT UNSIGNED NULL AFTER paid_at',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'payroll_runs'
               AND INDEX_NAME = 'idx_run_workflow');
SET @sql := IF(@idx = 0,
    'ALTER TABLE payroll_runs ADD INDEX idx_run_workflow (tenant_id, workflow_instance_id)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
