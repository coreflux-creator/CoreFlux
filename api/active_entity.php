<?php
/**
 * Active-entity API.
 *
 *   GET  /api/active_entity         → { active_entity_id, entities[] }
 *   POST /api/active_entity         { entity_id }  → { active_entity_id, entity }
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/active_entity.php';

$ctx       = api_require_auth();
$tenantId  = (int) $ctx['tenant_id'];
$method    = api_method();

if ($method === 'GET') {
    api_ok([
        'active_entity_id' => activeEntityGet($tenantId),
        'entities'         => activeEntityAvailable($tenantId),
    ]);
}

if ($method === 'POST') {
    $body = api_json_body();
    api_require_fields($body, ['entity_id']);
    $row = activeEntitySet($tenantId, (int) $body['entity_id']);
    api_ok([
        'active_entity_id' => (int) $row['id'],
        'entity'           => $row,
    ]);
}

api_error('Method not allowed', 405);
