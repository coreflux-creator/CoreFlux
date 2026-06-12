<?php
/**
 * Placement rate <-> WorkflowEngine sync.
 *
 * WorkflowGraph owns the approval decision. Placements owns the immutable
 * approved rate snapshot and prior-row supersede mechanics.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/rate_approve.php';
require_once __DIR__ . '/workflow.php';

function placementsSyncRateFromWorkflow(
    int $tenantId,
    int $rateId,
    string $action,
    ?int $userId,
    string $instanceStatus,
    ?string $comment = null
): void {
    try {
        $rate = placementsRateWorkflowRow($tenantId, $rateId);
        if (!$rate) return;
        $placementId = (int) ($rate['placement_id'] ?? 0);

        if ($action === 'reject' && $userId) {
            placementsAudit('placement.rate.approval_rejected', [
                'placement_id' => $placementId,
                'rate_id' => $rateId,
                'rejected_by_user_id' => $userId,
                'reason' => $comment ?: 'Rejected through workflow',
                'source' => 'workflow',
            ], $placementId);
            return;
        }

        if (!in_array($action, ['approve', 'skip'], true) || $instanceStatus !== 'approved' || !$userId) {
            return;
        }
        if (!empty($rate['approved_at'])) return;

        $prior = getDB()->prepare(
            'SELECT id FROM placement_rates
              WHERE tenant_id = :t AND placement_id = :pid
                AND id != :rid AND approved_at IS NOT NULL
              LIMIT 1'
        );
        $prior->execute(['t' => $tenantId, 'pid' => $placementId, 'rid' => $rateId]);
        $hasPriorApproved = (bool) $prior->fetchColumn();
        $isCorrection = $hasPriorApproved || !empty($comment);
        $reason = $comment ?: ($hasPriorApproved ? 'Rate update (auto-detected supersede of prior approved row)' : null);

        placementsRateApproveOneForTenant($tenantId, $rateId, ['id' => $userId], $isCorrection, $reason);
        placementsAudit('placement.rate.workflow_snapshot_locked', [
            'placement_id' => $placementId,
            'rate_id' => $rateId,
            'approved_by_user_id' => $userId,
            'source' => 'workflow',
            'workflow_instance_status' => $instanceStatus,
        ], $placementId);
    } catch (\Throwable $e) {
        error_log('[placements.workflow_sync] sync failed: ' . $e->getMessage());
    }
}
