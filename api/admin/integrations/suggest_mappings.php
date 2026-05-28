<?php
/**
 * /api/admin/integrations/suggest_mappings.php
 *
 * Rule-based auto-mapping suggestions for the Field Mapping Studio.
 * Given an (integration, entity_type) for the current tenant, returns
 * a ranked list of proposed (source_path → target_table.target_column,
 * linked_entity, transform) pairs with confidence scores so the
 * operator can review-and-apply in one click.
 *
 *   POST /api/admin/integrations/suggest_mappings.php
 *     body: { integration: "jobdiva", entity_type: "person", limit?: 50 }
 *   → 200 {
 *       ok: true,
 *       suggestions: [
 *         { source_path, sample_value, value_type,
 *           target_module, target_table, target_column,
 *           linked_entity, transform, confidence, reason },
 *         …
 *       ]
 *     }
 *
 *   GET …?integration=…&entity_type=… also works for easy curl-testing.
 *
 * Returns ONLY proposals that don't collide with mappings the operator
 * has already saved for the same (source_path) — surfacing duplicates
 * would erode trust.
 *
 * RBAC: tenant_admin.integrations (same gate as field-map admin).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/integrations/mapping_suggester.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];
$method = api_method();
if ($method !== 'POST' && $method !== 'GET') api_error('Method not allowed', 405);
rbac_legacy_require($user, 'tenant_admin.integrations');

$body = $method === 'POST' ? (api_json_body() ?: []) : $_GET;
$integration = trim((string) ($body['integration'] ?? ''));
$entityType  = trim((string) ($body['entity_type'] ?? ''));
$limit       = (int) ($body['limit'] ?? 50);
if ($integration === '' || $entityType === '') {
    api_error('integration and entity_type are required', 400);
}

$suggestions = mappingSuggesterSuggest($tid, $integration, $entityType, $limit);
api_ok([
    'ok'          => true,
    'integration' => $integration,
    'entity_type' => $entityType,
    'count'       => count($suggestions),
    'suggestions' => $suggestions,
]);
