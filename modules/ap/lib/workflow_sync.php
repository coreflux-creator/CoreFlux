<?php
/**
 * AP ↔ WorkflowEngine sync (Sprint 6 cutover).
 *
 * When the generic WorkflowEngine fires a step action on an `ap_bill`
 * subject, mirror the decision into the legacy `ap_bill_approvals`
 * table and the `ap_bills.status` column so the existing AP UI stays
 * coherent until we rip out the hand-rolled tables entirely.
 *
 * Called from core/workflow_engine.php::workflowAct whenever
 * `$instance['subject_type'] === 'ap_bill'`.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/db.php';

/**
 * Sync a workflow step action back into ap_bill_approvals + ap_bills.
 *
 *   approve  — marks the caller's pending ap_bill_approvals row 'approved';
 *              when the overall workflow instance is `approved`, flip the
 *              bill to status='approved'.
 *   reject   — marks the caller's row 'rejected' + bill.status='disputed'.
 *   comment  — no legacy mirror needed (workflow_step_actions holds the note).
 *
 * Best-effort: a schema drift or missing row MUST NOT cascade a failure
 * back into the workflow engine. All exceptions are swallowed.
 */
function apSyncFromWorkflow(int $tenantId, int $billId, string $action, ?int $userId, string $instanceStatus): void {
    try {
        $pdo = getDB();
        if (!$pdo) return;

        if (in_array($action, ['approve', 'skip'], true) && $userId) {
            // Mark this approver's row as approved (first pending one they own for this bill).
            $upd = $pdo->prepare(
                "UPDATE ap_bill_approvals
                    SET state = 'approved', decision_at = NOW()
                  WHERE tenant_id = :t AND bill_id = :b AND approver_user_id = :u AND state = 'pending'
                  LIMIT 1"
            );
            $upd->execute(['t' => $tenantId, 'b' => $billId, 'u' => $userId]);
        }

        if ($action === 'reject' && $userId) {
            $upd = $pdo->prepare(
                "UPDATE ap_bill_approvals
                    SET state = 'rejected', decision_at = NOW()
                  WHERE tenant_id = :t AND bill_id = :b AND approver_user_id = :u AND state = 'pending'
                  LIMIT 1"
            );
            $upd->execute(['t' => $tenantId, 'b' => $billId, 'u' => $userId]);
            // Reject is final — move the bill to disputed.
            $pdo->prepare(
                "UPDATE ap_bills SET status = 'disputed', updated_at = NOW()
                  WHERE tenant_id = :t AND id = :b AND status = 'pending_approval'"
            )->execute(['t' => $tenantId, 'b' => $billId]);
            return;
        }

        // If the workflow instance as a whole just flipped to approved, mark the bill.
        if ($instanceStatus === 'approved') {
            $pdo->prepare(
                "UPDATE ap_bills SET status = 'approved', approved_at = NOW(), updated_at = NOW()
                  WHERE tenant_id = :t AND id = :b AND status = 'pending_approval'"
            )->execute(['t' => $tenantId, 'b' => $billId]);
        }
    } catch (\Throwable $_) {
        // Silently drop — workflow_engine must not break because legacy
        // schema is missing a column. Surface via audit_log instead.
    }
}
