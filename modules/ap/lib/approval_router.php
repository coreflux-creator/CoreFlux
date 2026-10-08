<?php
/**
 * C2 — Layered AP approval policy router (Sprint 3).
 *
 * Given a bill (with $entityId, $amount, $vendorId, $vendorType, $glCode),
 * find the highest-priority active policy that matches every supplied
 * dimension, return its approver chain. NULL match dimensions on the
 * policy = wildcard.
 *
 * Each policy.chain_json is a list of steps:
 *   [
 *     { "step": 1, "approver_user_ids": [12, 17], "quorum": 1, "label": "Manager" },
 *     { "step": 2, "approver_user_ids": [3],      "quorum": 1, "label": "CFO" }
 *   ]
 * A step may also include "include_active_tenant_admins": true. The bill
 * creator is excluded from each resolved step to preserve two-eye review.
 * quorum = number of approvers that must approve at this step before moving on.
 *
 * The router is *vertical-agnostic*: vendor_type is just a string the
 * staffing layer happens to populate with values like '1099'/'c2c'/'eor'.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/vendor_risk.php';

/**
 * Evaluate $bill against active policies. Returns the matched policy +
 * resolved approver chain, or null if no policy matches.
 *
 * @param array $bill { id, entity_id?, total?, total_amount?, created_by_user_id?, vendor_id?, vendor_type?, gl_account_code? }
 * @return array{
 *   policy_id: ?int,
 *   policy_name: ?string,
 *   chain: list<array{step:int, approver_user_ids:list<int>, quorum:int, label:string}>,
 *   risk: array{level:string, score:int, factors:list<string>, requires_manual_review:bool},
 *   matched: bool
 * }
 */
function apEvaluateApprovalPolicy(int $tenantId, array $bill): array {
    $pdo = getDB();
    if (!$pdo) {
        return ['policy_id' => null, 'policy_name' => null, 'chain' => [], 'risk' => apVendorRiskDefault(), 'matched' => false];
    }

    $risk = !empty($bill['vendor_id'])
        ? apVendorRiskFor($tenantId, (int) $bill['vendor_id'])
        : apVendorRiskDefault();

    $stmt = $pdo->prepare(
        "SELECT * FROM ap_approval_policies
          WHERE tenant_id = :t AND active = 1
          ORDER BY priority ASC, id ASC"
    );
    $stmt->execute(['t' => $tenantId]);
    $policies = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $entityId   = isset($bill['entity_id']) ? (int) $bill['entity_id'] : null;
    $amount     = (float) ($bill['total_amount'] ?? $bill['total'] ?? 0);
    $vendorType = $bill['vendor_type'] ?? null;
    $glCode     = $bill['gl_account_code'] ?? null;
    $bRisk      = $risk['level'];
    $creatorId  = (int) ($bill['created_by_user_id'] ?? 0);
    $tenantAdminIds = null;
    $activeMemberIds = null;

    foreach ($policies as $p) {
        if (!_apPolicyMatches($p, $entityId, $amount, $vendorType, $glCode, $bRisk)) continue;
        $chain = json_decode((string) $p['chain_json'], true);
        if (!is_array($chain) || !$chain) continue;
        $resolved = [];
        foreach ($chain as $i => $step) {
            $approverIds = array_map('intval', (array) ($step['approver_user_ids'] ?? []));
            if (!empty($step['include_active_tenant_admins'])) {
                $tenantAdminIds ??= apActiveTenantAdminIds($tenantId);
                $approverIds = array_merge($approverIds, $tenantAdminIds);
            }
            $activeMemberIds ??= apActiveTenantMemberIds($tenantId);
            $approverIds = array_values(array_unique(array_intersect(
                array_filter($approverIds, static fn(int $id): bool => $id > 0 && $id !== $creatorId),
                $activeMemberIds
            )));
            $quorum = max(1, (int) ($step['quorum'] ?? 1));
            if (count($approverIds) < $quorum) {
                $reason = !empty($step['include_active_tenant_admins'])
                    ? 'Add another active tenant administrator to approve this bill; its creator cannot approve it.'
                    : 'The approval policy has too few active tenant approvers for this bill; its creator cannot approve it.';
                return [
                    'policy_id' => (int) $p['id'], 'policy_name' => $p['name'],
                    'chain' => [], 'risk' => $risk, 'matched' => true,
                    'routing_error' => $reason,
                ];
            }
            $resolved[] = [
                'step'              => (int) ($step['step'] ?? ($i + 1)),
                'approver_user_ids' => $approverIds,
                'quorum'            => $quorum,
                'label'             => (string) ($step['label'] ?? ('Step ' . ($i + 1))),
            ];
        }
        return [
            'policy_id'   => (int) $p['id'],
            'policy_name' => $p['name'],
            'chain'       => $resolved,
            'risk'        => $risk,
            'matched'     => true,
        ];
    }

    return ['policy_id' => null, 'policy_name' => null, 'chain' => [], 'risk' => $risk, 'matched' => false];
}

