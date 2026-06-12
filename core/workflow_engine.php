<?php
/**
 * Generic WorkflowEngine (Sprint 4 / A1).
 *
 * Replaces hand-rolled per-module approval flows. Subject types in use:
 *   • 'ap_bill'                      (replaces ap_bill_approvals)
 *   • 'billing_invoice'              (Billing two-eye)
 *   • 'accounting_period_close'      (period close manager sign-off)
 *   • 'time_period'                  (time approval)
 *   • 'accounting_journal_entry'     (JE approval over threshold)
 *
 * Public surface:
 *   workflowDefine($tenantId, $defKey, $subjectType, $label, $steps, $opts) → definition_id
 *   workflowStart($tenantId, $defKey, $subjectType, $subjectId, $payload, $startedByUserId)
 *   workflowAct($tenantId, $instanceId, $userId, $action, $comment, $via)
 *   workflowGetPendingForUser($tenantId, $userId, $subjectType?)
 *   workflowGetInstance($tenantId, $instanceId)
 *
 * Step JSON shape (steps_json):
 *   [
 *     {
 *       "step": 1,
 *       "label": "Manager",
 *       "approver_user_ids": [12, 17],
 *       "approver_resolution": {
 *         "strategy": "responsibility|approval_policy|role|relationship|named_actor|manager_chain",
 *         "object_module": "ap",
 *         "object_type": "bill",
 *         "object_id": "123"
 *       },
 *       "quorum": 1,                  // # of approvals needed before advancing
 *       "separation_of_duties_required": true,
 *       "sod_blocked_user_ids": [44],  // optional explicit maker/preparer ids
 *       "allow_email": true,          // expose tokenized email link
 *       "sla_hours": 24,              // escalate after N hours
 *       "escalate_to_user_id": 3      // who gets the escalation push
 *     },
 *     ...
 *   ]
 *
 * VERTICAL-AGNOSTIC. Posts every state change to audit_log + fires push
 * notifications to step approvers via core/push_service.php.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/push_service.php';
require_once __DIR__ . '/people_graph.php';

const WORKFLOW_STATUS_PENDING   = 'pending';
const WORKFLOW_STATUS_APPROVED  = 'approved';
const WORKFLOW_STATUS_REJECTED  = 'rejected';
const WORKFLOW_STATUS_CANCELLED = 'cancelled';
const WORKFLOW_STATUS_ESCALATED = 'escalated';
const WORKFLOW_STATUS_EXPIRED   = 'expired';

/**
 * Upsert a workflow definition. Returns the definition row.
 */
function workflowDefine(int $tenantId, string $defKey, string $subjectType, string $label, array $steps, array $opts = []): array {
    $pdo = getDB();
    if (!$pdo) throw new \RuntimeException('No DB');
    if (!$steps) throw new \InvalidArgumentException('steps required');

    $existing = $pdo->prepare(
        "SELECT id, version FROM workflow_definitions
          WHERE tenant_id = :t AND def_key = :k AND active = 1
          ORDER BY version DESC LIMIT 1"
    );
    $existing->execute(['t' => $tenantId, 'k' => $defKey]);
    $prior = $existing->fetch(PDO::FETCH_ASSOC);

    $version = $prior ? ((int) $prior['version'] + 1) : 1;

    // Deactivate the prior version (definitions are versioned-immutable).
    if ($prior) {
        // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
        $pdo->prepare("UPDATE workflow_definitions SET active = 0 WHERE id = :id")
            ->execute(['id' => (int) $prior['id']]);
    }

    $ins = $pdo->prepare(
        "INSERT INTO workflow_definitions
           (tenant_id, def_key, label, description, subject_type, steps_json,
            notify_on_start, active, version, created_at)
         VALUES
           (:t, :k, :l, :d, :st, :sj, :nos, 1, :ver, NOW())"
    );
    $ins->execute([
        't'  => $tenantId,
        'k'  => $defKey,
        'l'  => $label,
        'd'  => $opts['description'] ?? null,
        'st' => $subjectType,
        'sj' => json_encode($steps, JSON_UNESCAPED_SLASHES),
        'nos'=> !empty($opts['notify_on_start']) ? 1 : 1, // default on
        'ver'=> $version,
    ]);
    $id = (int) $pdo->lastInsertId();

    return [
        'id' => $id, 'tenant_id' => $tenantId, 'def_key' => $defKey,
        'label' => $label, 'subject_type' => $subjectType,
        'steps' => $steps, 'version' => $version,
    ];
}

/**
 * Start a workflow instance for a subject. Idempotent — returns existing
 * pending instance if one already exists (same subject_type + subject_id).
 */
