<?php
/**
 * Treasury money movement <-> WorkflowEngine sync.
 *
 * WorkflowGraph owns the approval decision. Treasury owns payment and transfer
 * lifecycle state, posting prerequisites, and money-movement audit metadata.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/workflow.php';

function treasurySyncPaymentFromWorkflow(
    int $tenantId,
    int $paymentId,
    string $action,
    ?int $userId,
    string $instanceStatus,
    ?string $comment = null
): void {
        $payment = treasuryPaymentWorkflowRow($tenantId, $paymentId);
        if (!$payment) throw new \RuntimeException("Treasury payment {$paymentId} not found");

        if ($action === 'reject' && $userId) {
            getDB()->prepare(
                "UPDATE treasury_payments
                    SET status = 'rejected',
                        failure_reason = COALESCE(:reason, failure_reason),
                        updated_at = NOW()
                  WHERE tenant_id = :t AND id = :id AND status IN ('draft', 'pending_approval')"
            )->execute([
                'reason' => $comment ?: 'Rejected through workflow',
                't' => $tenantId,
                'id' => $paymentId,
            ]);

            $updated = treasuryPaymentWorkflowRow($tenantId, $paymentId) ?? $payment;
            treasuryWorkflowAudit($tenantId, $userId, 'treasury.payment.approval_rejected', [
                'payment_id' => $paymentId,
                'payment_number' => $payment['payment_number'] ?? null,
                'rejected_by_user_id' => $userId,
                'reason' => $comment ?: 'Rejected through workflow',
                'source' => 'workflow',
            ], $paymentId, [
                'before' => $payment,
                'after' => $updated,
            ]);
            return;
        }

        if (!in_array($action, ['approve', 'skip'], true) || $instanceStatus !== WORKFLOW_STATUS_APPROVED) {
            return;
        }
        if ((string) ($payment['status'] ?? '') === 'approved') return;
        if (!in_array((string) ($payment['status'] ?? ''), ['draft', 'pending_approval'], true)) {
            throw new \RuntimeException("Treasury payment {$paymentId} cannot be approved from status {$payment['status']}");
        }

        getDB()->prepare(
            "UPDATE treasury_payments
                SET status = 'approved',
                    approved_by_user_id = COALESCE(approved_by_user_id, :u),
                    approved_at = COALESCE(approved_at, NOW()),
                    failure_reason = NULL,
                    updated_at = NOW()
              WHERE tenant_id = :t AND id = :id AND status IN ('draft', 'pending_approval')"
        )->execute(['u' => $userId, 't' => $tenantId, 'id' => $paymentId]);

        $updated = treasuryPaymentWorkflowRow($tenantId, $paymentId) ?? $payment;
        treasuryWorkflowAudit($tenantId, $userId, 'treasury.payment.approved', [
            'payment_id' => $paymentId,
            'payment_number' => $payment['payment_number'] ?? null,
            'approved_by_user_id' => $userId,
            'source' => 'workflow',
            'workflow_instance_status' => $instanceStatus,
        ], $paymentId, [
            'before' => $payment,
            'after' => $updated,
        ]);
}

function treasurySyncTransferFromWorkflow(
    int $tenantId,
    int $transferId,
    string $action,
    ?int $userId,
    string $instanceStatus,
    ?string $comment = null
): void {
        $transfer = treasuryTransferWorkflowRow($tenantId, $transferId);
        if (!$transfer) throw new \RuntimeException("Treasury transfer {$transferId} not found");

        if ($action === 'reject' && $userId) {
            getDB()->prepare(
                "UPDATE treasury_transfers
                    SET status = 'rejected',
                        failure_reason = COALESCE(:reason, failure_reason),
                        updated_at = NOW()
                  WHERE tenant_id = :t AND id = :id AND status IN ('draft', 'pending_approval')"
            )->execute([
                'reason' => $comment ?: 'Rejected through workflow',
                't' => $tenantId,
                'id' => $transferId,
            ]);

            $updated = treasuryTransferWorkflowRow($tenantId, $transferId) ?? $transfer;
            treasuryWorkflowAudit($tenantId, $userId, 'treasury.transfer.approval_rejected', [
                'transfer_id' => $transferId,
                'transfer_number' => $transfer['transfer_number'] ?? null,
                'rejected_by_user_id' => $userId,
                'reason' => $comment ?: 'Rejected through workflow',
                'source' => 'workflow',
            ], $transferId, [
                'before' => $transfer,
                'after' => $updated,
            ]);
            return;
        }

        if (!in_array($action, ['approve', 'skip'], true) || $instanceStatus !== WORKFLOW_STATUS_APPROVED) {
            return;
        }
        if ((string) ($transfer['status'] ?? '') === 'approved') return;
        if (!in_array((string) ($transfer['status'] ?? ''), ['draft', 'pending_approval'], true)) {
            throw new \RuntimeException("Treasury transfer {$transferId} cannot be approved from status {$transfer['status']}");
        }

        getDB()->prepare(
            "UPDATE treasury_transfers
                SET status = 'approved',
                    approved_by_user_id = COALESCE(approved_by_user_id, :u),
                    approved_at = COALESCE(approved_at, NOW()),
                    failure_reason = NULL,
                    updated_at = NOW()
              WHERE tenant_id = :t AND id = :id AND status IN ('draft', 'pending_approval')"
        )->execute(['u' => $userId, 't' => $tenantId, 'id' => $transferId]);

        $updated = treasuryTransferWorkflowRow($tenantId, $transferId) ?? $transfer;
        treasuryWorkflowAudit($tenantId, $userId, 'treasury.transfer.approved', [
            'transfer_id' => $transferId,
            'transfer_number' => $transfer['transfer_number'] ?? null,
            'approved_by_user_id' => $userId,
            'source' => 'workflow',
            'workflow_instance_status' => $instanceStatus,
        ], $transferId, [
            'before' => $transfer,
            'after' => $updated,
        ]);
}
