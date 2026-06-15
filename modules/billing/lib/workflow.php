<?php
/**
 * Billing invoice WorkflowGraph bridge.
 *
 * Billing owns invoice state. WorkflowGraph owns approval routing,
 * People Graph resolution, and separation-of-duties enforcement.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/../../../core/audit.php';
require_once __DIR__ . '/../../../core/domain_people_graph.php';
require_once __DIR__ . '/../../../core/workflow_engine.php';
require_once __DIR__ . '/billing.php';

/** @internal */
function billingWorkflowAudit(
    int $tenantId,
    ?int $actorUserId,
    string $event,
    array $meta = [],
    ?int $targetId = null,
    array $opts = []
): void
{
    platformAuditLogWrite($tenantId, $actorUserId, $event, $targetId, $meta, array_merge([
        'object_type' => 'billing_invoice',
        'source' => $meta['source'] ?? 'billing',
    ], $opts));
}

function billingInvoiceWorkflowRow(int $tenantId, int $invoiceId): ?array
{
    $pdo = getDB();
    if (!$pdo) return null;
    $stmt = $pdo->prepare(
        'SELECT * FROM billing_invoices
          WHERE tenant_id = :t AND id = :id
          LIMIT 1'
    );
    $stmt->execute(['t' => $tenantId, 'id' => $invoiceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** @internal */
function billingInvoiceWorkflowSteps(int $invoiceId): array
{
    $resolution = domainPeopleGraphWorkflowApproverResolution('billing', 'invoice', $invoiceId, [
        'workflow_step' => 1,
        'workflow_step_label' => 'Billing invoice approval',
        'separation_of_duties_required' => true,
    ], ['strategy' => 'approval_policy']);
    unset($resolution['resource_id'], $resolution['object_id']);
    $resolution['object_module'] = 'billing';
    $resolution['object_type'] = 'invoice';
    $resolution['separation_of_duties_required'] = true;

    return [[
        'step' => 1,
        'label' => 'Billing invoice approval',
        'approver_resolution' => $resolution,
        'approver_user_ids' => [],
        'fallback_approver_user_ids' => [],
        'quorum' => 1,
        'separation_of_duties_required' => true,
        'allow_email' => false,
    ]];
}

/** @internal */
function billingInvoiceWorkflowContext(array $invoice, ?int $starterUserId = null): array
{
    $invoiceId = (int) ($invoice['id'] ?? 0);
    $context = [
        'resource_module' => 'billing',
        'resource_type' => 'invoice',
        'resource_id' => (string) $invoiceId,
        'object_module' => 'billing',
        'object_type' => 'invoice',
        'object_id' => (string) $invoiceId,
        'approval_resource' => 'billing.invoice',
        'invoice_id' => $invoiceId,
        'invoice_number' => $invoice['invoice_number'] ?? null,
        'client_name' => $invoice['client_name'] ?? null,
        'client_company_id' => isset($invoice['client_company_id']) ? (int) $invoice['client_company_id'] : null,
        'entity_id' => isset($invoice['entity_id']) ? (int) $invoice['entity_id'] : null,
        'issue_date' => $invoice['issue_date'] ?? null,
        'due_date' => $invoice['due_date'] ?? null,
        'subtotal' => isset($invoice['subtotal']) ? (float) $invoice['subtotal'] : null,
        'tax_total' => isset($invoice['tax_total']) ? (float) $invoice['tax_total'] : null,
        'total' => isset($invoice['total']) ? (float) $invoice['total'] : null,
        'amount_due' => isset($invoice['amount_due']) ? (float) $invoice['amount_due'] : null,
        'currency' => $invoice['currency'] ?? 'USD',
        'separation_of_duties_required' => true,
    ];

    if (!empty($invoice['created_by_user_id'])) {
        $createdBy = (int) $invoice['created_by_user_id'];
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
function billingInvoiceWorkflowSodBlockedUserIds(array $invoice, ?int $starterUserId = null): array
{
    $ids = [];
    foreach ([$starterUserId, $invoice['created_by_user_id'] ?? null] as $id) {
        if ((int) $id > 0) $ids[] = (int) $id;
    }
    return array_values(array_unique($ids));
}

/** @internal */
function billingInvoiceWorkflowPayload(array $invoice, ?int $starterUserId = null): array
{
    $invoiceId = (int) ($invoice['id'] ?? 0);
    $context = billingInvoiceWorkflowContext($invoice, $starterUserId);
    $blocked = billingInvoiceWorkflowSodBlockedUserIds($invoice, $starterUserId);
    $amount = number_format((float) ($invoice['amount_due'] ?? $invoice['total'] ?? 0), 2);

    return [
        'title' => 'Billing invoice needs approval',
        'body' => sprintf(
            'Invoice %s for %s, %s %s due %s.',
            (string) ($invoice['invoice_number'] ?? ('#' . $invoiceId)),
            (string) ($invoice['client_name'] ?? 'client'),
            (string) ($invoice['currency'] ?? 'USD'),
            $amount,
            (string) ($invoice['due_date'] ?? '')
        ),
        'deep_link' => '/modules/billing/invoices/' . $invoiceId,
        'object_module' => 'billing',
        'object_type' => 'invoice',
        'object_id' => (string) $invoiceId,
        'resource_module' => 'billing',
        'resource_type' => 'invoice',
        'resource_id' => (string) $invoiceId,
        'context' => $context,
        'source_actor_type' => $starterUserId !== null && $starterUserId > 0 ? 'user' : null,
        'source_actor_id' => $starterUserId,
        'sod_required' => true,
        'separation_of_duties_required' => true,
        'sod_blocked_user_ids' => $blocked,
        'started_by_user_id' => $starterUserId,
    ];
}

function billingInvoiceWorkflowStart(int $tenantId, int $invoiceId, ?int $starterUserId = null): ?int
{
    $invoice = billingInvoiceWorkflowRow($tenantId, $invoiceId);
    if (!$invoice || !billingTransitionAllowed((string) ($invoice['status'] ?? ''), 'approved')) return null;

    try {
        $defKey = 'billing_invoice_approval';
        workflowEnsureDefinition(
            $tenantId,
            $defKey,
            'billing_invoice',
            'Billing invoice approval',
            billingInvoiceWorkflowSteps($invoiceId)
        );
        $payload = billingInvoiceWorkflowPayload($invoice, $starterUserId);
        $instance = workflowStart($tenantId, $defKey, 'billing_invoice', $invoiceId, $payload, $starterUserId);
        $instanceId = (int) ($instance['id'] ?? 0);
        if ($instanceId <= 0) return null;

        $pdo = getDB();
        try {
            $pdo->prepare(
                'UPDATE billing_invoices
                    SET workflow_instance_id = :w, updated_at = NOW()
                  WHERE tenant_id = :t AND id = :id'
            )->execute(['w' => $instanceId, 't' => $tenantId, 'id' => $invoiceId]);
        } catch (\Throwable $_) { /* schema drift: workflow instance still exists */ }

        $latest = billingInvoiceWorkflowRow($tenantId, $invoiceId) ?? $invoice;
        $payload = billingInvoiceWorkflowPayload($latest, $starterUserId);
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

        billingWorkflowAudit($tenantId, $starterUserId, 'billing.invoice.workflow_started', [
            'invoice_id' => $invoiceId,
            'invoice_number' => $invoice['invoice_number'] ?? null,
            'workflow_instance_id' => $instanceId,
            'created_by_user_id' => $invoice['created_by_user_id'] ?? null,
        ], $invoiceId, [
            'before' => $invoice,
            'after' => $latest,
        ]);
        return $instanceId;
    } catch (\Throwable $e) {
        billingWorkflowAudit($tenantId, $starterUserId, 'billing.invoice.workflow_start_failed', [
            'invoice_id' => $invoiceId,
            'reason' => $e->getMessage(),
        ], $invoiceId);
        error_log('[billing.invoice.workflow] start failed: ' . $e->getMessage());
        return null;
    }
}

function billingInvoiceWorkflowPendingInstanceId(int $tenantId, int $invoiceId, ?int $storedInstanceId = null): int
{
    $pdo = getDB();
    if (!$pdo) return 0;
    $instanceId = (int) ($storedInstanceId ?? 0);
    if ($instanceId > 0) {
        $check = $pdo->prepare(
            "SELECT id FROM workflow_instances
              WHERE tenant_id = :t AND id = :id AND subject_type = 'billing_invoice' AND status = 'pending'"
        );
        $check->execute(['t' => $tenantId, 'id' => $instanceId]);
        $instanceId = (int) ($check->fetchColumn() ?: 0);
    }
    if ($instanceId > 0) return $instanceId;

    $row = $pdo->prepare(
        "SELECT id FROM workflow_instances
          WHERE tenant_id = :t AND subject_type = 'billing_invoice' AND subject_id = :s AND status = 'pending'
          ORDER BY id DESC LIMIT 1"
    );
    $row->execute(['t' => $tenantId, 's' => $invoiceId]);
    return (int) ($row->fetchColumn() ?: 0);
}

function billingInvoiceWorkflowAct(
    int $tenantId,
    int $invoiceId,
    int $userId,
    string $action = 'approve',
    ?string $note = null,
    string $via = 'app'
): array {
    $invoice = billingInvoiceWorkflowRow($tenantId, $invoiceId);
    if (!$invoice) throw new \RuntimeException("Invoice {$invoiceId} not found");
    if (!billingTransitionAllowed((string) ($invoice['status'] ?? ''), 'approved')) {
        throw new \RuntimeException("Cannot approve from status {$invoice['status']}");
    }

    try {
        $starter = !empty($invoice['created_by_user_id']) ? (int) $invoice['created_by_user_id'] : null;
        $instanceId = billingInvoiceWorkflowPendingInstanceId(
            $tenantId,
            $invoiceId,
            isset($invoice['workflow_instance_id']) ? (int) $invoice['workflow_instance_id'] : null
        );
        if ($instanceId <= 0) {
            $instanceId = (int) (billingInvoiceWorkflowStart($tenantId, $invoiceId, $starter) ?? 0);
        }
        if ($instanceId <= 0) {
            throw new \RuntimeException('Could not start billing invoice approval workflow');
        }

        $payload = billingInvoiceWorkflowPayload($invoice, $starter);
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
        $updated = billingInvoiceWorkflowRow($tenantId, $invoiceId) ?? $invoice;
        $approved = (string) ($updated['status'] ?? '') === 'approved';
        if (($instance['status'] ?? null) === WORKFLOW_STATUS_APPROVED && !$approved) {
            throw new \RuntimeException('Workflow approved but billing invoice sync did not apply');
        }

        billingWorkflowAudit($tenantId, $userId, 'billing.invoice.workflow_approved', [
            'invoice_id' => $invoiceId,
            'workflow_instance_id' => $instanceId,
            'workflow_status' => $instance['status'] ?? null,
            'approved' => $approved,
        ], $invoiceId, [
            'before' => $invoice,
            'after' => $updated,
        ]);
        return [
            'applied' => true,
            'approved' => $approved,
            'instance' => $instance,
            'invoice' => $updated,
        ];
    } catch (\Throwable $e) {
        billingWorkflowAudit($tenantId, $userId, 'billing.invoice.approval_blocked', [
            'invoice_id' => $invoiceId,
            'control' => 'workflow_engine',
            'reason' => $e->getMessage(),
        ], $invoiceId);
        throw $e;
    }
}
