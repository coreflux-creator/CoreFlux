-- Recover decisions hidden by the former one-reviewer-per-step key.
INSERT INTO ap_bill_approvals
    (tenant_id, bill_id, step_no, approver_user_id, state, decision_at, decision_note)
SELECT wi.tenant_id, wi.subject_id, a.step_no, a.actor_user_id,
       IF(a.action = 'reject', 'rejected', 'approved'), a.acted_at, LEFT(a.comment, 500)
  FROM workflow_step_actions a
  JOIN workflow_instances wi ON wi.id = a.instance_id AND wi.tenant_id = a.tenant_id
  JOIN ap_bills b ON b.id = wi.subject_id AND b.tenant_id = wi.tenant_id
 WHERE wi.subject_type = 'ap_bill'
   AND a.action IN ('approve', 'reject')
   AND a.actor_user_id IS NOT NULL
 ORDER BY a.id ASC
ON DUPLICATE KEY UPDATE
    state = VALUES(state),
    decision_at = VALUES(decision_at),
    decision_note = VALUES(decision_note);

-- An unchosen reviewer has not approved or rejected a completed bill.
UPDATE ap_bill_approvals a
  JOIN ap_bills b ON b.id = a.bill_id AND b.tenant_id = a.tenant_id
   SET a.state = 'superseded'
 WHERE a.state = 'pending'
   AND b.status IN ('approved','partially_paid','paid','disputed','void');

-- The generic workflow may already have advanced beyond a stale legacy step.
UPDATE ap_bill_approvals a
  JOIN workflow_instances wi ON wi.tenant_id = a.tenant_id
      AND wi.subject_type = 'ap_bill' AND wi.subject_id = a.bill_id
   SET a.state = 'superseded'
 WHERE a.state = 'pending' AND wi.status = 'pending'
   AND a.step_no < wi.current_step;
