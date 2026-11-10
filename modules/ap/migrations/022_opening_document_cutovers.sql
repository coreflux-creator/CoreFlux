SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ap_bills' AND COLUMN_NAME='opening_cutover_id');
SET @sql := IF(@col=0, 'ALTER TABLE ap_bills ADD COLUMN opening_cutover_id BIGINT UNSIGNED NULL, ADD INDEX idx_apb_opening_cutover (tenant_id, opening_cutover_id)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
