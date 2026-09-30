-- Approved time keeps the assignment dimensions that were validated with its rate.
-- Historical approvals remain NULL; their existing posting path is unchanged.
SET @te_exists := (SELECT COUNT(*) FROM information_schema.tables
                    WHERE table_schema = DATABASE() AND table_name = 'time_entries');
SET @snapshot_exists := (SELECT COUNT(*) FROM information_schema.columns
                          WHERE table_schema = DATABASE() AND table_name = 'time_entries'
                            AND column_name = 'dimension_snapshot_json');
SET @sql := IF(@te_exists = 1 AND @snapshot_exists = 0,
    'ALTER TABLE time_entries ADD COLUMN dimension_snapshot_json JSON NULL AFTER rate_snapshot_id',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @hash_exists := (SELECT COUNT(*) FROM information_schema.columns
                      WHERE table_schema = DATABASE() AND table_name = 'time_entries'
                        AND column_name = 'dimension_snapshot_hash');
SET @sql := IF(@te_exists = 1 AND @hash_exists = 0,
    'ALTER TABLE time_entries ADD COLUMN dimension_snapshot_hash CHAR(64) NULL AFTER dimension_snapshot_json',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
