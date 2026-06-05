<?php
/**
 * LayerFi business-token service (spec §6).
 *
 * Issues a SHORT-LIVED, business-scoped LayerFi token for the embedded UI.
 * Returns only businessId + businessAccessToken (+ expiry/type/env). The
 * platform OAuth token and client secret are NEVER returned. The business
 * token is NEVER persisted.
 */
declare(strict_types=1);

require_once __DIR__ . '/layer_client.php';
require_once __DIR__ . '/layer_business_service.php';
require_once __DIR__ . '/layer_audit.php';

/**
 * @return array { businessId, businessAccessToken, expiresIn, tokenType, environment }
 * @throws LayerNotConfiguredException when the tenant has no LayerFi mapping
 */
function layer_create_business_token(int $tenantId): array
{
    $cfg     = layer_config();
    $mapping = layer_get_mapping($tenantId);
    if (!$mapping) {
        throw new LayerNotConfiguredException('Tenant has no LayerFi business mapping');
    }
    $businessId = (string) $mapping['layer_business_id'];

    try {
        $token = layer_get_platform_token();
        $resp  = layer_create_business_auth_token($token['access_token'], $businessId, $cfg['tokenTtl']);
        $data  = $resp['data'] ?? $resp;

        $businessToken = (string) ($data['access_token'] ?? $data['business_access_token'] ?? '');
        if ($businessToken === '') {
            throw new LayerApiException('LayerFi did not return a business token', 502, 'no_business_token');
        }
        $expiresIn = (int) ($data['expires_in'] ?? $cfg['tokenTtl']);

        layer_audit('layer.business_token.created', 'success', [
            'tenant_id'   => $tenantId,
            'object_type' => 'business',
            'object_id'   => $businessId,
            'metadata'    => [
                'source'          => !empty($token['stub']) ? 'stub' : 'backend',
                'layerBusinessId' => $businessId,
            ],
        ]);

        return [
            'businessId'          => $businessId,
            'businessAccessToken' => $businessToken,
            'expiresIn'           => $expiresIn,
            'tokenType'           => 'Bearer',
            'environment'         => $cfg['environment'],
        ];
    } catch (\Throwable $e) {
        if ($e instanceof LayerNotConfiguredException) throw $e;
        layer_audit('layer.business_token.failed', 'error', [
            'tenant_id'     => $tenantId,
            'object_id'     => $businessId,
            'error_code'    => $e instanceof LayerApiException ? $e->errorCode : null,
            'error_message' => $e->getMessage(),
            'metadata'      => ['layerBusinessId' => $businessId],
        ]);
        throw $e;
    }
}
