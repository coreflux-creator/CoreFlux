<?php
/**
 * Accounting JE <-> WorkflowEngine sync.
 *
 * WorkflowGraph owns the approval decision. Accounting owns the JE source row,
 * approval evidence, and posting controls.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/workflow.php';

function accountingSyncJournalEntryFromWorkflow(
    int $tenantId,
    int $jeId,
    string $action,
    ?int $userId,
    string $instanceStatus,
    ?string $comment = null
): void {
    try {
        $je = accountingJeWorkflowRow($tenantId, $jeId);
        if (!$je) return;

        if ($action === 'reject' && $userId) {
            getDB()->prepare(
                "UPDATE accounting_journal_entries
                    SET approval_state = 'rejected',
                        rejected_by_user_id = :u,
                        rejected_at = NOW(),
                        rejection_reason = COALESCE(:reason, rejection_reason)
                  WHERE tenant_id = :t
                    AND id = :id
                    AND status = 'draft'
                    AND approval_state IN ('draft','pending_approval','rejected')"
            )->execute([
                'u' => $userId,
                'reason' => $comment ?: 'Rejected through workflow',
                't' => $tenantId,
                'id' => $jeId,
            ]);

            accountingWorkflowAudit($tenantId, $userId, 'accounting.je.rejected', [
                'je_id' => $jeId,
                'je_number' => $je['je_number'] ?? null,
                'rejected_by_user_id' => $userId,
                'reason' => $comment ?: 'Rejected through workflow',
                'source' => 'workflow',
            ], $jeId);
            return;
        }

        if (!in_array($action, ['approve', 'skip'], true) || $instanceStatus !== WORKFLOW_STATUS_APPROVED || !$userId) {
            return;
        }
        if ((string) ($je['approval_state'] ?? '') === 'approved') return;
        if ((string) ($je['status'] ?? '') !== 'draft') return;

        getDB()->prepare(
            "UPDATE accounting_journal_entries
                SET approval_state = 'approved',
                    requires_approval = 1,
                    approved_by_user_id = COALESCE(approved_by_user_id, :u),
                    approved_at = COALESCE(approved_at, NOW()),
                    rejected_by_user_id = NULL,
                    rejected_at = NULL,
                    rejection_reason = NULL
              WHERE tenant_id = :t
                AND id = :id
                AND status = 'draft'
                AND approval_state IN ('draft','pending_approval','rejected')"
        )->execute(['u' => $userId, 't' => $tenantId, 'id' => $jeId]);

        accountingWorkflowAudit($tenantId, $userId, 'accounting.je.approved', [
            'je_id' => $jeId,
            'je_number' => $je['je_number'] ?? null,
            'approved_by_user_id' => $userId,
            'source' => 'workflow',
            'workflow_instance_status' => $instanceStatus,
        ], $jeId);
    } catch (\Throwable $e) {
        error_log('[accounting.je.workflow_sync] sync failed: ' . $e->getMessage());
    }
}