function workflowStart(int $tenantId, string $defKey, string $subjectType, int $subjectId, array $payload = [], ?int $startedByUserId = null): array {
    $pdo = getDB();
    if (!$pdo) throw new \RuntimeException('No DB');

    // Idempotency.
    $exist = $pdo->prepare(
        "SELECT * FROM workflow_instances
          WHERE tenant_id = :t AND subject_type = :st AND subject_id = :si
            AND status = 'pending'
          ORDER BY id DESC
          LIMIT 1"
    );
    $exist->execute(['t' => $tenantId, 'st' => $subjectType, 'si' => $subjectId]);
    if ($prior = $exist->fetch(PDO::FETCH_ASSOC)) {
        return _workflowHydrate($prior);
    }

    $defStmt = $pdo->prepare(
        "SELECT * FROM workflow_definitions
          WHERE tenant_id = :t AND def_key = :k AND active = 1
          ORDER BY version DESC LIMIT 1"
    );
    $defStmt->execute(['t' => $tenantId, 'k' => $defKey]);
    $def = $defStmt->fetch(PDO::FETCH_ASSOC);
    if (!$def) throw new \RuntimeException("Workflow definition '{$defKey}' not found");

    $steps = json_decode((string) $def['steps_json'], true) ?: [];
    if (!$steps) throw new \RuntimeException("Workflow '{$defKey}' has no steps");

    $sla = (int) ($steps[0]['sla_hours'] ?? 0);
    $slaDueAt = $sla > 0 ? (new DateTimeImmutable("+{$sla} hours"))->format('Y-m-d H:i:s') : null;

    $ins = $pdo->prepare(
        "INSERT INTO workflow_instances
           (tenant_id, definition_id, subject_type, subject_id, status, current_step,
            payload_json, started_by_user_id, started_at, sla_due_at, last_activity_at)
         VALUES
           (:t, :d, :st, :si, 'pending', 1, :pj, :u, NOW(), :sd, NOW())"
    );
    $ins->execute([
        't'  => $tenantId,
        'd'  => (int) $def['id'],
        'st' => $subjectType,
        'si' => $subjectId,
        'pj' => $payload ? json_encode($payload, JSON_UNESCAPED_SLASHES) : null,
        'u'  => $startedByUserId,
        'sd' => $slaDueAt,
    ]);
    $instanceId = (int) $pdo->lastInsertId();

    _workflowAuditEvent($tenantId, $startedByUserId, 'workflow.started', $instanceId, [
        'def_key' => $defKey, 'subject_type' => $subjectType, 'subject_id' => $subjectId,
    ]);

    // Fire push to step-1 approvers, unless the caller (e.g. the AP
    // router which emits its own AI-narrated push) asks us to stay quiet.
    if (empty($payload['suppress_push'])) {
        $step1 = $steps[0];
        _workflowPushApprovers($tenantId, $instanceId, $subjectType, $subjectId, $step1, $payload);
    }

    return _workflowHydrate(_workflowFetchRow($tenantId, $instanceId));
}

/**
 * Idempotent-by-shape definition upsert. Computes a stable hash of
 * `$steps + $label` and only bumps the definition's version when the
 * hash actually changes. Callers that know they want "one workflow
 * definition per AP approval policy" (or any other runtime-computed
 * chain) can call this instead of the raw `workflowDefine` to avoid
 * orphaning a new version row on every route.
 *
 * @return int   definition id (existing or newly inserted)
 */
function workflowEnsureDefinition(int $tenantId, string $defKey, string $subjectType, string $label, array $steps, array $opts = []): int {
    $pdo = getDB();
    if (!$pdo) throw new \RuntimeException('No DB');
    if (!$steps) throw new \InvalidArgumentException('steps required');

    $hashInput = json_encode(['l' => $label, 's' => $steps], JSON_UNESCAPED_SLASHES);
    $shapeHash = substr(hash('sha256', (string) $hashInput), 0, 16);

    $stmt = $pdo->prepare(
        "SELECT id, steps_json, label FROM workflow_definitions
          WHERE tenant_id = :t AND def_key = :k AND active = 1
          ORDER BY version DESC LIMIT 1"
    );
    $stmt->execute(['t' => $tenantId, 'k' => $defKey]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        $existingHash = substr(hash('sha256', (string) json_encode(
            ['l' => $existing['label'], 's' => json_decode((string) $existing['steps_json'], true) ?: []],
            JSON_UNESCAPED_SLASHES
        )), 0, 16);
        if ($existingHash === $shapeHash) return (int) $existing['id'];
    }

    $def = workflowDefine($tenantId, $defKey, $subjectType, $label, $steps, $opts);
    return (int) $def['id'];
}

/**
 * Record an actor's action. Advances or completes the workflow.
 *
 * @param string $action  one of: approve, reject, skip, delegate, comment, escalate
 * @return array  updated instance row
 */
