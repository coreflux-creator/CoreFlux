-- Persist original workspace only after verified import or explicit matching-source review.
-- Historical rows remain NULL; the current connection is never an automatic backfill.

SET @col_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='quanta_time_imports' AND column_name='source_workspace_id');
SET @sql := IF(@col_exists=0, 'ALTER TABLE quanta_time_imports ADD COLUMN source_workspace_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='quanta_time_imports' AND column_name='source_workspace_binding_hash');
SET @sql := IF(@col_exists=0, 'ALTER TABLE quanta_time_imports ADD COLUMN source_workspace_binding_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='quanta_time_imports' AND column_name='source_workspace_verified_by');
SET @sql := IF(@col_exists=0, 'ALTER TABLE quanta_time_imports ADD COLUMN source_workspace_verified_by BIGINT UNSIGNED NULL', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='quanta_time_imports' AND column_name='source_workspace_verified_at');
SET @sql := IF(@col_exists=0, 'ALTER TABLE quanta_time_imports ADD COLUMN source_workspace_verified_at DATETIME NULL', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='quanta_time_imports' AND column_name='source_workspace_verification');
SET @sql := IF(@col_exists=0, 'ALTER TABLE quanta_time_imports ADD COLUMN source_workspace_verification VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
