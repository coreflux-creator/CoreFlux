<?php
/**
 * People Graph API.
 *
 * v1 alias:
 *   GET  /api/v1/people/graph/vocabulary
 *   GET  /api/v1/people/graph/resolve?question=who_approves&object_module=payroll&object_type=run&object_id=123
 *   GET  /api/v1/people/graph/responsibilities
 *   POST /api/v1/people/graph/responsibilities
 *   GET  /api/v1/people/graph/relationships
 *   POST /api/v1/people/graph/relationships
 *   GET  /api/v1/people/graph/delegations
 *   POST /api/v1/people/graph/delegations
 *   GET  /api/v1/people/graph/permission-grants
 *   POST /api/v1/people/graph/permission-grants
 *   POST /api/v1/people/graph/check-permission
 *   GET  /api/v1/people/graph/approval-policies
 *   POST /api/v1/people/graph/approval-policies
 *   GET  /api/v1/people/graph/approval-rules
 *   POST /api/v1/people/graph/approval-rules
 *   POST /api/v1/people/graph/resolve-approvers
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/people_graph.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$tenantId = (int) $ctx['tenant_id'];
$actorUserId = (int) ($user['id'] ?? 0);
$method = api_method();
$action = (string) api_query('action', '');
if ($action === '') $action = 'vocabulary';

try {
    if ($method === 'GET' && $action === 'vocabulary') {
        rbac_legacy_require($user, 'people.graph.view');
        api_ok(peopleGraphVocabulary());
    }

    if ($method === 'GET' && $action === 'resolve') {
        rbac_legacy_require($user, 'people.graph.view');
        $question = (string) api_query('question', 'who_owns');
        $result = peopleGraphResolve($tenantId, $question, [
            'object_module' => api_query('object_module'),
            'object_type'   => api_query('object_type'),
            'object_id'     => api_query('object_id'),
        ], [
            'limit' => api_query('limit', 100),
        ]);
        peopleGraphAudit($tenantId, $actorUserId ?: null, 'people.graph.resolved', null, null, [
            'question' => $question,
            'object' => $result['object'] ?? null,
            'count' => $result['count'] ?? 0,
        ]);
        api_ok($result);
    }

    if ($method === 'GET' && $action === 'organizations') {
        rbac_legacy_require($user, 'people.graph.view');
        api_ok(['organizations' => peopleGraphListOrganizations($tenantId, $_GET)]);
    }

    if ($method === 'POST' && $action === 'organizations') {
        rbac_legacy_require($user, 'people.graph.manage');
        api_ok(['organization' => peopleGraphCreateOrganization($tenantId, api_json_body(), $actorUserId ?: null)], 201);
    }

    if ($method === 'GET' && ($action === 'actor_links' || $action === 'actor-links')) {
        rbac_legacy_require($user, 'people.graph.view');
        api_ok(['actor_links' => peopleGraphListActorLinks($tenantId, $_GET)]);
    }

    if ($method === 'POST' && ($action === 'actor_links' || $action === 'actor-links')) {
        rbac_legacy_require($user, 'people.graph.manage');
        api_ok(['actor_link' => peopleGraphCreateActorLink($tenantId, api_json_body(), $actorUserId ?: null)], 201);
    }

    if ($method === 'GET' && $action === 'teams') {
        rbac_legacy_require($user, 'people.graph.view');
        api_ok(['teams' => peopleGraphListTeams($tenantId, $_GET)]);
    }

    if ($method === 'POST' && $action === 'teams') {
        rbac_legacy_require($user, 'people.graph.manage');
        api_ok(['team' => peopleGraphCreateTeam($tenantId, api_json_body(), $actorUserId ?: null)], 201);
    }

    if ($method === 'GET' && $action === 'roles') {
        rbac_legacy_require($user, 'people.graph.view');
        api_ok(['roles' => peopleGraphListRoles($tenantId, $_GET)]);
    }

    if ($method === 'POST' && $action === 'roles') {
        rbac_legacy_require($user, 'people.graph.manage');
        api_ok(['role' => peopleGraphCreateRole($tenantId, api_json_body(), $actorUserId ?: null)], 201);
    }

    if ($method === 'GET' && ($action === 'permission_grants' || $action === 'permission-grants')) {
        rbac_legacy_require($user, 'people.graph.view');
        api_ok(['permission_grants' => peopleGraphListPermissionGrants($tenantId, $_GET)]);
    }

    if ($method === 'POST' && ($action === 'permission_grants' || $action === 'permission-grants')) {
        rbac_legacy_require($user, 'people.graph.manage');
        api_ok(['permission_grant' => peopleGraphGrantPermission($tenantId, api_json_body(), $actorUserId ?: null)], 201);
    }

    if ($method === 'POST' && ($action === 'revoke_permission_grant' || $action === 'revoke-permission-grant')) {
        rbac_legacy_require($user, 'people.graph.manage');
        $id = (int) api_query('id', 0);
        if ($id <= 0) {
            $body = api_json_body();
            $id = (int) ($body['id'] ?? 0);
        }
        if ($id <= 0) api_error('id required', 422);
        api_ok(['permission_grant' => peopleGraphRevokePermissionGrant($tenantId, $id, $actorUserId ?: null)]);
    }

    if ($method === 'POST' && ($action === 'check_permission' || $action === 'permission_check' || $action === 'check-permission' || $action === 'permission-check')) {
        rbac_legacy_require($user, 'people.graph.view');
        api_ok(['decision' => peopleGraphCheckPermission($tenantId, api_json_body(), $actorUserId ?: null)]);
    }

    if ($method === 'GET' && ($action === 'approval_policies' || $action === 'approval-policies')) {
        rbac_legacy_require($user, 'people.graph.view');
        api_ok(['approval_policies' => peopleGraphListApprovalPolicies($tenantId, $_GET)]);
    }

    if ($method === 'POST' && ($action === 'approval_policies' || $action === 'approval-policies')) {
        rbac_legacy_require($user, 'people.graph.manage');
        api_ok(['approval_policy' => peopleGraphCreateApprovalPolicy($tenantId, api_json_body(), $actorUserId ?: null)], 201);
    }

    if ($method === 'GET' && ($action === 'approval_rules' || $action === 'approval-rules')) {
        rbac_legacy_require($user, 'people.graph.view');
        api_ok(['approval_rules' => peopleGraphListApprovalRules($tenantId, $_GET)]);
    }

    if ($method === 'POST' && ($action === 'approval_rules' || $action === 'approval-rules')) {
        rbac_legacy_require($user, 'people.graph.manage');
        api_ok(['approval_rule' => peopleGraphCreateApprovalRule($tenantId, api_json_body(), $actorUserId ?: null)], 201);
    }

    if ($method === 'POST' && ($action === 'resolve_approvers' || $action === 'resolve-approvers')) {
        rbac_legacy_require($user, 'people.graph.view');
        api_ok(['approval_resolution' => peopleGraphResolveApprovers($tenantId, api_json_body())]);
    }

    if ($method === 'GET' && $action === 'relationships') {
        rbac_legacy_require($user, 'people.graph.view');
        api_ok(['relationships' => peopleGraphListRelationships($tenantId, $_GET)]);
    }

    if ($method === 'POST' && $action === 'relationships') {
        rbac_legacy_require($user, 'people.graph.manage');
        api_ok(['relationship' => peopleGraphCreateRelationship($tenantId, api_json_body(), $actorUserId ?: null)], 201);
    }

    if ($method === 'GET' && ($action === 'responsibilities' || $action === 'assignments')) {
        rbac_legacy_require($user, 'people.graph.view');
        api_ok(['responsibilities' => peopleGraphListResponsibilities($tenantId, $_GET)]);
    }

    if ($method === 'POST' && ($action === 'responsibilities' || $action === 'assignments')) {
        rbac_legacy_require($user, 'people.graph.manage');
        api_ok(['responsibility' => peopleGraphAssignResponsibility($tenantId, api_json_body(), $actorUserId ?: null)], 201);
    }

    if ($method === 'GET' && $action === 'delegations') {
        rbac_legacy_require($user, 'people.graph.view');
        api_ok(['delegations' => peopleGraphListDelegations($tenantId, $_GET)]);
    }

    if ($method === 'POST' && $action === 'delegations') {
        rbac_legacy_require($user, 'people.graph.delegate');
        api_ok(['delegation' => peopleGraphCreateDelegation($tenantId, api_json_body(), $actorUserId ?: null)], 201);
    }

    if ($method === 'POST' && ($action === 'revoke_delegation' || $action === 'revoke-delegation')) {
        rbac_legacy_require($user, 'people.graph.delegate');
        $id = (int) api_query('id', 0);
        if ($id <= 0) {
            $body = api_json_body();
            $id = (int) ($body['id'] ?? 0);
        }
        if ($id <= 0) api_error('id required', 422);
        api_ok(['delegation' => peopleGraphRevokeDelegation($tenantId, $id, $actorUserId ?: null)]);
    }
} catch (PeopleGraphException $e) {
    api_error($e->getMessage(), 422);
}

api_error('Method not allowed', 405);
