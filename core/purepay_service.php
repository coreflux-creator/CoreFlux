<?php
/** Tenant-scoped Pure//Pay connection persistence. */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/encryption.php';
require_once __DIR__ . '/purepay_adapter.php';

function purepayServiceConfigured(): bool
{
    return (bool) (defined('COREFLUX_DATA_KEY') || getenv('COREFLUX_DATA_KEY'));
}

function purepayWalletBalanceCents(array $response): ?int
{
    $wallet = purepayResource($response, ['wallet']);
    foreach (['balance_cents', 'available_balance_cents', 'available_cents'] as $key) {
        if (isset($wallet[$key]) && is_numeric($wallet[$key])) return (int) $wallet[$key];
    }
    foreach (['balance', 'available_balance', 'available'] as $key) {
        if (isset($wallet[$key]) && is_numeric($wallet[$key])) return (int) round(((float) $wallet[$key]) * 100);
    }
    return null;
}

function purepayGetConnection(int $tenantId): ?array
{
    try {
        $stmt = getDB()->prepare(
            'SELECT id, tenant_id, label, api_key_ct, api_key_last4,
                    webhook_secret_ct, webhook_secret_last4, status,
                    wallet_balance_cents, last_probe_at, last_probe_error,
                    created_at, updated_at
               FROM purepay_connections WHERE tenant_id = :t LIMIT 1'
        );
        $stmt->execute(['t' => $tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return null;
        $row['api_key'] = decryptField($row['api_key_ct'] ?? null) ?: '';
        $row['webhook_secret'] = decryptField($row['webhook_secret_ct'] ?? null) ?: '';
        unset($row['api_key_ct'], $row['webhook_secret_ct']);
        return $row;
    } catch (\Throwable $_) {
        return null;
    }
}

function purepayStoreConnection(int $tenantId, string $apiKey, ?string $label, ?int $userId): array
{
    $apiKey = trim($apiKey);
    if ($apiKey === '') throw new PurePayApiException('Pure//Pay: API key required');
    if (!purepayServiceConfigured()) {
        throw new PurePayApiException('CoreFlux data encryption key is not configured');
    }
    $wallet = purepayGetWallet($apiKey); // Probe before persistence.
    $balance = purepayWalletBalanceCents($wallet);
    $ct = encryptField($apiKey);
    getDB()->prepare(
        'INSERT INTO purepay_connections
            (tenant_id, label, api_key_ct, api_key_last4, status,
             wallet_balance_cents, last_probe_at, last_probe_error, created_by_user_id)
         VALUES (:t, :lb, :ct, :l4, "active", :bal, NOW(), NULL, :u)
         ON DUPLICATE KEY UPDATE
            label = VALUES(label), api_key_ct = VALUES(api_key_ct),
            api_key_last4 = VALUES(api_key_last4), status = "active",
            wallet_balance_cents = VALUES(wallet_balance_cents),
            last_probe_at = NOW(), last_probe_error = NULL, updated_at = NOW()'
    )->execute([
        't' => $tenantId, 'lb' => $label, 'ct' => $ct,
        'l4' => substr($apiKey, -4), 'bal' => $balance, 'u' => $userId,
    ]);
    return ['wallet_balance_cents' => $balance];
}

function purepaySaveWebhookSecret(int $tenantId, string $secret): void
{
    $secret = trim($secret);
    if ($secret === '' || !str_starts_with($secret, 'whsec_')) {
        throw new \InvalidArgumentException('Pure//Pay webhook secret must start with whsec_');
    }
    $stmt = getDB()->prepare(
        'UPDATE purepay_connections
            SET webhook_secret_ct = :ct, webhook_secret_last4 = :l4, updated_at = NOW()
          WHERE tenant_id = :t'
    );
    $stmt->execute(['ct' => encryptField($secret), 'l4' => substr($secret, -4), 't' => $tenantId]);
    if ($stmt->rowCount() === 0) throw new \RuntimeException('Connect Pure//Pay before saving a webhook secret');
}

function purepayProbeConnection(int $tenantId): array
{
    $conn = purepayGetConnection($tenantId);
    if (!$conn || ($conn['status'] ?? '') === 'revoked') throw new PurePayApiException('Pure//Pay is not connected');
    try {
        $wallet = purepayGetWallet((string) $conn['api_key']);
        $balance = purepayWalletBalanceCents($wallet);
        getDB()->prepare(
            'UPDATE purepay_connections SET status="active", wallet_balance_cents=:b,
                    last_probe_at=NOW(), last_probe_error=NULL, updated_at=NOW()
              WHERE tenant_id=:t'
        )->execute(['b' => $balance, 't' => $tenantId]);
        return ['wallet_balance_cents' => $balance];
    } catch (PurePayApiException $e) {
        purepayFlagConnectionError($tenantId, $e->getMessage());
        throw $e;
    }
}

function purepayFlagConnectionError(int $tenantId, string $message): void
{
    try {
        getDB()->prepare(
            'UPDATE purepay_connections SET status="error", last_probe_at=NOW(),
                    last_probe_error=:e, updated_at=NOW() WHERE tenant_id=:t'
        )->execute(['e' => substr($message, 0, 255), 't' => $tenantId]);
    } catch (\Throwable $_) {}
}

function purepayRevokeConnection(int $tenantId): void
{
    getDB()->prepare(
        'UPDATE purepay_connections SET status="revoked", updated_at=NOW() WHERE tenant_id=:t'
    )->execute(['t' => $tenantId]);
}

