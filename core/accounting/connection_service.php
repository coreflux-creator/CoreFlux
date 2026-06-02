<?php
/**
 * core/accounting/connection_service.php — connect / validate /
 * rotate / disconnect for the accounting backend per spec §15.1.
 *
 * Provider-neutral. Stores credentials AES-256-GCM encrypted via
 * encryptField(); never returns the plaintext over the API — only
 * last4 + status + scope summary.
 */
declare(strict_types=1);

require_once __DIR__ . '/provider_adapter.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../encryption.php';

function accountingConnectionGet(int $tenantId, int $subTenantId, string $provider): ?array
{
    $stmt = getDB()->prepare(
        'SELECT id, tenant_id, sub_tenant_id, provider, provider_org_id,
                credential_last4, connection_status, base_currency,
                sync_config,
                api_scope_summary, last_validated_at, last_validation_error,
                created_by_user_id, created_at, updated_at
           FROM accounting_provider_connections
          WHERE tenant_id = :t AND sub_tenant_id = :st AND provider = :p
          LIMIT 1'
    );
    $stmt->execute(['t' => $tenantId, 'st' => $subTenantId, 'p' => $provider]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row) return null;
    $row['id']            = (int) $row['id'];
    $row['tenant_id']     = (int) $row['tenant_id'];
    $row['sub_tenant_id'] = (int) $row['sub_tenant_id'];
    if (!empty($row['api_scope_summary'])) {
        $d = json_decode((string) $row['api_scope_summary'], true);
        $row['api_scope_summary'] = is_array($d) ? $d : null;
    }
    if (!empty($row['sync_config'])) {
        $d = json_decode((string) $row['sync_config'], true);
        $row['sync_config'] = is_array($d) ? $d : null;
    } else {
        $row['sync_config'] = null;
    }
    return $row;
}

function accountingConnectionUpsert(int $tenantId, int $subTenantId, string $provider, array $data, ?int $userId = null): array
{
    $apiKey = trim((string) ($data['api_key'] ?? ''));
    if ($apiKey === '') throw new \InvalidArgumentException('api_key required');
    if (strlen($apiKey) < 16) throw new \InvalidArgumentException('api_key must be at least 16 characters');

    $orgId       = trim((string) ($data['provider_org_id'] ?? ''));
    $baseCurrency = strtoupper(trim((string) ($data['base_currency'] ?? 'USD')));
    $ct           = encryptField($apiKey);
    $last4        = substr($apiKey, -4);

    getDB()->prepare(
        'INSERT INTO accounting_provider_connections
            (tenant_id, sub_tenant_id, provider, provider_org_id,
             credential_secret_ct, credential_last4,
             connection_status, base_currency, created_by_user_id)
         VALUES (:t, :st, :p, :org, :ct, :l4, "pending", :bc, :uid)
         ON DUPLICATE KEY UPDATE
            provider_org_id      = VALUES(provider_org_id),
            credential_secret_ct = VALUES(credential_secret_ct),
            credential_last4     = VALUES(credential_last4),
            connection_status    = "pending",
            base_currency        = VALUES(base_currency),
            last_validation_error = NULL'
    )->execute([
        't'   => $tenantId, 'st' => $subTenantId, 'p' => $provider,
        'org' => $orgId !== '' ? $orgId : null,
        'ct'  => $ct, 'l4' => $last4,
        'bc'  => $baseCurrency,
        'uid' => $userId,
    ]);

    return accountingConnectionGet($tenantId, $subTenantId, $provider);
}

function accountingConnectionValidate(int $tenantId, int $subTenantId, string $provider): array
{
    $adapter = accountingProviderAdapterFor($provider);
    $probe   = $adapter->validateConnection($tenantId, $subTenantId);

    $status = $probe['ok']
        ? (in_array($probe['status'], ['active','pending_diligence'], true) ? $probe['status'] : 'active')
        : 'failed';
    // Slice 1: 'pending_diligence' is stored as 'pending' in the ENUM
    // (which doesn't include 'pending_diligence') — keeps the schema
    // generic; the UI surfaces the distinction via api_scope_summary.
    $dbStatus = $status === 'pending_diligence' ? 'pending' : $status;

    getDB()->prepare(
        'UPDATE accounting_provider_connections
            SET connection_status   = :s,
                api_scope_summary   = :scope,
                last_validated_at   = NOW(),
                last_validation_error = :err
          WHERE tenant_id = :t AND sub_tenant_id = :st AND provider = :p'
    )->execute([
        's'     => $dbStatus,
        'scope' => json_encode([
            'permissions'         => $probe['scope']['permissions']  ?? [],
            'shadow_user'         => $probe['scope']['shadow_user']  ?? null,
            'org'                 => $probe['org']                   ?? null,
            'not_implemented_yet' => $probe['not_implemented_yet']   ?? false,
        ], JSON_UNESCAPED_SLASHES),
        'err'   => $probe['error'] ?? null,
        't'     => $tenantId, 'st' => $subTenantId, 'p' => $provider,
    ]);

    return [
        'ok'         => $probe['ok'],
        'status'     => $status,
        'connection' => accountingConnectionGet($tenantId, $subTenantId, $provider),
    ];
}

function accountingConnectionDisconnect(int $tenantId, int $subTenantId, string $provider): void
{
    getDB()->prepare(
        'UPDATE accounting_provider_connections
            SET connection_status = "revoked",
                credential_secret_ct = NULL,
                credential_last4     = NULL
          WHERE tenant_id = :t AND sub_tenant_id = :st AND provider = :p'
    )->execute(['t' => $tenantId, 'st' => $subTenantId, 'p' => $provider]);
}
