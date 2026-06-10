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
$canView = $viewPerm !== '' && rbac_legacy_can($user, $viewPerm);
$canManage = $managePerm !== '' && rbac_legacy_can($user, $managePerm);
$canPii = $piiPerm !== '' && rbac_legacy_can($user, $piiPerm);

if ($method === 'GET') {
    if (!$canView && !$canManage) api_error('Forbidden', 403, ['required' => $viewPerm ?: $managePerm]);
    api_ok([
        'entity_type' => $entityType,
        'record_id' => $recordId,
        'values' => customFieldValues($tenantId, $entityType, $recordId, $canPii),
        'pii_included' => $canPii,
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
        if (!empty($defs[$fieldKey]['pii']) && !$canPii) {
            api_error("Forbidden: missing permission for PII custom field '{$fieldKey}'", 403, [
                'required' => $piiPerm,
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
    }
    api_ok(['ok' => true, 'updated' => $updated]);
}

api_error('Method not allowed', 405);
