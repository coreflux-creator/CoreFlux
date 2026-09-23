-- Background approval must not depend on api_bootstrap repairing its columns.
-- Guard each addition for installations already repaired by the API.

SET @has_table := (
    SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staffing_timesheets'
);

SET @has_column := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staffing_timesheets'
       AND COLUMN_NAME = 'approved_via'
);
SET @sql := IF(@has_table > 0 AND @has_column = 0,
    'ALTER TABLE staffing_timesheets ADD COLUMN approved_via VARCHAR(32) NOT NULL DEFAULT ''internal_app''',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_column := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staffing_timesheets'
       AND COLUMN_NAME = 'external_approver_email'
);
SET @sql := IF(@has_table > 0 AND @has_column = 0,
    'ALTER TABLE staffing_timesheets ADD COLUMN external_approver_email VARCHAR(255) NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_column := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staffing_timesheets'
       AND COLUMN_NAME = 'external_approver_name'
);
SET @sql := IF(@has_table > 0 AND @has_column = 0,
    'ALTER TABLE staffing_timesheets ADD COLUMN external_approver_name VARCHAR(255) NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_column := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staffing_timesheets'
       AND COLUMN_NAME = 'approval_note'
);
SET @sql := IF(@has_table > 0 AND @has_column = 0,
    'ALTER TABLE staffing_timesheets ADD COLUMN approval_note VARCHAR(1000) NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
