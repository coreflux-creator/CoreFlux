<?php
/**
 * /api/admin/integrations/writable_targets.php — catalog endpoint
 * powering the Field Mapping UI's right-pane (target picker).
 *
 * GET /api/admin/integrations/writable_targets.php
 *   → { targets: [ {target_module, target_table, target_column,
 *                   value_type, description, default_linked_entity} ] }
 *
 * GET ...?module=placements
 *   → filtered by target_module.
 *
 * GET ...?module=placements&table=placement_rates
 *   → filtered by (target_module, target_table).
 *
 * Returns global rows only (tenant_id IS NULL) — per-tenant
 * overrides are a future extension (column reserved on the
 * `integration_writable_targets` table).
 *
 * RBAC: tenant_admin.integrations (same gate as field-map admin).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/integrations/field_map_apply.php';

$ctx = api_require_auth();
$user = $ctx['user'];
if (api_method() !== 'GET') api_error('Method not allowed', 405);
rbac_legacy_require($user, 'tenant_admin.integrations');

$module = isset($_GET['module']) ? trim((string) $_GET['module']) : '';
$table  = isset($_GET['table'])  ? trim((string) $_GET['table'])  : '';

api_ok([
    'targets' => integrationWritableTargetsList($module !== '' ? $module : null,
                                                $table  !== '' ? $table  : null),
]);
