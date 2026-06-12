-- Placements migration 005 -- rate workflow controls
--
-- Durable WorkflowGraph linkage for placement rate snapshot approvals.

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'placement_rates'
               AND COLUMN_NAME = 'workflow_instance_id');
SET @sql := IF(@col = 0,
    'ALTER TABLE placement_rates ADD COLUMN workflow_instance_id BIGINT UNSIGNED NULL AFTER approved_at',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'placement_rates'
               AND INDEX_NAME = 'idx_prt_workflow');
SET @sql := IF(@idx = 0,
    'ALTER TABLE placement_rates ADD INDEX idx_prt_workflow (tenant_id, workflow_instance_id)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
