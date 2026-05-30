<?php
/**
 * Tenant Integration Field Map — admin CRUD (Slice 3 scaffolding).
 *
 *   GET    /api/admin/integrations/field_map.php
 *          [?integration=jobdiva][&entity_type=placement]
 *            → { rows: [...], allowed_internal_fields: {entity_type: [...]},
 *                transforms: [...] }
 *
 *   POST   /api/admin/integrations/field_map.php
 *          body: { integration, entity_type, external_field, internal_field,
 *                  transform?, enabled?, notes? }
 *            → { row: {...} }
 *
 *   DELETE /api/admin/integrations/field_map.php?id=42
 *            → { ok: true }
 *
 * RBAC: `integrations.field_map.manage` (master_admin + tenant_admin via
 * RBAC role bundles). Read uses the same permission so tenant operators
 * can't peek at the config; tightens info-disclosure surface.
 *
 * WIRING STATUS: scaffolding — the syncer doesn't consult this registry
 * yet. Slice 4 will wire it into jobdivaSyncUpsertPlacement /
 * jobdivaPlacementsAutoCreatePerson / etc.
 */
declare(strict_types=1);

// ---------------------------------------------------------------------
// LOCAL fatal-error trap — registered BEFORE require_once so it survives
// a fatal in any of the includes. set_exception_handler() in
// api_bootstrap.php only catches throwables; PHP fatals (E_ERROR /
// Call to undefined function / class not found / parse) bypass it and
// produce an empty 500 with Content-Type: text/html in production.
// Without this, the operator sees only "Request failed" with no detail.
//
// Belt-and-braces — api_bootstrap.php now has the same handler globally
// but a partial deploy may not have shipped that file yet, so we keep
// a copy here. Both register cleanly (PHP runs shutdown functions in
// FIFO order; the JSON envelope from the first one wins).
register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err === null) return;
    $fatalMask = E_ERROR | E_PARSE | E_CORE_ERROR | E_CORE_WARNING
               | E_COMPILE_ERROR | E_COMPILE_WARNING | E_USER_ERROR;
    if (($err['type'] & $fatalMask) === 0) return;
    if (headers_sent()) return;
    @http_response_code(500);
    @header('Content-Type: application/json; charset=utf-8');
    @header('Cache-Control: no-store');
    while (ob_get_level() > 0) { @ob_end_clean(); }
    error_log('[field_map.php/fatal] ' . $err['message'] . ' @ ' . ($err['file'] ?? '?') . ':' . ($err['line'] ?? '?'));
    echo json_encode([
        'error'  => 'Fatal PHP error: ' . $err['message'],
        'status' => 500,
        'kind'   => 'fatal',
        'file'   => isset($err['file']) ? basename((string) $err['file']) : null,
        'line'   => $err['line'] ?? null,
        'origin' => 'field_map.php inline shutdown handler',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
});

// Path note: this file lives at /api/admin/integrations/field_map.php so
// the core/ directory is THREE levels up, not two. The original file
// used `../../core/` which silently resolved to /api/core/ (nonexistent)
// and produced a fatal "Failed opening required" — masked in production
// by display_errors=Off until the inline shutdown handler above unmasked
// it. Sister endpoint field_map_suggest.php had the same bug; fixed in
// the same change.
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/integrations/field_map.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];
$method = api_method();

// `integrations.field_map.manage` is a new permission bundled into the
// master_admin and tenant_admin roles via the RBAC seed. Read access is
// gated the same way for now; broaden later if a "viewer" role wants
// read-only inspection.
rbac_legacy_require_any($user, ['integrations.field_map.manage', 'tenant_admin.integrations']);

// All handler bodies are wrapped so any uncaught throwable surfaces as a
// JSON error (default PHP 500s on this stack return an empty body in
// prod, which makes the UI useless when something genuinely breaks).
// We also special-case "table doesn't exist" → `migration_pending=true`
// so the UI can render the same self-heal banner the SyncHistoryDrawer
// uses (migration 068 may not have applied on this environment yet).
try {
    switch ($method) {
        case 'GET': {
            $integration = (string) (api_query('integration') ?? '');
            $entityType  = (string) (api_query('entity_type') ?? '');
            $rows = tenantIntegrationFieldMapList($tid, $integration ?: null, $entityType ?: null);
            // Expose the allow-list per entity_type so the UI can render
            // a constrained dropdown for `internal_field` (prevents
            // operators from typing arbitrary column names).
            $allow = [];
            foreach (['placement', 'person', 'company', 'contact',
                      'gl_account', 'journal_entry', 'bill', 'invoice', 'payment'] as $et) {
                $allow[$et] = tenantIntegrationFieldMapAllowedInternalFields($et);
            }
            api_ok([
                'rows'                     => $rows,
                'allowed_internal_fields'  => $allow,
                'transforms'               => TENANT_INTEGRATION_FIELD_MAP_TRANSFORMS,
            ]);
        }

        case 'POST': {
            $body = api_json_body();
            try {
                $row = tenantIntegrationFieldMapUpsert($tid, $body, $user['id'] ?? null);
            } catch (\InvalidArgumentException $e) {
                api_error($e->getMessage(), 422);
            }
            api_ok(['row' => $row]);
        }

        case 'DELETE': {
            $id = (int) (api_query('id') ?? 0);
            if ($id <= 0) api_error('id required', 400);
            $ok = tenantIntegrationFieldMapDelete($tid, $id, $user['id'] ?? null);
            api_ok(['ok' => $ok]);
        }
    }
} catch (\PDOException $e) {
    $msg = $e->getMessage();
    // Migration not applied — the registry table is missing on this env.
    // Mirror sync_history.php's contract: return migration_pending so the
    // UI can render its self-heal banner + "Run pending migrations"
    // button instead of an opaque HTTP 500.
    if (str_contains($msg, 'tenant_integration_field_map') && str_contains($msg, "doesn't exist")) {
        api_ok([
            'rows'                    => [],
            'allowed_internal_fields' => [
                'placement' => tenantIntegrationFieldMapAllowedInternalFields('placement'),
                'person'    => tenantIntegrationFieldMapAllowedInternalFields('person'),
                'company'   => tenantIntegrationFieldMapAllowedInternalFields('company'),
                'contact'   => tenantIntegrationFieldMapAllowedInternalFields('contact'),
                'gl_account'    => tenantIntegrationFieldMapAllowedInternalFields('gl_account'),
                'journal_entry' => tenantIntegrationFieldMapAllowedInternalFields('journal_entry'),
                'bill'          => tenantIntegrationFieldMapAllowedInternalFields('bill'),
                'invoice'       => tenantIntegrationFieldMapAllowedInternalFields('invoice'),
                'payment'       => tenantIntegrationFieldMapAllowedInternalFields('payment'),
            ],
            'transforms'        => TENANT_INTEGRATION_FIELD_MAP_TRANSFORMS,
            'migration_pending' => true,
            'migration_hint'    => 'Run /api/admin/migrate.php to create tenant_integration_field_map (migration 068).',
        ]);
    }
    error_log('[field_map.php] PDOException: ' . $msg);
    api_error('Database error: ' . $msg, 500, ['code' => $e->getCode()]);
} catch (\Throwable $e) {
    error_log('[field_map.php] ' . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    api_error('Server error: ' . $e->getMessage(), 500, ['class' => get_class($e)]);
}

api_error('Method not allowed', 405);
