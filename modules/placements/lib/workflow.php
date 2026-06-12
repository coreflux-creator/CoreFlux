<?php
/**
 * Placement rate WorkflowGraph bridge.
 *
 * Placements owns the commercial rate snapshot. WorkflowGraph owns approval
 * routing, People Graph resolution, and separation-of-duties enforcement.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/../../../core/domain_people_graph.php';
require_once __DIR__ . '/../../../core/workflow_engine.php';
require_once __DIR__ . '/placements.php';

/** @internal */
function placementsWorkflowAudit(int $tenantId, ?int $actorUserId, string $event, array $meta = [], ?int $targetId = null): void
{
    $currentTenant = function_exists('currentTenantId') ? (int) (currentTenantId() ?? 0) : 0;
    if ($currentTenant > 0 && $currentTenant === $tenantId) {
        placementsAudit($event, $meta, $targetId);
        return;
    }
    try {
        $pdo = getDB();
        if (!$pdo) return;
        $stmt = $pdo->prepare(
            'INSERT INTO audit_log
             (tenant_id, actor_user_id, event, target_id, meta_json, ip_address, request_id, created_at)
             VALUES (:tenant_id, :actor_user_id, :event, :target_id, :meta_json, :ip_address, :request_id, NOW())'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'actor_user_id' => $actorUserId,
            'event' => $event,
            'target_id' => $targetId,
            'meta_json' => $meta ? json_encode($meta, JSON_UNESCAPED_SLASHES) : null,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'request_id' => $_SERVER['HTTP_X_REQUEST_ID'] ?? null,
        ]);
    } catch (\Throwable $e) {
        error_log("[placements.workflow.audit] db-write-failed: " . $e->getMessage() . " event={$event}");
    }
}

