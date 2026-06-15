<?php
/**
 * AP bill approval bridge to the generic WorkflowEngine.
 *
 * Compatibility endpoints should use this instead of writing approval state
 * directly, so People Graph routing, SoD, workflow audit, and subject sync
 * remain the single approval control point.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/db.php';

/**
 * Compatibility submit path for AP bills. Routes through the common AP
 * approval policy router so WorkflowEngine + People Graph are the source of
 * approver resolution, SoD evidence, workflow state, and legacy row mirrors.
 *
 * @return array{policy_id:?int, approval_ids:list<int>, workflow_instance_id:int, push_count:int, risk:array, matched:bool}
 */
function apWorkflowSubmitBillForApproval(
    int $tenantId,
    array $bill,
    ?int $actorUserId = null,
    string $via = 'api'
): array {
    $pdo = getDB();
    if (!$pdo) throw new \RuntimeException('No DB');

    $billId = (int) ($bill['id'] ?? 0);
    if ($billId <= 0) throw new \InvalidArgumentException('bill.id required');

    $status = (string) ($bill['status'] ?? '');
    if (!in_array($status, ['inbox', 'pending_review', 'pending_approval'], true)) {
        throw new \RuntimeException("Bill status '{$status}' cannot be submitted for approval");
    }

    if (apWorkflowFindBillInstance($tenantId, $billId, true)) {
        throw new \RuntimeException('Bill already has a pending WorkflowEngine approval');
    }

    $legacy = $pdo->prepare(
        "SELECT 1 FROM ap_bill_approvals
          WHERE tenant_id = :t AND bill_id = :b AND state = 'pending'
          LIMIT 1"
    );
    $legacy->execute(['t' => $tenantId, 'b' => $billId]);
    if ($legacy->fetchColumn()) {
        throw new \RuntimeException('Bill already has pending approval rows');
    }

    require_once __DIR__ . '/approval_router.php';
    $routeActorUserId = !empty($bill['created_by_user_id'])
        ? (int) $bill['created_by_user_id']
        : ($actorUserId ?: null);
    $routing = apRouteBillForApproval($tenantId, $bill, $routeActorUserId);
    if (empty($routing['matched'])) {
        throw new \RuntimeException('No approval workflow policy matched this AP bill');
    }
    if (empty($routing['workflow_instance_id'])) {
        $pdo->prepare(
            "DELETE FROM ap_bill_approvals
              WHERE tenant_id = :t AND bill_id = :b AND state = 'pending'"
        )->execute(['t' => $tenantId, 'b' => $billId]);
        throw new \RuntimeException('AP approval route did not create a WorkflowEngine instance');
    }

    $beforeBill = apWorkflowBillRow($tenantId, $billId) ?? $bill;
    $pdo->prepare(
        "UPDATE ap_bills
            SET status = 'pending_approval', updated_at = NOW()
          WHERE tenant_id = :t AND id = :b
            AND status IN ('inbox', 'pending_review', 'pending_approval')"
    )->execute(['t' => $tenantId, 'b' => $billId]);
    $afterBill = apWorkflowBillRow($tenantId, $billId) ?? $beforeBill;

    try {
        require_once __DIR__ . '/ap.php';
        apAudit('ap.bill.approval_submitted', [
            'bill_id' => $billId,
            'policy_id' => $routing['policy_id'] ?? null,
            'approval_ids' => $routing['approval_ids'] ?? [],
            'workflow_instance_id' => $routing['workflow_instance_id'] ?? null,
            'risk_level' => $routing['risk']['level'] ?? null,
            'source' => 'workflow',
            'via' => $via,
        ], $billId, [
            'before' => $beforeBill,
            'after' => $afterBill,
        ]);
    } catch (\Throwable $_) { /* non-fatal */ }

    return $routing;
}

