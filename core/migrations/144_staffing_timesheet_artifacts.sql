-- Promote every weekly staffing timesheet into CoreFlux's first-class artifact layer.
--
-- One staffing_timesheets row remains the authoritative domain record for one
-- person/week. artifact_objects supplies its durable UUID, versioned payload,
-- lifecycle, provenance, and immutable event history. Approval credentials stay
-- in approval_tokens and bind to the same staffing_timesheet header.

SET @sts_exists := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'staffing_timesheets')
;
SET @ao_exists := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'artifact_objects')
;
SET @ae_exists := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'artifact_events')
;
SET @ar_exists := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'artifact_relationships')
;
SET @te_exists := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'time_entries')
;

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'staffing_timesheets' AND column_name = 'artifact_id')
;
SET @sql := IF(@sts_exists = 1 AND @col = 0, 'ALTER TABLE staffing_timesheets ADD COLUMN artifact_id CHAR(36) NULL AFTER id', 'DO 0')
;
PREPARE stmt FROM @sql
;
EXECUTE stmt
;
DEALLOCATE PREPARE stmt
;

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'staffing_timesheets' AND column_name = 'origin_source')
;
SET @sql := IF(@sts_exists = 1 AND @col = 0, "ALTER TABLE staffing_timesheets ADD COLUMN origin_source VARCHAR(40) NOT NULL DEFAULT 'manual_entry' AFTER workflow_instance_id", 'DO 0')
;
PREPARE stmt FROM @sql
;
EXECUTE stmt
;
DEALLOCATE PREPARE stmt
;

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'staffing_timesheets' AND column_name = 'origin_system')
;
SET @sql := IF(@sts_exists = 1 AND @col = 0, 'ALTER TABLE staffing_timesheets ADD COLUMN origin_system VARCHAR(80) NULL AFTER origin_source', 'DO 0')
;
PREPARE stmt FROM @sql
;
EXECUTE stmt
;
DEALLOCATE PREPARE stmt
;

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'staffing_timesheets' AND column_name = 'created_by_user_id')
;
SET @sql := IF(@sts_exists = 1 AND @col = 0, 'ALTER TABLE staffing_timesheets ADD COLUMN created_by_user_id BIGINT UNSIGNED NULL AFTER origin_system', 'DO 0')
;
PREPARE stmt FROM @sql
;
EXECUTE stmt
;
DEALLOCATE PREPARE stmt
;

SET @idx := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'staffing_timesheets' AND index_name = 'uq_sts_tenant_artifact')
;
SET @sql := IF(@sts_exists = 1 AND @idx = 0, 'ALTER TABLE staffing_timesheets ADD UNIQUE KEY uq_sts_tenant_artifact (tenant_id, artifact_id)', 'DO 0')
;
PREPARE stmt FROM @sql
;
EXECUTE stmt
;
DEALLOCATE PREPARE stmt
;

-- Preserve useful provenance for historical headers before assigning artifacts.
SET @sql := IF(@sts_exists = 1 AND @te_exists = 1, "UPDATE staffing_timesheets t SET t.origin_source = CASE WHEN EXISTS (SELECT 1 FROM time_entries te WHERE te.tenant_id = t.tenant_id AND te.timesheet_id = t.id AND te.status != 'superseded' AND te.source = 'bulk_upload') THEN 'bulk_upload' WHEN EXISTS (SELECT 1 FROM time_entries te WHERE te.tenant_id = t.tenant_id AND te.timesheet_id = t.id AND te.status != 'superseded' AND te.source = 'ai_inbox') THEN 'document_import' WHEN EXISTS (SELECT 1 FROM time_entries te WHERE te.tenant_id = t.tenant_id AND te.timesheet_id = t.id AND te.status != 'superseded' AND te.source = 'client_portal_paste') THEN 'client_portal' ELSE 'manual_entry' END, t.created_by_user_id = COALESCE(t.created_by_user_id, (SELECT MIN(te.created_by_user_id) FROM time_entries te WHERE te.tenant_id = t.tenant_id AND te.timesheet_id = t.id)) WHERE t.artifact_id IS NULL", 'DO 0')
;
PREPARE stmt FROM @sql
;
EXECUTE stmt
;
DEALLOCATE PREPARE stmt
;

