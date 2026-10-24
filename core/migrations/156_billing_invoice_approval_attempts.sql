-- Keep rejected Billing invoice reviews, but allow their draft to enter a new
-- review round. All other states and subjects retain their original
-- one-instance-per-subject constraint. MariaDB UNIQUE permits multiple NULLs.
ALTER TABLE workflow_instances
    ADD COLUMN unique_subject_id BIGINT UNSIGNED
        GENERATED ALWAYS AS (
            CASE WHEN subject_type = 'billing_invoice' AND status = 'rejected'
                THEN NULL ELSE subject_id END
        ) STORED,
    ADD UNIQUE KEY uq_wfi_live_subject (tenant_id, subject_type, unique_subject_id),
    ADD INDEX idx_wfi_subject_history (tenant_id, subject_type, subject_id, id),
    DROP INDEX uq_wfi_subject;