function workflowAct(int $tenantId, int $instanceId, ?int $userId, string $action, ?string $comment = null, string $via = 'app', ?int $delegatedTo = null, ?string $actorEmail = null): array {
    $pdo = getDB();
    if (!$pdo) throw new \RuntimeException('No DB');

    $instance = _workflowFetchRow($tenantId, $instanceId);
    if (!$instance) throw new \RuntimeException("Instance not found");
    if ($instance['status'] !== WORKFLOW_STATUS_PENDING) {
        throw new \RuntimeException("Instance already {$instance['status']}");
    }

    // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
    $defStmt = $pdo->prepare("SELECT * FROM workflow_definitions WHERE id = :id");
    $defStmt->execute(['id' => (int) $instance['definition_id']]);
    $def = $defStmt->fetch(PDO::FETCH_ASSOC);
    $steps = json_decode((string) $def['steps_json'], true) ?: [];
    $payload = json_decode((string) ($instance['payload_json'] ?? '{}'), true) ?: [];
    $currentStepDef = $steps[(int) $instance['current_step'] - 1] ?? null;

    if (in_array($action, ['approve', 'reject', 'skip'], true) && is_array($currentStepDef)) {
        _workflowAssertCurrentApprover($tenantId, $instance, $currentStepDef, $payload, $userId);
        _workflowEnforceSeparationOfDuties($tenantId, $instance, $currentStepDef, $payload, $userId);
    }

    // Record the action.
    $pdo->prepare(
        "INSERT INTO workflow_step_actions
           (tenant_id, instance_id, step_no, actor_user_id, actor_email, action,
            delegated_to_user_id, comment, via, acted_at)
         VALUES (:t, :i, :s, :u, :ae, :a, :dt, :c, :v, NOW())"
    )->execute([
        't'  => $tenantId,
        'i'  => $instanceId,
        's'  => (int) $instance['current_step'],
        'u'  => $userId,
        'ae' => $actorEmail,
        'a'  => $action,
        'dt' => $delegatedTo,
        'c'  => $comment,
        'v'  => $via,
    ]);

    if ($action === 'reject') {
        $result = _workflowComplete($tenantId, $instanceId, WORKFLOW_STATUS_REJECTED, $userId, $comment);
        _workflowSubjectSync($tenantId, (string) $instance['subject_type'], (int) $instance['subject_id'],
                             $action, $userId, WORKFLOW_STATUS_REJECTED, $comment);
        return $result;
    }
    if ($action === 'comment') {
        // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
        $pdo->prepare("UPDATE workflow_instances SET last_activity_at = NOW() WHERE id = :id")->execute(['id' => $instanceId]);
        return _workflowHydrate(_workflowFetchRow($tenantId, $instanceId));
    }
    if ($action === 'delegate') {
        // Delegation does not advance the step but unblocks the delegate to approve.
        // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
        $pdo->prepare("UPDATE workflow_instances SET last_activity_at = NOW() WHERE id = :id")->execute(['id' => $instanceId]);
        return _workflowHydrate(_workflowFetchRow($tenantId, $instanceId));
    }

    // Approve / skip — check quorum on the current step.
    $stepIdx  = (int) $instance['current_step'] - 1;
    $stepDef  = $currentStepDef ?? ($steps[$stepIdx] ?? null);
    if (!$stepDef) {
        return _workflowComplete($tenantId, $instanceId, WORKFLOW_STATUS_APPROVED, $userId, $comment);
    }
    $quorum = max(1, (int) ($stepDef['quorum'] ?? 1));

    // tenant-leak-allow: defense-in-depth — caller scoped row by tenant_id before this id-only write
    $cnt = $pdo->prepare(
        "SELECT COUNT(*) FROM workflow_step_actions
          WHERE instance_id = :i AND step_no = :s AND action IN ('approve','skip')"
    );
    $cnt->execute(['i' => $instanceId, 's' => (int) $instance['current_step']]);
    $approved = (int) $cnt->fetchColumn();

    if ($approved < $quorum) {
        // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
        $pdo->prepare("UPDATE workflow_instances SET last_activity_at = NOW() WHERE id = :id")->execute(['id' => $instanceId]);
        // Sync the individual approval back to the legacy subject store
        // (e.g. ap_bill_approvals) even when the workflow itself hasn't
        // advanced yet, so per-approver rows flip 'pending' → 'approved'.
        _workflowSubjectSync($tenantId, (string) $instance['subject_type'], (int) $instance['subject_id'],
                             $action, $userId, WORKFLOW_STATUS_PENDING, $comment);
        return _workflowHydrate(_workflowFetchRow($tenantId, $instanceId));
    }

    // Advance to next step or complete.
    $nextIdx = $stepIdx + 1;
    if (!isset($steps[$nextIdx])) {
        $result = _workflowComplete($tenantId, $instanceId, WORKFLOW_STATUS_APPROVED, $userId, $comment);
        _workflowSubjectSync($tenantId, (string) $instance['subject_type'], (int) $instance['subject_id'],
                             $action, $userId, WORKFLOW_STATUS_APPROVED, $comment);
        return $result;
    }
    $nextStep = $steps[$nextIdx];
    $sla = (int) ($nextStep['sla_hours'] ?? 0);
    $slaDueAt = $sla > 0 ? (new DateTimeImmutable("+{$sla} hours"))->format('Y-m-d H:i:s') : null;
    // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
    $pdo->prepare(
        "UPDATE workflow_instances
            SET current_step = :s, sla_due_at = :sd, last_activity_at = NOW()
          WHERE id = :id"
    )->execute(['s' => $nextIdx + 1, 'sd' => $slaDueAt, 'id' => $instanceId]);

    _workflowAuditEvent($tenantId, $userId, 'workflow.advanced', $instanceId, [
        'from_step' => $stepIdx + 1, 'to_step' => $nextIdx + 1,
    ]);

    _workflowPushApprovers($tenantId, $instanceId,
        (string) $instance['subject_type'], (int) $instance['subject_id'],
        $nextStep, json_decode((string) ($instance['payload_json'] ?? '{}'), true) ?: []
    );

    // Sync the per-approver decision that caused the advance.
    _workflowSubjectSync($tenantId, (string) $instance['subject_type'], (int) $instance['subject_id'],
                         $action, $userId, WORKFLOW_STATUS_PENDING, $comment);

    return _workflowHydrate(_workflowFetchRow($tenantId, $instanceId));
}

/**
 * Pluggable subject-sync dispatch. Currently only `ap_bill` has a
 * legacy mirror; other subject types are no-ops. Kept at the bottom
 * of the engine so the core stays vertical-agnostic — each vertical
 * owns its own sync file under its module.
 */
