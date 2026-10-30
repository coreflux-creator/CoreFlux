-- Keep every completed review and permit the next review round, as workflowStart()
-- and the deployed 118 migration require. Only a pending review occupies a slot.
-- This replaces the retired 156/158 variants without rewriting their ledger rows.
-- Duplicate pending reviews require explicit recovery; never discard audit rows.
DROP TEMPORARY TABLE IF EXISTS cf_workflow_pending_identity_guard;
CREATE TEMPORARY TABLE cf_workflow_pending_identity_guard (
    no_duplicate_pending TINYINT NOT NULL,
    CONSTRAINT chk_workflow_pending_identity CHECK (no_duplicate_pending = 1)
);
INSERT INTO cf_workflow_pending_identity_guard (no_duplicate_pending)
SELECT CASE WHEN EXISTS (
    SELECT 1 FROM workflow_instances
    WHERE status = 'pending'
    GROUP BY tenant_id, subject_type, subject_id
    HAVING COUNT(*) > 1
) THEN 0 ELSE 1 END;
DROP TEMPORARY TABLE cf_workflow_pending_identity_guard;

ALTER TABLE workflow_instances
    ADD COLUMN IF NOT EXISTS unique_subject_id BIGINT UNSIGNED
        GENERATED ALWAYS AS (CASE WHEN status = 'pending' THEN subject_id ELSE NULL END) STORED;
ALTER TABLE workflow_instances
    MODIFY COLUMN unique_subject_id BIGINT UNSIGNED
        GENERATED ALWAYS AS (CASE WHEN status = 'pending' THEN subject_id ELSE NULL END) STORED;
ALTER TABLE workflow_instances
    DROP INDEX IF EXISTS uq_wfi_subject,
    DROP INDEX IF EXISTS uq_wfi_live_subject,
    ADD UNIQUE KEY uq_wfi_live_subject (tenant_id, subject_type, unique_subject_id),
    ADD INDEX IF NOT EXISTS idx_wfi_subject_history (tenant_id, subject_type, subject_id, id);
