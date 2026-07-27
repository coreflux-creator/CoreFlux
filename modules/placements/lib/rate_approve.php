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
require_once __DIR__ . '/economics.php';

if (!function_exists('placementsRateApproveOne')) {
    function placementsRateIsUnsafeJobDivaAutoDraft(array $rate): bool
    {
        if (!empty($rate['created_by_user_id'])) return false;
        if (abs((float) ($rate['bill_rate'] ?? 0) - (float) ($rate['pay_rate'] ?? 0)) >= 0.0001) return false;
        if ((float) ($rate['bill_rate'] ?? 0) <= 0) return false;
        try {
            $mapping = scopedFind(
                "SELECT id FROM external_entity_mappings
                  WHERE tenant_id = :tenant_id
                    AND source_system = 'jobdiva'
                    AND internal_entity_type = 'placement'
                    AND internal_entity_id = :pid
                  LIMIT 1",
                ['pid' => (int) ($rate['placement_id'] ?? 0)]
            );
            return (bool) $mapping;
        } catch (\Throwable $e) {
            error_log('[placements rate approve] unsafe JobDiva draft check failed: ' . $e->getMessage());
            return false;
        }
    }

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
        if (placementsRateIsUnsafeJobDivaAutoDraft($rate)) {
            throw new \RuntimeException(
                'JobDiva auto-drafted this rate with identical bill and pay values. Run Repair rates to rebuild from a real pay field, or enter a manual rate before approval.'
            );
        }

        $margin = placementEconomicsApprovalSnapshot((int) currentTenantId(), $rate);

        $pdo = getDB();
        $ownsTxn = cf_tx_begin($pdo);
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
                    economics_snapshot_json = :snapshot,
                    is_correction = :ic,
                    correction_reason = :reason
                 WHERE tenant_id = :tenant_id AND id = :id'
            );
            $stmt2->execute([
                'uid'       => $user['id'] ?? null,
                'abr'       => $margin['adjusted_bill_rate'],
                'ntv'       => $margin['net_to_vendor'],
                'snapshot'  => json_encode($margin['economics_snapshot'], JSON_UNESCAPED_SLASHES),
                'ic'        => $isCorrection ? 1 : 0,
                'reason'    => $correctionReason,
                'tenant_id' => currentTenantId(),
                'id'        => $rateId,
            ]);
            cf_tx_commit($pdo, $ownsTxn);
        } catch (\Throwable $e) {
            cf_tx_rollback($pdo, $ownsTxn);
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

if (!function_exists('placementsEnsureDraftRateFromSourcePayload')) {
    function placementsJobDivaNormaliseExternalId(?string $externalId): string
    {
        $externalId = trim((string) $externalId);
        if ($externalId === '') return '';
        return str_starts_with($externalId, 'jd:') ? substr($externalId, 3) : $externalId;
    }

    function placementsJobDivaSeedPayloadFromBinding(array $placement, ?array $mapping): array
    {
        $payload = [];
        $externalId = placementsJobDivaNormaliseExternalId(
            (string) (($mapping['external_id'] ?? '') ?: ($placement['external_id'] ?? ''))
        );
        if ($externalId !== '') {
            $payload['id'] = $externalId;
            $payload['startId'] = $externalId;
            $payload['start_id'] = $externalId;
            $payload['placementId'] = $externalId;
        }

        $jobId = trim((string) ($placement['jobdiva_job_id'] ?? ''));
        if ($jobId !== '') {
            $payload['jobID'] = $jobId;
            $payload['jobId'] = $jobId;
            $payload['job_id'] = $jobId;
        }

        return $payload;
    }

    /**
     * Repair an imported placement that has source data but no approvable
     * placement_rates row. This closes the dead-end where activation requires
     * an approved rate while the Draft Rates queue is empty.
     *
     * Current implementation supports JobDiva placements because their full
     * placement payload is persisted in external_entity_mappings and the
     * JobDiva syncer already owns the canonical rate resolution rules.
     */
    function placementsEnsureDraftRateFromSourcePayload(int $placementId, array $user): bool
    {
        if ($placementId <= 0) return false;

        $tenantId = (int) (currentTenantId() ?? 0);
        if ($tenantId <= 0) return false;

        $placement = scopedFind(
            'SELECT id, start_date, external_id, jobdiva_job_id
               FROM placements
              WHERE tenant_id = :tenant_id
                AND id = :id
                AND deleted_at IS NULL',
            ['id' => $placementId]
        );
        if (!$placement) return false;

        $startDate = trim((string) ($placement['start_date'] ?? ''));
        if ($startDate === '') $startDate = date('Y-m-d');

        // Nothing to repair: activation will already pass, or there is an
        // explicit draft row waiting for the normal approval path below.
        if (placementCurrentRate($placementId, $startDate)) return false;
        $draft = scopedFind(
            'SELECT id, bill_rate, pay_rate, created_by_user_id
               FROM placement_rates
              WHERE tenant_id = :tenant_id
                AND placement_id = :pid
                AND approved_at IS NULL
              ORDER BY effective_from DESC, id DESC
              LIMIT 1',
            ['pid' => $placementId]
        );
        if ($draft) {
            $unsafeAutoDraft = empty($draft['created_by_user_id'])
                && abs((float) ($draft['pay_rate'] ?? 0) - (float) ($draft['bill_rate'] ?? 0)) < 0.0001;
            if (!$unsafeAutoDraft) return false;
        }

        $mapping = scopedFind(
            "SELECT id, external_id, payload_snapshot
               FROM external_entity_mappings
              WHERE tenant_id = :tenant_id
                AND source_system = 'jobdiva'
                AND internal_entity_type = 'placement'
                AND internal_entity_id = :pid
              ORDER BY CASE WHEN payload_snapshot IS NOT NULL AND payload_snapshot <> '' THEN 0 ELSE 1 END,
                       updated_at DESC, id DESC
              LIMIT 1",
            ['pid' => $placementId]
        );
        if (!$mapping) {
            placementsAudit('placement.rate.auto_draft_from_source_unavailable', [
                'placement_id' => $placementId,
                'source'       => 'jobdiva',
                'reason'       => 'missing_jobdiva_source_binding',
            ], $placementId);
            return false;
        }

        $payload = [];
        if (is_string($mapping['payload_snapshot'] ?? null) && trim((string) $mapping['payload_snapshot']) !== '') {
            $decoded = json_decode((string) $mapping['payload_snapshot'], true);
            if (is_array($decoded)) {
                $payload = $decoded;
            } else {
                placementsAudit('placement.rate.auto_draft_from_source_unavailable', [
                    'placement_id' => $placementId,
                    'source'       => 'jobdiva',
                    'mapping_id'   => (int) ($mapping['id'] ?? 0),
                    'reason'       => 'invalid_payload_snapshot_json_rebuilding_from_binding',
                ], $placementId);
            }
        }

        if (!$payload) {
            $payload = placementsJobDivaSeedPayloadFromBinding($placement, $mapping);
        }

        if (!$payload) {
            placementsAudit('placement.rate.auto_draft_from_source_unavailable', [
                'placement_id' => $placementId,
                'source'       => 'jobdiva',
                'mapping_id'   => (int) ($mapping['id'] ?? 0),
                'reason'       => 'missing_payload_snapshot_and_source_ids',
            ], $placementId);
            return false;
        }

        try {
            require_once __DIR__ . '/../../../core/jobdiva/sync.php';
            if (!function_exists('jobdivaSyncUpsertPlacementRates')) {
                placementsAudit('placement.rate.auto_draft_from_source_failed', [
                    'placement_id' => $placementId,
                    'source'       => 'jobdiva',
                    'reason'       => 'jobdiva_rate_writer_unavailable',
                ], $placementId);
                return false;
            }

            $mirrorStats = [];
            if (function_exists('jobdivaPlacementPayloadWithMirrors')) {
                $payload = jobdivaPlacementPayloadWithMirrors(
                    $tenantId,
                    $payload,
                    $mirrorStats,
                    (string) ($mapping['external_id'] ?? '')
                );
            }

            $drafted = (bool) jobdivaSyncUpsertPlacementRates($tenantId, $placementId, $startDate, $payload);
            if ($drafted) {
                placementsAudit('placement.rate.auto_drafted_from_source', [
                    'placement_id' => $placementId,
                    'source'       => 'jobdiva',
                    'mapping_id'   => (int) ($mapping['id'] ?? 0),
                    'external_id'  => (string) ($mapping['external_id'] ?? ''),
                    'effective_from' => $startDate,
                    'mirror_stats' => $mirrorStats,
                    'user_id'      => (int) ($user['id'] ?? 0),
                ], $placementId);
                return true;
            }

            placementsAudit('placement.rate.auto_draft_from_source_unavailable', [
                'placement_id' => $placementId,
                'source'       => 'jobdiva',
                'mapping_id'   => (int) ($mapping['id'] ?? 0),
                'reason'       => 'payload_has_no_positive_bill_or_pay_rate',
            ], $placementId);
        } catch (\Throwable $e) {
            placementsAudit('placement.rate.auto_draft_from_source_failed', [
                'placement_id' => $placementId,
                'source'       => 'jobdiva',
                'reason'       => $e->getMessage(),
            ], $placementId);
        }

        return false;
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

        placementsEnsureDraftRateFromSourcePayload($placementId, $user);

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
