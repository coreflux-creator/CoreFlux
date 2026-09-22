-- First-class assignment dimensions for staffing profitability and compliance.
--
-- Client, worker, job, recruiter and account-manager relationships already
-- exist on placements. These columns cover the remaining assignment-owned
-- axes so downstream business events can inherit a complete dimension set.

SET @tbl := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placements');

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placements' AND COLUMN_NAME='branch');
SET @sql := IF(@tbl>0 AND @col=0,'ALTER TABLE placements ADD COLUMN branch VARCHAR(160) NULL AFTER remote_policy','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placements' AND COLUMN_NAME='service_line');
SET @sql := IF(@tbl>0 AND @col=0,'ALTER TABLE placements ADD COLUMN service_line VARCHAR(120) NULL AFTER branch','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placements' AND COLUMN_NAME='workers_comp_class');
SET @sql := IF(@tbl>0 AND @col=0,'ALTER TABLE placements ADD COLUMN workers_comp_class VARCHAR(64) NULL AFTER service_line','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placements' AND COLUMN_NAME='department');
SET @sql := IF(@tbl>0 AND @col=0,'ALTER TABLE placements ADD COLUMN department VARCHAR(120) NULL AFTER workers_comp_class','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placements' AND COLUMN_NAME='cost_center');
SET @sql := IF(@tbl>0 AND @col=0,'ALTER TABLE placements ADD COLUMN cost_center VARCHAR(120) NULL AFTER department','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placements' AND COLUMN_NAME='accounting_entity_id');
SET @sql := IF(@tbl>0 AND @col=0,'ALTER TABLE placements ADD COLUMN accounting_entity_id BIGINT UNSIGNED NULL AFTER cost_center','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placements' AND INDEX_NAME='idx_pl_accounting_entity');
SET @sql := IF(@tbl>0 AND @idx=0,'CREATE INDEX idx_pl_accounting_entity ON placements (tenant_id, accounting_entity_id)','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO integration_writable_targets
    (target_module, target_table, target_column, value_type, description, default_linked_entity)
VALUES
    ('placements', 'placements', 'branch',                'string', 'Branch or business unit inherited by assignment activity', 'self'),
    ('placements', 'placements', 'service_line',          'string', 'Contract staffing, direct hire, referral, SOW, or other service line', 'self'),
    ('placements', 'placements', 'workers_comp_class',    'string', 'Workers compensation class code for the assignment', 'self'),
    ('placements', 'placements', 'department',            'string', 'Assignment reporting department', 'self'),
    ('placements', 'placements', 'cost_center',           'string', 'Assignment reporting cost center', 'self'),
    ('placements', 'placements', 'accounting_entity_id',  'number', 'Owning legal entity id', 'self');
