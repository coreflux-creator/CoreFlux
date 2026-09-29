-- Retain the exact Quanta work context alongside each imported time component.
-- Legacy rows remain NULL so they are not mistaken for verified source context.
SET @quanta_imports_exists := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'quanta_time_imports');
SET @col_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'quanta_time_imports' AND column_name = 'source_worksite_id');
SET @sql := IF(@quanta_imports_exists = 1 AND @col_exists = 0,
    'ALTER TABLE quanta_time_imports ADD COLUMN source_worksite_id VARCHAR(128) NULL AFTER worker_id',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'quanta_time_imports' AND column_name = 'source_worksite_name');
SET @sql := IF(@quanta_imports_exists = 1 AND @col_exists = 0,
    'ALTER TABLE quanta_time_imports ADD COLUMN source_worksite_name VARCHAR(255) NULL AFTER source_worksite_id',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'quanta_time_imports' AND column_name = 'source_dimension_values_json');
SET @sql := IF(@quanta_imports_exists = 1 AND @col_exists = 0,
    'ALTER TABLE quanta_time_imports ADD COLUMN source_dimension_values_json TEXT NULL AFTER source_worksite_name',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
