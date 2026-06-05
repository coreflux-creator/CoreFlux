<?php
/**
 * POST /api/accounting/layer-business-token
 *
 * Return a temporary, business-scoped LayerFi token for the CURRENT tenant
 * so the frontend can mount the embedded accounting UI. Requires
 * accounting.view. Never returns the platform token or client secret; the
 * business token is never persisted.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/integrations/layer/layer_token_service.php';

if (!layer_enabled()) api_error('Not found', 404);

$ctx  = api_require_auth();
$user = $ctx['user'];
if (api_method() !== 'POST') api_error('Method not allowed', 405);

rbac_legacy_require($user, 'accounting.view');

$tenantId = (int) ($ctx['tenant_id'] ?? 0);
if ($tenantId <= 0) api_error('No tenant context', 400);

try {
    $r = layer_create_business_token($tenantId);
    api_ok($r); // businessId, businessAccessToken, expiresIn, tokenType, environment
} catch (LayerNotConfiguredException $e) {
    api_error('LayerFi is not configured for this tenant', 404, [
        'provider'   => 'layer',
        'configured' => false,
    ]);
} catch (\Throwable $e) {
    $status = $e instanceof LayerApiException ? $e->httpStatus : 502;
    api_error('LayerFi token issuance failed: ' . $e->getMessage(), $status, ['provider' => 'layer']);
}
