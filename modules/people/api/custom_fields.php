<?php
/**
 * People API - custom field definitions compatibility adapter.
 *
 * The platform service owns custom-field validation, persistence, RBAC metadata,
 * and audit semantics. This endpoint preserves the legacy People response shape
 * for old callers while routing all work through core/custom_fields.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/custom_fields.php';
require_once __DIR__ . '/../lib/audit.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$tenantId = (int) $ctx['tenant_id'];
$userId = (int) ($user['id'] ?? 0);
$method = api_method();
$entityType = 'people';

$entity = customFieldEntity($entityType);
if (!$entity) api_error('People custom-field entity not registered', 500);

$viewPerm = (string) ($entity['view_permission'] ?? 'people.view');
$managePerm = (string) ($entity['manage_permission'] ?? 'people.custom_fields.manage');
$piiPerm = (string) ($entity['pii_permission'] ?? 'people.pii.view');
$piiManagePerm = (string) (($entity['pii_manage_permission'] ?? null) ?: $piiPerm);
$canView = rbac_legacy_can($user, $viewPerm);
$canManage = rbac_legacy_can($user, $managePerm);
$canPii = $piiPerm !== '' && rbac_legacy_can($user, $piiPerm);
$canPiiManage = $piiManagePerm !== '' && rbac_legacy_can($user, $piiManagePerm);

if ($method === 'GET') {
    if (!$canView && !$canManage) api_error('Forbidden', 403, ['required' => $viewPerm ?: $managePerm]);
    api_ok([
        'fields' => peopleCustomFieldDefinitionsForLegacy($tenantId, $canPii, $user, $canManage),
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
            'legacy_endpoint' => 'people/custom_fields.php',
        ]);
        peopleAudit('people.custom_field.defined', ['id' => $id, 'field_key' => $body['field_key'] ?? null], $id);
        api_ok(['id' => $id], 201);
    } catch (InvalidArgumentException $e) {
        api_error($e->getMessage(), 422);
    }
}

if ($method === 'PATCH') {
    if (!$canManage) api_error('Forbidden', 403, ['required' => $managePerm]);
    $id = (int) api_query('id', 0);
    if ($id <= 0) api_error('id required', 400);
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
            'legacy_endpoint' => 'people/custom_fields.php',
        ]);
        api_ok(['ok' => true]);
    } catch (InvalidArgumentException $e) {
        api_error($e->getMessage(), 422);
    }
}

if ($method === 'DELETE') {
    if (!$canManage) api_error('Forbidden', 403, ['required' => $managePerm]);
    $id = (int) api_query('id', 0);
    if ($id <= 0) api_error('id required', 400);
    try {
        customFieldDefinitionDelete($tenantId, $entityType, $id);
        customFieldAudit($tenantId, $userId, 'custom_field.definition.deleted', $id, [
            'entity_type' => $entityType,
            'legacy_endpoint' => 'people/custom_fields.php',
        ]);
        api_ok(['ok' => true]);
    } catch (InvalidArgumentException $e) {
        api_error($e->getMessage(), 422);
    }
}

api_error('Method not allowed', 405);

function peopleCustomFieldDefinitionsForLegacy(int $tenantId, bool $includePii, array $user, bool $includeRestricted = false): array
{
    $rows = [];
    foreach (customFieldFilterDefinitionsForUser(customFieldDefinitions($tenantId, 'people'), $user, $includeRestricted) as $def) {
        if (!empty($def['pii']) && !$includePii) {
            $def['sensitive_hidden'] = true;
            unset($def['options_json'], $def['options']);
        }
        $rows[] = $def;
    }
    return $rows;
}
