<?php
/**
 * Platform Custom Field Definition API.
 *
 * GET    /api/custom_field_definitions.php?entity_type=people
 * POST   /api/custom_field_definitions.php?entity_type=people
 * PATCH  /api/custom_field_definitions.php?entity_type=people&id=123
 * DELETE /api/custom_field_definitions.php?entity_type=people&id=123
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/custom_fields.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$userId = (int) ($user['id'] ?? 0);
$tenantId = (int) $ctx['tenant_id'];
$method = api_method();

$entityType = trim((string) (api_query('entity_type') ?? ''));
if ($entityType === '') api_error('entity_type required', 422);

$entity = customFieldEntity($entityType);
if (!$entity) api_error('Custom field entity not found', 404);

$viewPerm = (string) ($entity['view_permission'] ?? '');
$managePerm = (string) ($entity['manage_permission'] ?? '');
$piiPerm = (string) ($entity['pii_permission'] ?? '');
$piiManagePerm = (string) (($entity['pii_manage_permission'] ?? null) ?: $piiPerm);
$canView = $viewPerm !== '' && rbac_legacy_can($user, $viewPerm);
$canManage = $managePerm !== '' && rbac_legacy_can($user, $managePerm);
$canPii = $piiPerm !== '' && rbac_legacy_can($user, $piiPerm);
$canPiiManage = $piiManagePerm !== '' && rbac_legacy_can($user, $piiManagePerm);
if (!$canView && !$canManage) api_error('Forbidden', 403, ['required' => $viewPerm ?: $managePerm]);

if ($method === 'GET') {
    $definitions = [];
    foreach (customFieldFilterDefinitionsForUser(customFieldDefinitions($tenantId, $entityType), $user, $canManage) as $def) {
        if (!empty($def['pii']) && !$canPii) {
            $def['sensitive_hidden'] = true;
            unset($def['options_json'], $def['options']);
        }
        $definitions[] = $def;
    }
    api_ok([
        'entity_type' => $entityType,
        'definitions' => $definitions,
        'count' => count($definitions),
        'pii_included' => $canPii,
        'field_access_enforced' => true,
    ]);
}

if ($method === 'POST') {
    if (!$canManage) api_error('Forbidden', 403, ['required' => $managePerm]);
    $body = api_json_body();
    if (!empty($body['pii']) && !$canPiiManage) api_error('Forbidden', 403, ['required' => $piiManagePerm]);
    try {
        $id = customFieldDefinitionCreate($tenantId, $entityType, $body);
        customFieldAudit($tenantId, $userId, 'custom_field.definition.created', $id, [
            'entity_type' => $entityType,
            'field_key' => $body['field_key'] ?? null,
            'field_type' => $body['field_type'] ?? null,
            'pii' => !empty($body['pii']),
            'visible_to' => customFieldRoleListFromRaw($body['visible_to'] ?? $body['visible_to_roles'] ?? $body['visible_to_roles_json'] ?? null),
            'editable_by' => customFieldRoleListFromRaw($body['editable_by'] ?? $body['editable_by_roles'] ?? $body['editable_by_roles_json'] ?? null),
        ]);
        api_ok(['id' => $id], 201);
    } catch (InvalidArgumentException $e) {
        api_error($e->getMessage(), 422);
    }
}

if ($method === 'PATCH') {
    if (!$canManage) api_error('Forbidden', 403, ['required' => $managePerm]);
    $id = (int) api_query('id', 0);
    if ($id <= 0) api_error('id required', 422);
    $body = api_json_body();
    if (!empty($body['pii']) && !$canPiiManage) api_error('Forbidden', 403, ['required' => $piiManagePerm]);
    try {
        customFieldDefinitionUpdate($tenantId, $entityType, $id, $body);
        customFieldAudit($tenantId, $userId, 'custom_field.definition.updated', $id, [
            'entity_type' => $entityType,
            'fields' => array_keys($body),
            'pii' => array_key_exists('pii', $body) ? (bool) $body['pii'] : null,
            'visible_to' => customFieldPayloadHasAnyKey($body, ['visible_to', 'visible_to_roles', 'visible_to_roles_json'])
                ? customFieldRoleListFromRaw($body['visible_to'] ?? $body['visible_to_roles'] ?? $body['visible_to_roles_json'] ?? null)
                : null,
            'editable_by' => customFieldPayloadHasAnyKey($body, ['editable_by', 'editable_by_roles', 'editable_by_roles_json'])
                ? customFieldRoleListFromRaw($body['editable_by'] ?? $body['editable_by_roles'] ?? $body['editable_by_roles_json'] ?? null)
                : null,
        ]);
        api_ok(['id' => $id]);
    } catch (InvalidArgumentException $e) {
        api_error($e->getMessage(), 422);
    }
}

if ($method === 'DELETE') {
    if (!$canManage) api_error('Forbidden', 403, ['required' => $managePerm]);
    $id = (int) api_query('id', 0);
    if ($id <= 0) api_error('id required', 422);
    try {
        customFieldDefinitionDelete($tenantId, $entityType, $id);
        customFieldAudit($tenantId, $userId, 'custom_field.definition.deleted', $id, [
            'entity_type' => $entityType,
        ]);
        api_ok(['id' => $id]);
    } catch (InvalidArgumentException $e) {
        api_error($e->getMessage(), 422);
    }
}

api_error('Method not allowed', 405);