SET @sql := IF(@sts_exists = 1 AND @te_exists = 0, "UPDATE staffing_timesheets SET origin_source = 'historical_backfill' WHERE artifact_id IS NULL", 'DO 0')
;
PREPARE stmt FROM @sql
;
EXECUTE stmt
;
DEALLOCATE PREPARE stmt
;

SET @sql := IF(@sts_exists = 1, 'UPDATE staffing_timesheets SET artifact_id = LOWER(UUID()) WHERE artifact_id IS NULL OR CHAR_LENGTH(artifact_id) = 0', 'DO 0')
;
PREPARE stmt FROM @sql
;
EXECUTE stmt
;
DEALLOCATE PREPARE stmt
;

-- Backfill the artifact object with the same status and source identity.
SET @sql := IF(@sts_exists = 1 AND @ao_exists = 1, "INSERT IGNORE INTO artifact_objects (id, tenant_id, artifact_type, title, status, version, source_module, source_record_type, source_record_id, payload_json, created_by_user_id, created_at, updated_at) SELECT t.artifact_id, t.tenant_id, 'staffing_timesheet', CONCAT('Timesheet TS-', t.id, ' - week of ', t.period_start), CASE t.status WHEN 'submitted' THEN 'review' WHEN 'approved' THEN 'approved' WHEN 'payroll_ready' THEN 'approved' WHEN 'billing_ready' THEN 'approved' WHEN 'locked' THEN 'final' WHEN 'rejected' THEN 'rejected' ELSE 'draft' END, 1, 'staffing', 'staffing_timesheet', t.id, JSON_OBJECT('timesheet_id', t.id, 'display_id', CONCAT('TS-', t.id), 'person_id', t.person_id, 'period_start', t.period_start, 'period_end', t.period_end, 'status', t.status, 'total_hours', t.total_hours, 'origin_source', t.origin_source, 'origin_system', t.origin_system), t.created_by_user_id, t.created_at, t.updated_at FROM staffing_timesheets t LEFT JOIN artifact_objects ao ON ao.id = t.artifact_id WHERE ao.id IS NULL", 'DO 0')
;
PREPARE stmt FROM @sql
;
EXECUTE stmt
;
DEALLOCATE PREPARE stmt
;

SET @sql := IF(@sts_exists = 1 AND @ae_exists = 1, "INSERT INTO artifact_events (tenant_id, artifact_id, event_type, prior_status, new_status, actor_user_id, payload, created_at) SELECT t.tenant_id, t.artifact_id, 'created', NULL, ao.status, t.created_by_user_id, JSON_OBJECT('artifact_type', 'staffing_timesheet', 'backfilled', TRUE), t.created_at FROM staffing_timesheets t JOIN artifact_objects ao ON ao.id = t.artifact_id WHERE NOT EXISTS (SELECT 1 FROM artifact_events ae WHERE ae.tenant_id = t.tenant_id AND ae.artifact_id = t.artifact_id AND ae.event_type = 'created')", 'DO 0')
;
PREPARE stmt FROM @sql
;
EXECUTE stmt
;
DEALLOCATE PREPARE stmt
;

SET @sql := IF(@sts_exists = 1 AND @ar_exists = 1, "INSERT INTO artifact_relationships (tenant_id, source_artifact_id, target_table, target_record_id, relationship_type, created_by_user_id, created_at) SELECT t.tenant_id, t.artifact_id, 'staffing_timesheets', t.id, 'represents', t.created_by_user_id, t.created_at FROM staffing_timesheets t WHERE NOT EXISTS (SELECT 1 FROM artifact_relationships ar WHERE ar.tenant_id = t.tenant_id AND ar.source_artifact_id = t.artifact_id AND ar.target_table = 'staffing_timesheets' AND ar.target_record_id = t.id AND ar.relationship_type = 'represents')", 'DO 0')
;
PREPARE stmt FROM @sql
;
EXECUTE stmt
;
DEALLOCATE PREPARE stmt
;
