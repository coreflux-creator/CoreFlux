-- =======================================================================
-- Placements migration 002 — Per-relationship cycle defaults
-- -----------------------------------------------------------------------
-- Used by Time settlement engine as DEFAULTS only. Cycles are advisory:
-- the Settlement UI groups suggested days into cycle-aligned chunks
-- (Mon-Sun for weekly, etc.) but the user can always override and extract
-- any subset of approved days at any time.
-- =======================================================================

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placements' AND COLUMN_NAME='client_bill_cycle');
SET @sql := IF(@col=0,
    "ALTER TABLE placements
        ADD COLUMN client_bill_cycle ENUM('weekly','biweekly','semimonthly','monthly','adhoc') NOT NULL DEFAULT 'monthly',
        ADD COLUMN client_bill_cycle_anchor DATE NULL",
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placements' AND COLUMN_NAME='vendor_pay_cycle');
SET @sql := IF(@col=0,
    "ALTER TABLE placements
        ADD COLUMN vendor_pay_cycle ENUM('weekly','biweekly','semimonthly','monthly','adhoc') NOT NULL DEFAULT 'biweekly',
        ADD COLUMN vendor_pay_cycle_anchor DATE NULL",
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
