-- Repair the two cadence defaults independently. Earlier migrations could
-- already have added the anchor columns, causing a combined ADD to fail.
SET @placement_table := (SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'placements');

SET @cycle_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'placements' AND COLUMN_NAME = 'client_bill_cycle');
SET @cycle_sql := IF(@placement_table > 0 AND @cycle_col = 0,
    "ALTER TABLE placements ADD COLUMN client_bill_cycle ENUM('weekly','biweekly','semimonthly','monthly','adhoc') NOT NULL DEFAULT 'monthly'",
    'DO 0');
PREPARE stmt FROM @cycle_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cycle_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'placements' AND COLUMN_NAME = 'client_bill_cycle_anchor');
SET @cycle_sql := IF(@placement_table > 0 AND @cycle_col = 0,
    'ALTER TABLE placements ADD COLUMN client_bill_cycle_anchor DATE NULL',
    'DO 0');
PREPARE stmt FROM @cycle_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cycle_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'placements' AND COLUMN_NAME = 'vendor_pay_cycle');
SET @cycle_sql := IF(@placement_table > 0 AND @cycle_col = 0,
    "ALTER TABLE placements ADD COLUMN vendor_pay_cycle ENUM('weekly','biweekly','semimonthly','monthly','adhoc') NOT NULL DEFAULT 'biweekly'",
    'DO 0');
PREPARE stmt FROM @cycle_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cycle_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'placements' AND COLUMN_NAME = 'vendor_pay_cycle_anchor');
SET @cycle_sql := IF(@placement_table > 0 AND @cycle_col = 0,
    'ALTER TABLE placements ADD COLUMN vendor_pay_cycle_anchor DATE NULL',
    'DO 0');
PREPARE stmt FROM @cycle_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
