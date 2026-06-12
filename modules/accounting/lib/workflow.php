<?php
/**
 * Accounting journal-entry WorkflowGraph bridge.
 *
 * Accounting owns JE draft/posting state. WorkflowGraph owns approval routing,
 * People Graph resolution, and separation-of-duties enforcement for manual JEs.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/../../../core/domain_people_graph.php';
require_once __DIR__ . '/../../../core/workflow_engine.php';

/** @internal */
function accountingWorkflowAudit(int $tenantId, ?int $actorUserId, string $event, array $meta = [], ?int $targetId = null): void
{
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
        error_log("[accounting.workflow.audit] db-write-failed: " . $e->getMessage() . " event={$event}");
    }
}

function accountingJeWorkflowRow(int $tenantId, int $jeId): ?array
{
    $pdo = getDB();
    if (!$pdo) return null;
    $stmt = $pdo->prepare(
        'SELECT * FROM accounting_journal_entries
          WHERE tenant_id = :t AND id = :id
          LIMIT 1'
    );
    $stmt->execute(['t' => $tenantId, 'id' => $jeId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** @internal */
function accountingJeWorkflowSteps(int $jeId): array
{
    $resolution = domainPeopleGraphWorkflowApproverResolution('accounting', 'journal_entry', $jeId, [
        'workflow_step' => 1,
        'workflow_step_label' => 'Journal entry approval',
        'separation_of_duties_required' => true,
    ], ['strategy' => 'approval_policy']);
    unset($resolution['resource_id'], $resolution['object_id']);
    $resolution['object_module'] = 'accounting';
    $resolution['object_type'] = 'journal_entry';
    $resolution['separation_of_duties_required'] = true;

    return [[
        'step' => 1,
        'label' => 'Journal entry approval',
        'approver_resolution' => $resolution,
        'approver_user_ids' => [],
        'fallback_approver_user_ids' => [],
        'quorum' => 1,
        'separation_of_duties_required' => true,
        'allow_email' => false,
    ]];
}

/** @internal */
function accountingJeWorkflowContext(array $je, ?int $starterUserId = null): array
{
    $jeId = (int) ($je['id'] ?? 0);
    $context = [
        'resource_module' => 'accounting',
        'resource_type' => 'journal_entry',
        'resource_id' => (string) $jeId,
        'object_module' => 'accounting',
        'object_type' => 'journal_entry',
        'object_id' => (string) $jeId,
        'approval_resource' => 'accounting.journal_entry',
        'je_id' => $jeId,
        'je_number' => $je['je_number'] ?? null,
        'entity_id' => isset($je['entity_id']) ? (int) $je['entity_id'] : null,
        'period_id' => isset($je['period_id']) ? (int) $je['period_id'] : null,
        'posting_date' => $je['posting_date'] ?? null,
        'source_module' => $je['source_module'] ?? null,
        'source_ref_type' => $je['source_ref_type'] ?? null,
        'source_ref_id' => isset($je['source_ref_id']) ? (int) $je['source_ref_id'] : null,
        'status' => $je['status'] ?? null,
        'approval_state' => $je['approval_state'] ?? null,
        'total_debit' => isset($je['total_debit']) ? (float) $je['total_debit'] : null,
        'total_credit' => isset($je['total_credit']) ? (float) $je['total_credit'] : null,
        'currency' => $je['currency'] ?? 'USD',
        'separation_of_duties_required' => true,
    ];

    foreach (['created_by_user_id', 'submitted_by_user_id'] as $key) {
        if (!empty($je[$key])) {
            $context[$key] = (int) $je[$key];
        }
    }
    if (!empty($je['created_by_user_id'])) {
        $createdBy = (int) $je['created_by_user_id'];
        $context['prepared_by_user_id'] = $createdBy;
        $context['preparer_user_id'] = $createdBy;
        $context['requester_user_id'] = $createdBy;
    }
    if (!empty($je['submitted_by_user_id'])) {
        $context['submitter_user_id'] = (int) $je['submitted_by_user_id'];
    }
    if ($starterUserId !== null && $starterUserId > 0) {
        $context['source_actor_type'] = 'user';
        $context['source_actor_id'] = $starterUserId;
        $context['started_by_user_id'] = $starterUserId;
    }

    return $context;
}

/** @internal */
function accountingJeWorkflowSodBlockedUserIds(array $je, ?int $starterUserId = null): array
{
    $ids = [];
    foreach ([$starterUserId, $je['created_by_user_id'] ?? null, $je['submitted_by_user_id'] ?? null] as $id) {
        if ((int) $id > 0) $ids[] = (int) $id;
    }
    return array_values(array_unique($ids));
}

/** @internal */
function accountingJeWorkflowPayload(array $je, ?int $starterUserId = null): array
{
    $jeId = (int) ($je['id'] ?? 0);
    $context = accountingJeWorkflowContext($je, $starterUserId);
    $blocked = accountingJeWorkflowSodBlockedUserIds($je, $starterUserId);
    $amount = number_format((float) ($je['total_debit'] ?? 0), 2);

    return [
        'title' => 'Journal entry needs approval',
        'body' => sprintf(
            'Journal entry %s for %s %s on %s.',
            (string) ($je['je_number'] ?? ('#' . $jeId)),
            (string) ($je['currency'] ?? 'USD'),
            $amount,
            (string) ($je['posting_date'] ?? '')
        ),
        'deep_link' => '/modules/accounting/journal/' . $jeId,
        'object_module' => 'accounting',
        'object_type' => 'journal_entry',
        'object_id' => (string) $jeId,
        'resource_module' => 'accounting',
        'resource_type' => 'journal_entry',
        'resource_id' => (string) $jeId,
        'context' => $context,
        'source_actor_type' => $starterUserId !== null && $starterUserId > 0 ? 'user' : null,
        'source_actor_id' => $starterUserId,
        'sod_required' => true,
        'separation_of_duties_required' => true,
        'sod_blocked_user_ids' => $blocked,
        'started_by_user_id' => $starterUserId,
    ];
}

function accountingJeWorkflowPendingInstanceId(int $tenantId, int $jeId, ?int $storedInstanceId = null): int
{
    $pdo = getDB();
    if (!$pdo) return 0;
    $instanceId = (int) ($storedInstanceId ?? 0);
    if ($instanceId > 0) {
        $check = $pdo->prepare(
            "SELECT id FROM workflow_instances
              WHERE tenant_id = :t AND id = :id AND subject_type = 'accounting_journal_entry' AND status = 'pending'"
        );
        $check->execute(['t' => $tenantId, 'id' => $instanceId]);
        $instanceId = (int) ($check->fetchColumn() ?: 0);
    }
    if ($instanceId > 0) return $instanceId;

    $row = $pdo->prepare(
        "SELECT id FROM workflow_instances
          WHERE tenant_id = :t AND subject_type = 'accounting_journal_entry' AND subject_id = :s AND status = 'pending'
          ORDER BY id DESC LIMIT 1"
    );
    $row->execute(['t' => $tenantId, 's' => $jeId]);
    return (int) ($row->fetchColumn() ?: 0);
}

function accountingJeWorkflowStart(int $tenantId, int $jeId, ?int $starterUserId = null): ?int
{
    $je = accountingJeWorkflowRow($tenantId, $jeId);
    if (!$je || (string) ($je['status'] ?? '') !== 'draft') return null;
    if ((string) ($je['approval_state'] ?? 'draft') === 'approved') return null;

    try {
        $existing = accountingJeWorkflowPendingInstanceId(
            $tenantId,
            $jeId,
            isset($je['workflow_instance_id']) ? (int) $je['workflow_instance_id'] : null
        );
        if ($existing > 0) {
            getDB()->prepare(
                "UPDATE accounting_journal_entries
                    SET workflow_instance_id = :w,
                        approval_state = 'pending_approval',
                        requires_approval = 1,
                        submitted_by_user_id = COALESCE(submitted_by_user_id, :u),
                        submitted_at = COALESCE(submitted_at, NOW())
                  WHERE tenant_id = :t AND id = :id AND status = 'draft'"
            )->execute(['w' => $existing, 'u' => $starterUserId, 't' => $tenantId, 'id' => $jeId]);
            return $existing;
        }

        $defKey = 'accounting_journal_entry_approval';
        workflowEnsureDefinition(
            $tenantId,
            $defKey,
            'accounting_journal_entry',
            'Journal entry approval',
            accountingJeWorkflowSteps($jeId)
        );
        $payload = accountingJeWorkflowPayload($je, $starterUserId);
        $instance = workflowStart($tenantId, $defKey, 'accounting_journal_entry', $jeId, $payload, $starterUserId);
        $instanceId = (int) ($instance['id'] ?? 0);
        if ($instanceId <= 0) return null;

        $pdo = getDB();
        $pdo->prepare(
            "UPDATE accounting_journal_entries
                SET workflow_instance_id = :w,
                    approval_state = 'pending_approval',
                    requires_approval = 1,
                    submitted_by_user_id = :u,
                    submitted_at = NOW(),
                    approved_by_user_id = NULL,
                    approved_at = NULL,
                    rejected_by_user_id = NULL,
                    rejected_at = NULL,
                    rejection_reason = NULL
              WHERE tenant_id = :t AND id = :id AND status = 'draft'"
        )->execute(['w' => $instanceId, 'u' => $starterUserId, 't' => $tenantId, 'id' => $jeId]);

        $latest = accountingJeWorkflowRow($tenantId, $jeId) ?? $je;
        $payload = accountingJeWorkflowPayload($latest, $starterUserId);
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

        accountingWorkflowAudit($tenantId, $starterUserId, 'accounting.je.submitted', [
            'je_id' => $jeId,
            'je_number' => $je['je_number'] ?? null,
            'workflow_instance_id' => $instanceId,
            'created_by_user_id' => $je['created_by_user_id'] ?? null,
        ], $jeId);
        accountingWorkflowAudit($tenantId, $starterUserId, 'accounting.je.workflow_started', [
            'je_id' => $jeId,
            'workflow_instance_id' => $instanceId,
        ], $jeId);
        return $instanceId;
    } catch (\Throwable $e) {
        accountingWorkflowAudit($tenantId, $starterUserId, 'accounting.je.workflow_start_failed', [
            'je_id' => $jeId,
            'reason' => $e->getMessage(),
        ], $jeId);
        error_log('[accounting.je.workflow] start failed: ' . $e->getMessage());
        return null;
    }
}

function accountingJeWorkflowAct(
    int $tenantId,
    int $jeId,
    int $userId,
    string $action = 'approve',
    ?string $note = null,
    string $via = 'app'
): array {
    $je = accountingJeWorkflowRow($tenantId, $jeId);
    if (!$je) throw new \RuntimeException("Journal entry {$jeId} not found");
    if (!in_array($action, ['approve', 'reject'], true)) {
        throw new \InvalidArgumentException('Unsupported journal entry workflow action');
    }
    if ((string) ($je['status'] ?? '') !== 'draft') {
        throw new \RuntimeException("Cannot {$action} JE with posting status {$je['status']}");
    }
    if (!in_array((string) ($je['approval_state'] ?? 'draft'), ['draft', 'pending_approval', 'rejected'], true)) {
        throw new \RuntimeException("Cannot {$action} JE with approval state {$je['approval_state']}");
    }

    try {
        $starter = !empty($je['submitted_by_user_id'])
            ? (int) $je['submitted_by_user_id']
            : (!empty($je['created_by_user_id']) ? (int) $je['created_by_user_id'] : null);
        $instanceId = accountingJeWorkflowPendingInstanceId(
            $tenantId,
            $jeId,
            isset($je['workflow_instance_id']) ? (int) $je['workflow_instance_id'] : null
        );
        if ($instanceId <= 0) {
            $instanceId = (int) (accountingJeWorkflowStart($tenantId, $jeId, $starter) ?? 0);
        }
        if ($instanceId <= 0) {
            throw new \RuntimeException('Could not start journal entry approval workflow');
        }

        $latest = accountingJeWorkflowRow($tenantId, $jeId) ?? $je;
        $payload = accountingJeWorkflowPayload($latest, $starter);
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
        $updated = accountingJeWorkflowRow($tenantId, $jeId) ?? $latest;
        $approved = (string) ($updated['approval_state'] ?? '') === 'approved';
        $rejected = (string) ($updated['approval_state'] ?? '') === 'rejected';
        if ($action === 'approve' && ($instance['status'] ?? null) === WORKFLOW_STATUS_APPROVED && !$approved) {
            throw new \RuntimeException('Workflow approved but journal entry sync did not apply');
        }
        if ($action === 'reject' && ($instance['status'] ?? null) === WORKFLOW_STATUS_REJECTED && !$rejected) {
            throw new \RuntimeException('Workflow rejected but journal entry sync did not apply');
        }

        accountingWorkflowAudit($tenantId, $userId, $action === 'approve'
            ? 'accounting.je.workflow_approved'
            : 'accounting.je.workflow_rejected', [
            'je_id' => $jeId,
            'workflow_instance_id' => $instanceId,
            'workflow_status' => $instance['status'] ?? null,
            'approved' => $approved,
            'rejected' => $rejected,
        ], $jeId);
        return [
            'applied' => true,
            'approved' => $approved,
            'rejected' => $rejected,
            'instance' => $instance,
            'journal_entry' => $updated,
        ];
    } catch (\Throwable $e) {
        accountingWorkflowAudit($tenantId, $userId, 'accounting.je.approval_blocked', [
            'je_id' => $jeId,
            'action' => $action,
            'control' => 'workflow_engine',
            'reason' => $e->getMessage(),
        ], $jeId);
        throw $e;
    }
}
