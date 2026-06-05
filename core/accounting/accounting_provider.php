<?php
/**
 * Provider-neutral accounting abstraction (spec §10).
 *
 * Keeps the rest of CoreFlux decoupled from LayerFi so QuickBooks / Xero /
 * Digits / Puzzle / Zoho Books / native-ledger adapters can be added later
 * without a rewrite. The LayerFi sandbox is just the first implementation.
 */
declare(strict_types=1);

require_once __DIR__ . '/../integrations/layer/layer_business_service.php';
require_once __DIR__ . '/../integrations/layer/layer_token_service.php';

interface AccountingProviderAdapter
{
    public function provider(): string;

    /** @return array { tenantId, provider, environment, externalBusinessId, status } */
    public function createTenantAccountingInstance(int $tenantId, string $legalName): array;

    /** @return array { provider, businessId, accessToken, expiresIn } */
    public function getEmbeddedSession(int $tenantId): array;
}

final class LayerAccountingProviderAdapter implements AccountingProviderAdapter
{
    public function provider(): string
    {
        return 'layer';
    }

    public function createTenantAccountingInstance(int $tenantId, string $legalName): array
    {
        $r   = layer_create_or_get_business($tenantId, ['legalName' => $legalName]);
        $cfg = layer_config();
        return [
            'tenantId'           => $tenantId,
            'provider'           => 'layer',
            'environment'        => $cfg['environment'],
            'externalBusinessId' => $r['layerBusinessId'],
            'status'             => $r['status'],
        ];
    }

    public function getEmbeddedSession(int $tenantId): array
    {
        $r = layer_create_business_token($tenantId);
        return [
            'provider'    => 'layer',
            'businessId'  => $r['businessId'],
            'accessToken' => $r['businessAccessToken'],
            'expiresIn'   => $r['expiresIn'],
        ];
    }
}

/** Resolve the active accounting provider adapter. */
function accounting_provider_adapter(string $provider = 'layer'): AccountingProviderAdapter
{
    switch ($provider) {
        case 'layer':
        default:
            return new LayerAccountingProviderAdapter();
    }
}
