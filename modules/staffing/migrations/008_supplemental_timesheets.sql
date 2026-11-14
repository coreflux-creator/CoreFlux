-- Keep the primary week and its approvals unchanged. Late source is a separate
-- review/accounting unit with an explicit parent and immutable intake review.
SET @exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='staffing_timesheets' AND column_name='supplement_sequence');
SET @sql := IF(@exists=0, 'ALTER TABLE staffing_timesheets ADD supplement_sequence INT UNSIGNED NOT NULL DEFAULT 0, ADD supplement_of_id BIGINT UNSIGNED NULL, ADD supplement_review_hash CHAR(64) NULL', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add the replacement uniqueness before removing the original constraint.
SET @exists := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='staffing_timesheets' AND index_name='uq_sts_tenant_person_week_supplement');
SET @sql := IF(@exists=0, 'ALTER TABLE staffing_timesheets ADD UNIQUE KEY uq_sts_tenant_person_week_supplement (tenant_id,person_id,period_start,supplement_sequence)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='staffing_timesheets' AND index_name='uq_sts_tenant_person_week');
SET @sql := IF(@exists>0, 'ALTER TABLE staffing_timesheets DROP INDEX uq_sts_tenant_person_week', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='staffing_timesheets' AND index_name='idx_sts_supplement_parent');
SET @sql := IF(@exists=0, 'ALTER TABLE staffing_timesheets ADD KEY idx_sts_supplement_parent (tenant_id,supplement_of_id,supplement_sequence)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
