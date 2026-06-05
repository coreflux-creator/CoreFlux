<?php
/**
 * LayerFi Business service — create or resolve ONE LayerFi Business per
 * CoreFlux tenant (spec §6).
 *
 * Flow:
 *   1. Look up tenant_layer_accounts (tenant + environment).
 *   2. If a mapping exists → return it (idempotent, no LayerFi call).
 *   3. Else get a platform token, create the LayerFi Business, persist the
 *      mapping, and write an integration audit row.
 */
declare(strict_types=1);

require_once __DIR__ . '/layer_client.php';
require_once __DIR__ . '/layer_audit.php';
require_once __DIR__ . '/../../db.php';

class LayerNotConfiguredException extends \RuntimeException {}

/** Expected external id: coreflux:<env>:tenant:<tenant_id>. */
function layer_external_id(int $tenantId): string
{
    return 'coreflux:' . layer_config()['environment'] . ':tenant:' . $tenantId;
}

function layer_get_mapping(int $tenantId): ?array
{
    $env = layer_config()['environment'];
    $st  = getDB()->prepare(
        'SELECT * FROM tenant_layer_accounts WHERE tenant_id = :t AND layer_environment = :e LIMIT 1'
    );
    $st->execute(['t' => $tenantId, 'e' => $env]);
    $row = $st->fetch(\PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * @param array $input { legalName, usState?, entityType?, phoneNumber?, activationAt?, createdByUserId? }
 * @return array { layerBusinessId, layerExternalId, status, created }
 */
function layer_create_or_get_business(int $tenantId, array $input): array
{
    $env = layer_config()['environment'];

    $existing = layer_get_mapping($tenantId);
    if ($existing) {
        layer_audit('layer.business.resolved_existing', 'success', [
            'tenant_id'   => $tenantId,
            'object_type' => 'business',
            'object_id'   => $existing['layer_business_id'],
            'metadata'    => ['source' => 'db', 'layerBusinessId' => $existing['layer_business_id']],
        ]);
        return [
            'layerBusinessId' => $existing['layer_business_id'],
            'layerExternalId' => $existing['layer_external_id'],
            'status'          => $existing['status'],
            'created'         => false,
        ];
    }

    try {
        $token   = layer_get_platform_token();
        $payload = [
            'external_id'   => layer_external_id($tenantId),
            'legal_name'    => (string) ($input['legalName'] ?? ('Tenant ' . $tenantId)),
            'us_state'      => (string) ($input['usState'] ?? 'NC'),
            'entity_type'   => (string) ($input['entityType'] ?? 'LLC'),
            'phone_number'  => $input['phoneNumber'] ?? null,
            'activation_at' => (string) ($input['activationAt'] ?? gmdate('c')),
        ];

        $resp       = layer_create_business($token['access_token'], $payload);
        $biz        = $resp['data'] ?? $resp;
        $businessId = (string) ($biz['id'] ?? $biz['business_id'] ?? '');
        if ($businessId === '') {
            throw new LayerApiException('LayerFi did not return a business id', 502, 'no_business_id');
        }
        $externalId = (string) ($biz['external_id'] ?? $payload['external_id']);

        getDB()->prepare(
            'INSERT INTO tenant_layer_accounts
                (tenant_id, layer_environment, layer_business_id, layer_external_id, legal_name, status, created_by, created_at, updated_at)
             VALUES (:t, :e, :bid, :ext, :ln, :st, :cb, NOW(), NOW())'
        )->execute([
            't'   => $tenantId,
            'e'   => $env,
            'bid' => $businessId,
            'ext' => $externalId,
            'ln'  => $payload['legal_name'],
            'st'  => 'active',
            'cb'  => $input['createdByUserId'] ?? null,
        ]);

        layer_audit('layer.business.created', 'success', [
            'tenant_id'   => $tenantId,
            'object_type' => 'business',
            'object_id'   => $businessId,
            'metadata'    => [
                'source'         => !empty($token['stub']) ? 'stub' : 'backend',
                'layerBusinessId' => $businessId,
                'externalId'     => $externalId,
            ],
        ]);

        return [
            'layerBusinessId' => $businessId,
            'layerExternalId' => $externalId,
            'status'          => 'active',
            'created'         => true,
        ];
    } catch (\Throwable $e) {
        layer_audit('layer.business.create_failed', 'error', [
            'tenant_id'     => $tenantId,
            'error_code'    => $e instanceof LayerApiException ? $e->errorCode : null,
            'error_message' => $e->getMessage(),
            'metadata'      => ['source' => 'backend'],
        ]);
        throw $e;
    }
}
