-- Time: allow staffing external email approvals to be represented in
-- time_entries.approved_via.

SET @te := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'time_entries');
SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'time_entries' AND column_name = 'approved_via');
SET @sql := IF(@te = 1 AND @col = 1,
    "ALTER TABLE time_entries MODIFY COLUMN approved_via ENUM('manual','tokenized_client_email','bulk_pre_approved','external_email') NULL",
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
