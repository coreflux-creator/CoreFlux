<?php
/** Tenant-scoped payroll run reviewers on the shared People Graph policy store. */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/../../../core/memberships.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/rbac/permissions.php';
require_once __DIR__ . '/../../../core/people_graph.php';
require_once __DIR__ . '/workflow.php';

const PAYROLL_RUN_APPROVAL_POLICY_KEY = 'payroll_run_default';

function payrollRunEligibleReviewers(int $tenantId): array
{
    $stmt = getDB()->prepare(
        'SELECT u.id, u.name, u.email, u.role AS global_role, m.persona_type
           FROM ' . membershipReadSourceSql() . ' m
           JOIN users u ON u.id = m.user_id AND u.is_active = 1
          WHERE m.tenant_id = :tenant_id
          ORDER BY u.name ASC, u.id ASC, m.is_primary DESC'
    );
    $stmt->execute(['tenant_id' => $tenantId]);
    $reviewers = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $id = (int) $row['id'];
        if (isset($reviewers[$id])) continue;
        if (!RBAC::hasPermission([
            'tenant_role' => (string) $row['persona_type'],
            'global_role' => (string) $row['global_role'],
        ], 'payroll.run.approve')) continue;
        if (!RBACResolver::can([
            'id' => $id,
            'global_role' => (string) $row['global_role'],
        ], $tenantId, 'payroll', 'admin')) continue;
        $reviewers[$id] = [
            'id' => $id,
            'name' => (string) ($row['name'] ?: $row['email']),
            'email' => (string) $row['email'],
        ];
    }
    return array_values($reviewers);
}

function payrollRunApprovalSettingsRead(int $tenantId): array
{
    $pdo = getDB();
    $stmt = $pdo->prepare(
        'SELECT id, status, metadata_json FROM people_graph_approval_policies
          WHERE tenant_id = :tenant_id AND policy_key = :policy_key LIMIT 1'
    );
    $stmt->execute(['tenant_id' => $tenantId, 'policy_key' => PAYROLL_RUN_APPROVAL_POLICY_KEY]);
    $policy = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $metadata = $policy ? (json_decode((string) ($policy['metadata_json'] ?? '{}'), true) ?: []) : [];
    $configured = [];
    if ($policy && $policy['status'] === 'active') {
        $rules = $pdo->prepare(
            'SELECT approver_actor_id FROM people_graph_approval_policy_rules
              WHERE tenant_id = :tenant_id AND policy_id = :policy_id
                AND status = "active" AND approver_strategy = "named_actor"
                AND approver_actor_type = "user"
              ORDER BY sequence_num ASC, id ASC'
        );
        $rules->execute(['tenant_id' => $tenantId, 'policy_id' => (int) $policy['id']]);
        $configured = array_values(array_unique(array_map('intval', $rules->fetchAll(PDO::FETCH_COLUMN) ?: [])));
    }
    $eligible = payrollRunEligibleReviewers($tenantId);
    $other = $pdo->prepare(
        'SELECT COUNT(*) FROM people_graph_approval_policies
          WHERE tenant_id = :tenant_id AND policy_key <> :policy_key
            AND status = "active" AND resource_type = "run"
            AND (resource_module IS NULL OR resource_module = "payroll")'
    );
    $other->execute(['tenant_id' => $tenantId, 'policy_key' => PAYROLL_RUN_APPROVAL_POLICY_KEY]);
    return [
        'configured' => $policy !== null && $policy['status'] === 'active' && $configured !== [],
        'managed_default_policy' => ($metadata['managed_by'] ?? null) === 'payroll_approval_settings',
        'reviewer_user_ids' => $configured,
        'eligible_reviewers' => $eligible,
        'unavailable_reviewer_user_ids' => array_values(array_diff($configured, array_column($eligible, 'id'))),
        'other_active_policies' => (int) $other->fetchColumn(),
    ];
}

function payrollRunApprovalReadiness(int $tenantId, int $builderUserId, ?int $runId = null): array
{
    $run = $runId !== null && $runId > 0 ? payrollRunWorkflowRow($runId) : null;
    if ($runId !== null && $runId > 0 && !$run) {
        throw new InvalidArgumentException('Payroll run not found for reviewer preflight.');
    }
    $context = $run ? payrollRunWorkflowContext($run, $builderUserId) : [];
    $context['workflow_step'] = 1;
    $context['workflow_step_label'] = 'Payroll run approval';
    $context['separation_of_duties_required'] = true;
    $resolved = peopleGraphResolveApprovers($tenantId, [
        'resource_module' => 'payroll',
        'resource_type' => 'run',
        'resource_id' => (string) ($runId ?? 0),
        'context' => $context,
        'source_actor_type' => 'user',
        'source_actor_id' => $builderUserId,
    ]);
    $userIds = [];
    foreach ($resolved['requirements'] ?? [] as $requirement) {
        foreach ($requirement['approvers'] ?? [] as $actor) {
            $userIds = array_merge($userIds, _workflowPeopleGraphActorToUserIds($tenantId, $actor));
        }
    }
    $eligible = array_map('intval', array_column(payrollRunEligibleReviewers($tenantId), 'id'));
    // A recompute replaces the previous computed_by_user_id with this builder.
    $blocked = array_values(array_unique(array_filter([
        $builderUserId,
        $run['created_by_user_id'] ?? null,
    ], static fn($id) => (int) $id > 0)));
    $available = array_diff(array_intersect(array_unique(array_map('intval', $userIds)), $eligible), $blocked);
    if ($available !== []) {
        return ['ready' => true, 'status' => 'routed', 'message' => 'An independent payroll reviewer is available.'];
    }
    return [
        'ready' => false,
        'status' => empty($resolved['requirements']) ? 'missing' : 'no_independent_reviewer',
        'message' => empty($resolved['requirements'])
            ? 'Choose a payroll reviewer in Payroll Settings before computing this run.'
            : 'No active independent reviewer is available for this run. Update Payroll Settings or its approval policy.',
    ];
}

