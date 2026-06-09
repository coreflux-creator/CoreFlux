<?php
/**
 * Platform Custom Field Definition API.
 *
 * GET /api/custom_field_definitions.php?entity_type=people
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/custom_fields.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$tenantId = (int) $ctx['tenant_id'];

if (api_method() !== 'GET') api_error('Method not allowed', 405);

$entityType = trim((string) (api_query('entity_type') ?? ''));
if ($entityType === '') api_error('entity_type required', 422);

$entity = customFieldEntity($entityType);
if (!$entity) api_error('Custom field entity not found', 404);

$viewPerm = (string) ($entity['view_permission'] ?? '');
$managePerm = (string) ($entity['manage_permission'] ?? '');
$piiPerm = (string) ($entity['pii_permission'] ?? '');
$canView = $viewPerm !== '' && rbac_legacy_can($user, $viewPerm);
$canManage = $managePerm !== '' && rbac_legacy_can($user, $managePerm);
$canPii = $piiPerm !== '' && rbac_legacy_can($user, $piiPerm);
if (!$canView && !$canManage) api_error('Forbidden', 403, ['required' => $viewPerm ?: $managePerm]);

$definitions = [];
foreach (customFieldDefinitions($tenantId, $entityType) as $def) {
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
]);
