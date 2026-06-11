<?php
/**
 * Enterprise access review / certification service.
 *
 * Access reviews snapshot high-risk RBAC and People Graph grants, then record
 * certify/revoke/exception decisions with a durable audit trail.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ModuleRegistry.php';
require_once __DIR__ . '/RBAC.php';
require_once __DIR__ . '/people_graph.php';

const ACCESS_REVIEW_DECISIONS = ['certified', 'revoked', 'exception', 'needs_change'];

function accessReviewSensitivePermissionPatterns(): array
{
    return [
        'people.pii.*',
        'people.banking.*',
        'people.tax.*',
        'people.graph.manage',
        'people.graph.delegate',
        'people.access_reviews.*',
        'ap.vendor.view_pii',
        'ap.payment.*',
        'billing.invoice.approve',
        'billing.invoice.post',
        'payroll.profiles.banking.*',
        'payroll.run.approve',
        'payroll.run.disburse',
        'payroll.run.post',
        'payroll.tax.manage',
        'payroll.w2.generate',
        'accounting.je.approve',
        'accounting.je.post',
        'accounting.je.reverse',
        'accounting.period.close',
        'accounting.period.reopen',
        'accounting.reports.export',
        'accounting.connection.manage',
        'accounting.commands.execute',
        'treasury.approve_payment',
        'treasury.execute_payment',
        'treasury.payment.manage',
        'treasury.create_payment',
        'reports.export',
        'reports.custom.share',
        'integrations.*.manage',
        'integrations.field_map.manage',
        'ai.approve_actions',
        'ai.configure_agents',
        'ai.config.manage',
    ];
}

function accessReviewCreateCampaign(int $tenantId, string $name, array $opts = [], ?int $actorUserId = null): array
{
    $name = trim($name);
    if ($name === '') throw new \InvalidArgumentException('name is required');
    $key = trim((string) ($opts['campaign_key'] ?? ''));
    if ($key === '') $key = 'access_review_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(3));
    $pdo = accessReviewPdo();
    $stmt = $pdo->prepare(
        'INSERT INTO access_review_campaigns
            (tenant_id, campaign_key, name, description, status, scope_json, due_at, created_by_user_id, created_at, updated_at)
         VALUES
            (:tenant_id, :campaign_key, :name, :description, :status, :scope_json, :due_at, :created_by, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            description = VALUES(description),
            scope_json = VALUES(scope_json),
            due_at = VALUES(due_at),
            updated_at = NOW()'
    );
    $stmt->execute([
        'tenant_id' => $tenantId,
        'campaign_key' => accessReviewKey($key, 'campaign_key'),
        'name' => substr($name, 0, 200),
        'description' => $opts['description'] ?? null,
        'status' => $opts['status'] ?? 'draft',
        'scope_json' => accessReviewJson($opts['scope'] ?? []),
        'due_at' => $opts['due_at'] ?? null,
        'created_by' => $actorUserId,
    ]);
    $campaign = accessReviewFindCampaignByKey($tenantId, $key);
    accessReviewAudit($tenantId, (int) $campaign['id'], null, $actorUserId, 'people.access_review.campaign.created', [
        'campaign_key' => $key,
        'name' => $name,
    ]);
    return $campaign;
}

function accessReviewOpenCampaign(int $tenantId, int $campaignId, ?int $actorUserId = null): array
{
    $campaign = accessReviewGetCampaign($tenantId, $campaignId);
    if (!$campaign) throw new \RuntimeException('Access review campaign not found');
    $pdo = accessReviewPdo();
    $pdo->prepare(
        "UPDATE access_review_campaigns
            SET status = 'open', opened_by_user_id = :actor, opened_at = COALESCE(opened_at, NOW()), updated_at = NOW()
          WHERE tenant_id = :tenant_id AND id = :id"
    )->execute(['tenant_id' => $tenantId, 'id' => $campaignId, 'actor' => $actorUserId]);
    $count = accessReviewSnapshotCampaign($tenantId, $campaignId, $actorUserId);
    accessReviewAudit($tenantId, $campaignId, null, $actorUserId, 'people.access_review.campaign.opened', [
        'items_snapshot' => $count,
    ]);
    return accessReviewGetCampaign($tenantId, $campaignId) ?: $campaign;
}

function accessReviewSnapshotCampaign(int $tenantId, int $campaignId, ?int $actorUserId = null): int
{
    $campaign = accessReviewGetCampaign($tenantId, $campaignId);
    if (!$campaign) throw new \RuntimeException('Access review campaign not found');
    $scope = is_array($campaign['scope'] ?? null) ? $campaign['scope'] : [];
    $items = array_merge(
        accessReviewCollectRolePermissionItems($tenantId, $campaignId, $scope),
        accessReviewCollectModuleAccessItems($tenantId, $campaignId, $scope),
        accessReviewCollectPeopleGraphGrantItems($tenantId, $campaignId, $scope)
    );
    foreach ($items as $item) accessReviewUpsertItem($tenantId, $campaignId, $item);
    accessReviewAudit($tenantId, $campaignId, null, $actorUserId, 'people.access_review.campaign.snapshot', [
        'items' => count($items),
    ]);
    return count($items);
}

function accessReviewListCampaigns(int $tenantId, array $filters = []): array
{
    $where = ['tenant_id = :tenant_id'];
    $params = ['tenant_id' => $tenantId];
    if (!empty($filters['status'])) {
        $where[] = 'status = :status';
        $params['status'] = (string) $filters['status'];
    }
    $rows = accessReviewFetchAll(
        'SELECT * FROM access_review_campaigns WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC LIMIT ' . accessReviewLimit($filters),
        $params
    );
    return array_map('accessReviewHydrateCampaign', $rows);
}

function accessReviewGetCampaign(int $tenantId, int $campaignId): ?array
{
    $stmt = accessReviewPdo()->prepare('SELECT * FROM access_review_campaigns WHERE tenant_id = :tenant_id AND id = :id LIMIT 1');
    $stmt->execute(['tenant_id' => $tenantId, 'id' => $campaignId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return $row ? accessReviewHydrateCampaign($row) : null;
}

function accessReviewListItems(int $tenantId, int $campaignId, array $filters = []): array
{
    $where = ['tenant_id = :tenant_id', 'campaign_id = :campaign_id'];
    $params = ['tenant_id' => $tenantId, 'campaign_id' => $campaignId];
    foreach (['decision', 'risk_level', 'source', 'module_key'] as $key) {
        if (!empty($filters[$key])) {
            $where[] = "{$key} = :{$key}";
            $params[$key] = (string) $filters[$key];
        }
    }
    $rows = accessReviewFetchAll(
        'SELECT * FROM access_review_items WHERE ' . implode(' AND ', $where) . ' ORDER BY risk_level DESC, source ASC, id ASC LIMIT ' . accessReviewLimit($filters),
        $params
    );
    return array_map('accessReviewHydrateItem', $rows);
}

function accessReviewRecordDecision(int $tenantId, int $itemId, string $decision, ?int $actorUserId, ?string $note = null): array
{
    if (!in_array($decision, ACCESS_REVIEW_DECISIONS, true)) {
        throw new \InvalidArgumentException('Unsupported access review decision');
    }
    $item = accessReviewGetItem($tenantId, $itemId);
    if (!$item) throw new \RuntimeException('Access review item not found');
    $remediation = $decision === 'revoked' ? accessReviewApplyRevocation($tenantId, $item, $actorUserId) : 'not_required';
    $pdo = accessReviewPdo();
    $pdo->prepare(
        'UPDATE access_review_items
            SET decision = :decision,
                decision_by_user_id = :actor,
                decision_at = NOW(),
                decision_note = :note,
                remediation_status = :remediation,
                updated_at = NOW()
          WHERE tenant_id = :tenant_id AND id = :id'
    )->execute([
        'tenant_id' => $tenantId,
        'id' => $itemId,
        'decision' => $decision,
        'actor' => $actorUserId,
        'note' => $note,
        'remediation' => $remediation,
    ]);
    accessReviewAudit($tenantId, (int) $item['campaign_id'], $itemId, $actorUserId, 'people.access_review.item.decided', [
        'decision' => $decision,
        'remediation_status' => $remediation,
        'source' => $item['source'] ?? null,
    ]);
    return accessReviewGetItem($tenantId, $itemId) ?: $item;
}

function accessReviewCompleteCampaign(int $tenantId, int $campaignId, ?int $actorUserId = null): array
{
    $pending = accessReviewFetchValue(
        'SELECT COUNT(*) FROM access_review_items WHERE tenant_id = :tenant_id AND campaign_id = :campaign_id AND decision = "pending"',
        ['tenant_id' => $tenantId, 'campaign_id' => $campaignId]
    );
    if ((int) $pending > 0) {
        throw new \RuntimeException('Cannot complete access review while items are pending');
    }
    accessReviewPdo()->prepare(
        "UPDATE access_review_campaigns
            SET status = 'completed', completed_by_user_id = :actor, completed_at = NOW(), updated_at = NOW()
          WHERE tenant_id = :tenant_id AND id = :id"
    )->execute(['tenant_id' => $tenantId, 'id' => $campaignId, 'actor' => $actorUserId]);
    accessReviewAudit($tenantId, $campaignId, null, $actorUserId, 'people.access_review.campaign.completed', []);
    return accessReviewGetCampaign($tenantId, $campaignId) ?: [];
}

function accessReviewPermissionRisk(string $permission, ?string $moduleKey = null, ?string $accessLevel = null): string
{
    $p = strtolower($permission);
    $m = strtolower((string) $moduleKey);
    $level = strtolower((string) $accessLevel);
    if (
        str_contains($p, 'pii') ||
        str_contains($p, 'banking') ||
        str_contains($p, 'tax_id') ||
        str_contains($p, 'ssn') ||
        str_contains($p, 'execute_payment') ||
        str_contains($p, 'disburse') ||
        str_contains($p, 'grant_permission') ||
        str_contains($p, 'graph.delegate') ||
        ($level === 'admin' && in_array($m, ['treasury', 'payroll', 'accounting', 'people', 'integrations'], true))
    ) {
        return 'critical';
    }
    if (
        str_contains($p, 'approve') ||
        str_contains($p, 'post') ||
        str_contains($p, 'reverse') ||
        str_contains($p, 'export') ||
        str_contains($p, 'manage') ||
        $level === 'admin' ||
        $level === 'write'
    ) {
        return 'high';
    }
    return 'medium';
}

function accessReviewIsSensitivePermission(string $permission, array $scope = []): bool
{
    if (!empty($scope['include_low_risk'])) return true;
    foreach ((array) ($scope['permissions'] ?? []) as $exact) {
        if ($permission === (string) $exact) return true;
    }
    foreach (accessReviewSensitivePermissionPatterns() as $pattern) {
        if (accessReviewPatternMatches($pattern, $permission)) return true;
    }
    return false;
}

function accessReviewApplyRevocation(int $tenantId, array $item, ?int $actorUserId): string
{
    $source = (string) ($item['source'] ?? '');
    $sourceRefId = (string) ($item['source_ref_id'] ?? '');
    try {
        if ($source === 'membership_module_access' && ctype_digit($sourceRefId)) {
            accessReviewPdo()->prepare('UPDATE membership_module_access SET access_level = "none" WHERE id = :id')
                ->execute(['id' => (int) $sourceRefId]);
            accessReviewMembershipAudit($tenantId, $item, $actorUserId, 'access_review_revoke');
            return 'completed';
        }
        if ($source === 'people_graph_permission_grant' && ctype_digit($sourceRefId)) {
            peopleGraphRevokePermissionGrant($tenantId, (int) $sourceRefId, $actorUserId);
            return 'completed';
        }
    } catch (\Throwable $e) {
        accessReviewAudit($tenantId, (int) ($item['campaign_id'] ?? 0), (int) ($item['id'] ?? 0), $actorUserId, 'people.access_review.revocation_failed', [
            'source' => $source,
            'source_ref_id' => $sourceRefId,
            'error' => $e->getMessage(),
        ]);
        return 'failed';
    }
    return 'pending';
}

function accessReviewCollectRolePermissionItems(int $tenantId, int $campaignId, array $scope): array
{
    $declared = RBAC::getAllDeclaredPermissions();
    $memberships = accessReviewFetchAll(
        'SELECT tm.id AS membership_id, tm.user_id, tm.persona_type, tm.persona_label, u.email, u.name
           FROM tenant_memberships tm
           LEFT JOIN users u ON u.id = tm.user_id
          WHERE tm.tenant_id = :tenant_id AND tm.status IN ("active","pending")',
        ['tenant_id' => $tenantId]
    );
    $items = [];
    foreach ($memberships as $membership) {
        $role = (string) ($membership['persona_type'] ?? 'employee');
        $user = ['role' => $role, 'tenant_role' => $role];
        foreach ($declared as $permission) {
            $permission = (string) $permission;
            if (!accessReviewIsSensitivePermission($permission, $scope)) continue;
            if (!accessReviewModuleInScope(strtok($permission, '.') ?: '', $scope)) continue;
            if (!RBAC::hasPermission($user, $permission)) continue;
            $items[] = [
                'subject_user_id' => (int) $membership['user_id'],
                'subject_actor_type' => 'user',
                'subject_actor_id' => (int) $membership['user_id'],
                'membership_id' => (int) $membership['membership_id'],
                'permission_key' => $permission,
                'module_key' => strtok($permission, '.') ?: null,
                'access_level' => null,
                'source' => 'rbac_role_permission',
                'source_ref_type' => 'tenant_membership',
                'source_ref_id' => 'membership:' . (int) $membership['membership_id'] . ':' . $permission,
                'risk_level' => accessReviewPermissionRisk($permission),
                'entitlement_snapshot' => [
                    'role' => $role,
                    'persona_label' => $membership['persona_label'] ?? null,
                    'email' => $membership['email'] ?? null,
                    'name' => $membership['name'] ?? null,
                ],
            ];
        }
    }
    return $items;
}

function accessReviewCollectModuleAccessItems(int $tenantId, int $campaignId, array $scope): array
{
    $rows = accessReviewFetchAll(
        'SELECT mma.id, mma.membership_id, mma.module_key, mma.access_level, mma.sub_tenant_scope,
                tm.user_id, tm.persona_type, tm.persona_label, u.email, u.name
           FROM membership_module_access mma
           JOIN tenant_memberships tm ON tm.id = mma.membership_id
           LEFT JOIN users u ON u.id = tm.user_id
          WHERE tm.tenant_id = :tenant_id
            AND tm.status IN ("active","pending")
            AND mma.access_level IN ("write","admin")',
        ['tenant_id' => $tenantId]
    );
    $items = [];
    foreach ($rows as $row) {
        $module = (string) ($row['module_key'] ?? '');
        if (!accessReviewModuleInScope($module, $scope)) continue;
        $level = (string) ($row['access_level'] ?? '');
        $permission = $module . '.' . $level;
        $risk = accessReviewPermissionRisk($permission, $module, $level);
        if (empty($scope['include_low_risk']) && !in_array($risk, ['high', 'critical'], true)) continue;
        $items[] = [
            'subject_user_id' => (int) $row['user_id'],
            'subject_actor_type' => 'user',
            'subject_actor_id' => (int) $row['user_id'],
            'membership_id' => (int) $row['membership_id'],
            'permission_key' => null,
            'module_key' => $module,
            'access_level' => $level,
            'source' => 'membership_module_access',
            'source_ref_type' => 'membership_module_access',
            'source_ref_id' => (string) (int) $row['id'],
            'risk_level' => $risk,
            'entitlement_snapshot' => [
                'persona_type' => $row['persona_type'] ?? null,
                'persona_label' => $row['persona_label'] ?? null,
                'sub_tenant_scope' => $row['sub_tenant_scope'] ?? null,
                'email' => $row['email'] ?? null,
                'name' => $row['name'] ?? null,
            ],
        ];
    }
    return $items;
}

function accessReviewCollectPeopleGraphGrantItems(int $tenantId, int $campaignId, array $scope): array
{
    $rows = accessReviewFetchAll(
        'SELECT *
           FROM people_graph_permission_grants
          WHERE tenant_id = :tenant_id AND status = "active"
            AND (starts_at IS NULL OR starts_at <= NOW())
            AND (ends_at IS NULL OR ends_at >= NOW())',
        ['tenant_id' => $tenantId]
    );
    $items = [];
    foreach ($rows as $row) {
        $module = (string) ($row['resource_module'] ?? '');
        if (!accessReviewModuleInScope($module, $scope)) continue;
        $permission = 'people_graph.' . (string) $row['action'] . '.' . ($module ?: 'any') . '.' . (string) ($row['resource_type'] ?? 'resource');
        $sensitive = accessReviewSensitiveGraphGrant($row, $scope);
        if (!$sensitive) continue;
        $items[] = [
            'subject_user_id' => ($row['subject_actor_type'] ?? '') === 'user' ? (int) $row['subject_actor_id'] : null,
            'subject_actor_type' => (string) ($row['subject_actor_type'] ?? ''),
            'subject_actor_id' => (int) ($row['subject_actor_id'] ?? 0),
            'membership_id' => null,
            'permission_key' => $permission,
            'module_key' => $module ?: null,
            'access_level' => null,
            'source' => 'people_graph_permission_grant',
            'source_ref_type' => 'people_graph_permission_grant',
            'source_ref_id' => (string) (int) $row['id'],
            'risk_level' => accessReviewPermissionRisk($permission, $module, null),
            'entitlement_snapshot' => [
                'action' => $row['action'] ?? null,
                'resource_module' => $row['resource_module'] ?? null,
                'resource_type' => $row['resource_type'] ?? null,
                'resource_id' => $row['resource_id'] ?? null,
                'scope_type' => $row['scope_type'] ?? null,
                'scope_id' => $row['scope_id'] ?? null,
                'granted_by_user_id' => $row['granted_by_user_id'] ?? null,
            ],
        ];
    }
    return $items;
}

function accessReviewSensitiveGraphGrant(array $row, array $scope): bool
{
    if (!empty($scope['include_low_risk'])) return true;
    $action = (string) ($row['action'] ?? '');
    if (in_array($action, ['approve', 'post', 'release', 'export', 'override', 'grant_permission', 'file'], true)) return true;
    $module = (string) ($row['resource_module'] ?? '');
    return in_array($module, ['people', 'payroll', 'treasury', 'accounting', 'ap', 'integrations'], true);
}

function accessReviewUpsertItem(int $tenantId, int $campaignId, array $item): void
{
    $stmt = accessReviewPdo()->prepare(
        'INSERT INTO access_review_items
            (tenant_id, campaign_id, subject_user_id, subject_actor_type, subject_actor_id,
             membership_id, permission_key, module_key, access_level, source, source_ref_type,
             source_ref_id, risk_level, entitlement_snapshot_json, decision, remediation_status,
             created_at, updated_at)
         VALUES
            (:tenant_id, :campaign_id, :subject_user_id, :subject_actor_type, :subject_actor_id,
             :membership_id, :permission_key, :module_key, :access_level, :source, :source_ref_type,
             :source_ref_id, :risk_level, :snapshot, "pending", "not_required", NOW(), NOW())
         ON DUPLICATE KEY UPDATE
             subject_user_id = VALUES(subject_user_id),
             subject_actor_type = VALUES(subject_actor_type),
             subject_actor_id = VALUES(subject_actor_id),
             membership_id = VALUES(membership_id),
             permission_key = VALUES(permission_key),
             module_key = VALUES(module_key),
             access_level = VALUES(access_level),
             risk_level = VALUES(risk_level),
             entitlement_snapshot_json = VALUES(entitlement_snapshot_json),
             updated_at = NOW()'
    );
    $stmt->execute([
        'tenant_id' => $tenantId,
        'campaign_id' => $campaignId,
        'subject_user_id' => $item['subject_user_id'] ?? null,
        'subject_actor_type' => $item['subject_actor_type'] ?? null,
        'subject_actor_id' => $item['subject_actor_id'] ?? null,
        'membership_id' => $item['membership_id'] ?? null,
        'permission_key' => $item['permission_key'] ?? null,
        'module_key' => $item['module_key'] ?? null,
        'access_level' => $item['access_level'] ?? null,
        'source' => $item['source'],
        'source_ref_type' => $item['source_ref_type'],
        'source_ref_id' => $item['source_ref_id'],
        'risk_level' => $item['risk_level'] ?? 'medium',
        'snapshot' => accessReviewJson($item['entitlement_snapshot'] ?? []),
    ]);
}

function accessReviewGetItem(int $tenantId, int $itemId): ?array
{
    $stmt = accessReviewPdo()->prepare('SELECT * FROM access_review_items WHERE tenant_id = :tenant_id AND id = :id LIMIT 1');
    $stmt->execute(['tenant_id' => $tenantId, 'id' => $itemId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return $row ? accessReviewHydrateItem($row) : null;
}

function accessReviewHydrateCampaign(array $row): array
{
    $row['id'] = (int) $row['id'];
    $row['tenant_id'] = (int) $row['tenant_id'];
    $row['scope'] = !empty($row['scope_json']) ? (json_decode((string) $row['scope_json'], true) ?: []) : [];
    unset($row['scope_json']);
    return $row;
}

function accessReviewHydrateItem(array $row): array
{
    foreach (['id', 'tenant_id', 'campaign_id', 'subject_user_id', 'subject_actor_id', 'membership_id'] as $key) {
        if (array_key_exists($key, $row) && $row[$key] !== null) $row[$key] = (int) $row[$key];
    }
    $row['entitlement_snapshot'] = !empty($row['entitlement_snapshot_json'])
        ? (json_decode((string) $row['entitlement_snapshot_json'], true) ?: [])
        : [];
    unset($row['entitlement_snapshot_json']);
    return $row;
}

function accessReviewModuleInScope(string $moduleKey, array $scope): bool
{
    $modules = array_values(array_filter(array_map('strval', (array) ($scope['modules'] ?? []))));
    return !$modules || in_array($moduleKey, $modules, true);
}

function accessReviewPatternMatches(string $pattern, string $value): bool
{
    if ($pattern === $value) return true;
    $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/';
    return (bool) preg_match($regex, $value);
}

function accessReviewMembershipAudit(int $tenantId, array $item, ?int $actorUserId, string $action): void
{
    try {
        accessReviewPdo()->prepare(
            'INSERT INTO membership_audit
                (tenant_id, membership_id, action, actor_user_id, target_user_id, detail, occurred_at)
             VALUES (:tenant_id, :membership_id, :action, :actor, :target, :detail, NOW())'
        )->execute([
            'tenant_id' => $tenantId,
            'membership_id' => $item['membership_id'] ?? null,
            'action' => $action,
            'actor' => $actorUserId,
            'target' => $item['subject_user_id'] ?? null,
            'detail' => accessReviewJson([
                'access_review_item_id' => $item['id'] ?? null,
                'module_key' => $item['module_key'] ?? null,
                'prior_access_level' => $item['access_level'] ?? null,
            ]),
        ]);
    } catch (\Throwable $_) {
        // Best-effort audit. access_review_audit still captures the decision.
    }
}

function accessReviewAudit(int $tenantId, ?int $campaignId, ?int $itemId, ?int $actorUserId, string $event, array $payload): void
{
    try {
        accessReviewPdo()->prepare(
            'INSERT INTO access_review_audit
                (tenant_id, campaign_id, item_id, actor_user_id, event, payload_json, occurred_at)
             VALUES (:tenant_id, :campaign_id, :item_id, :actor, :event, :payload, NOW())'
        )->execute([
            'tenant_id' => $tenantId,
            'campaign_id' => $campaignId,
            'item_id' => $itemId,
            'actor' => $actorUserId,
            'event' => $event,
            'payload' => accessReviewJson($payload),
        ]);
    } catch (\Throwable $_) {
        // Audit failure should not block the control operation.
    }
}

function accessReviewFindCampaignByKey(int $tenantId, string $key): array
{
    $stmt = accessReviewPdo()->prepare('SELECT * FROM access_review_campaigns WHERE tenant_id = :tenant_id AND campaign_key = :campaign_key LIMIT 1');
    $stmt->execute(['tenant_id' => $tenantId, 'campaign_key' => $key]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row) throw new \RuntimeException('Access review campaign not found');
    return accessReviewHydrateCampaign($row);
}

function accessReviewFetchAll(string $sql, array $params = []): array
{
    $stmt = accessReviewPdo()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
}

function accessReviewFetchValue(string $sql, array $params = []): mixed
{
    $stmt = accessReviewPdo()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function accessReviewPdo(): \PDO
{
    $pdo = getDB();
    if (!$pdo) throw new \RuntimeException('No DB connection');
    return $pdo;
}

function accessReviewJson(mixed $value): ?string
{
    if ($value === null || $value === '') return null;
    $json = json_encode($value, JSON_UNESCAPED_SLASHES);
    return $json === false ? null : $json;
}

function accessReviewKey(string $value, string $field): string
{
    $value = trim($value);
    if ($value === '' || !preg_match('/^[a-zA-Z0-9_.:-]+$/', $value)) {
        throw new \InvalidArgumentException("Invalid {$field}");
    }
    return substr($value, 0, 120);
}

function accessReviewLimit(array $filters): int
{
    $limit = (int) ($filters['limit'] ?? 500);
    return max(1, min(2000, $limit));
}
