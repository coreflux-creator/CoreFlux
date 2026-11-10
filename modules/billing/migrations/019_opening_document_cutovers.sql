SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='billing_invoices' AND COLUMN_NAME='opening_cutover_id');
SET @sql := IF(@col=0, 'ALTER TABLE billing_invoices ADD COLUMN opening_cutover_id BIGINT UNSIGNED NULL, ADD INDEX idx_bi_opening_cutover (tenant_id, opening_cutover_id)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
