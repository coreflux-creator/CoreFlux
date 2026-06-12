-- Billing invoice WorkflowGraph controls.
--
-- Adds an explicit pointer from Billing invoices to the WorkflowGraph
-- approval instance that governed draft -> approved transitions.

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = 'billing_invoices'
                      AND COLUMN_NAME = 'workflow_instance_id');
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE billing_invoices ADD COLUMN workflow_instance_id BIGINT UNSIGNED NULL AFTER approved_at',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = 'billing_invoices'
                      AND INDEX_NAME = 'idx_bi_workflow');
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE billing_invoices ADD INDEX idx_bi_workflow (tenant_id, workflow_instance_id)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
