<?php
/**
 * POST /api/accounting/layer-setup-tenant
 *
 * Create or resolve the LayerFi Business for the CURRENT CoreFlux tenant
 * (resolved server-side — never trusts a browser-supplied tenant_id).
 * Internal admin OR a tenant admin with accounting integration rights.
 *
 * Idempotent: calling again returns the existing mapping.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/integrations/layer/layer_business_service.php';
require_once __DIR__ . '/../../../core/integrations/layer/layer_access.php';

if (!layer_enabled()) api_error('Not found', 404);

$ctx  = api_require_auth();
$user = $ctx['user'];
if (api_method() !== 'POST') api_error('Method not allowed', 405);

rbac_legacy_require_any($user, [
    'coreflux.internal_sandbox',
    'accounting.manage_integrations',
    'accounting.integrations.connect',
]);

$tenantId = (int) ($ctx['tenant_id'] ?? 0);
if ($tenantId <= 0) api_error('No tenant context', 400);

if (!layer_tenant_allowed($tenantId)) {
    api_error('LayerFi is not enabled for this tenant', 403, ['provider' => 'layer', 'allowed' => false]);
}

$body      = api_json_body();
$legalName = trim((string) ($body['legalName'] ?? ''));
if ($legalName === '') $legalName = 'Tenant ' . $tenantId . ' LLC';

try {
    $r = layer_create_or_get_business($tenantId, [
        'legalName'       => $legalName,
        'usState'         => $body['usState']     ?? 'NC',
        'entityType'      => $body['entityType']  ?? 'LLC',
        'phoneNumber'     => $body['phoneNumber'] ?? null,
        'createdByUserId' => $user['id'] ?? null,
    ]);
    api_ok([
        'provider'        => 'layer',
        'environment'     => layer_config()['environment'],
        'tenantId'        => $tenantId,
        'layerBusinessId' => $r['layerBusinessId'],
        'layerExternalId' => $r['layerExternalId'],
        'status'          => $r['status'],
        'created'         => $r['created'],
    ]);
} catch (\Throwable $e) {
    $status = $e instanceof LayerApiException ? max(500, $e->httpStatus) : 500;
    api_error('LayerFi setup failed: ' . $e->getMessage(), $status, ['provider' => 'layer']);
}
