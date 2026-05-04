-- =======================================================================
-- Accounting Phase 2 Sprint A.6 — IC split on AP bills
-- -----------------------------------------------------------------------
-- Lets an AP bill post with an intercompany split. When present, the
-- bill's `journal_entry_id` points at the SOURCE leg, and the
-- `intercompany_group_id` links all the entity-specific legs.
-- =======================================================================

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'ap_bills'
               AND COLUMN_NAME  = 'intercompany_group_id');
SET @sql := IF(@col = 0,
    'ALTER TABLE ap_bills ADD COLUMN intercompany_group_id VARCHAR(64) NULL AFTER journal_entry_id, ADD INDEX idx_apb_ic_group (tenant_id, intercompany_group_id)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
