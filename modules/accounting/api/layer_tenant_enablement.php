<?php
/**
 * POST /api/accounting/layer-tenant-enablement
 *
 * DB-backed per-tenant LayerFi toggle (admin switch). Internal admin only.
 * Lets ops enable/disable LayerFi for a tenant with NO env edit or restart.
 * Ignored (409) when an env allowlist (LAYER_TENANT_ALLOWLIST) is locking access.
 *
 * Body: { "enabled": true|false }
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/integrations/layer/layer_access.php';
require_once __DIR__ . '/../../../core/integrations/layer/layer_audit.php';

if (!layer_enabled()) api_error('Not found', 404);

$ctx  = api_require_auth();
$user = $ctx['user'];
if (api_method() !== 'POST') api_error('Method not allowed', 405);

rbac_legacy_require($user, 'coreflux.internal_sandbox');

$tenantId = (int) ($ctx['tenant_id'] ?? 0);
if ($tenantId <= 0) api_error('No tenant context', 400);

if (layer_env_locked()) {
    api_error('LayerFi access is locked by the LAYER_TENANT_ALLOWLIST env override', 409, [
        'provider'  => 'layer',
        'envLocked' => true,
    ]);
}

$body    = api_json_body();
$enabled = !empty($body['enabled']);

layer_set_tenant_enabled($tenantId, $enabled, $user['id'] ?? null);
layer_audit('layer.tenant_enablement.changed', 'success', [
    'tenant_id' => $tenantId,
    'metadata'  => ['enabled' => $enabled],
]);

$gov = layer_tenant_governance($tenantId);
api_ok([
    'provider'   => 'layer',
    'tenantId'   => $tenantId,
    'enabled'    => $enabled,
    'allowed'    => $gov['effective'],
    'governance' => $gov,
]);
