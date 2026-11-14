-- Existing computed runs must be recomputed; do not manufacture past review evidence.
SET @present := EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='payroll_runs' AND column_name='computation_sha256');
SET @sql := IF(@present,'DO 0','ALTER TABLE payroll_runs ADD computation_sha256 CHAR(64) NULL');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @present := EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='payroll_runs' AND column_name='approved_computation_revision');
SET @sql := IF(@present,'DO 0','ALTER TABLE payroll_runs ADD approved_computation_revision INT UNSIGNED NULL');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @present := EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='payroll_runs' AND column_name='approved_computation_sha256');
SET @sql := IF(@present,'DO 0','ALTER TABLE payroll_runs ADD approved_computation_sha256 CHAR(64) NULL');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
