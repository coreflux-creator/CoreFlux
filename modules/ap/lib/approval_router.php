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
 * quorum = number of approvers that must approve at this step before moving on.
 *
 * The router is *vertical-agnostic*: vendor_type is just a string the
 * staffing layer happens to populate with values like '1099'/'c2c'/'eor'.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/../../../core/domain_people_graph.php';
require_once __DIR__ . '/vendor_risk.php';

/**
 * Evaluate $bill against active policies. Returns the matched policy +
 * resolved approver chain, or null if no policy matches.
 *
 * @param array $bill { id, entity_id?, total_amount, vendor_id?, vendor_type?, gl_account_code? }
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
    $amount     = apBillApprovalAmount($bill);
    $vendorType = $bill['vendor_type'] ?? null;
    $glCode     = $bill['gl_account_code'] ?? null;
    $bRisk      = $risk['level'];

    foreach ($policies as $p) {
        if (!_apPolicyMatches($p, $entityId, $amount, $vendorType, $glCode, $bRisk)) continue;
        $chain = json_decode((string) $p['chain_json'], true);
        if (!is_array($chain) || !$chain) continue;
        $resolved = [];
        foreach ($chain as $i => $step) {
            $resolved[] = [
                'step'              => (int) ($step['step'] ?? ($i + 1)),
                'approver_user_ids' => array_map('intval', (array) ($step['approver_user_ids'] ?? [])),
                'quorum'            => (int) ($step['quorum'] ?? 1),
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

/**
 * Persist the evaluation outcome to the audit log + create approval rows
 * for the first step of the chain. Sends a push to each step-1 approver
 * (best-effort; never blocks).
 *
 * @return array{policy_id:?int, approval_ids:list<int>, push_count:int, risk:array, matched:bool}
 */
