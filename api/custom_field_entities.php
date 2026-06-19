<?php
/**
 * Platform Custom Field Entity Registry API.
 *
 * GET /api/custom_field_entities.php
 * GET /api/custom_field_entities.php?entity_type=people
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/custom_fields.php';

$ctx = api_require_auth();
$user = $ctx['user'];

if (api_method() !== 'GET') api_error('Method not allowed', 405);

$registry = customFieldEntityRegistry();
$entityType = (string) (api_query('entity_type') ?? '');
if ($entityType !== '') {
    if (!isset($registry[$entityType])) api_error('Custom field entity not found', 404);
    $entity = _presentCustomFieldEntity($registry[$entityType], $user);
    if (!$entity['can_view'] && !$entity['can_manage']) api_error('Forbidden', 403);
    api_ok(['entity' => $entity]);
}

$entities = [];
foreach ($registry as $entity) {
    $presented = _presentCustomFieldEntity($entity, $user);
    if ($presented['can_view'] || $presented['can_manage']) {
        $entities[] = $presented;
    }
}
api_ok(['entities' => $entities, 'count' => count($entities)]);

function _presentCustomFieldEntity(array $entity, array $user): array
{
    $viewPerm = (string) ($entity['view_permission'] ?? '');
    $managePerm = (string) ($entity['manage_permission'] ?? '');
    return $entity + [
        'can_view'   => $viewPerm !== '' && rbac_legacy_can($user, $viewPerm),
        'can_manage' => $managePerm !== '' && rbac_legacy_can($user, $managePerm),
    ];
}
