<?php
/**
 * Platform Custom Field Value API.
 *
 * GET  /api/custom_field_values.php?entity_type=people&record_id=123
 * POST /api/custom_field_values.php?entity_type=people&record_id=123
 * PUT  /api/custom_field_values.php?entity_type=people&record_id=123
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
$recordId = (int) (api_query('record_id') ?? 0);
if ($entityType === '') api_error('entity_type required', 422);
if ($recordId <= 0) api_error('record_id required', 422);

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

if ($method === 'GET') {
    if (!$canView && !$canManage) api_error('Forbidden', 403, ['required' => $viewPerm ?: $managePerm]);
    $values = customFieldFilterValuesForUser(
        customFieldValues($tenantId, $entityType, $recordId, $canPii),
        $user
    );
    $piiKeys = customFieldPiiFieldKeys($values);
    if ($piiKeys && $canPii) {
        customFieldAudit($tenantId, $userId, 'custom_field.value.pii_viewed', $recordId, [
            'entity_type' => $entityType,
            'record_id' => $recordId,
            'field_keys' => $piiKeys,
        ]);
        if ($entityType === 'people') {
            customFieldPeoplePiiAudit($userId, $recordId, 'custom_field_pii.viewed', $piiKeys);
        }
    }
    api_ok([
        'entity_type' => $entityType,
        'record_id' => $recordId,
        'values' => $values,
        'pii_included' => $canPii,
        'pii_write_allowed' => $canPiiManage,
        'field_access_enforced' => true,
    ]);
}

if ($method === 'POST' || $method === 'PUT') {
    if (!$canManage) api_error('Forbidden', 403, ['required' => $managePerm]);
    $body = api_json_body();
    if (empty($body['values']) || !is_array($body['values'])) api_error('values map required', 422);
    $defs = customFieldDefinitionMap($tenantId, $entityType);
    $updated = [];
    foreach ($body['values'] as $fieldKey => $value) {
        $fieldKey = (string) $fieldKey;
        if ($fieldKey === '' || !isset($defs[$fieldKey])) continue;
        if (!customFieldUserCanEditDefinition($user, $defs[$fieldKey])) {
            api_error("Forbidden: missing field-level edit access for custom field '{$fieldKey}'", 403, [
                'field_key' => $fieldKey,
                'editable_by' => customFieldDefinitionRoleList($defs[$fieldKey], 'editable'),
            ]);
        }
        if (!empty($defs[$fieldKey]['pii']) && !$canPiiManage) {
            api_error("Forbidden: missing permission for PII custom field '{$fieldKey}'", 403, [
                'required' => $piiManagePerm,
                'field_key' => $fieldKey,
            ]);
        }
        customFieldValueUpsert($tenantId, $entityType, $recordId, $fieldKey, $value);
        $updated[] = $fieldKey;
    }
    if ($updated) {
        customFieldAudit($tenantId, $userId, 'custom_field.value.updated', $recordId, [
            'entity_type' => $entityType,
            'record_id' => $recordId,
            'fields' => $updated,
        ]);
        $piiUpdated = customFieldPiiKeysFromDefinitions($defs, $updated);
        if ($piiUpdated && $entityType === 'people') {
            customFieldPeoplePiiAudit($userId, $recordId, 'custom_field_pii.set', $piiUpdated);
        }
    }
    api_ok(['ok' => true, 'updated' => $updated]);
}

api_error('Method not allowed', 405);

function customFieldPiiFieldKeys(array $values): array
{
    $keys = [];
    foreach ($values as $value) {
        if (!empty($value['pii']) && !empty($value['field_key'])) {
            $keys[] = (string) $value['field_key'];
        }
    }
    return array_values(array_unique($keys));
}

function customFieldPiiKeysFromDefinitions(array $defs, array $fieldKeys): array
{
    $keys = [];
    foreach ($fieldKeys as $fieldKey) {
        if (!empty($defs[$fieldKey]['pii'])) $keys[] = (string) $fieldKey;
    }
    return array_values(array_unique($keys));
}

function customFieldPeoplePiiAudit(int $userId, int $recordId, string $eventType, array $fieldKeys): void
{
    require_once __DIR__ . '/../modules/people/lib/people.php';
    require_once __DIR__ . '/../modules/people/lib/audit.php';
    if (function_exists('peopleLogPIIAccess')) {
        peopleLogPIIAccess($userId, $recordId, $eventType, $fieldKeys);
    }
    if (function_exists('peopleAudit')) {
        $event = $eventType === 'custom_field_pii.viewed'
            ? 'people.pii.viewed'
            : 'people.custom_field.value_set';
        peopleAudit($event, [
            'person_id' => $recordId,
            'resource' => 'custom_fields',
            'field_keys' => $fieldKeys,
            'event_type' => $eventType,
        ], $recordId);
    }
}
