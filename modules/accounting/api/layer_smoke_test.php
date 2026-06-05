<?php
/**
 * GET /api/accounting/layer-smoke-test
 *
 * Verify LayerFi sandbox credentials + backend connectivity. Internal admin
 * only (coreflux.internal_sandbox). Returns a masked connectivity result.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/integrations/layer/layer_client.php';
require_once __DIR__ . '/../../../core/integrations/layer/layer_audit.php';

if (!layer_enabled()) api_error('Not found', 404);

$ctx  = api_require_auth();
$user = $ctx['user'];
if (api_method() !== 'GET') api_error('Method not allowed', 405);

rbac_legacy_require($user, 'coreflux.internal_sandbox');

$cfg = layer_config();
try {
    $token = layer_get_platform_token();
    $who   = layer_whoami($token['access_token']);
    layer_audit('layer.smoke_test.succeeded', 'success', [
        'tenant_id' => $ctx['tenant_id'],
        'metadata'  => ['stub' => !empty($token['stub'])],
    ]);
    api_ok([
        'provider'    => 'layer',
        'environment' => $cfg['environment'],
        'ok'          => true,
        'stub'        => !empty($token['stub']),
    ]);
} catch (\Throwable $e) {
    layer_audit('layer.smoke_test.failed', 'error', [
        'tenant_id'     => $ctx['tenant_id'],
        'error_code'    => $e instanceof LayerApiException ? $e->errorCode : null,
        'error_message' => $e->getMessage(),
    ]);
    api_error('LayerFi smoke test failed: ' . $e->getMessage(), 502, [
        'provider' => 'layer',
        'ok'       => false,
    ]);
}