function _workflowSubjectSync(int $tenantId, string $subjectType, int $subjectId, string $action, ?int $userId, string $instanceStatus, ?string $comment = null): void {
    try {
        if ($subjectType === 'ap_bill') {
            require_once __DIR__ . '/../modules/ap/lib/workflow_sync.php';
            if (function_exists('apSyncFromWorkflow')) {
                apSyncFromWorkflow($tenantId, $subjectId, $action, $userId, $instanceStatus, $comment);
            }
        }
        if ($subjectType === 'time_timesheet') {
            require_once __DIR__ . '/../modules/time/lib/workflow_sync.php';
            if (function_exists('timeSyncTimesheetFromWorkflow')) {
                timeSyncTimesheetFromWorkflow($tenantId, $subjectId, $action, $userId, $instanceStatus, $comment);
            }
        }
        if ($subjectType === 'payroll_run') {
            require_once __DIR__ . '/../modules/payroll/lib/workflow_sync.php';
            if (function_exists('payrollSyncRunFromWorkflow')) {
                payrollSyncRunFromWorkflow($tenantId, $subjectId, $action, $userId, $instanceStatus, $comment);
            }
        }
        if ($subjectType === 'placement_rate') {
            require_once __DIR__ . '/../modules/placements/lib/workflow_sync.php';
            if (function_exists('placementsSyncRateFromWorkflow')) {
                placementsSyncRateFromWorkflow($tenantId, $subjectId, $action, $userId, $instanceStatus, $comment);
            }
        }
    } catch (\Throwable $_) {
        // Absolutely non-fatal.
    }
}

/** @internal */
function _workflowAssertCurrentApprover(int $tenantId, array $instance, array $stepDef, array $payload, ?int $userId): void {
    if ($userId === null) return;
    $approvers = _workflowResolveStepApproverUserIds(
        $tenantId,
        (int) $instance['id'],
        (string) $instance['subject_type'],
        (int) $instance['subject_id'],
        $stepDef,
        $payload
    );
    if ($approvers && !in_array($userId, $approvers, true)) {
        _workflowAuditEvent($tenantId, $userId, 'workflow.approver_blocked', (int) $instance['id'], [
            'subject_type' => (string) $instance['subject_type'],
            'subject_id' => (int) $instance['subject_id'],
            'current_step' => (int) $instance['current_step'],
            'reason' => 'not_current_step_approver',
        ]);
        throw new \RuntimeException('Workflow actor is not an approver for the current step');
    }
}

/** @internal */
function _workflowEnforceSeparationOfDuties(int $tenantId, array $instance, array $stepDef, array $payload, ?int $userId): void {
    if ($userId === null) return;
    if (!_workflowStepRequiresSeparationOfDuties($tenantId, $instance, $stepDef, $payload)) return;

    $blockers = _workflowSeparationOfDutiesBlockedUsers($instance, $stepDef, $payload);
    if (!isset($blockers[$userId])) return;

    _workflowAuditEvent($tenantId, $userId, 'workflow.sod_blocked', (int) $instance['id'], [
        'subject_type' => (string) $instance['subject_type'],
        'subject_id' => (int) $instance['subject_id'],
        'current_step' => (int) $instance['current_step'],
        'blocked_user_id' => $userId,
        'sources' => $blockers[$userId],
    ]);
    throw new \RuntimeException('Separation of duties: actor cannot approve this workflow step because they originated, prepared, requested, or submitted the item');
}

/** @internal */
function _workflowStepRequiresSeparationOfDuties(int $tenantId, array $instance, array $stepDef, array $payload): bool {
    foreach ([$stepDef, $stepDef['approver_resolution'] ?? [], $stepDef['people_graph_resolution'] ?? [], $payload, $payload['context'] ?? []] as $source) {
        if (is_array($source) && _workflowBoolFlag($source, [
            'separation_of_duties_required',
            'requires_separation_of_duties',
            'sod_required',
            'two_eye_required',
        ]) === true) {
            return true;
        }
    }
    return _workflowApprovalPolicyRequiresSeparationOfDuties(
        $tenantId,
        (int) $instance['id'],
        (string) $instance['subject_type'],
        (int) $instance['subject_id'],
        $stepDef,
        $payload
    );
}

/** @internal */
function _workflowApprovalPolicyRequiresSeparationOfDuties(
    int $tenantId,
    int $instanceId,
    string $subjectType,
    int $subjectId,
    array $stepDef,
    array $payload
): bool {
    $resolution = $stepDef['approver_resolution']
        ?? $stepDef['assignee_resolution']
        ?? $stepDef['people_graph_resolution']
        ?? null;
    if (!is_array($resolution) || (string) ($resolution['strategy'] ?? '') !== 'approval_policy') return false;

    try {
        $object = _workflowPeopleGraphObjectRef($subjectType, $subjectId, $resolution, $payload);
        $context = is_array($resolution['context'] ?? null) ? $resolution['context'] : [];
        $context = array_merge(is_array($payload['context'] ?? null) ? $payload['context'] : [], $context);
        $resolved = peopleGraphResolveApprovers($tenantId, [
            'resource_module' => $resolution['resource_module'] ?? $object['object_module'],
            'resource_type' => $resolution['resource_type'] ?? $object['object_type'],
            'resource_id' => $resolution['resource_id'] ?? $object['object_id'],
            'scope_type' => $resolution['scope_type'] ?? null,
            'scope_id' => $resolution['scope_id'] ?? null,
            'context' => $context,
        ]);
        foreach ((array) ($resolved['requirements'] ?? []) as $requirement) {
            $rule = is_array($requirement['rule'] ?? null) ? $requirement['rule'] : [];
            if (!empty($rule['separation_of_duties_required'])) {
                _workflowAuditEvent($tenantId, null, 'workflow.sod_policy_required', $instanceId, [
                    'subject_type' => $subjectType,
                    'subject_id' => $subjectId,
                    'policy_id' => $requirement['policy']['id'] ?? null,
                    'rule_id' => $rule['id'] ?? null,
                ]);
                return true;
            }
        }
    } catch (\Throwable $e) {
        error_log('[workflow.sod] policy check skipped: ' . $e->getMessage());
    }
    return false;
}

