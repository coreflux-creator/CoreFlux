<?php
/**
 * Tenant Integration Field Map — dry-run test against a sample payload.
 *
 *   POST /api/admin/integrations/field_map_test.php
 *     body: { integration, entity_type, payload: { ...arbitrary JSON... } }
 *       → { integration, entity_type, resolved: [...], unmapped_internal_fields: [...] }
 *
 * Operator pastes a JobDiva (or other source-side) record into the test
 * widget, and the UI shows what the configured field-map rules would
 * resolve to — WITHOUT writing anything to the database.
 *
 * RBAC: integrations.field_map.manage (same as single-row CRUD).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/integrations/field_map.php';
require_once __DIR__ . '/../../../core/integrations/field_map_apply.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];

rbac_legacy_require_any($user, ['integrations.field_map.manage', 'tenant_admin.integrations']);

if (api_method() !== 'POST') {
    api_error('Method not allowed', 405);
}

try {
    $body        = api_json_body();
    $integration = trim((string) ($body['integration'] ?? ''));
    $entityType  = trim((string) ($body['entity_type'] ?? ''));
    $payload     = $body['payload'] ?? null;

    if ($integration === '')   api_error('integration required', 422);
    if ($entityType === '')    api_error('entity_type required',  422);

    // Operators frequently paste raw JSON into a text area; accept both
    // a string (we'll json_decode) and an already-decoded array.
    if (is_string($payload)) {
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            api_error('payload string must be valid JSON object/array', 422);
        }
        $payload = $decoded;
    }
    if (!is_array($payload)) {
        api_error('payload must be a JSON object', 422);
    }

    // Reset resolver cache so the test endpoint reflects whatever the
    // operator just upserted via the field_map.php CRUD endpoint in the
    // SAME tab (PHP-FPM workers may otherwise serve stale rules).
    tenantIntegrationFieldMapFlushCache();

    $result = tenantIntegrationFieldMapTestPayload($tid, $integration, $entityType, $payload);
    // Phase 3 — also attach the generalised-shape evaluation so the
    // Studio UI can render the full target identity per row. The two
    // shapes coexist during cutover; the Studio reads from
    // result.generalised, the legacy admin reads from result.resolved.
    $result['generalised'] = integrationFieldMapTestPayloadGeneralised(
        $tid, $integration, $entityType, $payload
    );
    api_ok($result);
} catch (\PDOException $e) {
    $msg = $e->getMessage();
    if (str_contains($msg, 'tenant_integration_field_map') && str_contains($msg, "doesn't exist")) {
        api_error(
            'tenant_integration_field_map table missing — run /api/admin/migrate.php (migration 068).',
            500, ['migration_pending' => true]
        );
    }
    error_log('[field_map_test.php] PDOException: ' . $msg);
    api_error('Database error: ' . $msg, 500, ['code' => $e->getCode()]);
} catch (\Throwable $e) {
    error_log('[field_map_test.php] ' . get_class($e) . ': ' . $e->getMessage());
    api_error('Server error: ' . $e->getMessage(), 500, ['class' => get_class($e)]);
}