function apWorkflowFindBillInstance(int $tenantId, int $billId, bool $pendingOnly = true): ?array {
    $pdo = getDB();
    if (!$pdo) return null;
    $sql = "SELECT id, status FROM workflow_instances
              WHERE tenant_id = :t AND subject_type = 'ap_bill' AND subject_id = :s";
    if ($pendingOnly) $sql .= " AND status = 'pending'";
    $sql .= " ORDER BY id DESC LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['t' => $tenantId, 's' => $billId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function apWorkflowBillRow(int $tenantId, int $billId): ?array {
    $pdo = getDB();
    if (!$pdo) return null;
    $stmt = $pdo->prepare(
        'SELECT * FROM ap_bills
          WHERE tenant_id = :t AND id = :b
          LIMIT 1'
    );
    $stmt->execute(['t' => $tenantId, 'b' => $billId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function apWorkflowMirrorDecision(int $tenantId, int $billId, ?int $userId, string $action, ?string $note, bool $throwOnFailure = false): bool {
    try {
        require_once __DIR__ . '/../../../core/workflow_engine.php';
        $row = apWorkflowFindBillInstance($tenantId, $billId, true);
        if (!$row) return false;

        workflowAct(
            $tenantId,
            (int) $row['id'],
            $userId,
            $action,
            $note ?: null,
            'app',
            null,
            null
        );
        return true;
    } catch (\Throwable $e) {
        if ($throwOnFailure) throw $e;
        return false;
    }
}

function apWorkflowCurrentApproverUserIds(int $tenantId, int $billId): array {
    try {
        require_once __DIR__ . '/../../../core/workflow_engine.php';
        if (!function_exists('workflowResolveCurrentStepApprovers')) return [];
        $row = apWorkflowFindBillInstance($tenantId, $billId, true);
        if (!$row) return [];
        return array_values(array_unique(array_filter(array_map(
            'intval',
            workflowResolveCurrentStepApprovers($tenantId, (int) $row['id'])
        ))));
    } catch (\Throwable $_) {
        return [];
    }
}

/**
 * Route an AP bill into WorkflowEngine when needed, then act on the current
 * workflow step. Throws when routing is missing or WorkflowEngine gates the
 * actor.
 *
 * @return array{workflow_instance_id:int, workflow_status:string, routed:bool, routing:?array}
 */
function apWorkflowActBillApproval(
    int $tenantId,
    array $bill,
    ?int $userId,
    string $action,
    ?string $note = null,
    bool $routeIfMissing = true
): array {
    if (!in_array($action, ['approve', 'reject'], true)) {
        throw new \InvalidArgumentException("Unsupported AP workflow action {$action}");
    }
    $billId = (int) ($bill['id'] ?? 0);
    if ($billId <= 0) throw new \InvalidArgumentException('bill.id required');

    require_once __DIR__ . '/../../../core/workflow_engine.php';
    $instance = apWorkflowFindBillInstance($tenantId, $billId, true);
    $routing = null;
    $routed = false;

    if (!$instance && $routeIfMissing) {
        require_once __DIR__ . '/approval_router.php';
        $routeActorUserId = !empty($bill['created_by_user_id']) ? (int) $bill['created_by_user_id'] : null;
        $routing = apRouteBillForApproval($tenantId, $bill, $routeActorUserId);
        $routed = true;
        if (empty($routing['matched'])) {
            throw new \RuntimeException('No approval workflow policy matched this AP bill');
        }
        if (empty($routing['workflow_instance_id'])) {
            throw new \RuntimeException('AP approval route did not create a WorkflowEngine instance');
        }
        $instance = apWorkflowFindBillInstance($tenantId, $billId, true);
    }

    if (!$instance) {
        throw new \RuntimeException('No pending WorkflowEngine approval exists for this AP bill');
    }

    $result = workflowAct(
        $tenantId,
        (int) $instance['id'],
        $userId,
        $action,
        $note ?: null,
        'app',
        null,
        null
    );

    return [
        'workflow_instance_id' => (int) $instance['id'],
        'workflow_status' => (string) ($result['status'] ?? $instance['status'] ?? 'pending'),
        'routed' => $routed,
        'routing' => $routing,
    ];
}

function apWorkflowDecisionHttpStatus(\Throwable $e): int {
    $msg = strtolower($e->getMessage());
    if (str_contains($msg, 'not an approver') || str_contains($msg, 'separation of duties')) {
        return 403;
    }
    if (str_contains($msg, 'no approval workflow policy')) {
        return 422;
    }
    return 409;
}
