-- Harden durable business outputs and their artifact graph.
-- Idempotent and safe when optional modules are not installed.

SET @ao_exists := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'artifact_objects');
SET @ae_exists := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'artifact_events');
SET @ar_exists := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'artifact_relationships');
SET @apx_exists := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'ap_invoice_extraction_runs');
SET @cfr_exists := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'cash_forecast_runs');
SET @rec_exists := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'accounting_reconciliations');
SET @pr_exists := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'payroll_runs');

-- Artifact pointers must be wide enough for every module's BIGINT identity.
SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'artifact_objects' AND column_name = 'source_record_id' AND data_type <> 'bigint');
SET @sql := IF(@ao_exists = 1 AND @col = 1, 'ALTER TABLE artifact_objects MODIFY COLUMN source_record_id BIGINT UNSIGNED NULL', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'artifact_relationships' AND column_name = 'target_record_id' AND data_type <> 'bigint');
SET @sql := IF(@ar_exists = 1 AND @col = 1, 'ALTER TABLE artifact_relationships MODIFY COLUMN target_record_id BIGINT UNSIGNED NULL', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Durable outputs carry their canonical artifact UUID on the domain row.
SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ap_invoice_extraction_runs' AND column_name = 'artifact_id');
SET @sql := IF(@apx_exists = 1 AND @col = 0, 'ALTER TABLE ap_invoice_extraction_runs ADD COLUMN artifact_id CHAR(36) NULL AFTER source_artifact_id', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'accounting_reconciliations' AND column_name = 'artifact_id');
SET @sql := IF(@rec_exists = 1 AND @col = 0, 'ALTER TABLE accounting_reconciliations ADD COLUMN artifact_id CHAR(36) NULL AFTER id', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'payroll_runs' AND column_name = 'artifact_id');
SET @sql := IF(@pr_exists = 1 AND @col = 0, 'ALTER TABLE payroll_runs ADD COLUMN artifact_id CHAR(36) NULL AFTER id', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Reuse any pre-existing source artifact before assigning a fresh UUID.
SET @sql := IF(@apx_exists = 1 AND @ao_exists = 1, "UPDATE ap_invoice_extraction_runs r JOIN artifact_objects a ON a.tenant_id = r.tenant_id AND a.artifact_type = 'ap_invoice_review' AND a.source_module = 'ap' AND a.source_record_type = 'ap_invoice_extraction_run' AND a.source_record_id = r.id SET r.artifact_id = a.id WHERE r.artifact_id IS NULL", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@cfr_exists = 1 AND @ao_exists = 1, "UPDATE cash_forecast_runs r JOIN artifact_objects a ON a.tenant_id = r.tenant_id AND a.artifact_type = 'cash_forecast' AND a.source_module = 'ai' AND a.source_record_type = 'cash_forecast_run' AND a.source_record_id = r.id SET r.artifact_id = a.id WHERE r.artifact_id IS NULL", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@rec_exists = 1 AND @ao_exists = 1, "UPDATE accounting_reconciliations r JOIN artifact_objects a ON a.tenant_id = r.tenant_id AND a.artifact_type = 'accounting_reconciliation' AND a.source_module = 'accounting' AND a.source_record_type = 'accounting_reconciliation' AND a.source_record_id = r.id SET r.artifact_id = a.id WHERE r.artifact_id IS NULL", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@pr_exists = 1 AND @ao_exists = 1, "UPDATE payroll_runs r JOIN artifact_objects a ON a.tenant_id = r.tenant_id AND a.artifact_type = 'payroll_review' AND a.source_module = 'payroll' AND a.source_record_type = 'payroll_run' AND a.source_record_id = r.id SET r.artifact_id = a.id WHERE r.artifact_id IS NULL", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@apx_exists = 1, 'UPDATE ap_invoice_extraction_runs SET artifact_id = LOWER(UUID()) WHERE artifact_id IS NULL OR artifact_id = ""', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@cfr_exists = 1, 'UPDATE cash_forecast_runs SET artifact_id = LOWER(UUID()) WHERE artifact_id IS NULL OR artifact_id = ""', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@rec_exists = 1, 'UPDATE accounting_reconciliations SET artifact_id = LOWER(UUID()) WHERE artifact_id IS NULL OR artifact_id = ""', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@pr_exists = 1, 'UPDATE payroll_runs SET artifact_id = LOWER(UUID()) WHERE artifact_id IS NULL OR artifact_id = ""', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill artifact objects with status and provenance from the authoritative row.
SET @sql := IF(@apx_exists = 1 AND @ao_exists = 1, "INSERT IGNORE INTO artifact_objects (id, tenant_id, sub_tenant_id, artifact_type, title, status, version, source_module, source_record_type, source_record_id, payload_json, created_by_user_id, created_by_ai_run, created_at, updated_at) SELECT r.artifact_id, r.tenant_id, r.sub_tenant_id, 'ap_invoice_review', CONCAT('Invoice extraction #', r.id, IF(r.source_filename IS NULL, '', CONCAT(' - ', r.source_filename))), CASE r.status WHEN 'extracted' THEN 'review' WHEN 'duplicate' THEN 'rejected' WHEN 'drafted' THEN 'approved' WHEN 'posted' THEN 'final' WHEN 'failed' THEN 'rejected' ELSE 'draft' END, 1, 'ap', 'ap_invoice_extraction_run', r.id, JSON_OBJECT('extraction_run_id', r.id, 'status', r.status, 'source_filename', r.source_filename, 'draft_bill_id', r.draft_bill_id, 'posted_bill_id', r.posted_bill_id), r.created_by_user_id, r.ai_run_id, r.created_at, r.updated_at FROM ap_invoice_extraction_runs r LEFT JOIN artifact_objects a ON a.id = r.artifact_id WHERE a.id IS NULL", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@cfr_exists = 1 AND @ao_exists = 1, "INSERT IGNORE INTO artifact_objects (id, tenant_id, sub_tenant_id, artifact_type, title, status, version, source_module, source_record_type, source_record_id, payload_json, created_by_user_id, created_by_ai_run, created_at, updated_at) SELECT r.artifact_id, r.tenant_id, r.sub_tenant_id, 'cash_forecast', CONCAT('Cash forecast from ', r.starting_at), 'review', 1, 'ai', 'cash_forecast_run', r.id, JSON_OBJECT('forecast_id', r.id, 'starting_at', r.starting_at, 'weeks_count', r.weeks_count, 'currency', r.currency, 'starting_balance_cents', r.starting_balance_cents, 'ending_balance_cents', r.ending_balance_cents, 'min_week_balance_cents', r.min_week_balance_cents), r.created_by_user_id, r.ai_run_id, r.created_at, r.created_at FROM cash_forecast_runs r LEFT JOIN artifact_objects a ON a.id = r.artifact_id WHERE a.id IS NULL", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@rec_exists = 1 AND @ao_exists = 1, "INSERT IGNORE INTO artifact_objects (id, tenant_id, artifact_type, title, status, version, source_module, source_record_type, source_record_id, payload_json, created_at, updated_at) SELECT r.artifact_id, r.tenant_id, 'accounting_reconciliation', CONCAT('Bank reconciliation #', r.id, ' through ', r.period_end), CASE WHEN r.status = 'closed' THEN 'approved' ELSE 'draft' END, 1, 'accounting', 'accounting_reconciliation', r.id, JSON_OBJECT('reconciliation_id', r.id, 'bank_account_id', r.bank_account_id, 'period_end', r.period_end, 'statement_balance', r.statement_balance, 'gl_balance', r.gl_balance, 'difference', r.difference, 'status', r.status), r.created_at, COALESCE(r.closed_at, r.created_at) FROM accounting_reconciliations r LEFT JOIN artifact_objects a ON a.id = r.artifact_id WHERE a.id IS NULL", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@pr_exists = 1 AND @ao_exists = 1, "INSERT IGNORE INTO artifact_objects (id, tenant_id, artifact_type, title, status, version, source_module, source_record_type, source_record_id, payload_json, created_by_user_id, created_at, updated_at) SELECT r.artifact_id, r.tenant_id, 'payroll_review', CONCAT('Payroll run #', r.id), CASE r.status WHEN 'computed' THEN 'review' WHEN 'approved' THEN 'approved' WHEN 'paid' THEN 'final' WHEN 'voided' THEN 'archived' ELSE 'draft' END, 1, 'payroll', 'payroll_run', r.id, JSON_OBJECT('run_id', r.id, 'pay_period_id', r.pay_period_id, 'run_type', r.run_type, 'status', r.status, 'employee_count', r.employee_count, 'gross_total_cents', r.gross_total_cents, 'net_total_cents', r.net_total_cents), r.created_by_user_id, r.created_at, COALESCE(r.updated_at, r.created_at) FROM payroll_runs r LEFT JOIN artifact_objects a ON a.id = r.artifact_id WHERE a.id IS NULL", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Every backfilled artifact receives a created event and a represents edge.
SET @sql := IF(@ao_exists = 1 AND @ae_exists = 1, "INSERT INTO artifact_events (tenant_id, artifact_id, event_type, prior_status, new_status, actor_user_id, actor_ai_run, payload, created_at) SELECT a.tenant_id, a.id, 'created', NULL, a.status, a.created_by_user_id, a.created_by_ai_run, JSON_OBJECT('artifact_type', a.artifact_type, 'backfilled', TRUE), a.created_at FROM artifact_objects a WHERE a.source_module IN ('ap','ai','accounting','payroll') AND a.source_record_type IN ('ap_invoice_extraction_run','cash_forecast_run','accounting_reconciliation','payroll_run') AND NOT EXISTS (SELECT 1 FROM artifact_events e WHERE e.tenant_id = a.tenant_id AND e.artifact_id = a.id AND e.event_type = 'created')", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@apx_exists = 1 AND @ar_exists = 1, "INSERT INTO artifact_relationships (tenant_id, source_artifact_id, target_table, target_record_id, relationship_type, created_by_user_id, created_by_ai_run, created_at) SELECT r.tenant_id, r.artifact_id, 'ap_invoice_extraction_runs', r.id, 'represents', r.created_by_user_id, r.ai_run_id, r.created_at FROM ap_invoice_extraction_runs r WHERE NOT EXISTS (SELECT 1 FROM artifact_relationships x WHERE x.tenant_id = r.tenant_id AND x.source_artifact_id = r.artifact_id AND x.target_table = 'ap_invoice_extraction_runs' AND x.target_record_id = r.id AND x.relationship_type = 'represents')", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@cfr_exists = 1 AND @ar_exists = 1, "INSERT INTO artifact_relationships (tenant_id, source_artifact_id, target_table, target_record_id, relationship_type, created_by_user_id, created_by_ai_run, created_at) SELECT r.tenant_id, r.artifact_id, 'cash_forecast_runs', r.id, 'represents', r.created_by_user_id, r.ai_run_id, r.created_at FROM cash_forecast_runs r WHERE NOT EXISTS (SELECT 1 FROM artifact_relationships x WHERE x.tenant_id = r.tenant_id AND x.source_artifact_id = r.artifact_id AND x.target_table = 'cash_forecast_runs' AND x.target_record_id = r.id AND x.relationship_type = 'represents')", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@rec_exists = 1 AND @ar_exists = 1, "INSERT INTO artifact_relationships (tenant_id, source_artifact_id, target_table, target_record_id, relationship_type, created_at) SELECT r.tenant_id, r.artifact_id, 'accounting_reconciliations', r.id, 'represents', r.created_at FROM accounting_reconciliations r WHERE NOT EXISTS (SELECT 1 FROM artifact_relationships x WHERE x.tenant_id = r.tenant_id AND x.source_artifact_id = r.artifact_id AND x.target_table = 'accounting_reconciliations' AND x.target_record_id = r.id AND x.relationship_type = 'represents')", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@pr_exists = 1 AND @ar_exists = 1, "INSERT INTO artifact_relationships (tenant_id, source_artifact_id, target_table, target_record_id, relationship_type, created_by_user_id, created_at) SELECT r.tenant_id, r.artifact_id, 'payroll_runs', r.id, 'represents', r.created_by_user_id, r.created_at FROM payroll_runs r WHERE NOT EXISTS (SELECT 1 FROM artifact_relationships x WHERE x.tenant_id = r.tenant_id AND x.source_artifact_id = r.artifact_id AND x.target_table = 'payroll_runs' AND x.target_record_id = r.id AND x.relationship_type = 'represents')", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Duplicate edges have no semantic value. Keep the oldest before enforcing keys.
SET @sql := IF(@ar_exists = 1, "DELETE newer FROM artifact_relationships newer JOIN artifact_relationships older ON older.tenant_id = newer.tenant_id AND older.source_artifact_id = newer.source_artifact_id AND older.relationship_type = newer.relationship_type AND older.target_table = newer.target_table AND older.target_record_id = newer.target_record_id AND older.id < newer.id WHERE newer.target_table IS NOT NULL", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@ar_exists = 1, "DELETE newer FROM artifact_relationships newer JOIN artifact_relationships older ON older.tenant_id = newer.tenant_id AND older.source_artifact_id = newer.source_artifact_id AND older.relationship_type = newer.relationship_type AND older.target_artifact_id = newer.target_artifact_id AND older.id < newer.id WHERE newer.target_artifact_id IS NOT NULL", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'artifact_relationships' AND index_name = 'uq_artifact_row_edge');
SET @sql := IF(@ar_exists = 1 AND @idx = 0, 'ALTER TABLE artifact_relationships ADD UNIQUE KEY uq_artifact_row_edge (tenant_id, source_artifact_id, target_table, target_record_id, relationship_type)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @idx := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'artifact_relationships' AND index_name = 'uq_artifact_object_edge');
SET @sql := IF(@ar_exists = 1 AND @idx = 0, 'ALTER TABLE artifact_relationships ADD UNIQUE KEY uq_artifact_object_edge (tenant_id, source_artifact_id, target_artifact_id, relationship_type)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'artifact_objects' AND index_name = 'uq_artifact_source');
-- Do not silently skip this guard when duplicates exist: the ALTER must fail
-- deployment so the conflicting records are reconciled instead of leaving a
-- concurrency hole in production.
SET @sql := IF(@ao_exists = 1 AND @idx = 0, 'ALTER TABLE artifact_objects ADD UNIQUE KEY uq_artifact_source (tenant_id, artifact_type, source_module, source_record_type, source_record_id)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'ap_invoice_extraction_runs' AND index_name = 'uq_apx_tenant_artifact');
SET @sql := IF(@apx_exists = 1 AND @idx = 0, 'ALTER TABLE ap_invoice_extraction_runs ADD UNIQUE KEY uq_apx_tenant_artifact (tenant_id, artifact_id)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @idx := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'cash_forecast_runs' AND index_name = 'uq_cfr_tenant_artifact');
SET @sql := IF(@cfr_exists = 1 AND @idx = 0, 'ALTER TABLE cash_forecast_runs ADD UNIQUE KEY uq_cfr_tenant_artifact (tenant_id, artifact_id)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @idx := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'accounting_reconciliations' AND index_name = 'uq_arec_tenant_artifact');
SET @sql := IF(@rec_exists = 1 AND @idx = 0, 'ALTER TABLE accounting_reconciliations ADD UNIQUE KEY uq_arec_tenant_artifact (tenant_id, artifact_id)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @idx := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'payroll_runs' AND index_name = 'uq_prun_tenant_artifact');
SET @sql := IF(@pr_exists = 1 AND @idx = 0, 'ALTER TABLE payroll_runs ADD UNIQUE KEY uq_prun_tenant_artifact (tenant_id, artifact_id)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