function apRouteBillForApproval(int $tenantId, array $bill, ?int $actorUserId = null): array {
    $pdo = getDB();
    if (!$pdo) throw new \RuntimeException('No DB');

    $eval = apEvaluateApprovalPolicy($tenantId, $bill);
    $billId = (int) $bill['id'];
    $workflowContext = apWorkflowContextForBill($bill, $eval['risk'], $actorUserId);
    $sodBlockedUserIds = apWorkflowSodBlockedUserIds($bill, $actorUserId);

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
    $step1ApproverUserIds = array_values(array_unique(array_filter(array_map(
        'intval',
        (array) ($step1['approver_user_ids'] ?? [])
    ))));
    $apIds = [];
    $insertSql = $pdo->prepare(
        "INSERT INTO ap_bill_approvals
          (tenant_id, bill_id, approver_user_id, step_no, state, created_at)
         VALUES (:t, :b, :u, 1, 'pending', NOW())"
    );
    foreach ($step1ApproverUserIds as $uid) {
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
        $workflowSteps = apWorkflowStepsForPeopleGraph(
            $billId,
            $eval['chain']
        );
        $defId      = workflowEnsureDefinition(
            $tenantId, $defKey, 'ap_bill', $policyName, $workflowSteps
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
                                    number_format(apBillApprovalAmount($bill), 2),
                                    $eval['risk']['level'] !== 'none' ? " ({$eval['risk']['level']} risk)" : ''),
                'deep_link'     => '/modules/ap/bills/' . $billId,
                // mobile_deep_link defaults to coreflux://approvals/<instance_id>
                // which workflow_engine fills in automatically; no override needed.
                'amount_label'  => '$' . number_format(apBillApprovalAmount($bill), 2),
                'risk'          => $eval['risk']['level'],
                'policy_id'     => $policyId,
                'bill_id'       => $billId,
                'object_module' => 'ap',
                'object_type'   => 'bill',
                'object_id'     => (string) $billId,
                'resource_module' => 'ap',
                'resource_type' => 'bill',
                'resource_id'   => (string) $billId,
                'context'       => $workflowContext,
                'source_actor_type' => $actorUserId !== null && $actorUserId > 0 ? 'user' : null,
                'source_actor_id' => $actorUserId,
                'sod_required'  => true,
                'separation_of_duties_required' => true,
                'sod_blocked_user_ids' => $sodBlockedUserIds,
                'started_by_user_id' => $actorUserId,
                'suppress_push' => true,  // AP router emits its own AI-narrated push below
            ],
            $actorUserId
        );
        $workflowInstanceId = (int) ($instance['id'] ?? 0);
        if ($workflowInstanceId && function_exists('workflowResolveCurrentStepApprovers')) {
            $resolvedApprovers = workflowResolveCurrentStepApprovers($tenantId, $workflowInstanceId);
            if ($resolvedApprovers) {
                $step1ApproverUserIds = $resolvedApprovers;
                $apIds = apResetLegacyApprovalRowsForStep($pdo, $tenantId, $billId, 1, $step1ApproverUserIds);
            }
        }
        unset($defId);
    } catch (\Throwable $_) {
        // Non-fatal — legacy ap_bill_approvals path remains the source of truth.
    }

    // Fire push notifications to step-1 approvers (best-effort).
    $pushCount = 0;
    if ($apIds) {
        require_once __DIR__ . '/../../../core/push_service.php';
        require_once __DIR__ . '/risk_explainer.php';
        $aiExplain = '';
        try {
            if (!empty($bill['vendor_id']) && $eval['risk']['level'] !== 'none') {
                $aiExplain = apExplainRisk($tenantId, (int) $bill['vendor_id'], $billId);
            }
        } catch (\Throwable $_) { $aiExplain = ''; }

        $title = 'AP bill needs approval';
        $body  = sprintf('Bill #%d for $%s%s. Open to review.',
            $billId,
            number_format(apBillApprovalAmount($bill), 2),
            $eval['risk']['level'] !== 'none' ? " ({$eval['risk']['level']} risk)" : ''
        );
        if ($aiExplain) $body .= "\n\n" . $aiExplain;
        $opts = [
            'category'        => 'ap_bill_approval',
            'deep_link'       => '/modules/ap/bills/' . $billId,
            // Sprint 6-cutover: if we successfully created a workflow
            // instance, carry its mobile deep link on the AP push so
            // tapping the notification lands directly on the single-bill
            // approval screen in the Expo app.
            'mobile_deep_link'=> $workflowInstanceId
                                    ? "coreflux://approvals/{$workflowInstanceId}"
                                    : null,
            'source_module'   => 'ap',
            'source_event'    => 'bill.routed_for_approval',
            'source_ref_type' => 'ap_bill',
            'source_ref_id'   => $billId,
        ];
        foreach ($step1ApproverUserIds as $uid) {
            $pushCount += pushSendToUser($tenantId, (int) $uid, $title, $body, [
                'bill_id'              => $billId,
                'amount'               => apBillApprovalAmount($bill),
                'risk_level'           => $eval['risk']['level'],
                'policy_id'            => $eval['policy_id'],
                'workflow_instance_id' => $workflowInstanceId,
            ], $opts);
        }
    }

    return [
        'policy_id'            => $eval['policy_id'],
        'approval_ids'         => $apIds,
        'workflow_instance_id' => $workflowInstanceId,
        'push_count'           => $pushCount,
        'risk'                 => $eval['risk'],
        'matched'              => true,
    ];
}

/* ---------------------------------------------------------------------- */
/** @internal */
function apBillApprovalAmount(array $bill): float {
    return (float) ($bill['total_amount'] ?? $bill['total'] ?? 0);
}

/** @internal Build the AP resource context consumed by People Graph policies and Workflow SoD. */
function apWorkflowContextForBill(array $bill, array $risk, ?int $actorUserId = null): array {
    $billId = (int) ($bill['id'] ?? 0);
    $context = [
        'resource_module' => 'ap',
        'resource_type' => 'bill',
        'resource_id' => (string) $billId,
        'object_module' => 'ap',
        'object_type' => 'bill',
        'object_id' => (string) $billId,
        'approval_resource' => 'ap.bill',
        'bill_id' => $billId,
        'amount' => apBillApprovalAmount($bill),
        'total_amount' => apBillApprovalAmount($bill),
        'currency' => $bill['currency'] ?? null,
        'vendor_id' => isset($bill['vendor_id']) ? (int) $bill['vendor_id'] : null,
        'vendor_type' => $bill['vendor_type'] ?? null,
        'entity_id' => isset($bill['entity_id']) ? (int) $bill['entity_id'] : null,
        'gl_account_code' => $bill['gl_account_code'] ?? $bill['default_gl_code'] ?? null,
        'risk_level' => (string) ($risk['level'] ?? 'none'),
        'risk_score' => (int) ($risk['score'] ?? 0),
        'risk_factors' => is_array($risk['factors'] ?? null) ? $risk['factors'] : [],
        'requires_manual_review' => !empty($risk['requires_manual_review']),
    ];

    foreach ([
        'created_by_user_id',
        'prepared_by_user_id',
        'preparer_user_id',
        'submitted_by_user_id',
        'submitter_user_id',
        'requested_by_user_id',
        'requester_user_id',
        'owner_user_id',
        'entered_by_user_id',
        'imported_by_user_id',
    ] as $key) {
        if (isset($bill[$key]) && (int) $bill[$key] > 0) {
            $context[$key] = (int) $bill[$key];
        }
    }

    if ($actorUserId !== null && $actorUserId > 0) {
        $context['source_actor_type'] = 'user';
        $context['source_actor_id'] = $actorUserId;
        $context['submitted_by_user_id'] = $context['submitted_by_user_id'] ?? $actorUserId;
    }

    return $context;
}

