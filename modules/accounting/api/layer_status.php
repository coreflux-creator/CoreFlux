<?php
/**
 * GET /api/accounting/layer-status
 *
 * Whether the current tenant has LayerFi sandbox configured. Requires
 * accounting.view. The Layer business id is only surfaced to internal /
 * integration admins.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/integrations/layer/layer_business_service.php';
require_once __DIR__ . '/../../../core/integrations/layer/layer_access.php';

if (!layer_enabled()) api_error('Not found', 404);

$ctx  = api_require_auth();
$user = $ctx['user'];
if (api_method() !== 'GET') api_error('Method not allowed', 405);

rbac_legacy_require($user, 'accounting.view');

$cfg      = layer_config();
$tenantId = (int) ($ctx['tenant_id'] ?? 0);
$mapping  = $tenantId > 0 ? layer_get_mapping($tenantId) : null;

layer_audit('layer.status_viewed', 'success', [
    'tenant_id' => $tenantId,
    'metadata'  => ['configured' => (bool) $mapping],
]);

$allowed = layer_tenant_allowed($tenantId);
$gov     = layer_tenant_governance($tenantId);
$canToggle = rbac_legacy_can($user, 'coreflux.internal_sandbox') && !$gov['envLocked'];

$out = [
    'provider'      => 'layer',
    'environment'   => $cfg['environment'],
    'enabled'       => true,
    'allowed'       => $allowed,
    'allowlistMode' => layer_allowlist_mode(),
    'governance'    => $gov,
    'canToggle'     => $canToggle,
    'configured'    => $allowed && (bool) $mapping,
    'tenantId'      => $tenantId,
    'stub'          => layer_is_stub(),
];

if ($allowed && $mapping) {
    $canSeeBusiness = rbac_legacy_can($user, 'coreflux.internal_sandbox')
        || rbac_legacy_can($user, 'accounting.manage_integrations');
    $out['status']          = $mapping['status'];
    $out['legalName']       = $mapping['legal_name'];
    $out['layerExternalId'] = $mapping['layer_external_id'];
    if ($canSeeBusiness) {
        $out['layerBusinessId'] = $mapping['layer_business_id'];
    }
}

api_ok($out);
