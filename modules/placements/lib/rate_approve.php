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
require_once __DIR__.'/native_rates.php';

if (!function_exists('placementsRateApproveOne')) {
    function placementsRateIsUnsafeJobDivaAutoDraft(array $rate): bool
    {
        if (!empty($rate['created_by_user_id'])) return false;
        if (abs((float) ($rate['bill_rate'] ?? 0) - (float) ($rate['pay_rate'] ?? 0)) >= 0.0001) return false;
        if ((float) ($rate['bill_rate'] ?? 0) <= 0) return false;
        $pdo=getDB();
        $q=$pdo->prepare("SELECT m.id FROM external_entity_mappings m
            JOIN placements p ON p.id=m.internal_entity_id AND p.tenant_id=:owner
            WHERE m.source_system = 'jobdiva' AND m.internal_entity_type = 'placement'
              AND m.internal_entity_id = :pid LIMIT 1");
        $q->execute(['owner'=>(int)($rate['tenant_id']??placementsGraphTenantId()),'pid'=>(int)($rate['placement_id']??0)]);
        return (bool)$q->fetchColumn();
    }

    function placementsRateApprovalBlocker(array $rate): ?string
    {
        $bill = (float) ($rate['bill_rate'] ?? 0);
        $pay = (float) ($rate['pay_rate'] ?? 0);
        $billUnit = strtolower((string) ($rate['bill_rate_unit'] ?? 'hour'));
        $payUnit = strtolower((string) ($rate['pay_rate_unit'] ?? 'hour'));
        if ($bill < 0 || $pay < 0) {
            return 'Rates cannot be negative.';
        }
        if (($billUnit === 'hour' && $bill > 5000) || ($payUnit === 'hour' && $pay > 5000)) {
            return 'Hourly rate exceeds $5,000. This looks like an annual amount or an incorrect unit; correct it before approval.';
        }
        if ($bill > 0 && $pay > 0 && $billUnit !== $payUnit) {
            return 'Bill and pay use different units. Convert them to the same unit before approval so margin is calculated correctly.';
        }
        if (placementsRateIsUnsafeJobDivaAutoDraft($rate)) {
            return 'JobDiva supplied identical bill and pay values. Repair the source mapping or enter the correct rate before approval.';
        }
        return null;
    }

    function placementsRateApprovalWarning(array $rate): ?string
    {
        $bill = (float) ($rate['bill_rate'] ?? 0);
        $pay = (float) ($rate['pay_rate'] ?? 0);
        $billUnit = strtolower((string) ($rate['bill_rate_unit'] ?? 'hour'));
        $payUnit = strtolower((string) ($rate['pay_rate_unit'] ?? 'hour'));
        if ($bill > 0 && $pay > $bill) return 'Pay rate exceeds bill rate.';
        if ($bill > 0 && abs($bill - $pay) < 0.0001) return 'Bill and pay rates are identical, leaving no gross spread.';
        return null;
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
        return placementRateApproveLocked((int)currentTenantId(),$rateId,$user,$isCorrection,$correctionReason);
    }
}

if (!function_exists('placementsEnsureDraftRateFromSourcePayload')) {
    function placementsEnsureDraftRateFromSourcePayload(int $placementId, array $user): bool
    {
        if($placementId<=0||(int)currentTenantId()<=0)return false;
        require_once __DIR__.'/../../../core/jobdiva/source_rate_repair.php';
        $result=jobdivaRepairPlacementSourceRate((int)currentTenantId(),$placementId,isset($user['id'])?(int)$user['id']:null);
        return $result['status']==='drafted';
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

        return placementRateWithLockedRows((int)currentTenantId(),$placementId,null,static function()use($placementId,$user):int{
            placementsEnsureDraftRateFromSourcePayload($placementId, $user);
            $q=getDB()->prepare('SELECT id FROM placement_rates WHERE tenant_id=? AND placement_id=? AND approved_at IS NULL ORDER BY effective_from,id FOR UPDATE');
            $q->execute([(int)placementsGraphTenantId(),$placementId]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
            foreach($rows as$r)placementsRateApproveOne((int)$r['id'],$user,false,null);
            return count($rows);
        });
    }
}

if (!function_exists('placementsRequireActiveReady')) {
    /** Validate activation prerequisites for API, bulk, and CSV workflows. */
    function placementsRequireActiveReady(int $placementId, ?string $asOf, string $via): void
    {
        $asOf = $asOf ?: date('Y-m-d');
        $placement = placementAuditRow($placementId);
        $rate = placementCurrentRate($placementId, $asOf);
        if (!$rate) {
            placementsAudit('placement.activation_blocked_missing_rate', [
                'placement_id' => $placementId,
                'as_of' => $asOf,
                'via' => $via,
                'reason' => 'missing_approved_rate_coverage',
            ], $placementId, ['before' => $placement, 'after' => $placement]);
            throw new \RuntimeException(
                "Placement cannot become active without an approved rate covering {$asOf}. Correct and approve a bill/pay rate first.",
                422
            );
        }

        $economics = placementEconomicsContext((int) currentTenantId(), $placementId, true);
        if (empty($economics['available'])) {
            placementsAudit('placement.activation_blocked_economics_unavailable', [
                'placement_id' => $placementId,
                'as_of' => $asOf,
                'via' => $via,
                'errors' => $economics['reconcile']['errors'] ?? [],
            ], $placementId, ['before' => $placement, 'after' => $placement]);
            throw new \RuntimeException(
                'Placement economics are unavailable. Apply the staffing economic graph migration before activation.',
                409
            );
        }

        $readiness = $economics['readiness'] ?? [];
        if (empty($readiness['ready'])) {
            $labels = [
                'missing_receivable_party' => 'client billing recipient',
                'multiple_receivable_parties' => 'one bill-to client',
                'missing_payable_party' => 'worker or vendor payee',
                'missing_labor_payee' => 'primary labor payee',
                'multiple_labor_payees' => 'multiple primary labor payees',
                'missing_c2c_vendor' => 'C2C corporate vendor',
                'missing_referral_payee' => 'referral payout vendor and hourly rate',
                'multiple_referral_payees' => 'one referral payout vendor',
                'missing_billing_cycle' => 'client billing frequency',
                'missing_ap_cycle' => 'vendor payment frequency',
                'missing_payroll_cycle' => 'payroll frequency',
                'missing_ar_payment_terms' => 'client payment terms',
                'missing_ap_payment_terms' => 'vendor payment terms',
            ];
            $blockers = [];
            foreach ($labels as $key => $label) if (!empty($readiness[$key])) $blockers[] = $label;
            if (!empty($readiness['unresolved_parties'])) {
                $blockers[] = (int) $readiness['unresolved_parties'] . ' unresolved payment recipient(s)';
            }
            placementsAudit('placement.activation_blocked_economic_setup', [
                'placement_id' => $placementId,
                'as_of' => $asOf,
                'via' => $via,
                'blockers' => $blockers,
                'readiness' => $readiness,
            ], $placementId, ['before' => $placement, 'after' => $placement]);
            throw new \RuntimeException('Placement economic setup incomplete: ' . implode(', ', $blockers) . '.', 422);
        }

        placementsAudit('placement.activation_rate_verified', [
            'placement_id' => $placementId,
            'rate_id' => (int) ($rate['id'] ?? 0),
            'as_of' => $asOf,
            'via' => $via,
            'economics_ready' => true,
        ], $placementId, ['before' => $placement, 'after' => $placement]);
    }
}
