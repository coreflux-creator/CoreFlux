<?php
/**
 * Rate-approve transaction — shared between:
 *   - /api/placements/rates?action=approve       (single approve)
 *   - /api/placements/rates?action=bulk_approve  (queue + post-import)
 *   - /api/placements/placements (?action=bulk_status & PATCH status)
 *
 * The same helper means a CSV-imported placement promoted from draft →
 * active gets EXACTLY the same chain-based margin snapshot + supersede
 * audit as an operator clicking Approve in the per-placement Rates tab.
 *
 * SPEC §4 (margin) and §6.2 (rate approval) — single source of truth.
 */
declare(strict_types=1);

require_once __DIR__ . '/placements.php';

if (!function_exists('placementsRateApproveOne')) {
    /**
     * Approve one placement_rates row inside its own transaction.
     * Throws on failure (caller decides whether to map to HTTP / log /
     * collect into a bulk-result array).
     *
     * @return array{margin: array, superseded_count: int}
     */
    function placementsRateApproveOne(int $rateId, array $user, bool $isCorrection, ?string $correctionReason): array
    {
        $rate = scopedFind('SELECT * FROM placement_rates WHERE tenant_id = :tenant_id AND id = :id', ['id' => $rateId]);
        if (!$rate)               throw new \RuntimeException("Rate {$rateId} not found");
        if ($rate['approved_at']) throw new \RuntimeException("Rate {$rateId} already approved");

        $chain  = placementChain((int) $rate['placement_id']);
        $margin = placementsComputeMargin($rate, $chain);

        $pdo = getDB();
        $pdo->beginTransaction();
        try {
            // Close prior approved row covering this effective_from
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
                'tenant_id'     => currentTenantId(),
                'pid'           => $rate['placement_id'],
            ]);
            $closed = $stmt->rowCount();

            // Stamp the new row
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
                'tenant_id' => currentTenantId(),
                'id'        => $rateId,
            ]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        placementsAudit('placement.rate.approved', [
            'placement_id'         => (int) $rate['placement_id'],
            'rate_id'              => $rateId,
            'effective_from'       => $rate['effective_from'],
            'adjusted_bill_rate'   => $margin['adjusted_bill_rate'],
            'net_to_vendor'        => $margin['net_to_vendor'],
            'total_portal_fee_pct' => $margin['total_portal_fee_pct'],
            'is_correction'        => $isCorrection,
            'correction_reason'    => $correctionReason,
            'superseded_count'     => $closed,
        ], (int) $rate['placement_id']);

        if ($closed > 0) {
            placementsAudit('placement.rate.superseded', [
                'placement_id' => (int) $rate['placement_id'], 'by_rate_id' => $rateId, 'count' => $closed,
            ], (int) $rate['placement_id']);
        }

        return ['margin' => $margin, 'superseded_count' => $closed];
    }
}

if (!function_exists('placementsAutoApproveDraftRates')) {
    /**
     * Approve every unapproved rate row on a placement. Called when the
     * placement transitions out of `draft` — operator complaint: the
     * initial "Approve placement" step should also approve the rates
     * that were imported alongside it, not leave them dangling in a
     * separate queue.
     *
     * Skips silently if the user doesn't have `placements.financials.approve`
     * (we don't want a privilege-escalation side effect when a recruiter
     * with only `placements.manage` promotes a draft). Returns the count
     * of approved rates (0 when skipped or none pending).
     */
    function placementsAutoApproveDraftRates(int $placementId, array $user): int
    {
        // Permission check is intentionally soft — rbac_legacy_can()
        // returns bool; rbac_legacy_require() would 403 the whole
        // status change which is the wrong UX.
        $canApprove = function_exists('rbac_legacy_can')
            ? rbac_legacy_can($user, 'placements.financials.approve')
            : false;
        if (!$canApprove) {
            // Audit the soft skip so an operator wondering "why are
            // these still draft?" can trace it back to a permission
            // issue rather than thinking the feature is broken.
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
                // Initial promotion → not a correction. (There's by
                // definition no prior approved row on a draft.)
                placementsRateApproveOne((int) $r['id'], $user, false, null);
                $count++;
            } catch (\Throwable $e) {
                // Don't abort the whole status change — a single bad
                // rate (e.g. malformed chain) shouldn't prevent the
                // operator from moving the placement out of draft.
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
