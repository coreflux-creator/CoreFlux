-- Explicit commission terms. Unknown legacy values stay NULL; no amount or date is inferred.
SET @tbl := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_commissions');
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_commissions' AND COLUMN_NAME='currency');
SET @sql := IF(@tbl>0 AND @col=0,'ALTER TABLE placement_commissions ADD COLUMN currency CHAR(3) NULL','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @tbl := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_commissions');
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_commissions' AND COLUMN_NAME='payment_frequency');
SET @sql := IF(@tbl>0 AND @col=0,'ALTER TABLE placement_commissions ADD COLUMN payment_frequency VARCHAR(32) NULL','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @tbl := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_commissions');
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_commissions' AND COLUMN_NAME='due_month');
SET @sql := IF(@tbl>0 AND @col=0,'ALTER TABLE placement_commissions ADD COLUMN due_month CHAR(7) NULL','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @tbl := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_economic_parties');
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_economic_parties' AND COLUMN_NAME='currency');
SET @sql := IF(@tbl>0 AND @col=0,'ALTER TABLE placement_economic_parties ADD COLUMN currency CHAR(3) NULL','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @tbl := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_economic_parties');
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_economic_parties' AND COLUMN_NAME='payment_frequency');
SET @sql := IF(@tbl>0 AND @col=0,'ALTER TABLE placement_economic_parties ADD COLUMN payment_frequency VARCHAR(32) NULL','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @tbl := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_economic_parties');
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_economic_parties' AND COLUMN_NAME='due_month');
SET @sql := IF(@tbl>0 AND @col=0,'ALTER TABLE placement_economic_parties ADD COLUMN due_month CHAR(7) NULL','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
