-- First-class reconciliation artifacts for clean installs.
--
-- Core migration 145 also upgrades established installations, but core
-- migrations run before module migrations on a fresh database. This module
-- migration owns the same invariant after accounting_reconciliations exists.

SET @rec_exists := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'accounting_reconciliations');
SET @ao_exists := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'artifact_objects');
SET @ae_exists := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'artifact_events');
SET @ar_exists := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'artifact_relationships');

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'accounting_reconciliations' AND column_name = 'artifact_id');
SET @sql := IF(@rec_exists = 1 AND @col = 0, 'ALTER TABLE accounting_reconciliations ADD COLUMN artifact_id CHAR(36) NULL AFTER id', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@rec_exists = 1 AND @ao_exists = 1, "UPDATE accounting_reconciliations r JOIN artifact_objects a ON a.tenant_id = r.tenant_id AND a.artifact_type = 'accounting_reconciliation' AND a.source_module = 'accounting' AND a.source_record_type = 'accounting_reconciliation' AND a.source_record_id = r.id SET r.artifact_id = a.id WHERE r.artifact_id IS NULL", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@rec_exists = 1, 'UPDATE accounting_reconciliations SET artifact_id = LOWER(UUID()) WHERE artifact_id IS NULL OR artifact_id = ""', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@rec_exists = 1 AND @ao_exists = 1, "INSERT IGNORE INTO artifact_objects (id, tenant_id, artifact_type, title, status, version, source_module, source_record_type, source_record_id, payload_json, created_at, updated_at) SELECT r.artifact_id, r.tenant_id, 'accounting_reconciliation', CONCAT('Bank reconciliation #', r.id, ' through ', r.period_end), CASE WHEN r.status = 'closed' THEN 'approved' ELSE 'draft' END, 1, 'accounting', 'accounting_reconciliation', r.id, JSON_OBJECT('reconciliation_id', r.id, 'bank_account_id', r.bank_account_id, 'period_end', r.period_end, 'statement_balance', r.statement_balance, 'gl_balance', r.gl_balance, 'difference', r.difference, 'status', r.status), r.created_at, COALESCE(r.closed_at, r.created_at) FROM accounting_reconciliations r LEFT JOIN artifact_objects a ON a.id = r.artifact_id WHERE a.id IS NULL", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@rec_exists = 1 AND @ae_exists = 1, "INSERT INTO artifact_events (tenant_id, artifact_id, event_type, prior_status, new_status, payload, created_at) SELECT r.tenant_id, r.artifact_id, 'created', NULL, CASE WHEN r.status = 'closed' THEN 'approved' ELSE 'draft' END, JSON_OBJECT('artifact_type', 'accounting_reconciliation', 'backfilled', TRUE), r.created_at FROM accounting_reconciliations r WHERE NOT EXISTS (SELECT 1 FROM artifact_events e WHERE e.tenant_id = r.tenant_id AND e.artifact_id = r.artifact_id AND e.event_type = 'created')", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@rec_exists = 1 AND @ar_exists = 1, "INSERT INTO artifact_relationships (tenant_id, source_artifact_id, target_table, target_record_id, relationship_type, created_at) SELECT r.tenant_id, r.artifact_id, 'accounting_reconciliations', r.id, 'represents', r.created_at FROM accounting_reconciliations r WHERE NOT EXISTS (SELECT 1 FROM artifact_relationships x WHERE x.tenant_id = r.tenant_id AND x.source_artifact_id = r.artifact_id AND x.target_table = 'accounting_reconciliations' AND x.target_record_id = r.id AND x.relationship_type = 'represents')", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'accounting_reconciliations' AND index_name = 'uq_arec_tenant_artifact');
SET @sql := IF(@rec_exists = 1 AND @idx = 0, 'ALTER TABLE accounting_reconciliations ADD UNIQUE KEY uq_arec_tenant_artifact (tenant_id, artifact_id)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
