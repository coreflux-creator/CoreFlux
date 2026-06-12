<?php
/**
 * Placement rate snapshot approval primitives.
 *
 * API call sites must route approvals through modules/placements/lib/workflow.php.
 * The low-level writer below is kept as the single snapshot-lock primitive used
 * by WorkflowEngine sync after a WorkflowGraph decision is approved.
 */
declare(strict_types=1);

require_once __DIR__ . '/placements.php';

if (!function_exists('placementsRateApproveOne')) {
    /**
     * Approve one placement_rates row inside its own transaction.
     * Compatibility wrapper for tenant-scoped API requests.
     *
     * @return array{margin: array, superseded_count: int}
     */
    function placementsRateApproveOne(int $rateId, array $user, bool $isCorrection, ?string $correctionReason): array
    {
        return placementsRateApproveOneForTenant(currentTenantId(), $rateId, $user, $isCorrection, $correctionReason);
    }
}

if (!function_exists('placementsRateApproveOneForTenant')) {
    /**
     * Tenant-explicit snapshot writer used by WorkflowEngine sync.
     *
     * @return array{margin: array, superseded_count: int}
     */
    function placementsRateApproveOneForTenant(int $tenantId, int $rateId, array $user, bool $isCorrection, ?string $correctionReason): array
    {
        $pdo = getDB();
        if (!$pdo) throw new \RuntimeException('No DB');

        $rateStmt = $pdo->prepare('SELECT * FROM placement_rates WHERE tenant_id = :tenant_id AND id = :id');
        $rateStmt->execute(['tenant_id' => $tenantId, 'id' => $rateId]);
        $rate = $rateStmt->fetch(\PDO::FETCH_ASSOC);
        if (!$rate)               throw new \RuntimeException("Rate {$rateId} not found");
        if ($rate['approved_at']) throw new \RuntimeException("Rate {$rateId} already approved");

        $chainStmt = $pdo->prepare(
            'SELECT id, tenant_id, placement_id, position, party_name, party_role,
                    vendor_portal_id, portal_fee_pct, portal_fee_flat
               FROM placement_client_chain
              WHERE tenant_id = :tenant_id AND placement_id = :pid
              ORDER BY position'
        );
        $chainStmt->execute(['tenant_id' => $tenantId, 'pid' => (int) $rate['placement_id']]);
        $chain = $chainStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $margin = placementsComputeMargin($rate, $chain);

        $pdo->beginTransaction();
        try {
            // Close prior approved row covering this effective_from.
            $stmt = $pdo->prepare(
                "UPDATE placement_rates
                 SET effective_to = DATE_SUB(:eff_set, INTERVAL 1 DAY),
                     superseded_by = :new_id_set
                 WHERE tenant_id = :tenant_id AND placement_id = :pid
                   AND id != :new_id_filter
                   AND approved_at IS NOT NULL
                   AND effective_from <= :eff_lt
                   AND (effective_to IS NULL OR effective_to >= :eff_gt)"
            );
            $stmt->execute([
                'eff_set'       => $rate['effective_from'],
                'eff_lt'        => $rate['effective_from'],
                'eff_gt'        => $rate['effective_from'],
                'new_id_set'    => $rateId,
                'new_id_filter' => $rateId,
                'tenant_id'     => $tenantId,
                'pid'           => $rate['placement_id'],
            ]);
            $closed = $stmt->rowCount();

            // Stamp the new row.
            $stmt2 = $pdo->prepare(
                'UPDATE placement_rates SET
                    approved_by_user_id = :uid,
                    approved_at = NOW(),
                    adjusted_bill_rate = :abr,
                    net_to_vendor = :ntv,
                    is_correction = :ic,
                    correction_reason = :reason
                 WHERE tenant_id = :tenant_id AND id = :id'
            );
            $stmt2->execute([
                'uid'       => $user['id'] ?? null,
                'abr'       => $margin['adjusted_bill_rate'],
                'ntv'       => $margin['net_to_vendor'],
                'ic'        => $isCorrection ? 1 : 0,
                'reason'    => $correctionReason,
                'tenant_id' => $tenantId,
                'id'        => $rateId,
            ]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $approvedMeta = [
            'placement_id'         => (int) $rate['placement_id'],
            'rate_id'              => $rateId,
            'effective_from'       => $rate['effective_from'],
            'adjusted_bill_rate'   => $margin['adjusted_bill_rate'],
            'net_to_vendor'        => $margin['net_to_vendor'],
            'total_portal_fee_pct' => $margin['total_portal_fee_pct'],
            'is_correction'        => $isCorrection,
            'correction_reason'    => $correctionReason,
            'superseded_count'     => $closed,
        ];
        $currentTenant = function_exists('currentTenantId') ? (int) currentTenantId() : $tenantId;
        if ($tenantId === $currentTenant) {
            placementsAudit('placement.rate.approved', $approvedMeta, (int) $rate['placement_id']);
        } else {
            placementsRateAuditForTenant($tenantId, (int) ($user['id'] ?? 0), 'placement.rate.approved', $approvedMeta, (int) $rate['placement_id']);
        }

        if ($closed > 0) {
            $supersededMeta = [
                'placement_id' => (int) $rate['placement_id'],
                'by_rate_id' => $rateId,
                'count' => $closed,
            ];
            if ($tenantId === $currentTenant) {
                placementsAudit('placement.rate.superseded', $supersededMeta, (int) $rate['placement_id']);
            } else {
                placementsRateAuditForTenant($tenantId, (int) ($user['id'] ?? 0), 'placement.rate.superseded', $supersededMeta, (int) $rate['placement_id']);
            }
        }

        return ['margin' => $margin, 'superseded_count' => $closed];
    }
}

if (!function_exists('placementsRateAuditForTenant')) {
    function placementsRateAuditForTenant(int $tenantId, ?int $actorUserId, string $event, array $meta = [], ?int $targetId = null): void
    {
        try {
            $pdo = getDB();
            if (!$pdo) return;
            $stmt = $pdo->prepare(
                'INSERT INTO audit_log
                 (tenant_id, actor_user_id, event, target_id, meta_json, ip_address, request_id, created_at)
                 VALUES (:tenant_id, :actor_user_id, :event, :target_id, :meta_json, :ip_address, :request_id, NOW())'
            );
            $stmt->execute([
                'tenant_id'     => $tenantId,
                'actor_user_id' => $actorUserId,
                'event'         => $event,
                'target_id'     => $targetId,
                'meta_json'     => $meta ? json_encode($meta, JSON_UNESCAPED_SLASHES) : null,
                'ip_address'    => $_SERVER['REMOTE_ADDR'] ?? null,
                'request_id'    => $_SERVER['HTTP_X_REQUEST_ID'] ?? null,
            ]);
        } catch (\Throwable $e) {
            error_log("[placements.rate.audit] db-write-failed: " . $e->getMessage() . " event={$event}");
        }
    }
}

if (!function_exists('placementsAutoApproveDraftRates')) {
    /**
     * Attempt to approve every unapproved rate row on a placement through
     * WorkflowGraph. Returns only rows whose workflow completed and snapshot
     * lock was applied; pending/multi-step approvals do not satisfy activation.
     */
    function placementsAutoApproveDraftRates(int $placementId, array $user): int
    {
        require_once __DIR__ . '/workflow.php';

        // Permission check is intentionally soft: rbac_legacy_require() would
        // 403 the whole status change, while activation readiness can explain
        // that approved rate coverage is still missing.
        $canApprove = function_exists('rbac_legacy_can')
            ? rbac_legacy_can($user, 'placements.financials.approve')
            : false;
        if (!$canApprove) {
            placementsAudit('placement.rates.auto_approve_skipped_no_permission', [
                'placement_id' => $placementId,
                'user_id'      => (int) ($user['id'] ?? 0),
                'reason'       => 'rbac_legacy_can(placements.financials.approve)=false',
            ], $placementId);
            return 0;
        }

        $rows = scopedQuery(
            'SELECT id FROM placement_rates
              WHERE tenant_id = :tenant_id AND placement_id = :pid AND approved_at IS NULL
              ORDER BY id ASC',
            ['pid' => $placementId]
        );
        if (!$rows) return 0;

        $count = 0;
        foreach ($rows as $r) {
            try {
                $result = placementsRateWorkflowAct(currentTenantId(), (int) $r['id'], $user, false, null, 'auto_approve');
                if (!empty($result['approved'])) {
                    $count++;
                } else {
                    placementsAudit('placement.rate.auto_approve_pending_workflow', [
                        'placement_id' => $placementId,
                        'rate_id'      => (int) $r['id'],
                        'workflow_instance_id' => $result['instance']['id'] ?? null,
                        'workflow_status' => $result['instance']['status'] ?? null,
                    ], $placementId);
                }
            } catch (\Throwable $e) {
                placementsAudit('placement.rate.auto_approve_failed', [
                    'placement_id' => $placementId,
                    'rate_id'      => (int) $r['id'],
                    'reason'       => $e->getMessage(),
                ], $placementId);
            }
        }
        return $count;
    }
}
