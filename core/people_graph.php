<?php
/**
 * CoreFlux People Graph MVP.
 *
 * Shared authority/responsibility layer for people, users, companies,
 * teams, roles, organizations, and AI workers. Domain modules should call
 * this service to answer ownership/routing questions instead of inventing
 * module-local approver/delegation tables.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

class PeopleGraphException extends RuntimeException {}

const PEOPLE_GRAPH_ACTOR_TYPES = [
    'person', 'user', 'organization', 'company', 'team', 'role', 'ai_worker', 'external',
];

const PEOPLE_GRAPH_RELATIONSHIP_TYPES = [
    'reports_to', 'manages', 'member_of', 'owns', 'accountable_to', 'approves_for',
    'reviews_for', 'supervises_ai', 'notifies', 'escalates_to', 'delegates_to',
    'primary_contact_for', 'works_for', 'custom',
];

const PEOPLE_GRAPH_RESPONSIBILITY_TYPES = [
    'owner', 'accountable', 'preparer', 'approver', 'reviewer', 'requester',
    'recipient', 'ai_creator', 'ai_supervisor', 'notifier', 'operator',
    'viewer', 'escalation_contact',
];

const PEOPLE_GRAPH_DELEGATION_TYPES = [
    'approval', 'review', 'notification', 'ownership', 'supervision', 'all',
];

const PEOPLE_GRAPH_PERMISSION_ACTIONS = [
    'view', 'create', 'edit', 'delete', 'approve', 'submit', 'post', 'release',
    'invite', 'assign', 'export', 'override', 'review', 'notify', 'resolve',
    'supervise', 'file', 'grant_permission',
];

const PEOPLE_GRAPH_APPROVAL_STRATEGIES = [
    'role', 'relationship', 'responsibility', 'named_actor', 'manager_chain',
];

const PEOPLE_GRAPH_AI_FORBIDDEN_ACTIONS = [
    'approve', 'authorize', 'post', 'release', 'file', 'grant_permission',
    'override', 'modify_supervision',
];

/**
 * Product-level resolver questions supported by People Graph MVP.
 *
 * @return array<string, list<string>>
 */
function peopleGraphResolverQuestionMap(): array
{
    return [
        'who_owns'       => ['owner', 'accountable'],
        'who_prepares'   => ['preparer', 'operator'],
        'who_approves'   => ['approver'],
        'who_reviews'    => ['reviewer'],
        'who_reviews_ai' => ['ai_supervisor', 'reviewer'],
        'who_created_ai' => ['ai_creator'],
        'who_receives'   => ['recipient', 'requester'],
        'who_notifies'   => ['notifier', 'owner'],
        'who_escalates'  => ['escalation_contact', 'accountable'],
        'who_operates'   => ['operator', 'owner'],
        'who_can_view'   => ['viewer', 'owner', 'accountable'],
    ];
}

function peopleGraphVocabulary(): array
{
    return [
        'actor_types'          => PEOPLE_GRAPH_ACTOR_TYPES,
        'relationship_types'   => PEOPLE_GRAPH_RELATIONSHIP_TYPES,
        'responsibility_types' => PEOPLE_GRAPH_RESPONSIBILITY_TYPES,
        'delegation_types'     => PEOPLE_GRAPH_DELEGATION_TYPES,
        'permission_actions'   => PEOPLE_GRAPH_PERMISSION_ACTIONS,
        'approval_strategies'  => PEOPLE_GRAPH_APPROVAL_STRATEGIES,
        'ai_forbidden_actions' => PEOPLE_GRAPH_AI_FORBIDDEN_ACTIONS,
        'resolver_questions'   => peopleGraphResolverQuestionMap(),
    ];
}

function peopleGraphCreateOrganization(int $tenantId, array $body, ?int $actorUserId = null): array
{
    $name = trim((string) ($body['name'] ?? ''));
    if ($name === '') throw new PeopleGraphException('name is required');
    $orgType = peopleGraphEnum((string) ($body['org_type'] ?? 'other'), [
        'tenant','legal_entity','client','vendor','department','location','cost_center',
        'project','trust','fund','practice','external','other',
    ], 'org_type');

    $pdo = peopleGraphPdo();
    $stmt = $pdo->prepare(
        'INSERT INTO people_graph_organizations
            (tenant_id, org_key, name, org_type, source_table, source_id, status, metadata_json, created_at, updated_at)
         VALUES
            (:tenant_id, :org_key, :name, :org_type, :source_table, :source_id, :status, :metadata_json, NOW(), NOW())'
    );
    $stmt->execute([
        'tenant_id'     => $tenantId,
        'org_key'       => peopleGraphOptionalKey($body['org_key'] ?? null, 'org_key'),
        'name'          => substr($name, 0, 200),
        'org_type'      => $orgType,
        'source_table'  => peopleGraphOptionalKey($body['source_table'] ?? null, 'source_table'),
        'source_id'     => isset($body['source_id']) && $body['source_id'] !== '' ? (int) $body['source_id'] : null,
        'status'        => peopleGraphStatus($body['status'] ?? 'active'),
        'metadata_json' => peopleGraphJson($body['metadata'] ?? $body['metadata_json'] ?? null),
    ]);
    $id = (int) $pdo->lastInsertId();
    peopleGraphAudit($tenantId, $actorUserId, 'people.graph.organization.created', 'people_graph_organizations', $id, [
        'name' => $name,
        'org_type' => $orgType,
    ]);
    return peopleGraphGetById('people_graph_organizations', $tenantId, $id);
}

function peopleGraphListOrganizations(int $tenantId, array $filters = []): array
{
    $where = ['tenant_id = :tenant_id'];
    $params = ['tenant_id' => $tenantId];
    if (empty($filters['include_inactive'])) $where[] = "status = 'active'";
    if (!empty($filters['org_type'])) {
        $where[] = 'org_type = :org_type';
        $params['org_type'] = peopleGraphEnum((string) $filters['org_type'], [
            'tenant','legal_entity','client','vendor','department','location','cost_center',
            'project','trust','fund','practice','external','other',
        ], 'org_type');
    }
    return peopleGraphFetchAll(
        'SELECT * FROM people_graph_organizations WHERE ' . implode(' AND ', $where) . ' ORDER BY name ASC LIMIT ' . peopleGraphLimit($filters),
        $params
    );
}