function payrollRunApprovalSettingsSave(int $tenantId, array $reviewerIds, int $actorUserId): array
{
    if ($reviewerIds === [] || count($reviewerIds) > 20) {
        throw new InvalidArgumentException('Select between 1 and 20 payroll reviewers.');
    }
    $selected = [];
    foreach ($reviewerIds as $id) {
        if (!is_int($id) || $id <= 0) {
            throw new InvalidArgumentException('Reviewer IDs must be positive integers.');
        }
        $selected[$id] = $id;
    }
    $selected = array_values($selected);
    if (array_diff($selected, array_column(payrollRunEligibleReviewers($tenantId), 'id'))) {
        throw new InvalidArgumentException('A selected reviewer is inactive or lacks Payroll approval access.');
    }

    $pdo = getDB();
    $owns = !$pdo->inTransaction();
    $savepoint = $owns ? null : 'payroll_policy_' . bin2hex(random_bytes(4));
    if ($owns) $pdo->beginTransaction();
    else $pdo->exec('SAVEPOINT ' . $savepoint);
    try {
        $stmt = $pdo->prepare(
            'SELECT id, metadata_json FROM people_graph_approval_policies
              WHERE tenant_id = :tenant_id AND policy_key = :policy_key LIMIT 1 FOR UPDATE'
        );
        $stmt->execute(['tenant_id' => $tenantId, 'policy_key' => PAYROLL_RUN_APPROVAL_POLICY_KEY]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($existing) {
            $meta = json_decode((string) ($existing['metadata_json'] ?? '{}'), true) ?: [];
            if (($meta['managed_by'] ?? null) !== 'payroll_approval_settings') {
                throw new DomainException('The default payroll policy is managed elsewhere; review it in People Graph.');
            }
        }

        $policy = peopleGraphCreateApprovalPolicy($tenantId, [
            'policy_key' => PAYROLL_RUN_APPROVAL_POLICY_KEY,
            'name' => 'Payroll run reviewers',
            'resource_module' => 'payroll',
            'resource_type' => 'run',
            'status' => 'active',
            'requires_human_for_ai' => true,
            'metadata' => ['managed_by' => 'payroll_approval_settings'],
        ], $actorUserId);
        $policyId = (int) $policy['id'];
        $old = $pdo->prepare(
            'SELECT approver_actor_id FROM people_graph_approval_policy_rules
              WHERE tenant_id = :tenant_id AND policy_id = :policy_id AND status = "active"
              ORDER BY sequence_num ASC, id ASC'
        );
        $old->execute(['tenant_id' => $tenantId, 'policy_id' => $policyId]);
        $previous = array_values(array_unique(array_map('intval', $old->fetchAll(PDO::FETCH_COLUMN) ?: [])));
        if ($previous !== $selected) {
            $pdo->prepare(
                'UPDATE people_graph_approval_policy_rules SET status = "inactive", updated_at = NOW()
                  WHERE tenant_id = :tenant_id AND policy_id = :policy_id AND status = "active"'
            )->execute(['tenant_id' => $tenantId, 'policy_id' => $policyId]);
            foreach ($selected as $index => $userId) {
                peopleGraphCreateApprovalRule($tenantId, [
                    'policy_id' => $policyId,
                    'sequence_num' => $index + 1,
                    'approver_strategy' => 'named_actor',
                    'approver_actor_type' => 'user',
                    'approver_actor_id' => $userId,
                    'minimum_approvals' => 1,
                    'separation_of_duties_required' => true,
                    'metadata' => ['managed_by' => 'payroll_approval_settings'],
                ], $actorUserId);
            }
            peopleGraphAudit($tenantId, $actorUserId, 'payroll.run.reviewers_updated',
                'people_graph_approval_policies', $policyId, [
                    'old_reviewer_user_ids' => $previous,
                    'new_reviewer_user_ids' => $selected,
                ]);
        }
        if ($owns) $pdo->commit();
        else $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        return payrollRunApprovalSettingsRead($tenantId);
    } catch (Throwable $e) {
        if ($owns && $pdo->inTransaction()) $pdo->rollBack();
        elseif (!$owns && $pdo->inTransaction()) {
            $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
            $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        }
        throw $e;
    }
}