function placementsRateWorkflowRow(int $tenantId, int $rateId): ?array
{
    $pdo = getDB();
    if (!$pdo) return null;
    $stmt = $pdo->prepare(
        'SELECT pr.*,
                p.title AS placement_title,
                p.status AS placement_status,
                p.person_id,
                p.start_date AS placement_start_date,
                p.end_client_name,
                p.created_by_user_id AS placement_created_by_user_id,
                pe.first_name AS person_first_name,
                pe.last_name AS person_last_name,
                pe.email_primary AS person_email_primary
           FROM placement_rates pr
           JOIN placements p ON p.id = pr.placement_id AND p.tenant_id = pr.tenant_id
      LEFT JOIN people pe ON pe.id = p.person_id AND pe.tenant_id = p.tenant_id
          WHERE pr.tenant_id = :t AND pr.id = :id AND p.deleted_at IS NULL
          LIMIT 1'
    );
    $stmt->execute(['t' => $tenantId, 'id' => $rateId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** @internal */
function placementsRateWorkflowSteps(int $rateId): array
{
    $resolution = domainPeopleGraphWorkflowApproverResolution('placements', 'rate_snapshot', $rateId, [
        'workflow_step' => 1,
        'workflow_step_label' => 'Placement rate approval',
        'separation_of_duties_required' => true,
    ], ['strategy' => 'approval_policy']);
    unset($resolution['resource_id'], $resolution['object_id']);
    $resolution['object_module'] = 'placements';
    $resolution['object_type'] = 'rate_snapshot';
    $resolution['separation_of_duties_required'] = true;

    return [[
        'step' => 1,
        'label' => 'Placement rate approval',
        'approver_resolution' => $resolution,
        'approver_user_ids' => [],
        'fallback_approver_user_ids' => [],
        'quorum' => 1,
        'separation_of_duties_required' => true,
        'allow_email' => false,
    ]];
}

/** @internal */
function placementsRateWorkflowContext(array $rate, ?int $actorUserId = null, bool $isCorrection = false, ?string $correctionReason = null): array
{
    $rateId = (int) ($rate['id'] ?? 0);
    $placementId = (int) ($rate['placement_id'] ?? 0);
    $context = [
        'resource_module' => 'placements',
        'resource_type' => 'rate_snapshot',
        'resource_id' => (string) $rateId,
        'object_module' => 'placements',
        'object_type' => 'rate_snapshot',
        'object_id' => (string) $rateId,
        'approval_resource' => 'placements.rate_snapshot',
        'rate_id' => $rateId,
        'placement_id' => $placementId,
        'person_id' => isset($rate['person_id']) ? (int) $rate['person_id'] : null,
        'effective_from' => $rate['effective_from'] ?? null,
        'effective_to' => $rate['effective_to'] ?? null,
        'bill_rate' => isset($rate['bill_rate']) ? (float) $rate['bill_rate'] : null,
        'pay_rate' => isset($rate['pay_rate']) ? (float) $rate['pay_rate'] : null,
        'currency' => $rate['currency'] ?? 'USD',
        'is_correction' => $isCorrection,
        'correction_reason' => $correctionReason,
        'separation_of_duties_required' => true,
    ];

    if (!empty($rate['created_by_user_id'])) {
        $context['created_by_user_id'] = (int) $rate['created_by_user_id'];
        $context['drafted_by_user_id'] = (int) $rate['created_by_user_id'];
        $context['preparer_user_id'] = (int) $rate['created_by_user_id'];
    }
    if (!empty($rate['placement_created_by_user_id'])) {
        $context['placement_created_by_user_id'] = (int) $rate['placement_created_by_user_id'];
    }
    if ($actorUserId !== null && $actorUserId > 0) {
        $context['source_actor_type'] = 'user';
        $context['source_actor_id'] = $actorUserId;
        $context['started_by_user_id'] = $actorUserId;
    }

    return $context;
}

/** @internal */
function placementsRateWorkflowSodBlockedUserIds(array $rate, ?int $actorUserId = null): array
{
    $ids = [];
    foreach ([$actorUserId, $rate['created_by_user_id'] ?? null] as $id) {
        if ((int) $id > 0) $ids[] = (int) $id;
    }
    return array_values(array_unique($ids));
}

/** @internal */
function placementsRateWorkflowPayload(array $rate, ?int $actorUserId = null, bool $isCorrection = false, ?string $correctionReason = null): array
{
    $rateId = (int) ($rate['id'] ?? 0);
    $placementId = (int) ($rate['placement_id'] ?? 0);
    $context = placementsRateWorkflowContext($rate, $actorUserId, $isCorrection, $correctionReason);
    $blocked = placementsRateWorkflowSodBlockedUserIds($rate, $actorUserId);
    $bill = number_format((float) ($rate['bill_rate'] ?? 0), 2);
    $pay = number_format((float) ($rate['pay_rate'] ?? 0), 2);

    return [
        'title' => 'Placement rate needs approval',
        'body' => sprintf(
            'Rate #%d for placement #%d: bill %s %s, pay %s %s, effective %s.',
            $rateId,
            $placementId,
            (string) ($rate['currency'] ?? 'USD'),
            $bill,
            (string) ($rate['currency'] ?? 'USD'),
            $pay,
            (string) ($rate['effective_from'] ?? '')
        ),
        'deep_link' => '/modules/placements/' . $placementId . '/rates',
        'object_module' => 'placements',
        'object_type' => 'rate_snapshot',
        'object_id' => (string) $rateId,
        'resource_module' => 'placements',
        'resource_type' => 'rate_snapshot',
        'resource_id' => (string) $rateId,
        'context' => $context,
        'source_actor_type' => $actorUserId !== null && $actorUserId > 0 ? 'user' : null,
        'source_actor_id' => $actorUserId,
        'sod_required' => true,
        'separation_of_duties_required' => true,
        'sod_blocked_user_ids' => $blocked,
        'started_by_user_id' => $actorUserId,
        'is_correction' => $isCorrection,
        'correction_reason' => $correctionReason,
    ];
}

function placementsRateWorkflowStart(int $tenantId, int $rateId, ?int $actorUserId = null): ?int
{
    $rate = placementsRateWorkflowRow($tenantId, $rateId);
    if (!$rate || !empty($rate['approved_at'])) return null;

    try {
        $defKey = 'placement_rate_approval';
        workflowEnsureDefinition(
            $tenantId,
            $defKey,
            'placement_rate',
            'Placement rate approval',
            placementsRateWorkflowSteps($rateId)
        );
        $payload = placementsRateWorkflowPayload($rate, $actorUserId);
        $instance = workflowStart($tenantId, $defKey, 'placement_rate', $rateId, $payload, $actorUserId);
        $instanceId = (int) ($instance['id'] ?? 0);
        if ($instanceId <= 0) return null;

        $pdo = getDB();
        try {
            $pdo->prepare(
                'UPDATE placement_rates
                    SET workflow_instance_id = :w
                  WHERE tenant_id = :t AND id = :id'
            )->execute(['w' => $instanceId, 't' => $tenantId, 'id' => $rateId]);
        } catch (\Throwable $_) { /* schema drift: workflow instance still exists */ }

        $pdo->prepare(
            'UPDATE workflow_instances
                SET payload_json = :payload, last_activity_at = NOW()
              WHERE tenant_id = :t AND id = :id AND status = :status'
        )->execute([
            'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES),
            't' => $tenantId,
            'id' => $instanceId,
            'status' => WORKFLOW_STATUS_PENDING,
        ]);

        placementsWorkflowAudit($tenantId, $actorUserId, 'placement.rate.workflow_started', [
            'placement_id' => (int) $rate['placement_id'],
            'rate_id' => $rateId,
            'workflow_instance_id' => $instanceId,
            'drafted_by_user_id' => $rate['created_by_user_id'] ?? null,
        ], (int) $rate['placement_id']);
        return $instanceId;
    } catch (\Throwable $e) {
        placementsWorkflowAudit($tenantId, $actorUserId, 'placement.rate.workflow_start_failed', [
            'placement_id' => (int) ($rate['placement_id'] ?? 0),
            'rate_id' => $rateId,
            'reason' => $e->getMessage(),
        ], (int) ($rate['placement_id'] ?? 0));
        error_log('[placements.rate.workflow] start failed: ' . $e->getMessage());
        return null;
    }
}

function placementsRateWorkflowPendingInstanceId(int $tenantId, int $rateId, ?int $storedInstanceId = null): int
{
    $pdo = getDB();
    if (!$pdo) return 0;
    $instanceId = (int) ($storedInstanceId ?? 0);
    if ($instanceId > 0) {
        $check = $pdo->prepare(
            "SELECT id FROM workflow_instances
              WHERE tenant_id = :t AND id = :id AND subject_type = 'placement_rate' AND status = 'pending'"
        );
        $check->execute(['t' => $tenantId, 'id' => $instanceId]);
        $instanceId = (int) ($check->fetchColumn() ?: 0);
    }
    if ($instanceId > 0) return $instanceId;

    $row = $pdo->prepare(
        "SELECT id FROM workflow_instances
          WHERE tenant_id = :t AND subject_type = 'placement_rate' AND subject_id = :s AND status = 'pending'
          ORDER BY id DESC LIMIT 1"
    );
    $row->execute(['t' => $tenantId, 's' => $rateId]);
    return (int) ($row->fetchColumn() ?: 0);
}

function placementsRateWorkflowAct(
    int $tenantId,
    int $rateId,
    array $user,
    bool $isCorrection = false,
    ?string $correctionReason = null,
    string $via = 'app'
): array {
    $rate = placementsRateWorkflowRow($tenantId, $rateId);
    if (!$rate) throw new \RuntimeException("Rate {$rateId} not found");
    if (!empty($rate['approved_at'])) throw new \RuntimeException("Rate {$rateId} already approved");

    try {
        $instanceId = placementsRateWorkflowPendingInstanceId($tenantId, $rateId, isset($rate['workflow_instance_id']) ? (int) $rate['workflow_instance_id'] : null);
        if ($instanceId <= 0) {
            $starter = !empty($rate['created_by_user_id']) ? (int) $rate['created_by_user_id'] : null;
            $instanceId = (int) (placementsRateWorkflowStart($tenantId, $rateId, $starter) ?? 0);
        }
        if ($instanceId <= 0) {
            throw new \RuntimeException('Could not start placement rate approval workflow');
        }

        $payload = placementsRateWorkflowPayload(
            $rate,
            !empty($rate['created_by_user_id']) ? (int) $rate['created_by_user_id'] : null,
            $isCorrection,
            $correctionReason
        );
        getDB()->prepare(
            'UPDATE workflow_instances
                SET payload_json = :payload, last_activity_at = NOW()
              WHERE tenant_id = :t AND id = :id AND status = :status'
        )->execute([
            'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES),
            't' => $tenantId,
            'id' => $instanceId,
            'status' => WORKFLOW_STATUS_PENDING,
        ]);

        $instance = workflowAct(
            $tenantId,
            $instanceId,
            (int) ($user['id'] ?? 0),
            'approve',
            $correctionReason ?: null,
            $via
        );
        $updated = placementsRateWorkflowRow($tenantId, $rateId) ?? $rate;
        $approved = !empty($updated['approved_at']);
        if (($instance['status'] ?? null) === WORKFLOW_STATUS_APPROVED && !$approved) {
            throw new \RuntimeException('Workflow approved but placement rate sync did not apply');
        }
        placementsWorkflowAudit($tenantId, (int) ($user['id'] ?? 0), 'placement.rate.workflow_approved', [
            'placement_id' => (int) $rate['placement_id'],
            'rate_id' => $rateId,
            'workflow_instance_id' => $instanceId,
            'workflow_status' => $instance['status'] ?? null,
            'approved' => $approved,
        ], (int) $rate['placement_id']);
        return [
            'applied' => true,
            'approved' => $approved,
            'instance' => $instance,
            'rate' => $updated,
        ];
    } catch (\Throwable $e) {
        placementsWorkflowAudit($tenantId, (int) ($user['id'] ?? 0), 'placement.rate.approval_blocked', [
            'placement_id' => (int) ($rate['placement_id'] ?? 0),
            'rate_id' => $rateId,
            'control' => 'workflow_engine',
            'reason' => $e->getMessage(),
        ], (int) ($rate['placement_id'] ?? 0));
        throw $e;
    }
}
