-- =======================================================================
-- Time module migration 003 — Per-day settlement engine
-- -----------------------------------------------------------------------
-- Decouples Time → Billing/AP/Payroll from period close. Each day is its
-- own extractable block (one time_entries row per work_date per placement),
-- marked independently as billed / ap-extracted / payroll-extracted.
--
-- Once a day is extracted to a target it CANNOT be re-extracted to that
-- target until un-extracted (audit-trail requirement). Other targets are
-- still independent — a day can be billed without being paid yet, etc.
--
-- All columns NULL by default (un-extracted) so the migration is no-op
-- for existing data. Indexes added so the "ready to extract" query is fast.
-- =======================================================================

-- billing extract
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='time_entries' AND COLUMN_NAME='bill_extracted_at');
SET @sql := IF(@col=0,
    'ALTER TABLE time_entries
       ADD COLUMN bill_extracted_at  DATETIME NULL,
       ADD COLUMN bill_extracted_ref BIGINT UNSIGNED NULL,
       ADD COLUMN bill_extracted_by_user_id BIGINT UNSIGNED NULL,
       ADD INDEX idx_te_bill_ready (tenant_id, status, bill_extracted_at),
       ADD INDEX idx_te_bill_ref (tenant_id, bill_extracted_ref)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ap (vendor 1099/C2C) extract
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='time_entries' AND COLUMN_NAME='ap_extracted_at');
SET @sql := IF(@col=0,
    'ALTER TABLE time_entries
       ADD COLUMN ap_extracted_at    DATETIME NULL,
       ADD COLUMN ap_extracted_ref   BIGINT UNSIGNED NULL,
       ADD COLUMN ap_extracted_by_user_id BIGINT UNSIGNED NULL,
       ADD INDEX idx_te_ap_ready (tenant_id, status, ap_extracted_at),
       ADD INDEX idx_te_ap_ref (tenant_id, ap_extracted_ref)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- payroll (W-2 employee) extract
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='time_entries' AND COLUMN_NAME='payroll_extracted_at');
SET @sql := IF(@col=0,
    'ALTER TABLE time_entries
       ADD COLUMN payroll_extracted_at  DATETIME NULL,
       ADD COLUMN payroll_extracted_ref BIGINT UNSIGNED NULL,
       ADD COLUMN payroll_extracted_by_user_id BIGINT UNSIGNED NULL,
       ADD INDEX idx_te_payroll_ready (tenant_id, status, payroll_extracted_at),
       ADD INDEX idx_te_payroll_ref (tenant_id, payroll_extracted_ref)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
