<?php
/**
 * Treasury money-movement WorkflowGraph bridge.
 *
 * Treasury owns payment/transfer state. WorkflowGraph owns approval routing,
 * People Graph resolution, and separation-of-duties enforcement.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/../../../core/audit.php';
require_once __DIR__ . '/../../../core/domain_people_graph.php';
require_once __DIR__ . '/../../../core/workflow_engine.php';

/** @internal */
function treasuryWorkflowAudit(
    int $tenantId,
    ?int $actorUserId,
    string $event,
    array $meta = [],
    ?int $targetId = null,
    array $opts = []
): void
{
    platformAuditLogWrite($tenantId, $actorUserId, $event, $targetId, $meta, array_merge([
        'object_type' => treasuryWorkflowAuditObjectType($event),
        'source' => $meta['source'] ?? 'treasury',
    ], $opts));
}

/** @internal */
function treasuryWorkflowAuditObjectType(string $event): string
{
    if (str_contains($event, '.transfer.')) return 'treasury_transfer';
    if (str_contains($event, '.payment.')) return 'treasury_payment';
    return 'treasury_money_movement';
}

function treasuryPaymentWorkflowRow(int $tenantId, int $paymentId): ?array
{
    $pdo = getDB();
    if (!$pdo) return null;
    $stmt = $pdo->prepare(
        'SELECT * FROM treasury_payments
          WHERE tenant_id = :t AND id = :id
          LIMIT 1'
    );
    $stmt->execute(['t' => $tenantId, 'id' => $paymentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function treasuryTransferWorkflowRow(int $tenantId, int $transferId): ?array
{
    $pdo = getDB();
    if (!$pdo) return null;
    $stmt = $pdo->prepare(
        'SELECT * FROM treasury_transfers
          WHERE tenant_id = :t AND id = :id
          LIMIT 1'
    );
    $stmt->execute(['t' => $tenantId, 'id' => $transferId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** @internal */
function treasuryPaymentWorkflowSteps(int $paymentId): array
{
    $resolution = domainPeopleGraphWorkflowApproverResolution('treasury', 'payment', $paymentId, [
        'workflow_step' => 1,
        'workflow_step_label' => 'Treasury payment approval',
        'separation_of_duties_required' => true,
    ], ['strategy' => 'approval_policy']);
    unset($resolution['resource_id'], $resolution['object_id']);
    $resolution['object_module'] = 'treasury';
    $resolution['object_type'] = 'payment';
    $resolution['separation_of_duties_required'] = true;

    return [[
        'step' => 1,
        'label' => 'Treasury payment approval',
        'approver_resolution' => $resolution,
        'approver_user_ids' => [],
        'fallback_approver_user_ids' => [],
        'quorum' => 1,
        'separation_of_duties_required' => true,
        'allow_email' => false,
    ]];
}

/** @internal */
function treasuryTransferWorkflowSteps(int $transferId): array
{
    $resolution = domainPeopleGraphWorkflowApproverResolution('treasury', 'transfer', $transferId, [
        'workflow_step' => 1,
        'workflow_step_label' => 'Treasury transfer approval',
        'separation_of_duties_required' => true,
    ], ['strategy' => 'approval_policy']);
    unset($resolution['resource_id'], $resolution['object_id']);
    $resolution['object_module'] = 'treasury';
    $resolution['object_type'] = 'transfer';
    $resolution['separation_of_duties_required'] = true;

    return [[
        'step' => 1,
        'label' => 'Treasury transfer approval',
        'approver_resolution' => $resolution,
        'approver_user_ids' => [],
        'fallback_approver_user_ids' => [],
        'quorum' => 1,
        'separation_of_duties_required' => true,
        'allow_email' => false,
    ]];
}

/** @internal */
function treasuryPaymentWorkflowContext(array $payment, ?int $starterUserId = null): array
{
    $paymentId = (int) ($payment['id'] ?? 0);
    $context = [
        'resource_module' => 'treasury',
        'resource_type' => 'payment',
        'resource_id' => (string) $paymentId,
        'object_module' => 'treasury',
        'object_type' => 'payment',
        'object_id' => (string) $paymentId,
        'approval_resource' => 'treasury.payment',
        'payment_id' => $paymentId,
        'payment_number' => $payment['payment_number'] ?? null,
        'entity_id' => isset($payment['entity_id']) ? (int) $payment['entity_id'] : null,
        'payee_type' => $payment['payee_type'] ?? null,
        'payee_id' => isset($payment['payee_id']) ? (int) $payment['payee_id'] : null,
        'payee_name' => $payment['payee_name'] ?? null,
        'amount' => isset($payment['amount']) ? (float) $payment['amount'] : null,
        'currency' => $payment['currency'] ?? 'USD',
        'payment_date' => $payment['payment_date'] ?? null,
        'payment_method' => $payment['payment_method'] ?? null,
        'bank_account_id' => isset($payment['bank_account_id']) ? (int) $payment['bank_account_id'] : null,
        'counterparty_account_id' => isset($payment['counterparty_account_id']) ? (int) $payment['counterparty_account_id'] : null,
        'status' => $payment['status'] ?? null,
        'separation_of_duties_required' => true,
    ];

    if (!empty($payment['created_by_user_id'])) {
        $createdBy = (int) $payment['created_by_user_id'];
        $context['created_by_user_id'] = $createdBy;
        $context['prepared_by_user_id'] = $createdBy;
        $context['preparer_user_id'] = $createdBy;
        $context['requester_user_id'] = $createdBy;
    }
    if ($starterUserId !== null && $starterUserId > 0) {
        $context['source_actor_type'] = 'user';
        $context['source_actor_id'] = $starterUserId;
        $context['started_by_user_id'] = $starterUserId;
    }

    return $context;
}

/** @internal */
function treasuryTransferWorkflowContext(array $transfer, ?int $starterUserId = null): array
{
    $transferId = (int) ($transfer['id'] ?? 0);
    $context = [
        'resource_module' => 'treasury',
        'resource_type' => 'transfer',
        'resource_id' => (string) $transferId,
        'object_module' => 'treasury',
        'object_type' => 'transfer',
        'object_id' => (string) $transferId,
        'approval_resource' => 'treasury.transfer',
        'transfer_id' => $transferId,
        'transfer_number' => $transfer['transfer_number'] ?? null,
        'transfer_kind' => $transfer['transfer_kind'] ?? null,
        'source_bank_account_id' => isset($transfer['source_bank_account_id']) ? (int) $transfer['source_bank_account_id'] : null,
        'destination_bank_account_id' => isset($transfer['destination_bank_account_id']) ? (int) $transfer['destination_bank_account_id'] : null,
        'source_entity_id' => isset($transfer['source_entity_id']) ? (int) $transfer['source_entity_id'] : null,
        'destination_entity_id' => isset($transfer['destination_entity_id']) ? (int) $transfer['destination_entity_id'] : null,
        'amount' => isset($transfer['amount']) ? (float) $transfer['amount'] : null,
        'currency' => $transfer['currency'] ?? 'USD',
        'transfer_date' => $transfer['transfer_date'] ?? null,
        'status' => $transfer['status'] ?? null,
        'separation_of_duties_required' => true,
    ];

    if (!empty($transfer['created_by_user_id'])) {
        $createdBy = (int) $transfer['created_by_user_id'];
        $context['created_by_user_id'] = $createdBy;
        $context['prepared_by_user_id'] = $createdBy;
        $context['preparer_user_id'] = $createdBy;
        $context['requester_user_id'] = $createdBy;
    }
    if ($starterUserId !== null && $starterUserId > 0) {
        $context['source_actor_type'] = 'user';
        $context['source_actor_id'] = $starterUserId;
        $context['started_by_user_id'] = $starterUserId;
    }

    return $context;
}

/** @internal */
function treasuryPaymentWorkflowSodBlockedUserIds(array $payment, ?int $starterUserId = null): array
{
    $ids = [];
    foreach ([$starterUserId, $payment['created_by_user_id'] ?? null] as $id) {
        if ((int) $id > 0) $ids[] = (int) $id;
    }
    return array_values(array_unique($ids));
}

/** @internal */
function treasuryTransferWorkflowSodBlockedUserIds(array $transfer, ?int $starterUserId = null): array
{
    $ids = [];
    foreach ([$starterUserId, $transfer['created_by_user_id'] ?? null] as $id) {
        if ((int) $id > 0) $ids[] = (int) $id;
    }
    return array_values(array_unique($ids));
}

/** @internal */
function treasuryPaymentWorkflowPayload(array $payment, ?int $starterUserId = null): array
{
    $paymentId = (int) ($payment['id'] ?? 0);
    $context = treasuryPaymentWorkflowContext($payment, $starterUserId);
    $blocked = treasuryPaymentWorkflowSodBlockedUserIds($payment, $starterUserId);
    $amount = number_format((float) ($payment['amount'] ?? 0), 2);

    return [
        'title' => 'Treasury payment needs approval',
        'body' => sprintf(
            'Payment %s to %s for %s %s on %s.',
            (string) ($payment['payment_number'] ?? ('#' . $paymentId)),
            (string) ($payment['payee_name'] ?? 'payee'),
            (string) ($payment['currency'] ?? 'USD'),
            $amount,
            (string) ($payment['payment_date'] ?? '')
        ),
        'deep_link' => '/modules/treasury/payments/' . $paymentId,
        'object_module' => 'treasury',
        'object_type' => 'payment',
        'object_id' => (string) $paymentId,
        'resource_module' => 'treasury',
        'resource_type' => 'payment',
        'resource_id' => (string) $paymentId,
        'context' => $context,
        'source_actor_type' => $starterUserId !== null && $starterUserId > 0 ? 'user' : null,
        'source_actor_id' => $starterUserId,
        'sod_required' => true,
        'separation_of_duties_required' => true,
        'sod_blocked_user_ids' => $blocked,
        'started_by_user_id' => $starterUserId,
    ];
}

/** @internal */
function treasuryTransferWorkflowPayload(array $transfer, ?int $starterUserId = null): array
{
    $transferId = (int) ($transfer['id'] ?? 0);
    $context = treasuryTransferWorkflowContext($transfer, $starterUserId);
    $blocked = treasuryTransferWorkflowSodBlockedUserIds($transfer, $starterUserId);
    $amount = number_format((float) ($transfer['amount'] ?? 0), 2);

    return [
        'title' => 'Treasury transfer needs approval',
        'body' => sprintf(
            'Transfer %s for %s %s on %s.',
            (string) ($transfer['transfer_number'] ?? ('#' . $transferId)),
            (string) ($transfer['currency'] ?? 'USD'),
            $amount,
            (string) ($transfer['transfer_date'] ?? '')
        ),
        'deep_link' => '/modules/treasury/transfers/' . $transferId,
        'object_module' => 'treasury',
        'object_type' => 'transfer',
        'object_id' => (string) $transferId,
        'resource_module' => 'treasury',
        'resource_type' => 'transfer',
        'resource_id' => (string) $transferId,
        'context' => $context,
        'source_actor_type' => $starterUserId !== null && $starterUserId > 0 ? 'user' : null,
        'source_actor_id' => $starterUserId,
        'sod_required' => true,
        'separation_of_duties_required' => true,
        'sod_blocked_user_ids' => $blocked,
        'started_by_user_id' => $starterUserId,
    ];
}

function treasuryPaymentWorkflowStart(int $tenantId, int $paymentId, ?int $starterUserId = null): ?int
{
    $payment = treasuryPaymentWorkflowRow($tenantId, $paymentId);
    if (!$payment || !in_array((string) ($payment['status'] ?? ''), ['draft', 'pending_approval'], true)) {
        return null;
    }

    try {
        $defKey = 'treasury_payment_approval';
        workflowEnsureDefinition(
            $tenantId,
            $defKey,
            'treasury_payment',
            'Treasury payment approval',
            treasuryPaymentWorkflowSteps($paymentId)
        );
        $payload = treasuryPaymentWorkflowPayload($payment, $starterUserId);
        $instance = workflowStart($tenantId, $defKey, 'treasury_payment', $paymentId, $payload, $starterUserId);
        $instanceId = (int) ($instance['id'] ?? 0);
        if ($instanceId <= 0) return null;

        $pdo = getDB();
        $pdo->prepare(
            "UPDATE treasury_payments
                SET workflow_instance_id = :w,
                    status = CASE WHEN status = 'draft' THEN 'pending_approval' ELSE status END,
                    updated_at = NOW()
              WHERE tenant_id = :t AND id = :id"
        )->execute(['w' => $instanceId, 't' => $tenantId, 'id' => $paymentId]);

        $latest = treasuryPaymentWorkflowRow($tenantId, $paymentId) ?? $payment;
        $payload = treasuryPaymentWorkflowPayload($latest, $starterUserId);
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

        treasuryWorkflowAudit($tenantId, $starterUserId, 'treasury.payment.workflow_started', [
            'payment_id' => $paymentId,
            'payment_number' => $payment['payment_number'] ?? null,
            'workflow_instance_id' => $instanceId,
            'created_by_user_id' => $payment['created_by_user_id'] ?? null,
        ], $paymentId, [
            'before' => $payment,
            'after' => $latest,
        ]);
        return $instanceId;
    } catch (\Throwable $e) {
        treasuryWorkflowAudit($tenantId, $starterUserId, 'treasury.payment.workflow_start_failed', [
            'payment_id' => $paymentId,
            'reason' => $e->getMessage(),
        ], $paymentId);
        error_log('[treasury.payment.workflow] start failed: ' . $e->getMessage());
        return null;
    }
}

function treasuryTransferWorkflowStart(int $tenantId, int $transferId, ?int $starterUserId = null): ?int
{
    $transfer = treasuryTransferWorkflowRow($tenantId, $transferId);
    if (!$transfer || !in_array((string) ($transfer['status'] ?? ''), ['draft', 'pending_approval'], true)) {
        return null;
    }

    try {
        $defKey = 'treasury_transfer_approval';
        workflowEnsureDefinition(
            $tenantId,
            $defKey,
            'treasury_transfer',
            'Treasury transfer approval',
            treasuryTransferWorkflowSteps($transferId)
        );
        $payload = treasuryTransferWorkflowPayload($transfer, $starterUserId);
        $instance = workflowStart($tenantId, $defKey, 'treasury_transfer', $transferId, $payload, $starterUserId);
        $instanceId = (int) ($instance['id'] ?? 0);
        if ($instanceId <= 0) return null;

        $pdo = getDB();
        $pdo->prepare(
            "UPDATE treasury_transfers
                SET workflow_instance_id = :w,
                    status = CASE WHEN status = 'draft' THEN 'pending_approval' ELSE status END,
                    updated_at = NOW()
              WHERE tenant_id = :t AND id = :id"
        )->execute(['w' => $instanceId, 't' => $tenantId, 'id' => $transferId]);

        $latest = treasuryTransferWorkflowRow($tenantId, $transferId) ?? $transfer;
        $payload = treasuryTransferWorkflowPayload($latest, $starterUserId);
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

        treasuryWorkflowAudit($tenantId, $starterUserId, 'treasury.transfer.workflow_started', [
            'transfer_id' => $transferId,
            'transfer_number' => $transfer['transfer_number'] ?? null,
            'workflow_instance_id' => $instanceId,
            'created_by_user_id' => $transfer['created_by_user_id'] ?? null,
        ], $transferId, [
            'before' => $transfer,
            'after' => $latest,
        ]);
        return $instanceId;
    } catch (\Throwable $e) {
        treasuryWorkflowAudit($tenantId, $starterUserId, 'treasury.transfer.workflow_start_failed', [
            'transfer_id' => $transferId,
            'reason' => $e->getMessage(),
        ], $transferId);
        error_log('[treasury.transfer.workflow] start failed: ' . $e->getMessage());
        return null;
    }
}

function treasuryPaymentWorkflowPendingInstanceId(int $tenantId, int $paymentId, ?int $storedInstanceId = null): int
{
    $pdo = getDB();
    if (!$pdo) return 0;
    $instanceId = (int) ($storedInstanceId ?? 0);
    if ($instanceId > 0) {
        $check = $pdo->prepare(
            "SELECT id FROM workflow_instances
              WHERE tenant_id = :t AND id = :id AND subject_type = 'treasury_payment' AND status = 'pending'"
        );
        $check->execute(['t' => $tenantId, 'id' => $instanceId]);
        $instanceId = (int) ($check->fetchColumn() ?: 0);
    }
    if ($instanceId > 0) return $instanceId;

    $row = $pdo->prepare(
        "SELECT id FROM workflow_instances
          WHERE tenant_id = :t AND subject_type = 'treasury_payment' AND subject_id = :s AND status = 'pending'
          ORDER BY id DESC LIMIT 1"
    );
    $row->execute(['t' => $tenantId, 's' => $paymentId]);
    return (int) ($row->fetchColumn() ?: 0);
}

function treasuryTransferWorkflowPendingInstanceId(int $tenantId, int $transferId, ?int $storedInstanceId = null): int
{
    $pdo = getDB();
    if (!$pdo) return 0;
    $instanceId = (int) ($storedInstanceId ?? 0);
    if ($instanceId > 0) {
        $check = $pdo->prepare(
            "SELECT id FROM workflow_instances
              WHERE tenant_id = :t AND id = :id AND subject_type = 'treasury_transfer' AND status = 'pending'"
        );
        $check->execute(['t' => $tenantId, 'id' => $instanceId]);
        $instanceId = (int) ($check->fetchColumn() ?: 0);
    }
    if ($instanceId > 0) return $instanceId;

    $row = $pdo->prepare(
        "SELECT id FROM workflow_instances
          WHERE tenant_id = :t AND subject_type = 'treasury_transfer' AND subject_id = :s AND status = 'pending'
          ORDER BY id DESC LIMIT 1"
    );
    $row->execute(['t' => $tenantId, 's' => $transferId]);
    return (int) ($row->fetchColumn() ?: 0);
}

function treasuryPaymentWorkflowAct(
    int $tenantId,
    int $paymentId,
    int $userId,
    string $action = 'approve',
    ?string $note = null,
    string $via = 'app'
): array {
    $payment = treasuryPaymentWorkflowRow($tenantId, $paymentId);
    if (!$payment) throw new \RuntimeException("Payment {$paymentId} not found");
    if (!in_array($action, ['approve', 'reject'], true)) {
        throw new \InvalidArgumentException('Unsupported payment workflow action');
    }
    if (!in_array((string) ($payment['status'] ?? ''), ['draft', 'pending_approval'], true)) {
        throw new \RuntimeException("Cannot {$action} from status {$payment['status']}");
    }

    try {
        $starter = !empty($payment['created_by_user_id']) ? (int) $payment['created_by_user_id'] : null;
        $instanceId = treasuryPaymentWorkflowPendingInstanceId(
            $tenantId,
            $paymentId,
            isset($payment['workflow_instance_id']) ? (int) $payment['workflow_instance_id'] : null
        );
        if ($instanceId <= 0) {
            $instanceId = (int) (treasuryPaymentWorkflowStart($tenantId, $paymentId, $starter) ?? 0);
        }
        if ($instanceId <= 0) {
            throw new \RuntimeException('Could not start treasury payment approval workflow');
        }

        $latest = treasuryPaymentWorkflowRow($tenantId, $paymentId) ?? $payment;
        $payload = treasuryPaymentWorkflowPayload($latest, $starter);
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

        $instance = workflowAct($tenantId, $instanceId, $userId, $action, $note ?: null, $via);
        $updated = treasuryPaymentWorkflowRow($tenantId, $paymentId) ?? $latest;
        $approved = (string) ($updated['status'] ?? '') === 'approved';
        $rejected = (string) ($updated['status'] ?? '') === 'rejected';
        if ($action === 'approve' && ($instance['status'] ?? null) === WORKFLOW_STATUS_APPROVED && !$approved) {
            throw new \RuntimeException('Workflow approved but treasury payment sync did not apply');
        }
        if ($action === 'reject' && ($instance['status'] ?? null) === WORKFLOW_STATUS_REJECTED && !$rejected) {
            throw new \RuntimeException('Workflow rejected but treasury payment sync did not apply');
        }

        treasuryWorkflowAudit($tenantId, $userId, $action === 'approve'
            ? 'treasury.payment.workflow_approved'
            : 'treasury.payment.workflow_rejected', [
            'payment_id' => $paymentId,
            'workflow_instance_id' => $instanceId,
            'workflow_status' => $instance['status'] ?? null,
            'approved' => $approved,
            'rejected' => $rejected,
        ], $paymentId, [
            'before' => $latest,
            'after' => $updated,
        ]);
        return [
            'applied' => true,
            'approved' => $approved,
            'rejected' => $rejected,
            'instance' => $instance,
            'payment' => $updated,
        ];
    } catch (\Throwable $e) {
        treasuryWorkflowAudit($tenantId, $userId, 'treasury.payment.approval_blocked', [
            'payment_id' => $paymentId,
            'action' => $action,
            'control' => 'workflow_engine',
            'reason' => $e->getMessage(),
        ], $paymentId);
        throw $e;
    }
}

function treasuryTransferWorkflowAct(
    int $tenantId,
    int $transferId,
    int $userId,
    string $action = 'approve',
    ?string $note = null,
    string $via = 'app'
): array {
    $transfer = treasuryTransferWorkflowRow($tenantId, $transferId);
    if (!$transfer) throw new \RuntimeException("Transfer {$transferId} not found");
    if (!in_array($action, ['approve', 'reject'], true)) {
        throw new \InvalidArgumentException('Unsupported transfer workflow action');
    }
    if (!in_array((string) ($transfer['status'] ?? ''), ['draft', 'pending_approval'], true)) {
        throw new \RuntimeException("Cannot {$action} from status {$transfer['status']}");
    }

    try {
        $starter = !empty($transfer['created_by_user_id']) ? (int) $transfer['created_by_user_id'] : null;
        $instanceId = treasuryTransferWorkflowPendingInstanceId(
            $tenantId,
            $transferId,
            isset($transfer['workflow_instance_id']) ? (int) $transfer['workflow_instance_id'] : null
        );
        if ($instanceId <= 0) {
            $instanceId = (int) (treasuryTransferWorkflowStart($tenantId, $transferId, $starter) ?? 0);
        }
        if ($instanceId <= 0) {
            throw new \RuntimeException('Could not start treasury transfer approval workflow');
        }

        $latest = treasuryTransferWorkflowRow($tenantId, $transferId) ?? $transfer;
        $payload = treasuryTransferWorkflowPayload($latest, $starter);
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

        $instance = workflowAct($tenantId, $instanceId, $userId, $action, $note ?: null, $via);
        $updated = treasuryTransferWorkflowRow($tenantId, $transferId) ?? $latest;
        $approved = (string) ($updated['status'] ?? '') === 'approved';
        $rejected = (string) ($updated['status'] ?? '') === 'rejected';
        if ($action === 'approve' && ($instance['status'] ?? null) === WORKFLOW_STATUS_APPROVED && !$approved) {
            throw new \RuntimeException('Workflow approved but treasury transfer sync did not apply');
        }
        if ($action === 'reject' && ($instance['status'] ?? null) === WORKFLOW_STATUS_REJECTED && !$rejected) {
            throw new \RuntimeException('Workflow rejected but treasury transfer sync did not apply');
        }

        treasuryWorkflowAudit($tenantId, $userId, $action === 'approve'
            ? 'treasury.transfer.workflow_approved'
            : 'treasury.transfer.workflow_rejected', [
            'transfer_id' => $transferId,
            'workflow_instance_id' => $instanceId,
            'workflow_status' => $instance['status'] ?? null,
            'approved' => $approved,
            'rejected' => $rejected,
        ], $transferId, [
            'before' => $latest,
            'after' => $updated,
        ]);
        return [
            'applied' => true,
            'approved' => $approved,
            'rejected' => $rejected,
            'instance' => $instance,
            'transfer' => $updated,
        ];
    } catch (\Throwable $e) {
        treasuryWorkflowAudit($tenantId, $userId, 'treasury.transfer.approval_blocked', [
            'transfer_id' => $transferId,
            'action' => $action,
            'control' => 'workflow_engine',
            'reason' => $e->getMessage(),
        ], $transferId);
        throw $e;
    }
}
