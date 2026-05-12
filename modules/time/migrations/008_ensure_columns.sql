-- Time module — defensive backstop: ensure every canonical `time_entries`
-- column from 001_init.sql exists on this tenant, regardless of how the
-- table got created or drifted.
--
-- v2: every PREPARE / EXECUTE / DEALLOCATE on its own line so the
-- migration runner's split-on-`;`-newline parser handles each via a
-- separate query() call. Multi-statement in one PDO call is unsupported.
--
-- Idempotent: every column add is information_schema-guarded with `DO 0`
-- as the no-op fallback.

SET @te := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'time_entries')
;

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'time_entries' AND column_name = 'tenant_id')
;
SET @sql := IF(@te = 1 AND @col = 0, "ALTER TABLE time_entries ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0", 'DO 0')
;
PREPARE s FROM @sql
;
EXECUTE s
;
DEALLOCATE PREPARE s
;

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'time_entries' AND column_name = 'placement_id')
;
SET @sql := IF(@te = 1 AND @col = 0, "ALTER TABLE time_entries ADD COLUMN placement_id BIGINT UNSIGNED NULL", 'DO 0')
;
PREPARE s FROM @sql
;
EXECUTE s
;
DEALLOCATE PREPARE s
;

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'time_entries' AND column_name = 'person_id')
;
SET @sql := IF(@te = 1 AND @col = 0, "ALTER TABLE time_entries ADD COLUMN person_id BIGINT UNSIGNED NULL", 'DO 0')
;
PREPARE s FROM @sql
;
EXECUTE s
;
DEALLOCATE PREPARE s
;

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'time_entries' AND column_name = 'period_id')
;
SET @sql := IF(@te = 1 AND @col = 0, "ALTER TABLE time_entries ADD COLUMN period_id BIGINT UNSIGNED NULL", 'DO 0')
;
PREPARE s FROM @sql
;
EXECUTE s
;
DEALLOCATE PREPARE s
;

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'time_entries' AND column_name = 'work_date')
;
SET @sql := IF(@te = 1 AND @col = 0, "ALTER TABLE time_entries ADD COLUMN work_date DATE NULL", 'DO 0')
;
PREPARE s FROM @sql
;
EXECUTE s
;
DEALLOCATE PREPARE s
;

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'time_entries' AND column_name = 'category')
;
SET @sql := IF(@te = 1 AND @col = 0, "ALTER TABLE time_entries ADD COLUMN category ENUM('regular_billable','regular_nonbillable','OT_billable','OT_nonbillable','holiday','vacation','sick','bereavement','unpaid_leave','custom') NOT NULL DEFAULT 'regular_billable'", 'DO 0')
;
PREPARE s FROM @sql
;
EXECUTE s
;
DEALLOCATE PREPARE s
;

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'time_entries' AND column_name = 'hours')
;
SET @sql := IF(@te = 1 AND @col = 0, "ALTER TABLE time_entries ADD COLUMN hours DECIMAL(6,2) NOT NULL DEFAULT 0", 'DO 0')
;
PREPARE s FROM @sql
;
EXECUTE s
;
DEALLOCATE PREPARE s
;

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'time_entries' AND column_name = 'description')
;
SET @sql := IF(@te = 1 AND @col = 0, "ALTER TABLE time_entries ADD COLUMN description VARCHAR(500) NULL", 'DO 0')
;
PREPARE s FROM @sql
;
EXECUTE s
;
DEALLOCATE PREPARE s
;

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'time_entries' AND column_name = 'source')
;
SET @sql := IF(@te = 1 AND @col = 0, "ALTER TABLE time_entries ADD COLUMN source ENUM('ai_inbox','bulk_upload','manual_entry','client_portal_paste') NOT NULL DEFAULT 'manual_entry'", 'DO 0')
;
PREPARE s FROM @sql
;
EXECUTE s
;
DEALLOCATE PREPARE s
;

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'time_entries' AND column_name = 'status')
;
SET @sql := IF(@te = 1 AND @col = 0, "ALTER TABLE time_entries ADD COLUMN status ENUM('draft','pending_review','approved','rejected','superseded') NOT NULL DEFAULT 'draft'", 'DO 0')
;
PREPARE s FROM @sql
;
EXECUTE s
;
DEALLOCATE PREPARE s
;

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'time_entries' AND column_name = 'created_by_user_id')
;
SET @sql := IF(@te = 1 AND @col = 0, "ALTER TABLE time_entries ADD COLUMN created_by_user_id BIGINT UNSIGNED NULL", 'DO 0')
;
PREPARE s FROM @sql
;
EXECUTE s
;
DEALLOCATE PREPARE s
;

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'time_entries' AND column_name = 'approved_by_user_id')
;
SET @sql := IF(@te = 1 AND @col = 0, "ALTER TABLE time_entries ADD COLUMN approved_by_user_id BIGINT UNSIGNED NULL", 'DO 0')
;
PREPARE s FROM @sql
;
EXECUTE s
;
DEALLOCATE PREPARE s
;

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'time_entries' AND column_name = 'approved_at')
;
SET @sql := IF(@te = 1 AND @col = 0, "ALTER TABLE time_entries ADD COLUMN approved_at DATETIME NULL", 'DO 0')
;
PREPARE s FROM @sql
;
EXECUTE s
;
DEALLOCATE PREPARE s
;

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'time_entries' AND column_name = 'approved_via')
;
SET @sql := IF(@te = 1 AND @col = 0, "ALTER TABLE time_entries ADD COLUMN approved_via ENUM('manual','tokenized_client_email','bulk_pre_approved') NULL", 'DO 0')
;
PREPARE s FROM @sql
;
EXECUTE s
;
DEALLOCATE PREPARE s
;

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'time_entries' AND column_name = 'rejected_reason')
;
SET @sql := IF(@te = 1 AND @col = 0, "ALTER TABLE time_entries ADD COLUMN rejected_reason VARCHAR(500) NULL", 'DO 0')
;
PREPARE s FROM @sql
;
EXECUTE s
;
DEALLOCATE PREPARE s
;

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'time_entries' AND column_name = 'rate_snapshot_id')
;
SET @sql := IF(@te = 1 AND @col = 0, "ALTER TABLE time_entries ADD COLUMN rate_snapshot_id BIGINT UNSIGNED NULL", 'DO 0')
;
PREPARE s FROM @sql
;
EXECUTE s
;
DEALLOCATE PREPARE s
;

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'time_entries' AND column_name = 'source_ref_id')
;
SET @sql := IF(@te = 1 AND @col = 0, "ALTER TABLE time_entries ADD COLUMN source_ref_id BIGINT UNSIGNED NULL", 'DO 0')
;
PREPARE s FROM @sql
;
EXECUTE s
;
DEALLOCATE PREPARE s
;

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'time_entries' AND column_name = 'superseded_by_id')
;
SET @sql := IF(@te = 1 AND @col = 0, "ALTER TABLE time_entries ADD COLUMN superseded_by_id BIGINT UNSIGNED NULL", 'DO 0')
;
PREPARE s FROM @sql
;
EXECUTE s
;
DEALLOCATE PREPARE s
;

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'time_entries' AND column_name = 'correction_reason')
;
SET @sql := IF(@te = 1 AND @col = 0, "ALTER TABLE time_entries ADD COLUMN correction_reason VARCHAR(500) NULL", 'DO 0')
;
PREPARE s FROM @sql
;
EXECUTE s
;
DEALLOCATE PREPARE s
;

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'time_entries' AND column_name = 'custom_category_id')
;
SET @sql := IF(@te = 1 AND @col = 0, "ALTER TABLE time_entries ADD COLUMN custom_category_id BIGINT UNSIGNED NULL", 'DO 0')
;
PREPARE s FROM @sql
;
EXECUTE s
;
DEALLOCATE PREPARE s
;

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'time_entries' AND column_name = 'client_approver_email')
;
SET @sql := IF(@te = 1 AND @col = 0, "ALTER TABLE time_entries ADD COLUMN client_approver_email VARCHAR(255) NULL", 'DO 0')
;
PREPARE s FROM @sql
;
EXECUTE s
;
DEALLOCATE PREPARE s
;

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'time_entries' AND column_name = 'created_at')
;
SET @sql := IF(@te = 1 AND @col = 0, "ALTER TABLE time_entries ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP", 'DO 0')
;
PREPARE s FROM @sql
;
EXECUTE s
;
DEALLOCATE PREPARE s
;

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'time_entries' AND column_name = 'updated_at')
;
SET @sql := IF(@te = 1 AND @col = 0, "ALTER TABLE time_entries ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP", 'DO 0')
;
PREPARE s FROM @sql
;
EXECUTE s
;
DEALLOCATE PREPARE s
;
