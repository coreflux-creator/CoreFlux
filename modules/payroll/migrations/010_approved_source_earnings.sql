-- Native earnings retain their approved source, precise rates and compute revision.
SET @present := EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='payroll_runs' AND column_name='computation_revision');
SET @sql := IF(@present,'DO 0','ALTER TABLE payroll_runs ADD computation_revision INT UNSIGNED NOT NULL DEFAULT 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @present := EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='payroll_line_items' AND column_name='earnings_basis');
SET @sql := IF(@present,'DO 0',"ALTER TABLE payroll_line_items ADD earnings_basis ENUM('compensation','approved_time') NOT NULL DEFAULT 'compensation'");
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @present := EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='payroll_line_items' AND column_name='source_time_json');
SET @sql := IF(@present,'DO 0','ALTER TABLE payroll_line_items ADD source_time_json LONGTEXT NULL');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @present := EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='payroll_line_items' AND column_name='source_time_sha256');
SET @sql := IF(@present,'DO 0','ALTER TABLE payroll_line_items ADD source_time_sha256 CHAR(64) NULL');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @present := EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='payroll_earnings' AND column_name='source_rate');
SET @sql := IF(@present,'DO 0','ALTER TABLE payroll_earnings ADD source_rate DECIMAL(14,4) NULL');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @present := EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='payroll_earnings' AND column_name='source_multiplier');
SET @sql := IF(@present,'DO 0','ALTER TABLE payroll_earnings ADD source_multiplier DECIMAL(8,4) NULL');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @present := EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='payroll_earnings' AND column_name='source_entry_ids_json');
SET @sql := IF(@present,'DO 0','ALTER TABLE payroll_earnings ADD source_entry_ids_json LONGTEXT NULL');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @present := EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='payroll_earnings' AND column_name='source_snapshot_hash');
SET @sql := IF(@present,'DO 0','ALTER TABLE payroll_earnings ADD source_snapshot_hash CHAR(64) NULL');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE payroll_line_items MODIFY hours_regular DECIMAL(12,4) NOT NULL DEFAULT 0,
    MODIFY hours_overtime DECIMAL(12,4) NOT NULL DEFAULT 0;
ALTER TABLE payroll_earnings MODIFY hours DECIMAL(12,4) NULL;