/**
 * @internal
 * @return array<int, list<string>> blocked_user_id => evidence labels
 */
function _workflowSeparationOfDutiesBlockedUsers(array $instance, array $stepDef, array $payload): array {
    $blocked = [];
    _workflowAddSodUser($blocked, $instance['started_by_user_id'] ?? null, 'instance.started_by_user_id');
    _workflowCollectSodUsers($blocked, $stepDef, 'step');
    _workflowCollectSodUsers($blocked, $stepDef['approver_resolution'] ?? [], 'step.approver_resolution');
    _workflowCollectSodUsers($blocked, $stepDef['people_graph_resolution'] ?? [], 'step.people_graph_resolution');
    _workflowCollectSodUsers($blocked, $payload, 'payload');
    _workflowCollectSodUsers($blocked, $payload['context'] ?? [], 'payload.context');
    _workflowCollectSodUsers($blocked, $payload['sod'] ?? [], 'payload.sod');
    _workflowCollectSodUsers($blocked, $payload['separation_of_duties'] ?? [], 'payload.separation_of_duties');
    foreach ($blocked as &$sources) {
        $sources = array_values(array_unique($sources));
    }
    unset($sources);
    ksort($blocked);
    return $blocked;
}

/** @internal */
function _workflowCollectSodUsers(array &$blocked, mixed $source, string $prefix): void {
    if (!is_array($source)) return;
    foreach ([
        'started_by_user_id',
        'created_by_user_id',
        'computed_by_user_id',
        'prepared_by_user_id',
        'preparer_user_id',
        'submitted_by_user_id',
        'submitter_user_id',
        'requested_by_user_id',
        'requester_user_id',
        'originator_user_id',
        'initiator_user_id',
        'maker_user_id',
        'builder_user_id',
        'drafted_by_user_id',
        'imported_by_user_id',
    ] as $key) {
        _workflowAddSodUser($blocked, $source[$key] ?? null, "{$prefix}.{$key}");
    }
    foreach ([
        'sod_blocked_user_ids',
        'blocked_user_ids',
        'blocked_actor_user_ids',
        'blocked_approver_user_ids',
        'originator_user_ids',
        'preparer_user_ids',
        'submitter_user_ids',
        'requester_user_ids',
    ] as $key) {
        _workflowAddSodUser($blocked, $source[$key] ?? null, "{$prefix}.{$key}");
    }
    foreach ([
        ['source_actor_type', 'source_actor_id'],
        ['originator_actor_type', 'originator_actor_id'],
        ['preparer_actor_type', 'preparer_actor_id'],
        ['requester_actor_type', 'requester_actor_id'],
        ['submitter_actor_type', 'submitter_actor_id'],
    ] as [$typeKey, $idKey]) {
        if (($source[$typeKey] ?? null) === 'user') {
            _workflowAddSodUser($blocked, $source[$idKey] ?? null, "{$prefix}.{$idKey}");
        }
    }
}

/** @internal */
function _workflowAddSodUser(array &$blocked, mixed $value, string $source): void {
    if (is_array($value)) {
        foreach ($value as $item) _workflowAddSodUser($blocked, $item, $source);
        return;
    }
    $id = (int) $value;
    if ($id <= 0) return;
    $blocked[$id] = $blocked[$id] ?? [];
    $blocked[$id][] = $source;
}

/** @internal */
function _workflowBoolFlag(array $source, array $keys): ?bool {
    foreach ($keys as $key) {
        if (!array_key_exists($key, $source)) continue;
        $value = $source[$key];
        if (is_bool($value)) return $value;
        if (is_int($value) || is_float($value)) return ((int) $value) !== 0;
        if (is_string($value)) {
            $v = strtolower(trim($value));
            if (in_array($v, ['1', 'true', 'yes', 'on', 'required'], true)) return true;
            if (in_array($v, ['0', 'false', 'no', 'off', 'none'], true)) return false;
        }
    }
    return null;
}

/**
 * Inbox query — pending instances awaiting the user's action on the
 * current step.
 *
 * @return list<array>
 */
function workflowGetPendingForUser(int $tenantId, int $userId, ?string $subjectType = null): array {
    $pdo = getDB();
    if (!$pdo) return [];
    $sql = "SELECT i.*, d.def_key, d.label AS workflow_label, d.steps_json
              FROM workflow_instances i
              JOIN workflow_definitions d ON d.id = i.definition_id
             WHERE i.tenant_id = :t AND i.status = 'pending'";
    $params = ['t' => $tenantId];
    if ($subjectType) { $sql .= " AND i.subject_type = :st"; $params['st'] = $subjectType; }
    $sql .= " ORDER BY i.sla_due_at ASC, i.started_at ASC LIMIT 200";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $steps = json_decode((string) $r['steps_json'], true) ?: [];
        $stepDef = $steps[(int) $r['current_step'] - 1] ?? null;
        $payload = json_decode((string) ($r['payload_json'] ?? '{}'), true) ?: [];
        $approvers = $stepDef
            ? _workflowResolveStepApproverUserIds(
                $tenantId,
                (int) $r['id'],
                (string) $r['subject_type'],
                (int) $r['subject_id'],
                $stepDef,
                $payload
            )
            : [];
        if (in_array($userId, $approvers, true)) {
            $out[] = _workflowHydrate($r);
        }
    }
    return $out;
}

function workflowGetInstance(int $tenantId, int $instanceId): ?array {
    $row = _workflowFetchRow($tenantId, $instanceId);
    return $row ? _workflowHydrate($row) : null;
}

