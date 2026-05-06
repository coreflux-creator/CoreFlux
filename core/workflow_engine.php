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
 *       "quorum": 1,                  // # of approvals needed before advancing
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

    $defStmt = $pdo->prepare("SELECT * FROM workflow_definitions WHERE id = :id");
    $defStmt->execute(['id' => (int) $instance['definition_id']]);
    $def = $defStmt->fetch(PDO::FETCH_ASSOC);
    $steps = json_decode((string) $def['steps_json'], true) ?: [];

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
                             $action, $userId, WORKFLOW_STATUS_REJECTED);
        return $result;
    }
    if ($action === 'comment') {
        $pdo->prepare("UPDATE workflow_instances SET last_activity_at = NOW() WHERE id = :id")->execute(['id' => $instanceId]);
        return _workflowHydrate(_workflowFetchRow($tenantId, $instanceId));
    }
    if ($action === 'delegate') {
        // Delegation does not advance the step but unblocks the delegate to approve.
        $pdo->prepare("UPDATE workflow_instances SET last_activity_at = NOW() WHERE id = :id")->execute(['id' => $instanceId]);
        return _workflowHydrate(_workflowFetchRow($tenantId, $instanceId));
    }

    // Approve / skip — check quorum on the current step.
    $stepIdx  = (int) $instance['current_step'] - 1;
    $stepDef  = $steps[$stepIdx] ?? null;
    if (!$stepDef) {
        return _workflowComplete($tenantId, $instanceId, WORKFLOW_STATUS_APPROVED, $userId, $comment);
    }
    $quorum = max(1, (int) ($stepDef['quorum'] ?? 1));

    $cnt = $pdo->prepare(
        "SELECT COUNT(*) FROM workflow_step_actions
          WHERE instance_id = :i AND step_no = :s AND action IN ('approve','skip')"
    );
    $cnt->execute(['i' => $instanceId, 's' => (int) $instance['current_step']]);
    $approved = (int) $cnt->fetchColumn();

    if ($approved < $quorum) {
        $pdo->prepare("UPDATE workflow_instances SET last_activity_at = NOW() WHERE id = :id")->execute(['id' => $instanceId]);
        // Sync the individual approval back to the legacy subject store
        // (e.g. ap_bill_approvals) even when the workflow itself hasn't
        // advanced yet, so per-approver rows flip 'pending' → 'approved'.
        _workflowSubjectSync($tenantId, (string) $instance['subject_type'], (int) $instance['subject_id'],
                             $action, $userId, WORKFLOW_STATUS_PENDING);
        return _workflowHydrate(_workflowFetchRow($tenantId, $instanceId));
    }

    // Advance to next step or complete.
    $nextIdx = $stepIdx + 1;
    if (!isset($steps[$nextIdx])) {
        $result = _workflowComplete($tenantId, $instanceId, WORKFLOW_STATUS_APPROVED, $userId, $comment);
        _workflowSubjectSync($tenantId, (string) $instance['subject_type'], (int) $instance['subject_id'],
                             $action, $userId, WORKFLOW_STATUS_APPROVED);
        return $result;
    }
    $nextStep = $steps[$nextIdx];
    $sla = (int) ($nextStep['sla_hours'] ?? 0);
    $slaDueAt = $sla > 0 ? (new DateTimeImmutable("+{$sla} hours"))->format('Y-m-d H:i:s') : null;
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
                         $action, $userId, WORKFLOW_STATUS_PENDING);

    return _workflowHydrate(_workflowFetchRow($tenantId, $instanceId));
}

/**
 * Pluggable subject-sync dispatch. Currently only `ap_bill` has a
 * legacy mirror; other subject types are no-ops. Kept at the bottom
 * of the engine so the core stays vertical-agnostic — each vertical
 * owns its own sync file under its module.
 */
function _workflowSubjectSync(int $tenantId, string $subjectType, int $subjectId, string $action, ?int $userId, string $instanceStatus): void {
    try {
        if ($subjectType === 'ap_bill') {
            require_once __DIR__ . '/../modules/ap/lib/workflow_sync.php';
            if (function_exists('apSyncFromWorkflow')) {
                apSyncFromWorkflow($tenantId, $subjectId, $action, $userId, $instanceStatus);
            }
        }
    } catch (\Throwable $_) {
        // Absolutely non-fatal.
    }
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
        $approvers = (array) ($stepDef['approver_user_ids'] ?? []);
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
    $userIds = array_map('intval', (array) ($stepDef['approver_user_ids'] ?? []));
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
