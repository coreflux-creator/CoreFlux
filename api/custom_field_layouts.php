<?php
/**
 * Platform Custom Field Layout API.
 *
 * GET /api/custom_field_layouts.php
 * GET /api/custom_field_layouts.php?entity_type=people
 * GET /api/custom_field_layouts.php?entity_type=people&surface=forms
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/custom_fields.php';

$ctx = api_require_auth();
$user = $ctx['user'];

if (api_method() !== 'GET') api_error('Method not allowed', 405);

$entityType = trim((string) (api_query('entity_type') ?? ''));
$surface = trim((string) (api_query('surface') ?? ''));

if ($entityType !== '') {
    $entity = customFieldEntity($entityType);
    if (!$entity) api_error('Custom field entity not found', 404);
    $presented = _presentCustomFieldLayoutEntity($entity, $user);
    if (!$presented['can_view'] && !$presented['can_manage']) api_error('Forbidden', 403);
    if ($surface !== '') {
        try {
            api_ok(['layout' => customFieldSurfaceLayout($entityType, $surface) + [
                'can_view' => $presented['can_view'],
                'can_manage' => $presented['can_manage'],
            ]]);
        } catch (InvalidArgumentException $e) {
            api_error($e->getMessage(), 422);
        }
    }
    api_ok(['entity' => $presented + [
        'surface_layouts' => customFieldAllSurfaceLayouts($entityType)[$entityType] ?? [],
    ]]);
}

$entities = [];
foreach (customFieldEntityRegistry() as $entity) {
    $presented = _presentCustomFieldLayoutEntity($entity, $user);
    if ($presented['can_view'] || $presented['can_manage']) {
        $key = (string) $presented['entity_type'];
        $entities[] = $presented + [
            'surface_layouts' => customFieldAllSurfaceLayouts($key)[$key] ?? [],
        ];
    }
}
api_ok(['entities' => $entities, 'count' => count($entities)]);

function _presentCustomFieldLayoutEntity(array $entity, array $user): array
{
    $viewPerm = (string) ($entity['view_permission'] ?? '');
    $managePerm = (string) ($entity['manage_permission'] ?? '');
    return [
        'entity_type' => (string) ($entity['entity_type'] ?? ''),
        'module_id' => (string) ($entity['module_id'] ?? ''),
        'label' => (string) ($entity['label'] ?? ''),
        'surfaces' => array_values($entity['surfaces'] ?? []),
        'layouts' => $entity['layouts'] ?? [],
        'can_view' => $viewPerm !== '' && rbac_legacy_can($user, $viewPerm),
        'can_manage' => $managePerm !== '' && rbac_legacy_can($user, $managePerm),
    ];
}
