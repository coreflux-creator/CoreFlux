-- 118_workflow_instances_restartable_subjects.sql
--
-- A business subject can be resubmitted after a rejected/approved workflow.
-- Keep completed workflow_instances as audit history, but let workflowStart()
-- reuse only the active pending instance and create a fresh one afterward.

SET @cf_idx_exists := (
    SELECT COUNT(*)
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'workflow_instances'
       AND index_name = 'uq_wfi_subject'
);
SET @cf_sql := IF(
    @cf_idx_exists > 0,
    'ALTER TABLE workflow_instances DROP INDEX uq_wfi_subject',
    'SELECT 1'
);
PREPARE cf_stmt FROM @cf_sql;
EXECUTE cf_stmt;
DEALLOCATE PREPARE cf_stmt;

SET @cf_idx_exists := (
    SELECT COUNT(*)
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'workflow_instances'
       AND index_name = 'idx_wfi_subject_status'
);
SET @cf_sql := IF(
    @cf_idx_exists = 0,
    'ALTER TABLE workflow_instances ADD INDEX idx_wfi_subject_status (tenant_id, subject_type, subject_id, status, started_at)',
    'SELECT 1'
);
PREPARE cf_stmt FROM @cf_sql;
EXECUTE cf_stmt;
DEALLOCATE PREPARE cf_stmt;
