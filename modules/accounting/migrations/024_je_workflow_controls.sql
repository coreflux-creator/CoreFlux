-- Accounting JE WorkflowGraph controls.
-- Posting status stays draft/posted/reversed/void for compatibility; approval_state
-- carries the manual JE approval lifecycle.

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='accounting_journal_entries' AND COLUMN_NAME='approval_state');
SET @sql := IF(@col=0,"ALTER TABLE accounting_journal_entries ADD COLUMN approval_state ENUM('draft','pending_approval','approved','rejected') NOT NULL DEFAULT 'draft' AFTER created_by_user_id",'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='accounting_journal_entries' AND COLUMN_NAME='requires_approval');
SET @sql := IF(@col=0,'ALTER TABLE accounting_journal_entries ADD COLUMN requires_approval TINYINT(1) NOT NULL DEFAULT 0 AFTER approval_state','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='accounting_journal_entries' AND COLUMN_NAME='workflow_instance_id');
SET @sql := IF(@col=0,'ALTER TABLE accounting_journal_entries ADD COLUMN workflow_instance_id BIGINT UNSIGNED NULL AFTER requires_approval','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='accounting_journal_entries' AND COLUMN_NAME='submitted_by_user_id');
SET @sql := IF(@col=0,'ALTER TABLE accounting_journal_entries ADD COLUMN submitted_by_user_id BIGINT UNSIGNED NULL AFTER workflow_instance_id','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='accounting_journal_entries' AND COLUMN_NAME='submitted_at');
SET @sql := IF(@col=0,'ALTER TABLE accounting_journal_entries ADD COLUMN submitted_at DATETIME NULL AFTER submitted_by_user_id','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='accounting_journal_entries' AND COLUMN_NAME='approved_by_user_id');
SET @sql := IF(@col=0,'ALTER TABLE accounting_journal_entries ADD COLUMN approved_by_user_id BIGINT UNSIGNED NULL AFTER submitted_at','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='accounting_journal_entries' AND COLUMN_NAME='approved_at');
SET @sql := IF(@col=0,'ALTER TABLE accounting_journal_entries ADD COLUMN approved_at DATETIME NULL AFTER approved_by_user_id','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='accounting_journal_entries' AND COLUMN_NAME='rejected_by_user_id');
SET @sql := IF(@col=0,'ALTER TABLE accounting_journal_entries ADD COLUMN rejected_by_user_id BIGINT UNSIGNED NULL AFTER approved_at','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='accounting_journal_entries' AND COLUMN_NAME='rejected_at');
SET @sql := IF(@col=0,'ALTER TABLE accounting_journal_entries ADD COLUMN rejected_at DATETIME NULL AFTER rejected_by_user_id','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='accounting_journal_entries' AND COLUMN_NAME='rejection_reason');
SET @sql := IF(@col=0,'ALTER TABLE accounting_journal_entries ADD COLUMN rejection_reason VARCHAR(500) NULL AFTER rejected_at','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='accounting_journal_entries' AND INDEX_NAME='idx_aje_tenant_approval_state');
SET @sql := IF(@idx=0,'ALTER TABLE accounting_journal_entries ADD INDEX idx_aje_tenant_approval_state (tenant_id, approval_state)','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='accounting_journal_entries' AND INDEX_NAME='idx_aje_workflow');
SET @sql := IF(@idx=0,'ALTER TABLE accounting_journal_entries ADD INDEX idx_aje_workflow (tenant_id, workflow_instance_id)','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
