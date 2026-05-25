<?php
/**
 * Tenant Integration Field Map — bulk import/export.
 *
 *   GET  /api/admin/integrations/field_map_bulk.php[?integration=jobdiva]
 *          → { version: 1, exported_at, integration, tenant_id, mappings: [...] }
 *          Operators paste/download this and replay it elsewhere.
 *
 *   POST /api/admin/integrations/field_map_bulk.php
 *          body: { mode: 'merge'|'replace', mappings: [...] }
 *          → { mode, imported, skipped, replaced_integrations, integrations_affected, errors }
 *
 * RBAC: integrations.field_map.manage (same as single-row CRUD).
 *
 * Idempotent: replay the same export twice in merge mode → second pass
 * reports `imported=N, skipped=0`. In replace mode it wipes then
 * re-inserts, so row IDs may change.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/integrations/field_map.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];
$method = api_method();

rbac_legacy_require($user, 'integrations.field_map.manage');

try {
    switch ($method) {
        case 'GET': {
            $integration = (string) (api_query('integration') ?? '');
            $snapshot = tenantIntegrationFieldMapBulkExport(
                $tid,
                $integration !== '' ? $integration : null
            );
            // Hint the browser to treat this as a downloadable artefact.
            // The UI also has a "Copy JSON" path; this header just makes
            // direct GET-from-curl produce a sensible filename.
            $fname = sprintf(
                'field_map_%s_t%d_%s.json',
                $integration !== '' ? preg_replace('/[^a-z0-9_-]/i', '_', $integration) : 'all',
                $tid,
                gmdate('Ymd_His')
            );
            header('Content-Disposition: inline; filename="' . $fname . '"');
            api_ok($snapshot);
        }

        case 'POST': {
            $body = api_json_body();
            $mode = strtolower(trim((string) ($body['mode'] ?? 'merge')));
            try {
                $result = tenantIntegrationFieldMapBulkImport(
                    $tid,
                    $body,
                    $mode,
                    $user['id'] ?? null
                );
            } catch (\InvalidArgumentException $e) {
                api_error($e->getMessage(), 422);
            }
            api_ok($result);
        }
    }
} catch (\PDOException $e) {
    $msg = $e->getMessage();
    if (str_contains($msg, 'tenant_integration_field_map') && str_contains($msg, "doesn't exist")) {
        api_error(
            'tenant_integration_field_map table missing — run /api/admin/migrate.php (migration 068).',
            500, ['migration_pending' => true]
        );
    }
    error_log('[field_map_bulk.php] PDOException: ' . $msg);
    api_error('Database error: ' . $msg, 500, ['code' => $e->getCode()]);
} catch (\Throwable $e) {
    error_log('[field_map_bulk.php] ' . get_class($e) . ': ' . $e->getMessage());
    api_error('Server error: ' . $e->getMessage(), 500, ['class' => get_class($e)]);
}

api_error('Method not allowed', 405);
