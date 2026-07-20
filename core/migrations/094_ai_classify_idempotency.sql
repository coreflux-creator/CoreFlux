-- 094_ai_classify_idempotency.sql
--
-- AI Tool Gateway - Slice 6: idempotency markers so the
-- transaction-classification cron worker doesn't reprocess
-- already-classified rows.
--
-- Production-safe idempotency: older tenant databases may have
-- accounting_bank_statement_lines.posted_date but not posted_at, and some
-- hosts do not support ALTER TABLE ... ADD COLUMN IF NOT EXISTS. Discover
-- the live schema first, then run only the ALTER that applies.

SET @has_table := (
    SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'accounting_bank_statement_lines'
);
SET @has_ai_col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'accounting_bank_statement_lines'
       AND COLUMN_NAME = 'ai_classified_at'
);
SET @has_posted_at := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'accounting_bank_statement_lines'
       AND COLUMN_NAME = 'posted_at'
);
SET @has_posted_date := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'accounting_bank_statement_lines'
       AND COLUMN_NAME = 'posted_date'
);
SET @after_col := IF(@has_posted_at > 0, ' AFTER posted_at', IF(@has_posted_date > 0, ' AFTER posted_date', ''));
SET @sql := IF(@has_table > 0 AND @has_ai_col = 0,
    CONCAT('ALTER TABLE accounting_bank_statement_lines ADD COLUMN ai_classified_at TIMESTAMP NULL DEFAULT NULL', @after_col),
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_run_col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'accounting_bank_statement_lines'
       AND COLUMN_NAME = 'ai_workflow_run_id'
);
SET @sql := IF(@has_table > 0 AND @has_run_col = 0,
    'ALTER TABLE accounting_bank_statement_lines ADD COLUMN ai_workflow_run_id CHAR(36) NULL DEFAULT NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_ai_col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'accounting_bank_statement_lines'
       AND COLUMN_NAME = 'ai_classified_at'
);
SET @has_idx := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'accounting_bank_statement_lines'
       AND INDEX_NAME = 'ix_abst_ai_pending'
);
SET @sql := IF(@has_table > 0 AND @has_ai_col > 0 AND @has_idx = 0,
    'CREATE INDEX ix_abst_ai_pending ON accounting_bank_statement_lines (tenant_id, ai_classified_at)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_table := (
    SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'mercury_transactions'
);
SET @has_ai_col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'mercury_transactions'
       AND COLUMN_NAME = 'ai_classified_at'
);
SET @has_posted_at := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'mercury_transactions'
       AND COLUMN_NAME = 'posted_at'
);
SET @has_received_at := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'mercury_transactions'
       AND COLUMN_NAME = 'received_at'
);
SET @after_col := IF(@has_posted_at > 0, ' AFTER posted_at', IF(@has_received_at > 0, ' AFTER received_at', ''));
SET @sql := IF(@has_table > 0 AND @has_ai_col = 0,
    CONCAT('ALTER TABLE mercury_transactions ADD COLUMN ai_classified_at TIMESTAMP NULL DEFAULT NULL', @after_col),
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_run_col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'mercury_transactions'
       AND COLUMN_NAME = 'ai_workflow_run_id'
);
SET @sql := IF(@has_table > 0 AND @has_run_col = 0,
    'ALTER TABLE mercury_transactions ADD COLUMN ai_workflow_run_id CHAR(36) NULL DEFAULT NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_ai_col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'mercury_transactions'
       AND COLUMN_NAME = 'ai_classified_at'
);
SET @has_idx := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'mercury_transactions'
       AND INDEX_NAME = 'ix_mtx_ai_pending'
);
SET @sql := IF(@has_table > 0 AND @has_ai_col > 0 AND @has_idx = 0,
    'CREATE INDEX ix_mtx_ai_pending ON mercury_transactions (tenant_id, ai_classified_at)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