/**
 * Resolve the current step's approver users, including People Graph-backed
 * dynamic routing when the step declares approver_resolution.
 *
 * @return list<int>
 */
function workflowResolveCurrentStepApprovers(int $tenantId, int $instanceId): array {
    $row = _workflowFetchRow($tenantId, $instanceId);
    if (!$row) return [];
    $steps = json_decode((string) $row['steps_json'], true) ?: [];
    $stepDef = $steps[(int) $row['current_step'] - 1] ?? null;
    if (!$stepDef) return [];
    $payload = json_decode((string) ($row['payload_json'] ?? '{}'), true) ?: [];
    return _workflowResolveStepApproverUserIds(
        $tenantId,
        $instanceId,
        (string) $row['subject_type'],
        (int) $row['subject_id'],
        $stepDef,
        $payload
    );
}

/* ---------------------------------------------------------------------- */
/** @internal */
function _workflowFetchRow(int $tenantId, int $instanceId): ?array {
    $pdo = getDB();
    if (!$pdo) return null;
    $stmt = $pdo->prepare(
        "SELECT i.*, d.def_key, d.label AS workflow_label, d.steps_json
           FROM workflow_instances i
           JOIN workflow_definitions d ON d.id = i.definition_id
          WHERE i.tenant_id = :t AND i.id = :id"
    );
    $stmt->execute(['t' => $tenantId, 'id' => $instanceId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** @internal */
function _workflowHydrate(array $row): array {
    return [
        'id'            => (int) $row['id'],
        'tenant_id'     => (int) $row['tenant_id'],
        'definition_id' => (int) $row['definition_id'],
        'def_key'       => $row['def_key']        ?? null,
        'label'         => $row['workflow_label'] ?? null,
        'subject_type'  => $row['subject_type'],
        'subject_id'    => (int) $row['subject_id'],
        'status'        => $row['status'],
        'current_step'  => (int) $row['current_step'],
        'payload'       => $row['payload_json'] ? (json_decode((string) $row['payload_json'], true) ?: []) : [],
        'steps'         => $row['steps_json']   ? (json_decode((string) $row['steps_json'],   true) ?: []) : [],
        'sla_due_at'    => $row['sla_due_at'],
        'started_at'    => $row['started_at'],
        'completed_at'  => $row['completed_at'],
    ];
}

/** @internal */
function _workflowComplete(int $tenantId, int $instanceId, string $status, ?int $actorUserId, ?string $comment): array {
    $pdo = getDB();
    // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
    $pdo->prepare(
        "UPDATE workflow_instances SET status = :s, completed_at = NOW(), last_activity_at = NOW() WHERE id = :id"
    )->execute(['s' => $status, 'id' => $instanceId]);

    _workflowAuditEvent($tenantId, $actorUserId, 'workflow.completed', $instanceId, [
        'status' => $status, 'comment' => $comment,
    ]);
    return _workflowHydrate(_workflowFetchRow($tenantId, $instanceId));
}

/** @internal */
function _workflowPushApprovers(int $tenantId, int $instanceId, string $subjectType, int $subjectId, array $stepDef, array $payload): void {
    $userIds = _workflowResolveStepApproverUserIds($tenantId, $instanceId, $subjectType, $subjectId, $stepDef, $payload);
    if (!$userIds) return;
    $title = (string) ($payload['title'] ?? "Approval needed: {$subjectType} #{$subjectId}");
    $body  = (string) ($payload['body']  ?? "Open to review and approve.");
    $deepLink       = (string) ($payload['deep_link']        ?? "/modules/workflow/inbox?instance={$instanceId}");
    // Sprint 6 — mobile deep link routes a push tap straight to the bill /
    // workflow detail with 1-tap approve/reject. Always set so the Expo
    // notification handler can route without inspecting the web URL.
    $mobileDeepLink = (string) ($payload['mobile_deep_link'] ?? "coreflux://approvals/{$instanceId}");
    // Echo the deep links into the data payload so the mobile client sees them.
    $payload['deep_link']        = $deepLink;
    $payload['mobile_deep_link'] = $mobileDeepLink;
    $opts = [
        'category'        => 'workflow_approval',
        'deep_link'       => $deepLink,
        'mobile_deep_link'=> $mobileDeepLink,
        'source_module'   => 'workflow',
        'source_event'    => 'workflow.approval_required',
        'source_ref_type' => 'workflow_instance',
        'source_ref_id'   => $instanceId,
    ];
    foreach ($userIds as $uid) {
        try { pushSendToUser($tenantId, $uid, $title, $body, $payload, $opts); } catch (\Throwable $_) {}
    }
}

/** @internal */
function _workflowResolveStepApproverUserIds(
    int $tenantId,
    int $instanceId,
    string $subjectType,
    int $subjectId,
    array $stepDef,
    array $payload
): array {
    $explicit = array_values(array_unique(array_filter(array_map('intval', (array) ($stepDef['approver_user_ids'] ?? [])))));
    $resolution = $stepDef['approver_resolution']
        ?? $stepDef['assignee_resolution']
        ?? $stepDef['people_graph_resolution']
        ?? null;
    if (!is_array($resolution) || !$resolution) return $explicit;

    $actors = _workflowResolvePeopleGraphActors($tenantId, $instanceId, $subjectType, $subjectId, $resolution, $payload);
    $resolved = [];
    foreach ($actors as $actor) {
        $resolved = array_merge($resolved, _workflowPeopleGraphActorToUserIds($tenantId, $actor));
    }
    $resolved = array_values(array_unique(array_filter(array_map('intval', $resolved))));

    $fallback = array_values(array_unique(array_filter(array_map(
        'intval',
        (array) ($stepDef['fallback_approver_user_ids'] ?? $explicit)
    ))));
    return $resolved ?: $fallback;
}

/** @internal */
function _workflowResolvePeopleGraphActors(
    int $tenantId,
    int $instanceId,
    string $subjectType,
    int $subjectId,
    array $resolution,
    array $payload
): array {
    $strategy = (string) ($resolution['strategy'] ?? 'responsibility');
    $object = _workflowPeopleGraphObjectRef($subjectType, $subjectId, $resolution, $payload);
    $context = is_array($resolution['context'] ?? null) ? $resolution['context'] : [];
    $context = array_merge(is_array($payload['context'] ?? null) ? $payload['context'] : [], $context);

    try {
        if ($strategy === 'approval_policy') {
            $request = [
                'resource_module' => $resolution['resource_module'] ?? $object['object_module'],
                'resource_type' => $resolution['resource_type'] ?? $object['object_type'],
                'resource_id' => $resolution['resource_id'] ?? $object['object_id'],
                'scope_type' => $resolution['scope_type'] ?? null,
                'scope_id' => $resolution['scope_id'] ?? null,
                'context' => $context,
            ];
            foreach (['source_actor_type','source_actor_id'] as $key) {
                if (!empty($resolution[$key])) {
                    $request[$key] = $resolution[$key];
                } elseif (!empty($payload[$key])) {
                    $request[$key] = $payload[$key];
                } elseif (!empty($payload['context'][$key])) {
                    $request[$key] = $payload['context'][$key];
                }
            }
            $resolved = peopleGraphResolveApprovers($tenantId, $request);
            $actors = [];
            foreach (($resolved['requirements'] ?? []) as $requirement) {
                foreach (($requirement['approvers'] ?? []) as $approver) {
                    $actors[] = [
                        'actor_type' => (string) ($approver['actor_type'] ?? $approver['type'] ?? ''),
                        'actor_id' => (int) ($approver['actor_id'] ?? $approver['id'] ?? 0),
                    ];
                }
            }
            _workflowAuditEvent($tenantId, null, 'workflow.people_graph_resolved', $instanceId, [
                'strategy' => $strategy,
                'count' => count($actors),
            ]);
            return _workflowValidActorRefs($actors);
        }

        if ($strategy === 'responsibility' || !empty($resolution['question'])) {
            $question = (string) ($resolution['question'] ?? _workflowQuestionForResponsibility((string) ($resolution['responsibility_type'] ?? 'approver')));
            $resolved = peopleGraphResolve($tenantId, $question, $object, ['limit' => $resolution['limit'] ?? 100]);
            $actors = array_map(static fn($row) => [
                'actor_type' => (string) ($row['actor_type'] ?? ''),
                'actor_id' => (int) ($row['actor_id'] ?? 0),
            ], (array) ($resolved['assignments'] ?? []));
            _workflowAuditEvent($tenantId, null, 'workflow.people_graph_resolved', $instanceId, [
                'strategy' => $strategy,
                'question' => $question,
                'count' => count($actors),
            ]);
            return _workflowValidActorRefs($actors);
        }

        if ($strategy === 'named_actor') {
            return _workflowValidActorRefs([[
                'actor_type' => (string) ($resolution['actor_type'] ?? $resolution['approver_actor_type'] ?? ''),
                'actor_id' => (int) ($resolution['actor_id'] ?? $resolution['approver_actor_id'] ?? 0),
            ]]);
        }

        if ($strategy === 'role') {
            $roleId = (int) ($resolution['role_id'] ?? $resolution['approver_role_id'] ?? 0);
            if ($roleId <= 0 && !empty($resolution['role_key'])) {
                $role = peopleGraphFindByKey('people_graph_roles', 'role_key', $tenantId, (string) $resolution['role_key']);
                $roleId = (int) ($role['id'] ?? 0);
            }
            return $roleId > 0 ? [['actor_type' => 'role', 'actor_id' => $roleId]] : [];
        }

        if ($strategy === 'relationship' || $strategy === 'manager_chain') {
            $filters = [
                'relationship_type' => $strategy === 'manager_chain'
                    ? 'reports_to'
                    : (string) ($resolution['relationship_type'] ?? 'reports_to'),
            ];
            foreach (['source_actor_type','source_actor_id','target_actor_type','target_actor_id','context_module','context_entity_type','context_entity_id'] as $key) {
                if (!empty($resolution[$key])) $filters[$key] = $resolution[$key];
            }
            $returnActor = (string) ($resolution['return_actor'] ?? ($strategy === 'manager_chain' ? 'target' : 'target'));
            $rows = peopleGraphListRelationships($tenantId, $filters);
            $actors = array_map(static fn($row) => [
                'actor_type' => (string) ($row["{$returnActor}_actor_type"] ?? ''),
                'actor_id' => (int) ($row["{$returnActor}_actor_id"] ?? 0),
            ], $rows);
            return _workflowValidActorRefs($actors);
        }
    } catch (\Throwable $e) {
        error_log('[workflow.people_graph] resolution failed: ' . $e->getMessage());
    }

    return [];
}

/** @internal */
function _workflowPeopleGraphObjectRef(string $subjectType, int $subjectId, array $resolution, array $payload): array {
    $module = (string) (
        $resolution['object_module']
        ?? $resolution['resource_module']
        ?? $payload['object_module']
        ?? $payload['resource_module']
        ?? strtok($subjectType, '_')
        ?: 'workflow'
    );
    return [
        'object_module' => $module,
        'object_type' => (string) ($resolution['object_type'] ?? $resolution['resource_type'] ?? $payload['object_type'] ?? $payload['resource_type'] ?? $subjectType),
        'object_id' => (string) ($resolution['object_id'] ?? $resolution['resource_id'] ?? $payload['object_id'] ?? $payload['resource_id'] ?? $subjectId),
    ];
}

/** @internal */
function _workflowQuestionForResponsibility(string $responsibilityType): string {
    return match ($responsibilityType) {
        'owner', 'accountable' => 'who_owns',
        'reviewer' => 'who_reviews',
        'ai_supervisor' => 'who_reviews_ai',
        'notifier' => 'who_notifies',
        'escalation_contact' => 'who_escalates',
        'operator' => 'who_operates',
        'viewer' => 'who_can_view',
        default => 'who_approves',
    };
}

/** @internal */
function _workflowValidActorRefs(array $actors): array {
    $out = [];
    foreach ($actors as $actor) {
        $type = (string) ($actor['actor_type'] ?? $actor['type'] ?? '');
        $id = (int) ($actor['actor_id'] ?? $actor['id'] ?? 0);
        if ($type !== '' && $id > 0) $out[] = ['actor_type' => $type, 'actor_id' => $id];
    }
    return $out;
}

/** @internal */
function _workflowPeopleGraphActorToUserIds(int $tenantId, array $actor, array $seen = []): array {
    $type = (string) ($actor['actor_type'] ?? $actor['type'] ?? '');
    $id = (int) ($actor['actor_id'] ?? $actor['id'] ?? 0);
    if ($type === '' || $id <= 0) return [];
    $key = "{$type}:{$id}";
    if (isset($seen[$key])) return [];
    $seen[$key] = true;
    if ($type === 'user') return [$id];

    try {
        $pdo = getDB();
        if (!$pdo) return [];

        $link = function (string $actorType, int $actorId) use ($pdo, $tenantId): ?array {
            $stmt = $pdo->prepare(
                'SELECT * FROM people_graph_actor_links
                  WHERE tenant_id = :tenant_id AND actor_type = :actor_type AND actor_id = :actor_id AND status = "active"
                  LIMIT 1'
            );
            $stmt->execute(['tenant_id' => $tenantId, 'actor_type' => $actorType, 'actor_id' => $actorId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        };

        if ($type === 'person') {
            $row = $link($type, $id);
            if (!empty($row['user_id'])) return [(int) $row['user_id']];
            try {
                $stmt = $pdo->prepare('SELECT id FROM users WHERE person_id = :person_id LIMIT 1');
                $stmt->execute(['person_id' => $id]);
                $uid = (int) $stmt->fetchColumn();
                return $uid > 0 ? [$uid] : [];
            } catch (\Throwable $_) {
                return [];
            }
        }

        if ($type === 'team') {
            $stmt = $pdo->prepare(
                'SELECT member_actor_type, member_actor_id
                   FROM people_graph_team_memberships
                  WHERE tenant_id = :tenant_id AND team_id = :team_id AND status = "active"
                    AND (starts_at IS NULL OR starts_at <= NOW())
                    AND (ends_at IS NULL OR ends_at >= NOW())'
            );
            $stmt->execute(['tenant_id' => $tenantId, 'team_id' => $id]);
            $users = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $users = array_merge($users, _workflowPeopleGraphActorToUserIds($tenantId, [
                    'actor_type' => (string) $row['member_actor_type'],
                    'actor_id' => (int) $row['member_actor_id'],
                ], $seen));
            }
            return array_values(array_unique($users));
        }

        if ($type === 'role') {
            $stmt = $pdo->prepare(
                'SELECT actor_type, actor_id
                   FROM people_graph_role_assignments
                  WHERE tenant_id = :tenant_id AND role_id = :role_id AND status = "active"
                    AND (starts_at IS NULL OR starts_at <= NOW())
                    AND (ends_at IS NULL OR ends_at >= NOW())'
            );
            $stmt->execute(['tenant_id' => $tenantId, 'role_id' => $id]);
            $users = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $users = array_merge($users, _workflowPeopleGraphActorToUserIds($tenantId, [
                    'actor_type' => (string) $row['actor_type'],
                    'actor_id' => (int) $row['actor_id'],
                ], $seen));
            }
            return array_values(array_unique($users));
        }

        $row = $link($type, $id);
        if (!empty($row['user_id'])) return [(int) $row['user_id']];
        if (!empty($row['person_id'])) {
            return _workflowPeopleGraphActorToUserIds($tenantId, ['actor_type' => 'person', 'actor_id' => (int) $row['person_id']], $seen);
        }
    } catch (\Throwable $_) {
        return [];
    }

    return [];
}

/** @internal */
function _workflowAuditEvent(int $tenantId, ?int $userId, string $event, int $targetId, array $meta): void {
    $pdo = getDB();
    if (!$pdo) return;
    try {
        $pdo->prepare(
            "INSERT INTO audit_log (tenant_id, user_id, event, target_id, meta_json, ip_address, created_at)
             VALUES (:t, :u, :e, :ti, :m, :ip, NOW())"
        )->execute([
            't'  => $tenantId,
            'u'  => $userId,
            'e'  => $event,
            'ti' => $targetId,
            'm'  => json_encode($meta, JSON_UNESCAPED_SLASHES),
            'ip' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
        ]);
    } catch (\Throwable $_) { /* audit best-effort */ }
}
