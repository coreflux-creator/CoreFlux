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
    $amount     = (float) ($bill['total_amount'] ?? 0);
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
    $step1 = $eval['chain'][0];
    $apIds = [];
    $insertSql = $pdo->prepare(
        "INSERT INTO ap_bill_approvals
          (tenant_id, bill_id, approver_user_id, state, created_at)
         VALUES (:t, :b, :u, 'pending', NOW())"
    );
    foreach ($step1['approver_user_ids'] as $uid) {
        try {
            $insertSql->execute(['t' => $tenantId, 'b' => $billId, 'u' => $uid]);
            $apIds[] = (int) $pdo->lastInsertId();
        } catch (\Throwable $_) { /* duplicate or schema drift — non-fatal */ }
    }

    // Fire push notifications to step-1 approvers (best-effort).
    $pushCount = 0;
    if ($apIds) {
        require_once __DIR__ . '/../../../core/push_service.php';
        $title = 'AP bill needs approval';
        $body  = sprintf('Bill #%d for $%s%s. Open to review.',
            $billId,
            number_format((float) ($bill['total_amount'] ?? 0), 2),
            $eval['risk']['level'] !== 'none' ? " ({$eval['risk']['level']} risk)" : ''
        );
        $opts = [
            'category'        => 'ap_bill_approval',
            'deep_link'       => '/modules/ap/bills/' . $billId,
            'source_module'   => 'ap',
            'source_event'    => 'bill.routed_for_approval',
            'source_ref_type' => 'ap_bill',
            'source_ref_id'   => $billId,
        ];
        foreach ($step1['approver_user_ids'] as $uid) {
            $pushCount += pushSendToUser($tenantId, (int) $uid, $title, $body, [
                'bill_id'     => $billId,
                'amount'      => (float) ($bill['total_amount'] ?? 0),
                'risk_level'  => $eval['risk']['level'],
                'policy_id'   => $eval['policy_id'],
            ], $opts);
        }
    }

    return [
        'policy_id'    => $eval['policy_id'],
        'approval_ids' => $apIds,
        'push_count'   => $pushCount,
        'risk'         => $eval['risk'],
        'matched'      => true,
    ];
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
