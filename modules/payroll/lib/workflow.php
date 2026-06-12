<?php
/**
 * Payroll run WorkflowGraph bridge.
 *
 * Payroll owns run state. WorkflowGraph owns approval routing and SoD, using
 * People Graph to resolve who may approve a run.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/../../../core/tenant_scope.php';
require_once __DIR__ . '/../../../core/domain_people_graph.php';
require_once __DIR__ . '/../../../core/workflow_engine.php';
require_once __DIR__ . '/payroll.php';

/** @internal */
function payrollRunWorkflowRow(int $runId): ?array {
    return scopedFind(
        'SELECT r.*, pp.schedule_id, pp.period_start, pp.period_end, pp.pay_date
           FROM payroll_runs r
           JOIN payroll_pay_periods pp ON pp.id = r.pay_period_id AND pp.tenant_id = r.tenant_id
          WHERE r.tenant_id = :tenant_id AND r.id = :id',
        ['id' => $runId]
    ) ?: null;
}

/** @internal */
function payrollRunWorkflowSteps(int $runId): array {
    $resolution = domainPeopleGraphWorkflowApproverResolution('payroll', 'run', $runId, [
        'workflow_step' => 1,
        'workflow_step_label' => 'Payroll run approval',
        'separation_of_duties_required' => true,
    ], ['strategy' => 'approval_policy']);
    unset($resolution['resource_id'], $resolution['object_id']);
    $resolution['object_module'] = 'payroll';
    $resolution['object_type'] = 'run';
    $resolution['separation_of_duties_required'] = true;

    return [[
        'step' => 1,
        'label' => 'Payroll run approval',
        'approver_resolution' => $resolution,
        'approver_user_ids' => [],
        'fallback_approver_user_ids' => [],
        'quorum' => 1,
        'separation_of_duties_required' => true,
        'allow_email' => false,
    ]];
}

/** @internal */
function payrollRunWorkflowContext(array $run, ?int $actorUserId = null): array {
    $runId = (int) ($run['id'] ?? 0);
    $context = [
        'resource_module' => 'payroll',
        'resource_type' => 'run',
        'resource_id' => (string) $runId,
        'object_module' => 'payroll',
        'object_type' => 'run',
        'object_id' => (string) $runId,
        'approval_resource' => 'payroll.run',
        'run_id' => $runId,
        'pay_period_id' => isset($run['pay_period_id']) ? (int) $run['pay_period_id'] : null,
        'schedule_id' => isset($run['schedule_id']) ? (int) $run['schedule_id'] : null,
        'period_start' => $run['period_start'] ?? null,
        'period_end' => $run['period_end'] ?? null,
        'pay_date' => $run['pay_date'] ?? null,
        'run_type' => $run['run_type'] ?? null,
        'employee_count' => isset($run['employee_count']) ? (int) $run['employee_count'] : null,
        'gross_total_cents' => isset($run['gross_total_cents']) ? (int) $run['gross_total_cents'] : null,
        'net_total_cents' => isset($run['net_total_cents']) ? (int) $run['net_total_cents'] : null,
        'employer_taxes_cents' => isset($run['employer_taxes_cents']) ? (int) $run['employer_taxes_cents'] : null,
        'separation_of_duties_required' => true,
    ];

    foreach (['created_by_user_id', 'computed_by_user_id'] as $key) {
        if (isset($run[$key]) && (int) $run[$key] > 0) {
            $context[$key] = (int) $run[$key];
        }
    }
    if (!empty($run['created_by_user_id'])) {
        $context['preparer_user_id'] = (int) $run['created_by_user_id'];
        $context['requester_user_id'] = (int) $run['created_by_user_id'];
    }
    if (!empty($run['computed_by_user_id'])) {
        $context['submitter_user_id'] = (int) $run['computed_by_user_id'];
        $context['prepared_by_user_id'] = (int) $run['computed_by_user_id'];
    }
    if ($actorUserId !== null && $actorUserId > 0) {
        $context['source_actor_type'] = 'user';
        $context['source_actor_id'] = $actorUserId;
    }

    return $context;
}

/** @internal */
function payrollRunWorkflowSodBlockedUserIds(array $run, ?int $actorUserId = null): array {
    $ids = [];
    foreach ([$actorUserId, $run['created_by_user_id'] ?? null, $run['computed_by_user_id'] ?? null] as $id) {
        if ((int) $id > 0) $ids[] = (int) $id;
    }
    return array_values(array_unique($ids));
}

/** @internal */
function payrollRunWorkflowPayload(array $run, ?int $actorUserId = null): array {
    $runId = (int) ($run['id'] ?? 0);
    $context = payrollRunWorkflowContext($run, $actorUserId);
    $blocked = payrollRunWorkflowSodBlockedUserIds($run, $actorUserId);
    $net = number_format(((int) ($run['net_total_cents'] ?? 0)) / 100, 2);

    return [
        'title' => 'Payroll run needs approval',
        'body' => sprintf(
            'Payroll run #%d for pay date %s, net $%s across %d employees.',
            $runId,
            (string) ($run['pay_date'] ?? ''),
            $net,
            (int) ($run['employee_count'] ?? 0)
        ),
        'deep_link' => '/modules/payroll/runs/' . $runId,
        'object_module' => 'payroll',
        'object_type' => 'run',
        'object_id' => (string) $runId,
        'resource_module' => 'payroll',
        'resource_type' => 'run',
        'resource_id' => (string) $runId,
        'context' => $context,
        'source_actor_type' => $actorUserId !== null && $actorUserId > 0 ? 'user' : null,
        'source_actor_id' => $actorUserId,
        'sod_required' => true,
        'separation_of_duties_required' => true,
        'sod_blocked_user_ids' => $blocked,
        'started_by_user_id' => $actorUserId,
    ];
}

