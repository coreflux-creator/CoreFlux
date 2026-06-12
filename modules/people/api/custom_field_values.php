<?php
/**
 * People API - custom field values compatibility adapter.
 *
 * Preserves the legacy `person_id` and `pii_redacted` response contract while
 * delegating reads/writes to core/custom_fields.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/custom_fields.php';
require_once __DIR__ . '/../lib/people.php';
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
$managePerm = 'people.manage';
$piiPerm = (string) ($entity['pii_permission'] ?? 'people.pii.view');
$piiManagePerm = (string) (($entity['pii_manage_permission'] ?? null) ?: 'people.pii.manage');
$canView = rbac_legacy_can($user, $viewPerm);
$canManage = rbac_legacy_can($user, $managePerm);
$canPii = $piiPerm !== '' && rbac_legacy_can($user, $piiPerm);
$canPiiManage = $piiManagePerm !== '' && rbac_legacy_can($user, $piiManagePerm);

if ($method === 'GET') {
    if (!$canView && !$canManage) api_error('Forbidden', 403, ['required' => $viewPerm]);
    $personId = (int) api_query('person_id', (int) api_query('record_id', 0));
    if ($personId <= 0) api_error('person_id required', 400);
    $values = customFieldValues($tenantId, $entityType, $personId, $canPii);
    $piiKeys = peopleCustomFieldPiiKeys($values);
    if ($piiKeys && $canPii) {
        peopleLogPIIAccess($userId, $personId, 'custom_field_pii.viewed', $piiKeys);
        peopleAudit('people.pii.viewed', [
            'person_id' => $personId,
            'resource' => 'custom_fields',
            'field_keys' => $piiKeys,
        ], $personId);
        customFieldAudit($tenantId, $userId, 'custom_field.value.pii_viewed', $personId, [
            'entity_type' => $entityType,
            'record_id' => $personId,
            'field_keys' => $piiKeys,
            'legacy_endpoint' => 'people/custom_field_values.php',
        ]);
    }
    api_ok([
        'values' => peopleCustomFieldValuesForLegacy($values),
        'pii_redacted' => peopleCustomFieldHasPiiDefinitions($tenantId) && !$canPii,
        'pii_included' => $canPii,
    ]);
}

if ($method === 'PUT' || $method === 'POST') {
    if (!$canManage) api_error('Forbidden', 403, ['required' => $managePerm]);
    $personId = (int) api_query('person_id', (int) api_query('record_id', 0));
    if ($personId <= 0) api_error('person_id required', 400);
    $body = api_json_body();
    if (empty($body['values']) || !is_array($body['values'])) api_error('values map required', 422);

    $defs = customFieldDefinitionMap($tenantId, $entityType);
    $updated = [];
    foreach ($body['values'] as $fieldKey => $value) {
        $fieldKey = (string) $fieldKey;
        if ($fieldKey === '' || !isset($defs[$fieldKey])) continue;
        if (!empty($defs[$fieldKey]['pii']) && !$canPiiManage) {
            api_error("Forbidden: missing permission for PII custom field '{$fieldKey}'", 403, [
                'required' => $piiManagePerm,
                'field_key' => $fieldKey,
            ]);
        }
        customFieldValueUpsert($tenantId, $entityType, $personId, $fieldKey, $value);
        $updated[] = $fieldKey;
    }

    $piiUpdated = peopleCustomFieldPiiKeysFromDefinitions($defs, $updated);
    if ($piiUpdated) {
        peopleLogPIIAccess($userId, $personId, 'custom_field_pii.set', $piiUpdated);
    }
    customFieldAudit($tenantId, $userId, 'custom_field.value.updated', $personId, [
        'entity_type' => $entityType,
        'record_id' => $personId,
        'fields' => $updated,
        'legacy_endpoint' => 'people/custom_field_values.php',
    ]);
    peopleAudit('people.custom_field.value_set', ['person_id' => $personId, 'fields' => $updated], $personId);
    api_ok(['ok' => true, 'updated' => $updated]);
}

api_error('Method not allowed', 405);

function peopleCustomFieldValuesForLegacy(array $values): array
{
    return array_map(static function (array $row): array {
        $row['field_pii'] = $row['pii'] ?? 0;
        return $row;
    }, $values);
}

function peopleCustomFieldPiiKeys(array $values): array
{
    $keys = [];
    foreach ($values as $value) {
        if (!empty($value['pii']) && !empty($value['field_key'])) $keys[] = (string) $value['field_key'];
    }
    return array_values(array_unique($keys));
}

function peopleCustomFieldPiiKeysFromDefinitions(array $defs, array $fieldKeys): array
{
    $keys = [];
    foreach ($fieldKeys as $fieldKey) {
        if (!empty($defs[$fieldKey]['pii'])) $keys[] = (string) $fieldKey;
    }
    return array_values(array_unique($keys));
}

function peopleCustomFieldHasPiiDefinitions(int $tenantId): bool
{
    foreach (customFieldDefinitions($tenantId, 'people') as $def) {
        if (!empty($def['pii'])) return true;
    }
    return false;
}
