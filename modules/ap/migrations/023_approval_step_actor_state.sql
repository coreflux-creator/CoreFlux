-- One approval step can route to several eligible reviewers; only the quorum decides.
ALTER TABLE ap_bill_approvals
    MODIFY COLUMN state ENUM('pending','approved','rejected','superseded') NOT NULL DEFAULT 'pending',
    DROP INDEX uq_apba_step,
    ADD UNIQUE KEY uq_apba_step_actor (tenant_id, bill_id, step_no, approver_user_id);
