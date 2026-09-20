-- First-class payroll-run artifacts for clean installs.
--
-- Core migration 145 upgrades established installations. Module migrations
-- run later on an empty database, so Payroll repeats its own idempotent
-- artifact invariant after payroll_runs has been created.

SET @pr_exists := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'payroll_runs');
SET @ao_exists := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'artifact_objects');
SET @ae_exists := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'artifact_events');
SET @ar_exists := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'artifact_relationships');

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'payroll_runs' AND column_name = 'artifact_id');
SET @sql := IF(@pr_exists = 1 AND @col = 0, 'ALTER TABLE payroll_runs ADD COLUMN artifact_id CHAR(36) NULL AFTER id', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@pr_exists = 1 AND @ao_exists = 1, "UPDATE payroll_runs r JOIN artifact_objects a ON a.tenant_id = r.tenant_id AND a.artifact_type = 'payroll_review' AND a.source_module = 'payroll' AND a.source_record_type = 'payroll_run' AND a.source_record_id = r.id SET r.artifact_id = a.id WHERE r.artifact_id IS NULL", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@pr_exists = 1, 'UPDATE payroll_runs SET artifact_id = LOWER(UUID()) WHERE artifact_id IS NULL OR artifact_id = ""', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@pr_exists = 1 AND @ao_exists = 1, "INSERT IGNORE INTO artifact_objects (id, tenant_id, artifact_type, title, status, version, source_module, source_record_type, source_record_id, payload_json, created_by_user_id, created_at, updated_at) SELECT r.artifact_id, r.tenant_id, 'payroll_review', CONCAT('Payroll run #', r.id), CASE r.status WHEN 'computed' THEN 'review' WHEN 'approved' THEN 'approved' WHEN 'paid' THEN 'final' WHEN 'voided' THEN 'archived' ELSE 'draft' END, 1, 'payroll', 'payroll_run', r.id, JSON_OBJECT('run_id', r.id, 'pay_period_id', r.pay_period_id, 'run_type', r.run_type, 'status', r.status, 'employee_count', r.employee_count, 'gross_total_cents', r.gross_total_cents, 'net_total_cents', r.net_total_cents), r.created_by_user_id, r.created_at, COALESCE(r.updated_at, r.created_at) FROM payroll_runs r LEFT JOIN artifact_objects a ON a.id = r.artifact_id WHERE a.id IS NULL", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@pr_exists = 1 AND @ae_exists = 1, "INSERT INTO artifact_events (tenant_id, artifact_id, event_type, prior_status, new_status, actor_user_id, payload, created_at) SELECT r.tenant_id, r.artifact_id, 'created', NULL, CASE r.status WHEN 'computed' THEN 'review' WHEN 'approved' THEN 'approved' WHEN 'paid' THEN 'final' WHEN 'voided' THEN 'archived' ELSE 'draft' END, r.created_by_user_id, JSON_OBJECT('artifact_type', 'payroll_review', 'backfilled', TRUE), r.created_at FROM payroll_runs r WHERE NOT EXISTS (SELECT 1 FROM artifact_events e WHERE e.tenant_id = r.tenant_id AND e.artifact_id = r.artifact_id AND e.event_type = 'created')", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@pr_exists = 1 AND @ar_exists = 1, "INSERT INTO artifact_relationships (tenant_id, source_artifact_id, target_table, target_record_id, relationship_type, created_by_user_id, created_at) SELECT r.tenant_id, r.artifact_id, 'payroll_runs', r.id, 'represents', r.created_by_user_id, r.created_at FROM payroll_runs r WHERE NOT EXISTS (SELECT 1 FROM artifact_relationships x WHERE x.tenant_id = r.tenant_id AND x.source_artifact_id = r.artifact_id AND x.target_table = 'payroll_runs' AND x.target_record_id = r.id AND x.relationship_type = 'represents')", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'payroll_runs' AND index_name = 'uq_prun_tenant_artifact');
SET @sql := IF(@pr_exists = 1 AND @idx = 0, 'ALTER TABLE payroll_runs ADD UNIQUE KEY uq_prun_tenant_artifact (tenant_id, artifact_id)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
