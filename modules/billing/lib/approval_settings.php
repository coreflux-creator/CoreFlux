<?php
/** Tenant-scoped invoice reviewer setup on the shared People Graph policy store. */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/../../../core/memberships.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/rbac/permissions.php';
require_once __DIR__ . '/../../../core/people_graph.php';

const BILLING_INVOICE_APPROVAL_POLICY_KEY = 'billing_invoice_default';

/** @return list<array{id:int,name:string,email:string}> */
function billingInvoiceEligibleReviewers(int $tenantId): array
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
        ], 'billing.invoice.approve')) continue;
        if (!RBACResolver::can([
            'id' => $id,
            'global_role' => (string) $row['global_role'],
        ], $tenantId, 'billing', 'admin')) continue;
        $reviewers[$id] = [
            'id' => $id,
            'name' => (string) ($row['name'] ?: $row['email']),
            'email' => (string) $row['email'],
        ];
    }
    return array_values($reviewers);
}

function billingInvoiceReviewerIsEligible(int $tenantId, int $userId): bool
{
    if ($userId <= 0) return false;
    foreach (billingInvoiceEligibleReviewers($tenantId) as $reviewer) {
        if ($reviewer['id'] === $userId) return true;
    }
    return false;
}

function billingInvoiceApprovalSettingsRead(int $tenantId): array
{
    $pdo = getDB();
    $stmt = $pdo->prepare(
        'SELECT id, status, metadata_json FROM people_graph_approval_policies
          WHERE tenant_id = :tenant_id AND policy_key = :policy_key LIMIT 1'
    );
    $stmt->execute(['tenant_id' => $tenantId, 'policy_key' => BILLING_INVOICE_APPROVAL_POLICY_KEY]);
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
    $eligible = billingInvoiceEligibleReviewers($tenantId);
    $eligibleIds = array_column($eligible, 'id');
    $other = $pdo->prepare(
        'SELECT COUNT(*) FROM people_graph_approval_policies
          WHERE tenant_id = :tenant_id AND policy_key <> :policy_key
            AND status = "active" AND resource_type = "invoice"
            AND (resource_module IS NULL OR resource_module = "billing")'
    );
    $other->execute(['tenant_id' => $tenantId, 'policy_key' => BILLING_INVOICE_APPROVAL_POLICY_KEY]);
    return [
        'configured' => $policy !== null && $policy['status'] === 'active' && $configured !== [],
        'managed_default_policy' => ($metadata['managed_by'] ?? null) === 'billing_approval_settings',
        'reviewer_user_ids' => $configured,
        'eligible_reviewers' => $eligible,
        'unavailable_reviewer_user_ids' => array_values(array_diff($configured, $eligibleIds)),
        'other_active_policies' => (int) $other->fetchColumn(),
    ];
}

/** Capture the simple managed policy for an invoice without overriding custom graph policies. */
function billingInvoiceManagedReviewerSnapshot(int $tenantId, array $blockedUserIds = []): ?array
{
    $settings = billingInvoiceApprovalSettingsRead($tenantId);
    if (!$settings['configured'] || !$settings['managed_default_policy']
        || $settings['other_active_policies'] > 0) return null;

    $eligibleIds = array_column($settings['eligible_reviewers'], 'id');
    $blocked = array_values(array_unique(array_map('intval', $blockedUserIds)));
    return array_values(array_diff(
        array_intersect($settings['reviewer_user_ids'], $eligibleIds),
        $blocked
    ));
}

function billingInvoiceApprovalSettingsSave(int $tenantId, array $reviewerIds, int $actorUserId): array
{
    if ($reviewerIds === [] || count($reviewerIds) > 20) {
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
    $savepoint = $owns ? null : 'billing_policy_' . bin2hex(random_bytes(4));
    if ($owns) $pdo->beginTransaction();
    else $pdo->exec('SAVEPOINT ' . $savepoint);
    try {
        $stmt = $pdo->prepare(
            'SELECT id, metadata_json FROM people_graph_approval_policies
              WHERE tenant_id = :tenant_id AND policy_key = :policy_key LIMIT 1 FOR UPDATE'
        );
        $stmt->execute(['tenant_id' => $tenantId, 'policy_key' => BILLING_INVOICE_APPROVAL_POLICY_KEY]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($existing) {
            $meta = json_decode((string) ($existing['metadata_json'] ?? '{}'), true) ?: [];
            if (($meta['managed_by'] ?? null) !== 'billing_approval_settings') {
                throw new DomainException('The default invoice policy is managed elsewhere; review it in People Graph.');
            }
        }

        $policy = peopleGraphCreateApprovalPolicy($tenantId, [
            'policy_key' => BILLING_INVOICE_APPROVAL_POLICY_KEY,
            'name' => 'Billing invoice reviewers',
            'resource_module' => 'billing',
            'resource_type' => 'invoice',
            'status' => 'active',
            'requires_human_for_ai' => true,
            'metadata' => ['managed_by' => 'billing_approval_settings'],
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
                    'metadata' => ['managed_by' => 'billing_approval_settings'],
                ], $actorUserId);
            }
            peopleGraphAudit($tenantId, $actorUserId, 'billing.invoice.reviewers_updated',
                'people_graph_approval_policies', $policyId, [
                    'old_reviewer_user_ids' => $previous,
                    'new_reviewer_user_ids' => $selected,
                ]);
        }
        if ($owns) $pdo->commit();
        else $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        return billingInvoiceApprovalSettingsRead($tenantId);
    } catch (Throwable $e) {
        if ($owns && $pdo->inTransaction()) $pdo->rollBack();
        elseif (!$owns && $pdo->inTransaction()) {
            $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
            $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        }
        throw $e;
    }
}