function peopleGraphCreateActorLink(int $tenantId, array $body, ?int $actorUserId = null): array
{
    $actor = peopleGraphActorRef($body);
    $pdo = peopleGraphPdo();
    $stmt = $pdo->prepare(
        'INSERT INTO people_graph_actor_links
            (tenant_id, actor_type, actor_id, person_id, user_id, organization_id, company_id,
             ai_worker_id, label, status, source, metadata_json, created_at, updated_at)
         VALUES
            (:tenant_id, :actor_type, :actor_id, :person_id, :user_id, :organization_id, :company_id,
             :ai_worker_id, :label, :status, :source, :metadata_json, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
             person_id = VALUES(person_id),
             user_id = VALUES(user_id),
             organization_id = VALUES(organization_id),
             company_id = VALUES(company_id),
             ai_worker_id = VALUES(ai_worker_id),
             label = VALUES(label),
             status = VALUES(status),
             source = VALUES(source),
             metadata_json = VALUES(metadata_json),
             updated_at = NOW()'
    );
    $stmt->execute([
        'tenant_id'        => $tenantId,
        'actor_type'       => $actor['actor_type'],
        'actor_id'         => $actor['actor_id'],
        'person_id'        => peopleGraphNullableInt($body['person_id'] ?? null),
        'user_id'          => peopleGraphNullableInt($body['user_id'] ?? null),
        'organization_id'  => peopleGraphNullableInt($body['organization_id'] ?? null),
        'company_id'       => peopleGraphNullableInt($body['company_id'] ?? null),
        'ai_worker_id'     => peopleGraphNullableInt($body['ai_worker_id'] ?? null),
        'label'            => isset($body['label']) ? substr(trim((string) $body['label']), 0, 200) : null,
        'status'           => peopleGraphStatus($body['status'] ?? 'active'),
        'source'           => peopleGraphOptionalKey($body['source'] ?? null, 'source'),
        'metadata_json'    => peopleGraphJson($body['metadata'] ?? $body['metadata_json'] ?? null),
    ]);
    $row = peopleGraphActorLinkGet($tenantId, $actor['actor_type'], $actor['actor_id']);
    peopleGraphAudit($tenantId, $actorUserId, 'people.graph.actor_linked', 'people_graph_actor_links', (int) ($row['id'] ?? 0), $actor);
    return $row;
}

function peopleGraphActorLinkGet(int $tenantId, string $actorType, int $actorId): ?array
{
    $stmt = peopleGraphPdo()->prepare(
        'SELECT * FROM people_graph_actor_links
          WHERE tenant_id = :tenant_id AND actor_type = :actor_type AND actor_id = :actor_id LIMIT 1'
    );
    $stmt->execute(['tenant_id' => $tenantId, 'actor_type' => $actorType, 'actor_id' => $actorId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function peopleGraphListActorLinks(int $tenantId, array $filters = []): array
{
    $where = ['tenant_id = :tenant_id'];
    $params = ['tenant_id' => $tenantId];
    if (empty($filters['include_inactive'])) $where[] = "status = 'active'";
    if (!empty($filters['actor_type'])) {
        $where[] = 'actor_type = :actor_type';
        $params['actor_type'] = peopleGraphActorType((string) $filters['actor_type']);
    }
    return peopleGraphFetchAll(
        'SELECT * FROM people_graph_actor_links WHERE ' . implode(' AND ', $where) . ' ORDER BY actor_type, actor_id LIMIT ' . peopleGraphLimit($filters),
        $params
    );
}

function peopleGraphCreateTeam(int $tenantId, array $body, ?int $actorUserId = null): array
{
    $teamKey = peopleGraphRequiredKey($body['team_key'] ?? '', 'team_key');
    $name = trim((string) ($body['name'] ?? ''));
    if ($name === '') throw new PeopleGraphException('name is required');
    $pdo = peopleGraphPdo();
    $stmt = $pdo->prepare(
        'INSERT INTO people_graph_teams
            (tenant_id, team_key, name, module_scope, description, status, metadata_json, created_by_user_id, created_at, updated_at)
         VALUES
            (:tenant_id, :team_key, :name, :module_scope, :description, :status, :metadata_json, :created_by, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            name = VALUES(name), module_scope = VALUES(module_scope), description = VALUES(description),
            status = VALUES(status), metadata_json = VALUES(metadata_json), updated_at = NOW()'
    );
    $stmt->execute([
        'tenant_id'     => $tenantId,
        'team_key'      => $teamKey,
        'name'          => substr($name, 0, 200),
        'module_scope'  => peopleGraphOptionalKey($body['module_scope'] ?? null, 'module_scope'),
        'description'   => isset($body['description']) ? substr((string) $body['description'], 0, 1000) : null,
        'status'        => peopleGraphStatus($body['status'] ?? 'active'),
        'metadata_json' => peopleGraphJson($body['metadata'] ?? $body['metadata_json'] ?? null),
        'created_by'    => $actorUserId,
    ]);
    $row = peopleGraphFindByKey('people_graph_teams', 'team_key', $tenantId, $teamKey);
    peopleGraphAudit($tenantId, $actorUserId, 'people.graph.team.upserted', 'people_graph_teams', (int) ($row['id'] ?? 0), ['team_key' => $teamKey]);
    return $row;
}

function peopleGraphListTeams(int $tenantId, array $filters = []): array
{
    $where = ['tenant_id = :tenant_id'];
    $params = ['tenant_id' => $tenantId];
    if (empty($filters['include_inactive'])) $where[] = "status = 'active'";
    if (!empty($filters['module_scope'])) {
        $where[] = 'module_scope = :module_scope';
        $params['module_scope'] = peopleGraphRequiredKey($filters['module_scope'], 'module_scope');
    }
    return peopleGraphFetchAll(
        'SELECT * FROM people_graph_teams WHERE ' . implode(' AND ', $where) . ' ORDER BY name ASC LIMIT ' . peopleGraphLimit($filters),
        $params
    );
}

function peopleGraphCreateRole(int $tenantId, array $body, ?int $actorUserId = null): array
{
    $roleKey = peopleGraphRequiredKey($body['role_key'] ?? '', 'role_key');
    $label = trim((string) ($body['label'] ?? $body['name'] ?? ''));
    if ($label === '') throw new PeopleGraphException('label is required');
    $pdo = peopleGraphPdo();
    $stmt = $pdo->prepare(
        'INSERT INTO people_graph_roles
            (tenant_id, role_key, label, module_scope, description, status, metadata_json, created_by_user_id, created_at, updated_at)
         VALUES
            (:tenant_id, :role_key, :label, :module_scope, :description, :status, :metadata_json, :created_by, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            label = VALUES(label), module_scope = VALUES(module_scope), description = VALUES(description),
            status = VALUES(status), metadata_json = VALUES(metadata_json), updated_at = NOW()'
    );
    $stmt->execute([
        'tenant_id'     => $tenantId,
        'role_key'      => $roleKey,
        'label'         => substr($label, 0, 200),
        'module_scope'  => peopleGraphOptionalKey($body['module_scope'] ?? null, 'module_scope'),
        'description'   => isset($body['description']) ? substr((string) $body['description'], 0, 1000) : null,
        'status'        => peopleGraphStatus($body['status'] ?? 'active'),
        'metadata_json' => peopleGraphJson($body['metadata'] ?? $body['metadata_json'] ?? null),
        'created_by'    => $actorUserId,
    ]);
    $row = peopleGraphFindByKey('people_graph_roles', 'role_key', $tenantId, $roleKey);
    peopleGraphAudit($tenantId, $actorUserId, 'people.graph.role.upserted', 'people_graph_roles', (int) ($row['id'] ?? 0), ['role_key' => $roleKey]);
    return $row;
}

function peopleGraphListRoles(int $tenantId, array $filters = []): array
{
    $where = ['tenant_id = :tenant_id'];
    $params = ['tenant_id' => $tenantId];
    if (empty($filters['include_inactive'])) $where[] = "status = 'active'";
    if (!empty($filters['module_scope'])) {
        $where[] = 'module_scope = :module_scope';
        $params['module_scope'] = peopleGraphRequiredKey($filters['module_scope'], 'module_scope');
    }
    return peopleGraphFetchAll(
        'SELECT * FROM people_graph_roles WHERE ' . implode(' AND ', $where) . ' ORDER BY label ASC LIMIT ' . peopleGraphLimit($filters),
        $params
    );
}

function peopleGraphCreateRelationship(int $tenantId, array $body, ?int $actorUserId = null): array
{
    $source = peopleGraphActorRef($body, 'source_actor_type', 'source_actor_id');
    $target = peopleGraphActorRef($body, 'target_actor_type', 'target_actor_id');
    $type = peopleGraphEnum((string) ($body['relationship_type'] ?? ''), PEOPLE_GRAPH_RELATIONSHIP_TYPES, 'relationship_type');
    $context = peopleGraphContextRef($body);
    $pdo = peopleGraphPdo();
    $stmt = $pdo->prepare(
        'INSERT INTO people_graph_relationships
            (tenant_id, source_actor_type, source_actor_id, relationship_type, target_actor_type, target_actor_id,
             context_module, context_entity_type, context_entity_id, status, starts_at, ends_at, metadata_json,
             created_by_user_id, created_at, updated_at)
         VALUES
            (:tenant_id, :source_type, :source_id, :relationship_type, :target_type, :target_id,
             :context_module, :context_entity_type, :context_entity_id, :status, :starts_at, :ends_at, :metadata_json,
             :created_by, NOW(), NOW())'
    );
    $stmt->execute([
        'tenant_id'           => $tenantId,
        'source_type'         => $source['actor_type'],
        'source_id'           => $source['actor_id'],
        'relationship_type'   => $type,
        'target_type'         => $target['actor_type'],
        'target_id'           => $target['actor_id'],
        'context_module'      => $context['context_module'],
        'context_entity_type' => $context['context_entity_type'],
        'context_entity_id'   => $context['context_entity_id'],
        'status'              => peopleGraphStatus($body['status'] ?? 'active'),
        'starts_at'           => peopleGraphDateTime($body['starts_at'] ?? null, 'starts_at'),
        'ends_at'             => peopleGraphDateTime($body['ends_at'] ?? null, 'ends_at'),
        'metadata_json'       => peopleGraphJson($body['metadata'] ?? $body['metadata_json'] ?? null),
        'created_by'          => $actorUserId,
    ]);
    $id = (int) $pdo->lastInsertId();
    peopleGraphAudit($tenantId, $actorUserId, 'people.graph.relationship.created', 'people_graph_relationships', $id, [
        'relationship_type' => $type,
        'source' => $source,
        'target' => $target,
        'context' => $context,
    ]);
    return peopleGraphHydrateRelationship(peopleGraphGetById('people_graph_relationships', $tenantId, $id));
}

function peopleGraphListRelationships(int $tenantId, array $filters = []): array
{
    $where = ['tenant_id = :tenant_id'];
    $params = ['tenant_id' => $tenantId];
    if (empty($filters['include_inactive'])) $where[] = "status = 'active'";
    peopleGraphAppendActorFilter($where, $params, $filters, 'source');
    peopleGraphAppendActorFilter($where, $params, $filters, 'target');
    if (!empty($filters['relationship_type'])) {
        $where[] = 'relationship_type = :relationship_type';
        $params['relationship_type'] = peopleGraphEnum((string) $filters['relationship_type'], PEOPLE_GRAPH_RELATIONSHIP_TYPES, 'relationship_type');
    }
    peopleGraphAppendContextFilters($where, $params, $filters);
    $rows = peopleGraphFetchAll(
        'SELECT * FROM people_graph_relationships WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT ' . peopleGraphLimit($filters),
        $params
    );
    return array_map('peopleGraphHydrateRelationship', $rows);
}

function peopleGraphAssignResponsibility(int $tenantId, array $body, ?int $actorUserId = null): array
{
    $actor = peopleGraphActorRef($body);
    $object = peopleGraphObjectRef($body);
    $type = peopleGraphEnum((string) ($body['responsibility_type'] ?? ''), PEOPLE_GRAPH_RESPONSIBILITY_TYPES, 'responsibility_type');
    $pdo = peopleGraphPdo();
    $stmt = $pdo->prepare(
        'INSERT INTO people_graph_responsibility_assignments
            (tenant_id, object_module, object_type, object_id, responsibility_type,
             actor_type, actor_id, priority, status, starts_at, ends_at, conditions_json, source,
             assigned_by_user_id, created_at, updated_at)
         VALUES
            (:tenant_id, :object_module, :object_type, :object_id, :responsibility_type,
             :actor_type, :actor_id, :priority, :status, :starts_at, :ends_at, :conditions_json, :source,
             :assigned_by, NOW(), NOW())'
    );
    $stmt->execute([
        'tenant_id'            => $tenantId,
        'object_module'        => $object['object_module'],
        'object_type'          => $object['object_type'],
        'object_id'            => $object['object_id'],
        'responsibility_type'  => $type,
        'actor_type'           => $actor['actor_type'],
        'actor_id'             => $actor['actor_id'],
        'priority'             => max(0, min(10000, (int) ($body['priority'] ?? 100))),
        'status'               => peopleGraphStatus($body['status'] ?? 'active'),
        'starts_at'            => peopleGraphDateTime($body['starts_at'] ?? null, 'starts_at'),
        'ends_at'              => peopleGraphDateTime($body['ends_at'] ?? null, 'ends_at'),
        'conditions_json'      => peopleGraphJson($body['conditions'] ?? $body['conditions_json'] ?? null),
        'source'               => peopleGraphOptionalKey($body['source'] ?? null, 'source'),
        'assigned_by'          => $actorUserId,
    ]);
    $id = (int) $pdo->lastInsertId();
    peopleGraphAudit($tenantId, $actorUserId, 'people.graph.responsibility.assigned', 'people_graph_responsibility_assignments', $id, [
        'object' => $object,
        'responsibility_type' => $type,
        'actor' => $actor,
    ]);
    return peopleGraphHydrateResponsibility(peopleGraphGetById('people_graph_responsibility_assignments', $tenantId, $id), $tenantId);
}

function peopleGraphListResponsibilities(int $tenantId, array $filters = []): array
{
    $where = ['tenant_id = :tenant_id'];
    $params = ['tenant_id' => $tenantId];
    if (empty($filters['include_inactive'])) {
        $where[] = "status = 'active'";
        $where[] = '(starts_at IS NULL OR starts_at <= NOW())';
        $where[] = '(ends_at IS NULL OR ends_at >= NOW())';
    }
    peopleGraphAppendObjectFilters($where, $params, $filters);
    if (!empty($filters['responsibility_type'])) {
        $where[] = 'responsibility_type = :responsibility_type';
        $params['responsibility_type'] = peopleGraphEnum((string) $filters['responsibility_type'], PEOPLE_GRAPH_RESPONSIBILITY_TYPES, 'responsibility_type');
    }
    if (!empty($filters['actor_type'])) {
        $where[] = 'actor_type = :actor_type';
        $params['actor_type'] = peopleGraphActorType((string) $filters['actor_type']);
    }
    if (!empty($filters['actor_id'])) {
        $where[] = 'actor_id = :actor_id';
        $params['actor_id'] = (int) $filters['actor_id'];
    }
    $rows = peopleGraphFetchAll(
        'SELECT * FROM people_graph_responsibility_assignments WHERE ' . implode(' AND ', $where) . ' ORDER BY priority ASC, id ASC LIMIT ' . peopleGraphLimit($filters),
        $params
    );
    return array_map(fn($row) => peopleGraphHydrateResponsibility($row, $tenantId), $rows);
}

function peopleGraphResolve(int $tenantId, string $question, array $objectRef, array $opts = []): array
{
    $map = peopleGraphResolverQuestionMap();
    if (!isset($map[$question])) {
        throw new PeopleGraphException("Unsupported resolver question: {$question}");
    }
    $object = peopleGraphObjectRef($objectRef);
    $types = $map[$question];

    $placeholders = [];
    $params = [
        'tenant_id' => $tenantId,
        'object_module' => $object['object_module'],
        'object_type' => $object['object_type'],
        'object_id' => $object['object_id'],
    ];
    foreach ($types as $i => $type) {
        $key = 'rt' . $i;
        $placeholders[] = ':' . $key;
        $params[$key] = $type;
    }

    $rows = peopleGraphFetchAll(
        'SELECT * FROM people_graph_responsibility_assignments
          WHERE tenant_id = :tenant_id
            AND object_module = :object_module
            AND object_type = :object_type
            AND object_id = :object_id
            AND responsibility_type IN (' . implode(',', $placeholders) . ')
            AND status = "active"
            AND (starts_at IS NULL OR starts_at <= NOW())
            AND (ends_at IS NULL OR ends_at >= NOW())
          ORDER BY priority ASC, id ASC
          LIMIT ' . peopleGraphLimit($opts),
        $params
    );

    $assignments = [];
    foreach ($rows as $row) {
        $assignments[] = peopleGraphHydrateResponsibility(
            peopleGraphApplyDelegation($tenantId, $row, $object),
            $tenantId
        );
    }

    return [
        'question' => $question,
        'object' => $object,
        'responsibility_types' => $types,
        'assignments' => $assignments,
        'count' => count($assignments),
    ];
}

function peopleGraphCreateDelegation(int $tenantId, array $body, ?int $actorUserId = null): array
{
    $from = peopleGraphActorRef($body, 'from_actor_type', 'from_actor_id');
    $to = peopleGraphActorRef($body, 'to_actor_type', 'to_actor_id');
    $type = peopleGraphEnum((string) ($body['delegation_type'] ?? 'all'), PEOPLE_GRAPH_DELEGATION_TYPES, 'delegation_type');
    $object = peopleGraphOptionalObjectRef($body);
    $pdo = peopleGraphPdo();
    $stmt = $pdo->prepare(
        'INSERT INTO people_graph_delegations
            (tenant_id, from_actor_type, from_actor_id, to_actor_type, to_actor_id, delegation_type,
             object_module, object_type, object_id, status, starts_at, ends_at, reason, created_by_user_id,
             created_at, updated_at)
         VALUES
            (:tenant_id, :from_type, :from_id, :to_type, :to_id, :delegation_type,
             :object_module, :object_type, :object_id, :status, COALESCE(:starts_at, NOW()), :ends_at, :reason,
             :created_by, NOW(), NOW())'
    );
    $stmt->execute([
        'tenant_id'       => $tenantId,
        'from_type'       => $from['actor_type'],
        'from_id'         => $from['actor_id'],
        'to_type'         => $to['actor_type'],
        'to_id'           => $to['actor_id'],
        'delegation_type' => $type,
        'object_module'   => $object['object_module'] ?? null,
        'object_type'     => $object['object_type'] ?? null,
        'object_id'       => $object['object_id'] ?? null,
        'status'          => peopleGraphEnum((string) ($body['status'] ?? 'active'), ['active','revoked','expired'], 'status'),
        'starts_at'       => peopleGraphDateTime($body['starts_at'] ?? null, 'starts_at'),
        'ends_at'         => peopleGraphDateTime($body['ends_at'] ?? null, 'ends_at'),
        'reason'          => isset($body['reason']) ? substr((string) $body['reason'], 0, 1000) : null,
        'created_by'      => $actorUserId,
    ]);
    $id = (int) $pdo->lastInsertId();
    peopleGraphAudit($tenantId, $actorUserId, 'people.graph.delegation.created', 'people_graph_delegations', $id, [
        'from' => $from,
        'to' => $to,
        'delegation_type' => $type,
        'object' => $object,
    ]);
    return peopleGraphHydrateDelegation(peopleGraphGetById('people_graph_delegations', $tenantId, $id), $tenantId);
}

function peopleGraphListDelegations(int $tenantId, array $filters = []): array
{
    $where = ['tenant_id = :tenant_id'];
    $params = ['tenant_id' => $tenantId];
    if (empty($filters['include_inactive'])) {
        $where[] = "status = 'active'";
        $where[] = 'starts_at <= NOW()';
        $where[] = '(ends_at IS NULL OR ends_at >= NOW())';
    }
    peopleGraphAppendActorFilter($where, $params, $filters, 'from');
    peopleGraphAppendActorFilter($where, $params, $filters, 'to');
    peopleGraphAppendObjectFilters($where, $params, $filters, true);
    if (!empty($filters['delegation_type'])) {
        $where[] = 'delegation_type = :delegation_type';
        $params['delegation_type'] = peopleGraphEnum((string) $filters['delegation_type'], PEOPLE_GRAPH_DELEGATION_TYPES, 'delegation_type');
    }
    $rows = peopleGraphFetchAll(
        'SELECT * FROM people_graph_delegations WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT ' . peopleGraphLimit($filters),
        $params
    );
    return array_map(fn($row) => peopleGraphHydrateDelegation($row, $tenantId), $rows);
}

function peopleGraphRevokeDelegation(int $tenantId, int $delegationId, ?int $actorUserId = null): array
{
    if ($delegationId <= 0) throw new PeopleGraphException('delegation id required');
    $pdo = peopleGraphPdo();
    $stmt = $pdo->prepare(
        'UPDATE people_graph_delegations
            SET status = "revoked", revoked_by_user_id = :actor, revoked_at = NOW(), updated_at = NOW()
          WHERE tenant_id = :tenant_id AND id = :id'
    );
    $stmt->execute(['actor' => $actorUserId, 'tenant_id' => $tenantId, 'id' => $delegationId]);
    if ($stmt->rowCount() === 0) throw new PeopleGraphException('Delegation not found');
    peopleGraphAudit($tenantId, $actorUserId, 'people.graph.delegation.revoked', 'people_graph_delegations', $delegationId, []);
    return peopleGraphHydrateDelegation(peopleGraphGetById('people_graph_delegations', $tenantId, $delegationId), $tenantId);
}

function peopleGraphGrantPermission(int $tenantId, array $body, ?int $actorUserId = null): array
{
    $subject = peopleGraphSubjectRef($body);
    $resource = peopleGraphPermissionResourceRef($body);
    $pdo = peopleGraphPdo();
    $stmt = $pdo->prepare(
        'INSERT INTO people_graph_permission_grants
            (tenant_id, subject_actor_type, subject_actor_id, action, resource_module, resource_type,
             resource_id, scope_type, scope_id, conditions_json, status, starts_at, ends_at,
             granted_by_user_id, created_at, updated_at)
         VALUES
            (:tenant_id, :subject_type, :subject_id, :action, :resource_module, :resource_type,
             :resource_id, :scope_type, :scope_id, :conditions_json, :status, :starts_at, :ends_at,
             :granted_by, NOW(), NOW())'
    );
    $stmt->execute([
        'tenant_id'       => $tenantId,
        'subject_type'    => $subject['actor_type'],
        'subject_id'      => $subject['actor_id'],
        'action'          => $resource['action'],
        'resource_module' => $resource['resource_module'],
        'resource_type'   => $resource['resource_type'],
        'resource_id'     => $resource['resource_id'],
        'scope_type'      => $resource['scope_type'],
        'scope_id'        => $resource['scope_id'],
        'conditions_json' => peopleGraphJson($body['conditions'] ?? $body['conditions_json'] ?? null),
        'status'          => peopleGraphEnum((string) ($body['status'] ?? 'active'), ['active','inactive','revoked'], 'status'),
        'starts_at'       => peopleGraphDateTime($body['starts_at'] ?? null, 'starts_at'),
        'ends_at'         => peopleGraphDateTime($body['ends_at'] ?? null, 'ends_at'),
        'granted_by'      => $actorUserId,
    ]);
    $id = (int) $pdo->lastInsertId();
    peopleGraphAudit($tenantId, $actorUserId, 'people.graph.permission.granted', 'people_graph_permission_grants', $id, [
        'subject' => $subject,
        'resource' => $resource,
    ]);
    return peopleGraphHydratePermissionGrant(peopleGraphGetById('people_graph_permission_grants', $tenantId, $id), $tenantId);
}

function peopleGraphListPermissionGrants(int $tenantId, array $filters = []): array
{
    $where = ['tenant_id = :tenant_id'];
    $params = ['tenant_id' => $tenantId];
    if (empty($filters['include_inactive'])) {
        $where[] = "status = 'active'";
        $where[] = '(starts_at IS NULL OR starts_at <= NOW())';
        $where[] = '(ends_at IS NULL OR ends_at >= NOW())';
    }
    if (!empty($filters['subject_actor_type'])) {
        $where[] = 'subject_actor_type = :subject_actor_type';
        $params['subject_actor_type'] = peopleGraphActorType((string) $filters['subject_actor_type']);
    }
    if (!empty($filters['subject_actor_id'])) {
        $where[] = 'subject_actor_id = :subject_actor_id';
        $params['subject_actor_id'] = (int) $filters['subject_actor_id'];
    }
    if (!empty($filters['action'])) {
        $where[] = 'action = :action';
        $params['action'] = peopleGraphAction((string) $filters['action']);
    }
    peopleGraphAppendPermissionResourceFilters($where, $params, $filters);
    $rows = peopleGraphFetchAll(
        'SELECT * FROM people_graph_permission_grants WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT ' . peopleGraphLimit($filters),
        $params
    );
    return array_map(fn($row) => peopleGraphHydratePermissionGrant($row, $tenantId), $rows);
}

function peopleGraphRevokePermissionGrant(int $tenantId, int $grantId, ?int $actorUserId = null): array
{
    if ($grantId <= 0) throw new PeopleGraphException('permission grant id required');
    $pdo = peopleGraphPdo();
    $stmt = $pdo->prepare(
        'UPDATE people_graph_permission_grants
            SET status = "revoked", revoked_by_user_id = :actor, revoked_at = NOW(), updated_at = NOW()
          WHERE tenant_id = :tenant_id AND id = :id'
    );
    $stmt->execute(['actor' => $actorUserId, 'tenant_id' => $tenantId, 'id' => $grantId]);
    if ($stmt->rowCount() === 0) throw new PeopleGraphException('Permission grant not found');
    peopleGraphAudit($tenantId, $actorUserId, 'people.graph.permission.revoked', 'people_graph_permission_grants', $grantId, []);
    return peopleGraphHydratePermissionGrant(peopleGraphGetById('people_graph_permission_grants', $tenantId, $grantId), $tenantId);
}

function peopleGraphCheckPermission(int $tenantId, array $body, ?int $actorUserId = null): array
{
    $actor = peopleGraphActorRef($body);
    $resource = peopleGraphPermissionResourceRef($body);
    $context = is_array($body['context'] ?? null) ? $body['context'] : [];

    if ($actor['actor_type'] === 'ai_worker' && peopleGraphAiActionRequiresHuman($resource['action'])) {
        $decision = peopleGraphPermissionDecision(false, 'ai_worker_action_requires_human',
            'AI workers may prepare, recommend, route, and explain, but this action requires human approval.',
            $actor, $resource, [], [], [], []);
        peopleGraphAudit($tenantId, $actorUserId, 'people.graph.permission.checked', null, null, $decision);
        return $decision;
    }

    $subjects = [['ref' => $actor, 'source' => 'direct', 'delegation' => null]];
    foreach (peopleGraphActiveDelegationsForDelegate($tenantId, $actor, $resource) as $delegation) {
        $subjects[] = [
            'ref' => [
                'actor_type' => (string) $delegation['from_actor_type'],
                'actor_id' => (int) $delegation['from_actor_id'],
            ],
            'source' => 'delegation',
            'delegation' => $delegation,
        ];
    }

    $matchedGrants = [];
    $matchedRoles = [];
    $matchedDelegations = [];
    foreach ($subjects as $subject) {
        $subjectRef = $subject['ref'];
        foreach (peopleGraphPermissionRowsForSubject($tenantId, $subjectRef, $resource['action']) as $grant) {
            if (!peopleGraphGrantMatchesRequest($grant, $resource, $context)) continue;
            $matchedGrants[] = peopleGraphHydratePermissionGrant($grant, $tenantId);
            if ($subject['delegation']) $matchedDelegations[] = peopleGraphHydrateDelegation($subject['delegation'], $tenantId);
        }
        foreach (peopleGraphRoleIdsForActor($tenantId, $subjectRef, $resource) as $role) {
            $roleRef = ['actor_type' => 'role', 'actor_id' => (int) $role['role_id']];
            foreach (peopleGraphPermissionRowsForSubject($tenantId, $roleRef, $resource['action']) as $grant) {
                if (!peopleGraphGrantMatchesRequest($grant, $resource, $context)) continue;
                $matchedGrants[] = peopleGraphHydratePermissionGrant($grant, $tenantId);
                $matchedRoles[] = $role;
                if ($subject['delegation']) $matchedDelegations[] = peopleGraphHydrateDelegation($subject['delegation'], $tenantId);
            }
        }
    }

    $allowed = count($matchedGrants) > 0;
    $decision = peopleGraphPermissionDecision(
        $allowed,
        $allowed ? 'grant_matched' : 'no_matching_grant',
        $allowed ? 'Permission allowed by People Graph grant, role grant, or delegated grant.' : 'No active People Graph grant matched this actor, resource, scope, and conditions.',
        $actor,
        $resource,
        $matchedGrants,
        $matchedRoles,
        $matchedDelegations,
        []
    );
    peopleGraphAudit($tenantId, $actorUserId, 'people.graph.permission.checked', null, null, [
        'allowed' => $decision['allowed'],
        'reason_code' => $decision['reason_code'],
        'actor' => $actor,
        'resource' => $resource,
    ]);
    return $decision;
}

function peopleGraphCreateApprovalPolicy(int $tenantId, array $body, ?int $actorUserId = null): array
{
    $policyKey = peopleGraphRequiredKey($body['policy_key'] ?? '', 'policy_key');
    $name = trim((string) ($body['name'] ?? ''));
    if ($name === '') throw new PeopleGraphException('name is required');
    $resource = peopleGraphApprovalResourceRef($body);
    $pdo = peopleGraphPdo();
    $stmt = $pdo->prepare(
        'INSERT INTO people_graph_approval_policies
            (tenant_id, policy_key, name, resource_module, resource_type, scope_type, scope_id,
             priority, status, requires_human_for_ai, metadata_json, created_by_user_id, created_at, updated_at)
         VALUES
            (:tenant_id, :policy_key, :name, :resource_module, :resource_type, :scope_type, :scope_id,
             :priority, :status, :requires_human_for_ai, :metadata_json, :created_by, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            name = VALUES(name), resource_module = VALUES(resource_module), resource_type = VALUES(resource_type),
            scope_type = VALUES(scope_type), scope_id = VALUES(scope_id), priority = VALUES(priority),
            status = VALUES(status), requires_human_for_ai = VALUES(requires_human_for_ai),
            metadata_json = VALUES(metadata_json), updated_at = NOW()'
    );
    $stmt->execute([
        'tenant_id'              => $tenantId,
        'policy_key'             => $policyKey,
        'name'                   => substr($name, 0, 200),
        'resource_module'        => $resource['resource_module'],
        'resource_type'          => $resource['resource_type'],
        'scope_type'             => $resource['scope_type'],
        'scope_id'               => $resource['scope_id'],
        'priority'               => max(0, min(10000, (int) ($body['priority'] ?? 100))),
        'status'                 => peopleGraphStatus($body['status'] ?? 'active'),
        'requires_human_for_ai'  => array_key_exists('requires_human_for_ai', $body) ? (!empty($body['requires_human_for_ai']) ? 1 : 0) : 1,
        'metadata_json'          => peopleGraphJson($body['metadata'] ?? $body['metadata_json'] ?? null),
        'created_by'             => $actorUserId,
    ]);
    $row = peopleGraphFindByKey('people_graph_approval_policies', 'policy_key', $tenantId, $policyKey);
    peopleGraphAudit($tenantId, $actorUserId, 'people.graph.approval_policy.upserted', 'people_graph_approval_policies', (int) ($row['id'] ?? 0), [
        'policy_key' => $policyKey,
        'resource' => $resource,
    ]);
    return peopleGraphHydrateApprovalPolicy($row);
}

function peopleGraphListApprovalPolicies(int $tenantId, array $filters = []): array
{
    $where = ['tenant_id = :tenant_id'];
    $params = ['tenant_id' => $tenantId];
    if (empty($filters['include_inactive'])) $where[] = "status = 'active'";
    peopleGraphAppendApprovalResourceFilters($where, $params, $filters);
    $rows = peopleGraphFetchAll(
        'SELECT * FROM people_graph_approval_policies WHERE ' . implode(' AND ', $where) . ' ORDER BY priority ASC, name ASC LIMIT ' . peopleGraphLimit($filters),
        $params
    );
    return array_map('peopleGraphHydrateApprovalPolicy', $rows);
}

function peopleGraphCreateApprovalRule(int $tenantId, array $body, ?int $actorUserId = null): array
{
    $policyId = (int) ($body['policy_id'] ?? 0);
    if ($policyId <= 0 && !empty($body['policy_key'])) {
        $policy = peopleGraphFindByKey('people_graph_approval_policies', 'policy_key', $tenantId, peopleGraphRequiredKey($body['policy_key'], 'policy_key'));
        $policyId = (int) $policy['id'];
    }
    if ($policyId <= 0) throw new PeopleGraphException('policy_id or policy_key is required');
    $strategy = peopleGraphEnum((string) ($body['approver_strategy'] ?? ''), PEOPLE_GRAPH_APPROVAL_STRATEGIES, 'approver_strategy');
    $actor = null;
    if ($strategy === 'named_actor') {
        $actor = peopleGraphActorRef($body, 'approver_actor_type', 'approver_actor_id');
    }
    $pdo = peopleGraphPdo();
    $stmt = $pdo->prepare(
        'INSERT INTO people_graph_approval_policy_rules
            (tenant_id, policy_id, sequence_num, condition_json, approver_strategy, approver_role_id,
             approver_role_key, relationship_type, responsibility_type, approver_actor_type, approver_actor_id,
             scope_type, scope_id, minimum_approvals, separation_of_duties_required, status, metadata_json,
             created_by_user_id, created_at, updated_at)
         VALUES
            (:tenant_id, :policy_id, :sequence_num, :condition_json, :approver_strategy, :approver_role_id,
             :approver_role_key, :relationship_type, :responsibility_type, :approver_actor_type, :approver_actor_id,
             :scope_type, :scope_id, :minimum_approvals, :sod_required, :status, :metadata_json,
             :created_by, NOW(), NOW())'
    );
    $stmt->execute([
        'tenant_id'            => $tenantId,
        'policy_id'            => $policyId,
        'sequence_num'         => max(1, min(1000, (int) ($body['sequence_num'] ?? $body['sequence'] ?? 1))),
        'condition_json'       => peopleGraphJson($body['conditions'] ?? $body['condition'] ?? $body['condition_json'] ?? null),
        'approver_strategy'    => $strategy,
        'approver_role_id'     => peopleGraphNullableInt($body['approver_role_id'] ?? null),
        'approver_role_key'    => peopleGraphOptionalKey($body['approver_role_key'] ?? $body['approver_role'] ?? null, 'approver_role_key'),
        'relationship_type'    => isset($body['relationship_type']) && $body['relationship_type'] !== ''
            ? peopleGraphEnum((string) $body['relationship_type'], PEOPLE_GRAPH_RELATIONSHIP_TYPES, 'relationship_type') : null,
        'responsibility_type'  => isset($body['responsibility_type']) && $body['responsibility_type'] !== ''
            ? peopleGraphEnum((string) $body['responsibility_type'], PEOPLE_GRAPH_RESPONSIBILITY_TYPES, 'responsibility_type') : null,
        'approver_actor_type'  => $actor['actor_type'] ?? null,
        'approver_actor_id'    => $actor['actor_id'] ?? null,
        'scope_type'           => peopleGraphOptionalKey($body['scope_type'] ?? null, 'scope_type'),
        'scope_id'             => isset($body['scope_id']) && $body['scope_id'] !== '' ? peopleGraphRequiredObjectId($body['scope_id'], 'scope_id') : null,
        'minimum_approvals'    => max(1, min(20, (int) ($body['minimum_approvals'] ?? 1))),
        'sod_required'         => !empty($body['separation_of_duties_required']) ? 1 : 0,
        'status'               => peopleGraphStatus($body['status'] ?? 'active'),
        'metadata_json'        => peopleGraphJson($body['metadata'] ?? $body['metadata_json'] ?? null),
        'created_by'           => $actorUserId,
    ]);
    $id = (int) $pdo->lastInsertId();
    peopleGraphAudit($tenantId, $actorUserId, 'people.graph.approval_rule.created', 'people_graph_approval_policy_rules', $id, [
        'policy_id' => $policyId,
        'approver_strategy' => $strategy,
    ]);
    return peopleGraphHydrateApprovalRule(peopleGraphGetById('people_graph_approval_policy_rules', $tenantId, $id), $tenantId);
}

function peopleGraphListApprovalRules(int $tenantId, array $filters = []): array
{
    $where = ['tenant_id = :tenant_id'];
    $params = ['tenant_id' => $tenantId];
    if (empty($filters['include_inactive'])) $where[] = "status = 'active'";
    if (!empty($filters['policy_id'])) {
        $where[] = 'policy_id = :policy_id';
        $params['policy_id'] = (int) $filters['policy_id'];
    }
    $rows = peopleGraphFetchAll(
        'SELECT * FROM people_graph_approval_policy_rules WHERE ' . implode(' AND ', $where) . ' ORDER BY policy_id ASC, sequence_num ASC, id ASC LIMIT ' . peopleGraphLimit($filters),
        $params
    );
    return array_map(fn($row) => peopleGraphHydrateApprovalRule($row, $tenantId), $rows);
}

function peopleGraphResolveApprovers(int $tenantId, array $body): array
{
    $resource = peopleGraphApprovalResourceRef($body);
    $context = is_array($body['context'] ?? null) ? $body['context'] : [];
    $policies = peopleGraphMatchingApprovalPolicies($tenantId, $resource);
    $requirements = [];

    foreach ($policies as $policy) {
        $rules = peopleGraphFetchAll(
            'SELECT * FROM people_graph_approval_policy_rules
              WHERE tenant_id = :tenant_id AND policy_id = :policy_id AND status = "active"
              ORDER BY sequence_num ASC, id ASC',
            ['tenant_id' => $tenantId, 'policy_id' => (int) $policy['id']]
        );
        foreach ($rules as $rule) {
            $conditions = !empty($rule['condition_json']) ? (json_decode((string) $rule['condition_json'], true) ?: []) : [];
            if (!peopleGraphConditionsMatch($conditions, $context + $resource)) continue;
            $requirements[] = [
                'policy' => peopleGraphHydrateApprovalPolicy($policy),
                'rule' => peopleGraphHydrateApprovalRule($rule, $tenantId),
                'approvers' => peopleGraphResolveRuleApprovers($tenantId, $rule, $resource, $body),
            ];
        }
    }

    return [
        'resource' => $resource,
        'requirements' => $requirements,
        'count' => count($requirements),
    ];
}

function peopleGraphApplyDelegation(int $tenantId, array $assignment, array $object): array
{
    $delegationType = peopleGraphDelegationTypeForResponsibility((string) $assignment['responsibility_type']);
    $stmt = peopleGraphPdo()->prepare(
        'SELECT *
           FROM people_graph_delegations
          WHERE tenant_id = :tenant_id
            AND from_actor_type = :from_type
            AND from_actor_id = :from_id
            AND delegation_type IN ("all", :delegation_type)
            AND status = "active"
            AND starts_at <= NOW()
            AND (ends_at IS NULL OR ends_at >= NOW())
            AND (object_module IS NULL OR object_module = :object_module)
            AND (object_type IS NULL OR object_type = :object_type)
            AND (object_id IS NULL OR object_id = :object_id)
          ORDER BY
            CASE WHEN object_id IS NULL THEN 0 ELSE 1 END DESC,
            CASE WHEN object_type IS NULL THEN 0 ELSE 1 END DESC,
            CASE WHEN object_module IS NULL THEN 0 ELSE 1 END DESC,
            id DESC
          LIMIT 1'
    );
    $stmt->execute([
        'tenant_id'       => $tenantId,
        'from_type'       => $assignment['actor_type'],
        'from_id'         => (int) $assignment['actor_id'],
        'delegation_type' => $delegationType,
        'object_module'   => $object['object_module'],
        'object_type'     => $object['object_type'],
        'object_id'       => $object['object_id'],
    ]);
    $delegation = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$delegation) return $assignment;

    $assignment['delegated_from_actor_type'] = $assignment['actor_type'];
    $assignment['delegated_from_actor_id'] = (int) $assignment['actor_id'];
    $assignment['delegation_id'] = (int) $delegation['id'];
    $assignment['actor_type'] = $delegation['to_actor_type'];
    $assignment['actor_id'] = (int) $delegation['to_actor_id'];
    return $assignment;
}

function peopleGraphDelegationTypeForResponsibility(string $responsibilityType): string
{
    return match ($responsibilityType) {
        'approver' => 'approval',
        'reviewer' => 'review',
        'ai_supervisor' => 'supervision',
        'notifier', 'escalation_contact' => 'notification',
        'owner', 'accountable' => 'ownership',
        default => 'all',
    };
}

function peopleGraphActorLabel(int $tenantId, string $actorType, int $actorId): string
{
    try {
        $link = peopleGraphActorLinkGet($tenantId, $actorType, $actorId);
        if (!empty($link['label'])) return (string) $link['label'];

        $pdo = peopleGraphPdo();
        if ($actorType === 'person') {
            $st = $pdo->prepare('SELECT first_name, last_name, email_primary FROM people WHERE tenant_id = :t AND id = :id LIMIT 1');
            $st->execute(['t' => $tenantId, 'id' => $actorId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if ($r) return trim((string) ($r['first_name'] ?? '') . ' ' . (string) ($r['last_name'] ?? '')) ?: (string) ($r['email_primary'] ?? "person #{$actorId}");
        }
        if ($actorType === 'user') {
            $st = $pdo->prepare('SELECT name, email FROM users WHERE id = :id LIMIT 1');
            $st->execute(['id' => $actorId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if ($r) return (string) ($r['name'] ?: $r['email'] ?: "user #{$actorId}");
        }
        if ($actorType === 'company') {
            $st = $pdo->prepare('SELECT name FROM companies WHERE tenant_id = :t AND id = :id LIMIT 1');
            $st->execute(['t' => $tenantId, 'id' => $actorId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if ($r) return (string) ($r['name'] ?? "company #{$actorId}");
        }
        if ($actorType === 'organization') {
            $st = $pdo->prepare('SELECT name FROM people_graph_organizations WHERE tenant_id = :t AND id = :id LIMIT 1');
            $st->execute(['t' => $tenantId, 'id' => $actorId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if ($r) return (string) ($r['name'] ?? "organization #{$actorId}");
        }
        if ($actorType === 'team') {
            $st = $pdo->prepare('SELECT name FROM people_graph_teams WHERE tenant_id = :t AND id = :id LIMIT 1');
            $st->execute(['t' => $tenantId, 'id' => $actorId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if ($r) return (string) ($r['name'] ?? "team #{$actorId}");
        }
        if ($actorType === 'role') {
            $st = $pdo->prepare('SELECT label FROM people_graph_roles WHERE tenant_id = :t AND id = :id LIMIT 1');
            $st->execute(['t' => $tenantId, 'id' => $actorId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if ($r) return (string) ($r['label'] ?? "role #{$actorId}");
        }
        if ($actorType === 'ai_worker') {
            $st = $pdo->prepare('SELECT label, worker_key FROM ai_workers WHERE id = :id LIMIT 1');
            $st->execute(['id' => $actorId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if ($r) return (string) ($r['label'] ?: $r['worker_key'] ?: "ai worker #{$actorId}");
        }
    } catch (Throwable $_) {
        return "{$actorType} #{$actorId}";
    }
    return "{$actorType} #{$actorId}";
}

function peopleGraphHydrateResponsibility(array $row, int $tenantId): array
{
    $row['id'] = (int) $row['id'];
    $row['tenant_id'] = (int) $row['tenant_id'];
    $row['actor_id'] = (int) $row['actor_id'];
    $row['priority'] = (int) $row['priority'];
    $row['conditions'] = !empty($row['conditions_json']) ? (json_decode((string) $row['conditions_json'], true) ?: []) : [];
    $row['actor'] = [
        'type' => (string) $row['actor_type'],
        'id' => (int) $row['actor_id'],
        'label' => peopleGraphActorLabel($tenantId, (string) $row['actor_type'], (int) $row['actor_id']),
    ];
    if (!empty($row['delegated_from_actor_type']) && !empty($row['delegated_from_actor_id'])) {
        $row['delegated_from'] = [
            'type' => (string) $row['delegated_from_actor_type'],
            'id' => (int) $row['delegated_from_actor_id'],
            'label' => peopleGraphActorLabel($tenantId, (string) $row['delegated_from_actor_type'], (int) $row['delegated_from_actor_id']),
        ];
    }
    unset($row['conditions_json']);
    return $row;
}

function peopleGraphHydrateRelationship(array $row): array
{
    $row['id'] = (int) $row['id'];
    $row['tenant_id'] = (int) $row['tenant_id'];
    $row['source_actor_id'] = (int) $row['source_actor_id'];
    $row['target_actor_id'] = (int) $row['target_actor_id'];
    $row['metadata'] = !empty($row['metadata_json']) ? (json_decode((string) $row['metadata_json'], true) ?: []) : [];
    unset($row['metadata_json']);
    return $row;
}

function peopleGraphHydrateDelegation(array $row, int $tenantId): array
{
    $row['id'] = (int) $row['id'];
    $row['tenant_id'] = (int) $row['tenant_id'];
    $row['from_actor_id'] = (int) $row['from_actor_id'];
    $row['to_actor_id'] = (int) $row['to_actor_id'];
    $row['from_actor'] = [
        'type' => (string) $row['from_actor_type'],
        'id' => (int) $row['from_actor_id'],
        'label' => peopleGraphActorLabel($tenantId, (string) $row['from_actor_type'], (int) $row['from_actor_id']),
    ];
    $row['to_actor'] = [
        'type' => (string) $row['to_actor_type'],
        'id' => (int) $row['to_actor_id'],
        'label' => peopleGraphActorLabel($tenantId, (string) $row['to_actor_type'], (int) $row['to_actor_id']),
    ];
    return $row;
}

function peopleGraphHydratePermissionGrant(array $row, int $tenantId): array
{
    $row['id'] = (int) $row['id'];
    $row['tenant_id'] = (int) $row['tenant_id'];
    $row['subject_actor_id'] = (int) $row['subject_actor_id'];
    $row['conditions'] = !empty($row['conditions_json']) ? (json_decode((string) $row['conditions_json'], true) ?: []) : [];
    $row['subject_actor'] = [
        'type' => (string) $row['subject_actor_type'],
        'id' => (int) $row['subject_actor_id'],
        'label' => peopleGraphActorLabel($tenantId, (string) $row['subject_actor_type'], (int) $row['subject_actor_id']),
    ];
    unset($row['conditions_json']);
    return $row;
}

function peopleGraphHydrateApprovalPolicy(array $row): array
{
    $row['id'] = (int) $row['id'];
    $row['tenant_id'] = (int) $row['tenant_id'];
    $row['priority'] = (int) $row['priority'];
    $row['requires_human_for_ai'] = !empty($row['requires_human_for_ai']);
    $row['metadata'] = !empty($row['metadata_json']) ? (json_decode((string) $row['metadata_json'], true) ?: []) : [];
    unset($row['metadata_json']);
    return $row;
}

function peopleGraphHydrateApprovalRule(array $row, int $tenantId): array
{
    $row['id'] = (int) $row['id'];
    $row['tenant_id'] = (int) $row['tenant_id'];
    $row['policy_id'] = (int) $row['policy_id'];
    $row['sequence_num'] = (int) $row['sequence_num'];
    $row['minimum_approvals'] = (int) $row['minimum_approvals'];
    $row['separation_of_duties_required'] = !empty($row['separation_of_duties_required']);
    $row['conditions'] = !empty($row['condition_json']) ? (json_decode((string) $row['condition_json'], true) ?: []) : [];
    $row['metadata'] = !empty($row['metadata_json']) ? (json_decode((string) $row['metadata_json'], true) ?: []) : [];
    if (!empty($row['approver_actor_type']) && !empty($row['approver_actor_id'])) {
        $row['approver_actor'] = [
            'type' => (string) $row['approver_actor_type'],
            'id' => (int) $row['approver_actor_id'],
            'label' => peopleGraphActorLabel($tenantId, (string) $row['approver_actor_type'], (int) $row['approver_actor_id']),
        ];
    }
    unset($row['condition_json'], $row['metadata_json']);
    return $row;
}

function peopleGraphSubjectRef(array $body): array
{
    $type = (string) ($body['subject_actor_type'] ?? $body['subject_type'] ?? $body['actor_type'] ?? '');
    $id = (int) ($body['subject_actor_id'] ?? $body['subject_id'] ?? $body['actor_id'] ?? 0);
    if ($id <= 0) throw new PeopleGraphException('subject_actor_id must be a positive integer');
    return ['actor_type' => peopleGraphActorType($type), 'actor_id' => $id];
}

function peopleGraphPermissionResourceRef(array $body): array
{
    return [
        'action'          => peopleGraphAction((string) ($body['action'] ?? '')),
        'resource_module' => peopleGraphOptionalKey($body['resource_module'] ?? $body['object_module'] ?? null, 'resource_module'),
        'resource_type'   => peopleGraphRequiredKey($body['resource_type'] ?? $body['object_type'] ?? '', 'resource_type'),
        'resource_id'     => isset($body['resource_id']) && $body['resource_id'] !== ''
            ? peopleGraphRequiredObjectId($body['resource_id'], 'resource_id')
            : (isset($body['object_id']) && $body['object_id'] !== '' ? peopleGraphRequiredObjectId($body['object_id'], 'object_id') : null),
        'scope_type'      => peopleGraphOptionalKey($body['scope_type'] ?? null, 'scope_type'),
        'scope_id'        => isset($body['scope_id']) && $body['scope_id'] !== '' ? peopleGraphRequiredObjectId($body['scope_id'], 'scope_id') : null,
    ];
}

function peopleGraphApprovalResourceRef(array $body): array
{
    return [
        'resource_module' => peopleGraphOptionalKey($body['resource_module'] ?? $body['object_module'] ?? null, 'resource_module'),
        'resource_type'   => peopleGraphRequiredKey($body['resource_type'] ?? $body['object_type'] ?? '', 'resource_type'),
        'resource_id'     => isset($body['resource_id']) && $body['resource_id'] !== ''
            ? peopleGraphRequiredObjectId($body['resource_id'], 'resource_id')
            : (isset($body['object_id']) && $body['object_id'] !== '' ? peopleGraphRequiredObjectId($body['object_id'], 'object_id') : null),
        'scope_type'      => peopleGraphOptionalKey($body['scope_type'] ?? null, 'scope_type'),
        'scope_id'        => isset($body['scope_id']) && $body['scope_id'] !== '' ? peopleGraphRequiredObjectId($body['scope_id'], 'scope_id') : null,
    ];
}

function peopleGraphAppendPermissionResourceFilters(array &$where, array &$params, array $filters): void
{
    foreach (['resource_module', 'resource_type', 'resource_id', 'scope_type', 'scope_id'] as $key) {
        if (!array_key_exists($key, $filters) || $filters[$key] === '' || $filters[$key] === null) continue;
        $where[] = "{$key} = :{$key}";
        $params[$key] = in_array($key, ['resource_id', 'scope_id'], true)
            ? peopleGraphRequiredObjectId($filters[$key], $key)
            : peopleGraphRequiredKey($filters[$key], $key);
    }
}

function peopleGraphAppendApprovalResourceFilters(array &$where, array &$params, array $filters): void
{
    foreach (['resource_module', 'resource_type', 'scope_type', 'scope_id'] as $key) {
        if (!array_key_exists($key, $filters) || $filters[$key] === '' || $filters[$key] === null) continue;
        $where[] = "{$key} = :{$key}";
        $params[$key] = $key === 'scope_id'
            ? peopleGraphRequiredObjectId($filters[$key], $key)
            : peopleGraphRequiredKey($filters[$key], $key);
    }
}

function peopleGraphPermissionDecision(
    bool $allowed,
    string $reasonCode,
    string $explanation,
    array $actor,
    array $resource,
    array $matchedGrants,
    array $matchedRoles,
    array $matchedDelegations,
    array $requiredApprovals
): array {
    return [
        'allowed' => $allowed,
        'reason_code' => $reasonCode,
        'explanation' => $explanation,
        'actor' => $actor,
        'resource' => $resource,
        'matched_grants' => $matchedGrants,
        'matched_roles' => array_values($matchedRoles),
        'matched_delegations' => array_values($matchedDelegations),
        'required_approvals' => $requiredApprovals,
    ];
}

function peopleGraphAiActionRequiresHuman(string $action): bool
{
    $parts = preg_split('/[.:]/', strtolower($action)) ?: [$action];
    $verb = (string) end($parts);
    return in_array(strtolower($action), PEOPLE_GRAPH_AI_FORBIDDEN_ACTIONS, true)
        || in_array($verb, PEOPLE_GRAPH_AI_FORBIDDEN_ACTIONS, true);
}

function peopleGraphDelegationTypeForAction(string $action): string
{
    $parts = preg_split('/[.:]/', strtolower($action)) ?: [$action];
    $verb = (string) end($parts);
    return match ($verb) {
        'approve', 'authorize', 'post', 'release', 'submit' => 'approval',
        'review' => 'review',
        'notify' => 'notification',
        'own', 'assign' => 'ownership',
        'supervise' => 'supervision',
        default => 'all',
    };
}

function peopleGraphActiveDelegationsForDelegate(int $tenantId, array $delegate, array $resource): array
{
    $delegationType = peopleGraphDelegationTypeForAction($resource['action']);
    return peopleGraphFetchAll(
        'SELECT *
           FROM people_graph_delegations
          WHERE tenant_id = :tenant_id
            AND to_actor_type = :to_type
            AND to_actor_id = :to_id
            AND delegation_type IN ("all", :delegation_type)
            AND status = "active"
            AND starts_at <= NOW()
            AND (ends_at IS NULL OR ends_at >= NOW())
            AND (object_module IS NULL OR object_module = :resource_module)
            AND (object_type IS NULL OR object_type = :resource_type)
            AND (object_id IS NULL OR object_id = :resource_id)
          ORDER BY id DESC',
        [
            'tenant_id' => $tenantId,
            'to_type' => $delegate['actor_type'],
            'to_id' => (int) $delegate['actor_id'],
            'delegation_type' => $delegationType,
            'resource_module' => $resource['resource_module'],
            'resource_type' => $resource['resource_type'],
            'resource_id' => $resource['resource_id'],
        ]
    );
}

function peopleGraphPermissionRowsForSubject(int $tenantId, array $subject, string $action): array
{
    return peopleGraphFetchAll(
        'SELECT *
           FROM people_graph_permission_grants
          WHERE tenant_id = :tenant_id
            AND subject_actor_type = :subject_type
            AND subject_actor_id = :subject_id
            AND action IN (:action, "*")
            AND status = "active"
            AND (starts_at IS NULL OR starts_at <= NOW())
            AND (ends_at IS NULL OR ends_at >= NOW())
          ORDER BY id DESC',
        [
            'tenant_id' => $tenantId,
            'subject_type' => $subject['actor_type'],
            'subject_id' => (int) $subject['actor_id'],
            'action' => $action,
        ]
    );
}

function peopleGraphGrantMatchesRequest(array $grant, array $resource, array $context): bool
{
    foreach (['resource_module', 'resource_id', 'scope_type', 'scope_id'] as $key) {
        if (($grant[$key] ?? null) !== null && (string) $grant[$key] !== (string) ($resource[$key] ?? '')) {
            return false;
        }
    }
    $grantResourceType = (string) ($grant['resource_type'] ?? '');
    if ($grantResourceType !== 'any' && $grantResourceType !== (string) $resource['resource_type']) {
        return false;
    }
    $conditions = !empty($grant['conditions_json']) ? (json_decode((string) $grant['conditions_json'], true) ?: []) : [];
    return peopleGraphConditionsMatch($conditions, $context + $resource);
}

function peopleGraphRoleIdsForActor(int $tenantId, array $actor, array $resource): array
{
    $rows = peopleGraphFetchAll(
        'SELECT ra.role_id, r.role_key, r.label, ra.context_module, ra.context_entity_type, ra.context_entity_id
           FROM people_graph_role_assignments ra
           LEFT JOIN people_graph_roles r ON r.id = ra.role_id AND r.tenant_id = ra.tenant_id
          WHERE ra.tenant_id = :tenant_id
            AND ra.actor_type = :actor_type
            AND ra.actor_id = :actor_id
            AND ra.status = "active"
            AND (ra.starts_at IS NULL OR ra.starts_at <= NOW())
            AND (ra.ends_at IS NULL OR ra.ends_at >= NOW())
            AND (ra.context_module IS NULL OR ra.context_module = :resource_module)
            AND (ra.context_entity_type IS NULL OR ra.context_entity_type = :resource_type OR ra.context_entity_type = :scope_type)
            AND (ra.context_entity_id IS NULL OR ra.context_entity_id = :resource_id OR ra.context_entity_id = :scope_id)',
        [
            'tenant_id' => $tenantId,
            'actor_type' => $actor['actor_type'],
            'actor_id' => (int) $actor['actor_id'],
            'resource_module' => $resource['resource_module'] ?? null,
            'resource_type' => $resource['resource_type'] ?? null,
            'resource_id' => $resource['resource_id'] ?? null,
            'scope_type' => $resource['scope_type'] ?? null,
            'scope_id' => $resource['scope_id'] ?? null,
        ]
    );
    return array_map(static function (array $row): array {
        $row['role_id'] = (int) $row['role_id'];
        return $row;
    }, $rows);
}

function peopleGraphMatchingApprovalPolicies(int $tenantId, array $resource): array
{
    return peopleGraphFetchAll(
        'SELECT *
           FROM people_graph_approval_policies
          WHERE tenant_id = :tenant_id
            AND resource_type = :resource_type
            AND status = "active"
            AND (resource_module IS NULL OR resource_module = :resource_module)
            AND (scope_type IS NULL OR scope_type = :scope_type)
            AND (scope_id IS NULL OR scope_id = :scope_id)
          ORDER BY priority ASC, id ASC',
        [
            'tenant_id' => $tenantId,
            'resource_module' => $resource['resource_module'],
            'resource_type' => $resource['resource_type'],
            'scope_type' => $resource['scope_type'],
            'scope_id' => $resource['scope_id'],
        ]
    );
}

function peopleGraphResolveRuleApprovers(int $tenantId, array $rule, array $resource, array $request): array
{
    $strategy = (string) $rule['approver_strategy'];
    if ($strategy === 'named_actor' && !empty($rule['approver_actor_type']) && !empty($rule['approver_actor_id'])) {
        return [[
            'actor_type' => (string) $rule['approver_actor_type'],
            'actor_id' => (int) $rule['approver_actor_id'],
            'label' => peopleGraphActorLabel($tenantId, (string) $rule['approver_actor_type'], (int) $rule['approver_actor_id']),
            'resolution_source' => 'named_actor',
        ]];
    }

    if ($strategy === 'responsibility' && !empty($rule['responsibility_type']) && !empty($resource['resource_module']) && !empty($resource['resource_id'])) {
        $rows = peopleGraphListResponsibilities($tenantId, [
            'object_module' => $resource['resource_module'],
            'object_type' => $resource['resource_type'],
            'object_id' => $resource['resource_id'],
            'responsibility_type' => $rule['responsibility_type'],
        ]);
        return array_map(static fn($row) => [
            'actor_type' => $row['actor_type'],
            'actor_id' => (int) $row['actor_id'],
            'label' => $row['actor']['label'] ?? null,
            'resolution_source' => 'responsibility',
        ], $rows);
    }

    if ($strategy === 'role') {
        $roleId = (int) ($rule['approver_role_id'] ?? 0);
        if ($roleId <= 0 && !empty($rule['approver_role_key'])) {
            try {
                $role = peopleGraphFindByKey('people_graph_roles', 'role_key', $tenantId, (string) $rule['approver_role_key']);
                $roleId = (int) $role['id'];
            } catch (Throwable $_) {
                $roleId = 0;
            }
        }
        if ($roleId > 0) {
            $rows = peopleGraphFetchAll(
                'SELECT * FROM people_graph_role_assignments
                  WHERE tenant_id = :tenant_id AND role_id = :role_id AND status = "active"
                    AND (starts_at IS NULL OR starts_at <= NOW())
                    AND (ends_at IS NULL OR ends_at >= NOW())
                  ORDER BY id ASC',
                ['tenant_id' => $tenantId, 'role_id' => $roleId]
            );
            return array_map(fn($row) => [
                'actor_type' => (string) $row['actor_type'],
                'actor_id' => (int) $row['actor_id'],
                'label' => peopleGraphActorLabel($tenantId, (string) $row['actor_type'], (int) $row['actor_id']),
                'resolution_source' => 'role',
            ], $rows);
        }
    }

    if ($strategy === 'relationship' && !empty($rule['relationship_type'])) {
        $filters = ['relationship_type' => $rule['relationship_type']];
        foreach (['source_actor_type','source_actor_id','target_actor_type','target_actor_id'] as $key) {
            if (!empty($request[$key])) $filters[$key] = $request[$key];
        }
        $rows = peopleGraphListRelationships($tenantId, $filters);
        return array_map(fn($row) => [
            'actor_type' => (string) $row['source_actor_type'],
            'actor_id' => (int) $row['source_actor_id'],
            'label' => peopleGraphActorLabel($tenantId, (string) $row['source_actor_type'], (int) $row['source_actor_id']),
            'resolution_source' => 'relationship',
        ], $rows);
    }

    if ($strategy === 'manager_chain' && !empty($request['source_actor_type']) && !empty($request['source_actor_id'])) {
        $rows = peopleGraphListRelationships($tenantId, [
            'source_actor_type' => $request['source_actor_type'],
            'source_actor_id' => $request['source_actor_id'],
            'relationship_type' => 'reports_to',
        ]);
        return array_map(fn($row) => [
            'actor_type' => (string) $row['target_actor_type'],
            'actor_id' => (int) $row['target_actor_id'],
            'label' => peopleGraphActorLabel($tenantId, (string) $row['target_actor_type'], (int) $row['target_actor_id']),
            'resolution_source' => 'manager_chain',
        ], $rows);
    }

    return [];
}

function peopleGraphConditionsMatch(array $conditions, array $context): bool
{
    foreach ($conditions as $key => $expected) {
        if (in_array($key, ['amount_greater_than','amount_greater_than_or_equal','amount_less_than','amount_less_than_or_equal'], true)
            && !array_key_exists('amount', $context)) return false;
        if ($key === 'amount_greater_than' && !((float) $context['amount'] > (float) $expected)) return false;
        if ($key === 'amount_greater_than_or_equal' && !((float) $context['amount'] >= (float) $expected)) return false;
        if ($key === 'amount_less_than' && !((float) $context['amount'] < (float) $expected)) return false;
        if ($key === 'amount_less_than_or_equal' && !((float) $context['amount'] <= (float) $expected)) return false;
        if (in_array($key, ['amount_greater_than','amount_greater_than_or_equal','amount_less_than','amount_less_than_or_equal'], true)) continue;

        if (!array_key_exists($key, $context)) return false;
        if (is_bool($expected)) {
            if ((bool) $context[$key] !== $expected) return false;
            continue;
        }
        if (is_array($expected)) {
            if (!in_array($context[$key] ?? null, $expected, true)) return false;
            continue;
        }
        if ((string) $context[$key] !== (string) $expected) return false;
    }
    return true;
}

function peopleGraphAudit(int $tenantId, ?int $actorUserId, string $event, ?string $targetTable, ?int $targetId, array $meta = []): void
{
    try {
        $pdo = peopleGraphPdo();
        $payload = $meta ? json_encode($meta, JSON_UNESCAPED_SLASHES) : null;
        $pdo->prepare(
            'INSERT INTO people_graph_audit_log
                (tenant_id, actor_user_id, event, target_table, target_id, meta_json, ip_address, request_id, created_at)
             VALUES
                (:tenant_id, :actor_user_id, :event, :target_table, :target_id, :meta_json, :ip_address, :request_id, NOW())'
        )->execute([
            'tenant_id'     => $tenantId,
            'actor_user_id' => $actorUserId,
            'event'         => $event,
            'target_table'  => $targetTable,
            'target_id'     => $targetId,
            'meta_json'     => $payload,
            'ip_address'    => $_SERVER['REMOTE_ADDR'] ?? null,
            'request_id'    => $_SERVER['HTTP_X_REQUEST_ID'] ?? null,
        ]);
        $pdo->prepare(
            'INSERT INTO audit_log
                (tenant_id, actor_user_id, event, target_id, meta_json, ip_address, request_id, created_at)
             VALUES
                (:tenant_id, :actor_user_id, :event, :target_id, :meta_json, :ip_address, :request_id, NOW())'
        )->execute([
            'tenant_id'     => $tenantId,
            'actor_user_id' => $actorUserId,
            'event'         => $event,
            'target_id'     => $targetId,
            'meta_json'     => $payload,
            'ip_address'    => $_SERVER['REMOTE_ADDR'] ?? null,
            'request_id'    => $_SERVER['HTTP_X_REQUEST_ID'] ?? null,
        ]);
    } catch (Throwable $e) {
        error_log('[people_graph.audit] ' . $event . ' failed: ' . $e->getMessage());
    }
}

function peopleGraphPdo(): PDO
{
    $pdo = getDB();
    if (!$pdo) throw new PeopleGraphException('No database connection');
    return $pdo;
}

function peopleGraphGetById(string $table, int $tenantId, int $id): array
{
    peopleGraphSafeIdent($table);
    $stmt = peopleGraphPdo()->prepare("SELECT * FROM `{$table}` WHERE tenant_id = :tenant_id AND id = :id LIMIT 1");
    $stmt->execute(['tenant_id' => $tenantId, 'id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new PeopleGraphException('Record not found');
    return $row;
}

function peopleGraphFindByKey(string $table, string $keyColumn, int $tenantId, string $key): array
{
    peopleGraphSafeIdent($table);
    peopleGraphSafeIdent($keyColumn);
    $stmt = peopleGraphPdo()->prepare("SELECT * FROM `{$table}` WHERE tenant_id = :tenant_id AND `{$keyColumn}` = :k LIMIT 1");
    $stmt->execute(['tenant_id' => $tenantId, 'k' => $key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new PeopleGraphException('Record not found');
    return $row;
}

function peopleGraphFetchAll(string $sql, array $params): array
{
    $stmt = peopleGraphPdo()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function peopleGraphActorRef(array $body, string $typeKey = 'actor_type', string $idKey = 'actor_id'): array
{
    $type = peopleGraphActorType((string) ($body[$typeKey] ?? ''));
    $id = (int) ($body[$idKey] ?? 0);
    if ($id <= 0) throw new PeopleGraphException("{$idKey} must be a positive integer");
    return ['actor_type' => $type, 'actor_id' => $id];
}

function peopleGraphActorType(string $value): string
{
    return peopleGraphEnum($value, PEOPLE_GRAPH_ACTOR_TYPES, 'actor_type');
}

function peopleGraphObjectRef(array $body): array
{
    return [
        'object_module' => peopleGraphRequiredKey($body['object_module'] ?? '', 'object_module'),
        'object_type'   => peopleGraphRequiredKey($body['object_type'] ?? '', 'object_type'),
        'object_id'     => peopleGraphRequiredObjectId($body['object_id'] ?? '', 'object_id'),
    ];
}

function peopleGraphOptionalObjectRef(array $body): array
{
    $hasAny = ($body['object_module'] ?? null) !== null || ($body['object_type'] ?? null) !== null || ($body['object_id'] ?? null) !== null;
    if (!$hasAny || (($body['object_module'] ?? '') === '' && ($body['object_type'] ?? '') === '' && ($body['object_id'] ?? '') === '')) {
        return ['object_module' => null, 'object_type' => null, 'object_id' => null];
    }
    return peopleGraphObjectRef($body);
}

function peopleGraphContextRef(array $body): array
{
    return [
        'context_module'      => peopleGraphOptionalKey($body['context_module'] ?? null, 'context_module'),
        'context_entity_type' => peopleGraphOptionalKey($body['context_entity_type'] ?? null, 'context_entity_type'),
        'context_entity_id'   => isset($body['context_entity_id']) && $body['context_entity_id'] !== ''
            ? peopleGraphRequiredObjectId($body['context_entity_id'], 'context_entity_id') : null,
    ];
}

function peopleGraphAppendObjectFilters(array &$where, array &$params, array $filters, bool $nullableColumns = false): void
{
    foreach (['object_module', 'object_type', 'object_id'] as $key) {
        if (!array_key_exists($key, $filters) || $filters[$key] === '' || $filters[$key] === null) continue;
        $where[] = "{$key} = :{$key}";
        $params[$key] = $key === 'object_id'
            ? peopleGraphRequiredObjectId($filters[$key], $key)
            : peopleGraphRequiredKey($filters[$key], $key);
    }
    if ($nullableColumns) {
        return;
    }
}

function peopleGraphAppendContextFilters(array &$where, array &$params, array $filters): void
{
    foreach (['context_module', 'context_entity_type', 'context_entity_id'] as $key) {
        if (!array_key_exists($key, $filters) || $filters[$key] === '' || $filters[$key] === null) continue;
        $where[] = "{$key} = :{$key}";
        $params[$key] = $key === 'context_entity_id'
            ? peopleGraphRequiredObjectId($filters[$key], $key)
            : peopleGraphRequiredKey($filters[$key], $key);
    }
}

function peopleGraphAppendActorFilter(array &$where, array &$params, array $filters, string $prefix): void
{
    $typeKey = "{$prefix}_actor_type";
    $idKey = "{$prefix}_actor_id";
    if (!empty($filters[$typeKey])) {
        $where[] = "{$typeKey} = :{$typeKey}";
        $params[$typeKey] = peopleGraphActorType((string) $filters[$typeKey]);
    }
    if (!empty($filters[$idKey])) {
        $where[] = "{$idKey} = :{$idKey}";
        $params[$idKey] = (int) $filters[$idKey];
    }
}

function peopleGraphEnum(string $value, array $allowed, string $field): string
{
    $value = trim($value);
    if (!in_array($value, $allowed, true)) {
        throw new PeopleGraphException("Invalid {$field}: {$value}");
    }
    return $value;
}

function peopleGraphStatus($value): string
{
    return peopleGraphEnum((string) $value, ['active','inactive','deleted'], 'status');
}

function peopleGraphRequiredKey($value, string $field): string
{
    $value = trim((string) $value);
    if ($value === '' || !preg_match('/^[a-z][a-z0-9_-]{0,79}$/', $value)) {
        throw new PeopleGraphException("{$field} must be snake/kebab-case ASCII");
    }
    return $value;
}

function peopleGraphOptionalKey($value, string $field): ?string
{
    if ($value === null || $value === '') return null;
    return peopleGraphRequiredKey($value, $field);
}

function peopleGraphRequiredObjectId($value, string $field): string
{
    $value = trim((string) $value);
    if ($value === '' || strlen($value) > 120 || !preg_match('/^[A-Za-z0-9_.:-]+$/', $value)) {
        throw new PeopleGraphException("{$field} must be an ASCII id string");
    }
    return $value;
}

function peopleGraphAction(string $value): string
{
    $value = trim($value);
    if ($value === '*') return $value;
    if ($value === '' || strlen($value) > 120 || !preg_match('/^[a-z][a-z0-9_.:-]*$/', $value)) {
        throw new PeopleGraphException('action must be an ASCII action token');
    }
    return $value;
}

function peopleGraphNullableInt($value): ?int
{
    if ($value === null || $value === '') return null;
    $i = (int) $value;
    return $i > 0 ? $i : null;
}

function peopleGraphDateTime($value, string $field): ?string
{
    if ($value === null || $value === '') return null;
    $value = trim((string) $value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?)?$/', $value)) {
        throw new PeopleGraphException("{$field} must be YYYY-MM-DD or YYYY-MM-DD HH:MM:SS");
    }
    if (strlen($value) === 10) return $value . ' 00:00:00';
    return str_replace('T', ' ', strlen($value) === 16 ? $value . ':00' : $value);
}

function peopleGraphJson($value): ?string
{
    if ($value === null || $value === '') return null;
    if (is_string($value)) {
        json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE) throw new PeopleGraphException('Invalid JSON: ' . json_last_error_msg());
        return $value;
    }
    return json_encode($value, JSON_UNESCAPED_SLASHES);
}

function peopleGraphLimit(array $filters): int
{
    return max(1, min(500, (int) ($filters['limit'] ?? $filters['per_page'] ?? 100)));
}

function peopleGraphSafeIdent(string $name): string
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
        throw new PeopleGraphException("Unsafe SQL identifier: {$name}");
    }
    return $name;
}
