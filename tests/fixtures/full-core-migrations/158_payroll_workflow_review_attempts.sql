-- Payroll recompute cancels its prior review and opens a new approval round.
-- Preserve the completed review history while reserving uniqueness for a
-- live or approved workflow. Billing's rejected-draft retry remains intact.
ALTER TABLE workflow_instances
    MODIFY COLUMN unique_subject_id BIGINT UNSIGNED
        GENERATED ALWAYS AS (
            CASE
                WHEN subject_type = 'billing_invoice' AND status = 'rejected' THEN NULL
                WHEN subject_type = 'payroll_run' AND status IN ('cancelled', 'rejected', 'expired') THEN NULL
                ELSE subject_id
            END
        ) STORED;
