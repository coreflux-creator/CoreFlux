<?php
/** Explicit, audited reassignment of a pending Billing-managed invoice review. */
declare(strict_types=1);

require_once __DIR__ . '/workflow.php';

function billingInvoiceApprovalAssignmentRead(int $tenantId, int $invoiceId, ?int $viewerUserId = null): array
{
    $invoice = billingInvoiceWorkflowRow($tenantId, $invoiceId);
    if (!$invoice) throw new OutOfBoundsException('Invoice not found.');

    $instanceId = billingInvoiceWorkflowPendingInstanceId($tenantId, $invoiceId,
        isset($invoice['workflow_instance_id']) ? (int) $invoice['workflow_instance_id'] : null);
    $priorStatus = null;
    if ($instanceId <= 0) {
        $prior = getDB()->prepare('SELECT status FROM workflow_instances
            WHERE tenant_id = :tenant_id AND subject_type = "billing_invoice"
              AND subject_id = :invoice_id ORDER BY id DESC LIMIT 1');
        $prior->execute(['tenant_id' => $tenantId, 'invoice_id' => $invoiceId]);
        $priorStatus = $prior->fetchColumn() ?: null;
    }
    $eligible = billingInvoiceEligibleReviewers($tenantId);
    $routing = $instanceId > 0 ? null : billingInvoiceApprovalRouting($tenantId, $invoice);
    $snapshot = null;
    $assignedIds = [];
    $payload = [];
    if ($instanceId > 0) {
        $stmt = getDB()->prepare('SELECT payload_json FROM workflow_instances
            WHERE tenant_id = :tenant_id AND id = :id AND subject_type = "billing_invoice"
              AND subject_id = :invoice_id AND status = "pending"');
        $stmt->execute(['tenant_id' => $tenantId, 'id' => $instanceId, 'invoice_id' => $invoiceId]);
        $payload = json_decode((string) ($stmt->fetchColumn() ?: '{}'), true) ?: [];
        if (array_key_exists('billing_reviewer_user_ids_snapshot', $payload)) {
            $snapshot = array_values(array_unique(array_map('intval',
                (array) $payload['billing_reviewer_user_ids_snapshot'])));
            $assignedIds = $snapshot;
        } else {
            $assignedIds = workflowResolveCurrentStepApprovers($tenantId, $instanceId);
        }
    }
    $eligibleById = array_column($eligible, null, 'id');
    $assigned = [];
    foreach ($assignedIds as $id) {
        $assigned[] = $eligibleById[$id] ?? [
            'id' => $id, 'name' => 'User #' . $id . ' (access removed)', 'email' => '',
        ];
    }
    $blocked = array_values(array_unique(array_merge(
        billingInvoiceWorkflowSodBlockedUserIds($invoice,
            isset($payload['started_by_user_id']) ? (int) $payload['started_by_user_id'] : null),
        array_map('intval', (array) ($payload['sod_blocked_user_ids'] ?? []))
    )));
    $required = $instanceId > 0 || ($routing && !empty($routing['workflow_required']));
    $available = $instanceId > 0
        ? array_diff(array_intersect($assignedIds, array_column($eligible, 'id')), $blocked) !== []
        : $required && !empty($routing['infrastructure_available'])
            && billingInvoiceHasIndependentApprover($tenantId, (array) $routing['requirements'], $blocked)
            && billingInvoiceManagedReviewerSnapshot($tenantId, $blocked) !== [];
    $requestBlocked = $viewerUserId ? array_values(array_unique([...$blocked, $viewerUserId])) : $blocked;
    $viewerCanRequest = $instanceId <= 0 && $priorStatus === null && $required
        && !empty($routing['infrastructure_available'])
        && billingInvoiceHasIndependentApprover($tenantId, (array) $routing['requirements'], $requestBlocked)
        && billingInvoiceManagedReviewerSnapshot($tenantId, $requestBlocked) !== [];
    $viewerCanApprove = $viewerUserId && !in_array($viewerUserId, $blocked, true)
        && in_array($viewerUserId, array_column($eligible, 'id'), true)
        && ($instanceId > 0 ? in_array($viewerUserId, $assignedIds, true)
            : $priorStatus === null && !$required);
    return [
        'invoice_id' => $invoiceId,
        'pending' => $instanceId > 0,
        'workflow_instance_id' => $instanceId ?: null,
        'prior_review_status' => $priorStatus,
        'managed_assignment' => $snapshot !== null,
        'approval_required' => (bool) $required,
        'assigned_reviewer_user_ids' => $assignedIds,
        'assigned_reviewers' => $assigned,
        'eligible_reviewers' => $eligible,
        'blocked_reviewer_user_ids' => $blocked,
        'approval_available' => (bool) $available,
        'viewer_can_request' => (bool) $viewerCanRequest,
        'viewer_can_approve' => (bool) $viewerCanApprove,
    ];
}

function billingInvoiceApprovalReassign(
    int $tenantId,
    int $invoiceId,
    array $reviewerIds,
    int $actorUserId
): array {
    if (!$reviewerIds || count($reviewerIds) > 20) {
        throw new InvalidArgumentException('Select between 1 and 20 invoice reviewers.');
    }
    $selected = [];
    foreach ($reviewerIds as $id) {
        if (!is_int($id) || $id <= 0) {
            throw new InvalidArgumentException('Reviewer IDs must be positive integers.');
        }
        $selected[$id] = $id;
    }
    $selected = array_values($selected);
    $eligibleIds = array_column(billingInvoiceEligibleReviewers($tenantId), 'id');
    if (array_diff($selected, $eligibleIds)) {
        throw new InvalidArgumentException('A selected reviewer is inactive or lacks Billing approval access.');
    }

    $pdo = getDB();
    $owns = !$pdo->inTransaction();
    $savepoint = $owns ? null : 'billing_reassign_' . bin2hex(random_bytes(4));
    if ($owns) $pdo->beginTransaction();
    else $pdo->exec('SAVEPOINT ' . $savepoint);
    $changed = false;
    $instanceId = 0;
    $payload = [];
    $step = null;
    try {
        $instanceId = billingInvoiceWorkflowPendingInstanceId($tenantId, $invoiceId);
        $stmt = $pdo->prepare('SELECT * FROM workflow_instances
            WHERE tenant_id = :tenant_id AND id = :id AND subject_type = "billing_invoice"
              AND subject_id = :invoice_id AND status = "pending" FOR UPDATE');
        $stmt->execute(['tenant_id' => $tenantId, 'id' => $instanceId, 'invoice_id' => $invoiceId]);
        $instance = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$instance) throw new DomainException('This invoice has no pending approval to reassign.');

        $invoiceStmt = $pdo->prepare('SELECT * FROM billing_invoices
            WHERE tenant_id = :tenant_id AND id = :id FOR UPDATE');
        $invoiceStmt->execute(['tenant_id' => $tenantId, 'id' => $invoiceId]);
        $invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice || $invoice['status'] !== 'draft') {
            throw new DomainException('Only a pending draft invoice can be reassigned.');
        }
        $payload = json_decode((string) ($instance['payload_json'] ?? '{}'), true) ?: [];
        if (!array_key_exists('billing_reviewer_user_ids_snapshot', $payload)) {
            throw new DomainException('This approval uses a custom policy; review it in People Graph.');
        }
        $blocked = array_values(array_unique(array_merge(
            billingInvoiceWorkflowSodBlockedUserIds($invoice,
                isset($instance['started_by_user_id']) ? (int) $instance['started_by_user_id'] : null),
            array_map('intval', (array) ($payload['sod_blocked_user_ids'] ?? []))
        )));
        if (array_intersect($selected, $blocked)) {
            throw new InvalidArgumentException('The invoice preparer or requester cannot review their own invoice.');
        }
        $previous = array_values(array_unique(array_map('intval',
            (array) $payload['billing_reviewer_user_ids_snapshot'])));
        if ($previous !== $selected) {
            $payload['billing_reviewer_user_ids_snapshot'] = $selected;
            $pdo->prepare('UPDATE workflow_instances
                SET payload_json = :payload, last_activity_at = NOW()
                WHERE tenant_id = :tenant_id AND id = :id AND status = "pending"')
                ->execute([
                    'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES),
                    'tenant_id' => $tenantId, 'id' => $instanceId,
                ]);
            billingWorkflowAudit($tenantId, $actorUserId, 'billing.invoice.approval_reassigned', [
                'invoice_id' => $invoiceId,
                'workflow_instance_id' => $instanceId,
                'old_reviewer_user_ids' => $previous,
                'new_reviewer_user_ids' => $selected,
            ], $invoiceId);
            $changed = true;
        }
        $def = $pdo->prepare('SELECT steps_json FROM workflow_definitions WHERE id = :id AND tenant_id = :tenant_id');
        $def->execute(['id' => (int) $instance['definition_id'], 'tenant_id' => $tenantId]);
        $steps = json_decode((string) ($def->fetchColumn() ?: '[]'), true) ?: [];
        $step = $steps[(int) $instance['current_step'] - 1] ?? null;
        if ($owns) $pdo->commit();
        else $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
    } catch (Throwable $e) {
        if ($owns && $pdo->inTransaction()) $pdo->rollBack();
        elseif (!$owns && $pdo->inTransaction()) {
            $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
            $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        }
        throw $e;
    }

    if ($changed && is_array($step)) {
        try {
            _workflowPushApprovers($tenantId, $instanceId, 'billing_invoice', $invoiceId, $step, $payload);
        } catch (Throwable $e) {
            error_log('[billing.invoice.reassign] notification failed: ' . $e->getMessage());
        }
    }
    return billingInvoiceApprovalAssignmentRead($tenantId, $invoiceId);
}
