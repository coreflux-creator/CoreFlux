-- Treasury money-movement WorkflowGraph controls.
--
-- Durable WorkflowGraph linkage for payment and transfer approvals.

SET @tp_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'treasury_payments'
                  AND COLUMN_NAME = 'workflow_instance_id');
SET @sql := IF(@tp_col = 0,
    'ALTER TABLE treasury_payments ADD COLUMN workflow_instance_id BIGINT UNSIGNED NULL AFTER status',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @tp_idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'treasury_payments'
                  AND INDEX_NAME = 'idx_tp_workflow');
SET @sql := IF(@tp_idx = 0,
    'ALTER TABLE treasury_payments ADD INDEX idx_tp_workflow (tenant_id, workflow_instance_id)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @tt_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'treasury_transfers'
                  AND COLUMN_NAME = 'workflow_instance_id');
SET @sql := IF(@tt_col = 0,
    'ALTER TABLE treasury_transfers ADD COLUMN workflow_instance_id BIGINT UNSIGNED NULL AFTER status',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @tt_idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'treasury_transfers'
                  AND INDEX_NAME = 'idx_tt_workflow');
SET @sql := IF(@tt_idx = 0,
    'ALTER TABLE treasury_transfers ADD INDEX idx_tt_workflow (tenant_id, workflow_instance_id)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