/** @internal */
function apWorkflowSodBlockedUserIds(array $bill, ?int $actorUserId = null): array {
    $ids = [];
    if ($actorUserId !== null && $actorUserId > 0) $ids[] = $actorUserId;
    foreach ([
        'created_by_user_id',
        'prepared_by_user_id',
        'preparer_user_id',
        'submitted_by_user_id',
        'submitter_user_id',
        'requested_by_user_id',
        'requester_user_id',
        'owner_user_id',
        'entered_by_user_id',
        'imported_by_user_id',
    ] as $key) {
        if (isset($bill[$key]) && (int) $bill[$key] > 0) $ids[] = (int) $bill[$key];
    }
    return array_values(array_unique(array_filter(array_map('intval', $ids))));
}

/** @internal */
function apResetLegacyApprovalRowsForStep(\PDO $pdo, int $tenantId, int $billId, int $stepNo, array $approverUserIds): array {
    $pdo->prepare(
        "DELETE FROM ap_bill_approvals
          WHERE tenant_id = :t AND bill_id = :b AND step_no = :s AND state = 'pending'"
    )->execute(['t' => $tenantId, 'b' => $billId, 's' => $stepNo]);

    $ids = [];
    $insert = $pdo->prepare(
        "INSERT INTO ap_bill_approvals
          (tenant_id, bill_id, approver_user_id, step_no, state, created_at)
         VALUES (:t, :b, :u, :s, 'pending', NOW())"
    );
    foreach (array_values(array_unique(array_filter(array_map('intval', $approverUserIds)))) as $uid) {
        try {
            $insert->execute(['t' => $tenantId, 'b' => $billId, 'u' => $uid, 's' => $stepNo]);
            $ids[] = (int) $pdo->lastInsertId();
        } catch (\Throwable $_) { /* duplicate or schema drift: non-fatal */ }
    }
    return $ids;
}

/** @internal */
function apWorkflowStepsForPeopleGraph(
    int $billId,
    array $chain
): array {
    $steps = [];
    foreach ($chain as $i => $step) {
        $stepNo = (int) ($step['step'] ?? ($i + 1));
        $label = (string) ($step['label'] ?? ('Step ' . ($i + 1)));
        $stepContext = [
            'workflow_step' => $stepNo,
            'workflow_step_label' => $label,
            'separation_of_duties_required' => true,
        ];
        $resolution = domainPeopleGraphWorkflowApproverResolution('ap', 'bill', $billId, $stepContext, [
            'strategy' => 'approval_policy',
        ]);
        unset($resolution['resource_id'], $resolution['object_id']);
        $resolution['object_module'] = 'ap';
        $resolution['object_type'] = 'bill';
        $resolution['separation_of_duties_required'] = true;
        $fallbackApprovers = array_values(array_unique(array_filter(array_map(
            'intval',
            (array) ($step['approver_user_ids'] ?? [])
        ))));
        $workflowStep = [
            'step' => $stepNo,
            'label' => $label,
            'approver_resolution' => $resolution,
            'approver_user_ids' => $fallbackApprovers,
            'fallback_approver_user_ids' => $fallbackApprovers,
            'quorum' => max(1, (int) ($step['quorum'] ?? 1)),
            'separation_of_duties_required' => true,
            'allow_email' => array_key_exists('allow_email', $step) ? (bool) $step['allow_email'] : true,
        ];
        foreach (['sla_hours', 'escalate_to_user_id'] as $optionalKey) {
            if (isset($step[$optionalKey]) && (int) $step[$optionalKey] > 0) {
                $workflowStep[$optionalKey] = (int) $step[$optionalKey];
            }
        }
        $steps[] = $workflowStep;
    }
    return $steps;
}

/** @internal Strict ascending match: every non-NULL policy dim must satisfy. */
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
