-- 128_placement_commercial_contract.sql
--
-- Complete the effective-dated placement rate as the commercial contract.
-- Base bill/pay rates remain the canonical revenue and labor-compensation
-- inputs. These columns hold common, source-mappable adjustments; arbitrary
-- recipient fees continue to live on placement_economic_parties.

SET @tbl := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_rates');

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_rates' AND COLUMN_NAME='bill_adder_pct');
SET @sql := IF(@tbl>0 AND @col=0,'ALTER TABLE placement_rates ADD COLUMN bill_adder_pct DECIMAL(8,6) NULL AFTER adder_pct','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_rates' AND COLUMN_NAME='bill_adder_flat');
SET @sql := IF(@tbl>0 AND @col=0,'ALTER TABLE placement_rates ADD COLUMN bill_adder_flat DECIMAL(12,4) NULL AFTER bill_adder_pct','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_rates' AND COLUMN_NAME='bill_discount_pct');
SET @sql := IF(@tbl>0 AND @col=0,'ALTER TABLE placement_rates ADD COLUMN bill_discount_pct DECIMAL(8,6) NULL AFTER bill_adder_flat','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_rates' AND COLUMN_NAME='bill_discount_flat');
SET @sql := IF(@tbl>0 AND @col=0,'ALTER TABLE placement_rates ADD COLUMN bill_discount_flat DECIMAL(12,4) NULL AFTER bill_discount_pct','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_rates' AND COLUMN_NAME='workers_comp_pct');
SET @sql := IF(@tbl>0 AND @col=0,'ALTER TABLE placement_rates ADD COLUMN workers_comp_pct DECIMAL(8,6) NULL AFTER bill_discount_flat','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_rates' AND COLUMN_NAME='benefits_load_pct');
SET @sql := IF(@tbl>0 AND @col=0,'ALTER TABLE placement_rates ADD COLUMN benefits_load_pct DECIMAL(8,6) NULL AFTER workers_comp_pct','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_rates' AND COLUMN_NAME='other_cost_per_hour');
SET @sql := IF(@tbl>0 AND @col=0,'ALTER TABLE placement_rates ADD COLUMN other_cost_per_hour DECIMAL(12,4) NULL AFTER benefits_load_pct','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_rates' AND COLUMN_NAME='other_cost_flat');
SET @sql := IF(@tbl>0 AND @col=0,'ALTER TABLE placement_rates ADD COLUMN other_cost_flat DECIMAL(12,2) NULL AFTER other_cost_per_hour','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_rates' AND COLUMN_NAME='economics_snapshot_json');
SET @sql := IF(@tbl>0 AND @col=0,'ALTER TABLE placement_rates ADD COLUMN economics_snapshot_json JSON NULL AFTER background_fee_total','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO integration_writable_targets
    (target_module, target_table, target_column, value_type, description, default_linked_entity)
VALUES
    ('placements', 'placement_rates', 'bill_adder_pct', 'number', 'Client bill-rate adder percent stored as decimal', 'placement_rates'),
    ('placements', 'placement_rates', 'bill_adder_flat', 'number', 'Client bill-rate adder amount per billing unit', 'placement_rates'),
    ('placements', 'placement_rates', 'bill_discount_pct', 'number', 'Client bill-rate discount percent stored as decimal', 'placement_rates'),
    ('placements', 'placement_rates', 'bill_discount_flat', 'number', 'Client bill-rate discount amount per billing unit', 'placement_rates'),
    ('placements', 'placement_rates', 'workers_comp_pct', 'number', 'Workers compensation cost percent of labor pay', 'placement_rates'),
    ('placements', 'placement_rates', 'benefits_load_pct', 'number', 'Benefits load percent of labor pay', 'placement_rates'),
    ('placements', 'placement_rates', 'other_cost_per_hour', 'number', 'Other recurring cost per labor hour', 'placement_rates'),
    ('placements', 'placement_rates', 'other_cost_flat', 'number', 'Other fixed placement cost', 'placement_rates');