function payrollRunWorkflowStart(int $tenantId, int $runId, ?int $actorUserId = null): ?int {
    $run = payrollRunWorkflowRow($runId);
    if (!$run) return null;

    try {
        $defKey = 'payroll_run_approval';
        workflowEnsureDefinition(
            $tenantId,
            $defKey,
            'payroll_run',
            'Payroll run approval',
            payrollRunWorkflowSteps($runId)
        );
        $payload = payrollRunWorkflowPayload($run, $actorUserId);
        $instance = workflowStart($tenantId, $defKey, 'payroll_run', $runId, $payload, $actorUserId);
        $instanceId = (int) ($instance['id'] ?? 0);
        if ($instanceId <= 0) return null;

        $pdo = getDB();
        try {
            $pdo->prepare(
                'UPDATE payroll_runs
                    SET workflow_instance_id = :w, updated_at = NOW()
                  WHERE tenant_id = :t AND id = :id'
            )->execute(['w' => $instanceId, 't' => $tenantId, 'id' => $runId]);
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

        payrollAudit('payroll.run.workflow_started', [
            'run_id' => $runId,
            'workflow_instance_id' => $instanceId,
            'computed_by_user_id' => $run['computed_by_user_id'] ?? null,
        ], $runId);
        return $instanceId;
    } catch (\Throwable $e) {
        payrollAudit('payroll.run.workflow_start_failed', [
            'run_id' => $runId,
            'reason' => $e->getMessage(),
        ], $runId);
        error_log('[payroll.workflow] start failed: ' . $e->getMessage());
        return null;
    }
}

function payrollRunWorkflowCancelPending(int $tenantId, int $runId, ?int $actorUserId = null, string $reason = 'recompute'): int {
    $pdo = getDB();
    if (!$pdo) return 0;
    $stmt = $pdo->prepare(
        "SELECT id FROM workflow_instances
          WHERE tenant_id = :t AND subject_type = 'payroll_run' AND subject_id = :s AND status = 'pending'"
    );
    $stmt->execute(['t' => $tenantId, 's' => $runId]);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if (!$ids) return 0;
    $in = implode(',', array_map('intval', $ids));
    $pdo->exec(
        "UPDATE workflow_instances
            SET status = 'cancelled', completed_at = NOW(), last_activity_at = NOW()
          WHERE tenant_id = " . (int) $tenantId . " AND id IN ({$in})"
    );
    foreach ($ids as $instanceId) {
        payrollAudit('payroll.run.workflow_cancelled', [
            'run_id' => $runId,
            'workflow_instance_id' => $instanceId,
            'reason' => $reason,
            'actor_user_id' => $actorUserId,
        ], $runId);
    }
    return count($ids);
}

function payrollRunWorkflowAct(int $tenantId, array $run, int $userId, string $action, ?string $note = null): array {
    $runId = (int) ($run['id'] ?? 0);
    if ($runId <= 0) throw new \InvalidArgumentException('payroll run id required');
    try {
        $pdo = getDB();
        $instanceId = (int) ($run['workflow_instance_id'] ?? 0);
        if ($instanceId > 0) {
            $check = $pdo->prepare(
                "SELECT id FROM workflow_instances
                  WHERE tenant_id = :t AND id = :id AND status = 'pending'"
            );
            $check->execute(['t' => $tenantId, 'id' => $instanceId]);
            $instanceId = (int) ($check->fetchColumn() ?: 0);
        }
        if ($instanceId <= 0) {
            $row = $pdo->prepare(
                "SELECT id FROM workflow_instances
                  WHERE tenant_id = :t AND subject_type = 'payroll_run' AND subject_id = :s AND status = 'pending'
                  ORDER BY id DESC LIMIT 1"
            );
            $row->execute(['t' => $tenantId, 's' => $runId]);
            $instanceId = (int) ($row->fetchColumn() ?: 0);
        }
        if ($instanceId <= 0) {
            $instanceId = (int) (payrollRunWorkflowStart($tenantId, $runId, null) ?? 0);
        }
        if ($instanceId <= 0) {
            throw new \RuntimeException('Could not start payroll approval workflow');
        }

        $instance = workflowAct($tenantId, $instanceId, $userId, $action, $note ?: null, 'app');
        $updated = payrollRunWorkflowRow($runId) ?? $run;
        $approved = (string) ($updated['status'] ?? '') === 'approved';
        if (($instance['status'] ?? null) === WORKFLOW_STATUS_APPROVED && !$approved) {
            throw new \RuntimeException('Workflow approved but payroll run sync did not apply');
        }

        return [
            'applied' => true,
            'approved' => $approved,
            'instance' => $instance,
            'run' => $updated,
        ];
    } catch (\Throwable $e) {
        payrollAudit('payroll.run.approval_blocked', [
            'run_id' => $runId,
            'action' => $action,
            'control' => 'workflow_engine',
            'reason' => $e->getMessage(),
        ], $runId);
        throw $e;
    }
}
