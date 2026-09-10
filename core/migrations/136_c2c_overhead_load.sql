-- Keep C2C overhead separate from W-2 employer costs. A NULL placement rate
-- inherits the tenant default; zero is an explicit placement waiver.
SET @tbl := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tenant_staffing_economics_defaults');
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tenant_staffing_economics_defaults' AND COLUMN_NAME='c2c_overhead_pct');
SET @sql := IF(@tbl>0 AND @col=0,'ALTER TABLE tenant_staffing_economics_defaults ADD COLUMN c2c_overhead_pct DECIMAL(8,6) NOT NULL DEFAULT 0 AFTER w2_benefits_load_pct','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @tbl := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_rates');
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_rates' AND COLUMN_NAME='c2c_overhead_pct');
SET @sql := IF(@tbl>0 AND @col=0,'ALTER TABLE placement_rates ADD COLUMN c2c_overhead_pct DECIMAL(8,6) NULL AFTER benefits_load_pct','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO integration_writable_targets
    (target_module, target_table, target_column, value_type, description, default_linked_entity)
VALUES
    ('placements', 'placement_rates', 'c2c_overhead_pct', 'number', 'C2C overhead percent of vendor labor pay', 'placement_rates');