function apActiveTenantAdminIds(int $tenantId): array {
    $stmt = getDB()->prepare(
        "SELECT DISTINCT u.id
           FROM tenant_memberships m
           JOIN users u ON u.id = m.user_id AND u.is_active = 1
          WHERE m.tenant_id = :tenant_id
            AND m.persona_type = 'tenant_admin' AND m.status = 'active'
          ORDER BY u.id"
    );
    $stmt->execute(['tenant_id' => $tenantId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function apActiveTenantMemberIds(int $tenantId): array {
    $stmt = getDB()->prepare(
        "SELECT DISTINCT u.id
           FROM tenant_memberships m
           JOIN users u ON u.id = m.user_id AND u.is_active = 1
          WHERE m.tenant_id = :tenant_id AND m.status = 'active'
          ORDER BY u.id"
    );
    $stmt->execute(['tenant_id' => $tenantId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Persist the evaluation outcome to the audit log + create approval rows
 * for the first step of the chain. Sends a push to each step-1 approver
 * (best-effort; never blocks) unless the caller defers it until commit.
 *
 * @return array{policy_id:?int, approval_ids:list<int>, approver_user_ids:list<int>, workflow_instance_id:?int, push_count:int, risk:array, matched:bool}
 */
function apRouteBillForApproval(int $tenantId, array $bill, ?int $actorUserId = null,
    bool $deferPush = false): array {
    $pdo = getDB();
    if (!$pdo) throw new \RuntimeException('No DB');
    if (!array_key_exists('created_by_user_id', $bill)) {
        throw new \RuntimeException('AP bill creator is required to route approval safely');
    }

    $eval = apEvaluateApprovalPolicy($tenantId, $bill);
    if (!empty($eval['routing_error'])) throw new \RuntimeException($eval['routing_error']);
    $billId = (int) $bill['id'];
    $billAmount = (float) ($bill['total_amount'] ?? $bill['total'] ?? 0);

    // Append evaluation log.
    $pdo->prepare(
        "INSERT INTO ap_approval_policy_evaluations
          (tenant_id, bill_id, policy_id, matched, chain_json, risk_level, risk_factors_json, evaluated_at)
         VALUES (:t, :b, :p, :m, :c, :rl, :rf, NOW())"
    )->execute([
        't' => $tenantId, 'b' => $billId,
        'p' => $eval['policy_id'],
        'm' => $eval['matched'] ? 1 : 0,
        'c' => $eval['chain'] ? json_encode($eval['chain'], JSON_UNESCAPED_SLASHES) : null,
        'rl'=> $eval['risk']['level'],
        'rf'=> json_encode($eval['risk']['factors'], JSON_UNESCAPED_SLASHES),
    ]);

    if (!$eval['matched'] || !$eval['chain']) {
        return ['policy_id' => null, 'approval_ids' => [], 'push_count' => 0, 'risk' => $eval['risk'], 'matched' => false];
    }

    // Insert approval rows for step 1.
    // P1.7 — explicitly set step_no=1 so the chain advancement logic in
    // bill_approvals.php can read the canonical step number when
    // deciding whether to materialise the next step.
    $step1 = $eval['chain'][0];
    $apIds = [];
    $insertSql = $pdo->prepare(
        "INSERT INTO ap_bill_approvals
          (tenant_id, bill_id, approver_user_id, step_no, state, created_at)
         VALUES (:t, :b, :u, 1, 'pending', NOW())"
    );
    foreach ($step1['approver_user_ids'] as $uid) {
        try {
            $insertSql->execute(['t' => $tenantId, 'b' => $billId, 'u' => $uid]);
            $apIds[] = (int) $pdo->lastInsertId();
        } catch (\Throwable $_) { /* duplicate or schema drift — non-fatal */ }
    }

    // Sprint 6-cutover — also mirror the routing into the generic
    // WorkflowEngine so the bill shows up in the cross-module `/inbox`
    // and mobile pushes carry `coreflux://approvals/<instance_id>` deep
    // links. Best-effort: failure here MUST NOT break the legacy
    // ap_bill_approvals flow above, which is still the source of truth
    // until Phase-2 rip-out.
    $workflowInstanceId = null;
    try {
        require_once __DIR__ . '/../../../core/workflow_engine.php';
        $policyId   = $eval['policy_id'] ?? 0;
        $policyName = (string) ($eval['policy_name'] ?? 'AP bill approval');
        $defKey     = 'ap_bill_policy_' . ($policyId ?: 'default');
        $defId      = workflowEnsureDefinition(
            $tenantId, $defKey, 'ap_bill', $policyName, $eval['chain']
        );
        $instance = workflowStart(
            $tenantId,
            $defKey,
            'ap_bill',
            $billId,
            [
                'title'         => 'AP bill needs approval',
                'body'          => sprintf('Bill #%d for $%s%s. Open to review.',
                                    $billId,
                                    number_format($billAmount, 2),
                                    $eval['risk']['level'] !== 'none' ? " ({$eval['risk']['level']} risk)" : ''),
                'deep_link'     => '/modules/ap/bills/' . $billId,
                // mobile_deep_link defaults to coreflux://approvals/<instance_id>
                // which workflow_engine fills in automatically; no override needed.
                'amount_label'  => '$' . number_format($billAmount, 2),
                'risk'          => $eval['risk']['level'],
                'policy_id'     => $policyId,
                'bill_id'       => $billId,
                'suppress_push' => true,  // AP router emits its own AI-narrated push below
            ],
            $actorUserId
        );
        $workflowInstanceId = (int) ($instance['id'] ?? 0);
        unset($defId);
    } catch (\Throwable $_) {
        // Non-fatal — legacy ap_bill_approvals path remains the source of truth.
    }

    $approverUserIds = $apIds ? $step1['approver_user_ids'] : [];
    $pushCount = $deferPush ? 0 : apPushRoutedBillApprovers(
        $tenantId, $bill, $eval['risk'], (int) $eval['policy_id'],
        $workflowInstanceId, $approverUserIds
    );

    return [
        'policy_id'            => $eval['policy_id'],
        'approval_ids'         => $apIds,
        'approver_user_ids'    => $approverUserIds,
        'workflow_instance_id' => $workflowInstanceId,
        'push_count'           => $pushCount,
        'risk'                 => $eval['risk'],
        'matched'              => true,
    ];
}

/** Send only after a transactional machine route commits; ordinary AP routing calls this inline. */
function apPushRoutedBillApprovers(int $tenantId, array $bill, array $risk,
    int $policyId, ?int $workflowInstanceId, array $approverUserIds): int {
    if (!$approverUserIds) return 0;
    require_once __DIR__ . '/../../../core/push_service.php';
    require_once __DIR__ . '/risk_explainer.php';
    $billId = (int) $bill['id'];
    $billAmount = (float) ($bill['total_amount'] ?? $bill['total'] ?? 0);
    $riskLevel = (string) ($risk['level'] ?? 'none');
    $aiExplain = '';
    try {
        if (!empty($bill['vendor_id']) && $riskLevel !== 'none') {
            $aiExplain = apExplainRisk($tenantId, (int) $bill['vendor_id'], $billId);
        }
    } catch (\Throwable $_) { $aiExplain = ''; }

    $title = 'AP bill needs approval';
    $body = sprintf('Bill #%d for $%s%s. Open to review.',
        $billId, number_format($billAmount, 2),
        $riskLevel !== 'none' ? " ({$riskLevel} risk)" : '');
    if ($aiExplain) $body .= "\n\n" . $aiExplain;
    $opts = [
        'category' => 'ap_bill_approval',
        'deep_link' => '/modules/ap/bills/' . $billId,
        'mobile_deep_link' => $workflowInstanceId
            ? "coreflux://approvals/{$workflowInstanceId}" : null,
        'source_module' => 'ap',
        'source_event' => 'bill.routed_for_approval',
        'source_ref_type' => 'ap_bill',
        'source_ref_id' => $billId,
    ];
    $pushCount = 0;
    foreach ($approverUserIds as $uid) {
        $pushCount += pushSendToUser($tenantId, (int) $uid, $title, $body, [
            'bill_id' => $billId,
            'amount' => $billAmount,
            'risk_level' => $riskLevel,
            'policy_id' => $policyId,
            'workflow_instance_id' => $workflowInstanceId,
        ], $opts);
    }
    return $pushCount;
}

/* ---------------------------------------------------------------------- */
/** @internal Strict ascending match — every non-NULL policy dim must satisfy. */
function _apPolicyMatches(array $p, ?int $entityId, float $amount, ?string $vendorType, ?string $glCode, string $billRiskLevel): bool {
    if ($p['entity_id']       !== null && (int) $p['entity_id']   !== (int) $entityId) return false;
    if ($p['vendor_type']     !== null && (string) $p['vendor_type']  !== (string) $vendorType) return false;
    if ($p['min_amount']      !== null && $amount < (float) $p['min_amount']) return false;
    if ($p['max_amount']      !== null && $amount > (float) $p['max_amount']) return false;
    if ($p['gl_account_code'] !== null && (string) $p['gl_account_code']!== (string) $glCode) return false;
    if ($p['min_risk_level']  !== null) {
        $order = ['none' => 0, 'low' => 1, 'medium' => 2, 'high' => 3];
        if (($order[$billRiskLevel] ?? 0) < ($order[$p['min_risk_level']] ?? 0)) return false;
    }
    return true;
}
