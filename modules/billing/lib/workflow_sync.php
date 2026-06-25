<?php
/**
 * Billing invoice <-> WorkflowEngine sync.
 *
 * WorkflowGraph owns the approval decision. Billing owns invoice state and
 * invoice-specific audit metadata.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/workflow.php';
require_once __DIR__ . '/billing.php';

function billingSyncInvoiceFromWorkflow(
    int $tenantId,
    int $invoiceId,
    string $action,
    ?int $userId,
    string $instanceStatus,
    ?string $comment = null
): void {
    try {
        $invoice = billingInvoiceWorkflowRow($tenantId, $invoiceId);
        if (!$invoice) return;

        if ($action === 'reject' && $userId) {
            billingWorkflowAudit($tenantId, $userId, 'billing.invoice.approval_rejected', [
                'invoice_id' => $invoiceId,
                'invoice_number' => $invoice['invoice_number'] ?? null,
                'rejected_by_user_id' => $userId,
                'reason' => $comment ?: 'Rejected through workflow',
                'source' => 'workflow',
            ], $invoiceId, [
                'before' => $invoice,
                'after' => $invoice,
            ]);
            return;
        }

        if (!in_array($action, ['approve', 'skip'], true) || $instanceStatus !== WORKFLOW_STATUS_APPROVED || !$userId) {
            return;
        }
        if ((string) ($invoice['status'] ?? '') === 'approved') return;
        if (!billingTransitionAllowed((string) ($invoice['status'] ?? ''), 'approved')) return;

        $pdo = getDB();
        $pdo->prepare(
            "UPDATE billing_invoices
                SET status = 'approved',
                    approved_by_user_id = COALESCE(approved_by_user_id, :u),
                    approved_at = COALESCE(approved_at, NOW()),
                    updated_at = NOW()
              WHERE tenant_id = :t AND id = :id AND status = 'draft'"
        )->execute(['u' => $userId, 't' => $tenantId, 'id' => $invoiceId]);

        $updated = billingInvoiceWorkflowRow($tenantId, $invoiceId) ?? $invoice;
        billingWorkflowAudit($tenantId, $userId, 'billing.invoice.approved', [
            'invoice_id' => $invoiceId,
            'invoice_number' => $invoice['invoice_number'] ?? null,
            'approved_by_user_id' => $userId,
            'source' => 'workflow',
            'workflow_instance_status' => $instanceStatus,
        ], $invoiceId, [
            'before' => $invoice,
            'after' => $updated,
        ]);
    } catch (\Throwable $e) {
        error_log('[billing.workflow_sync] sync failed: ' . $e->getMessage());
    }
}
