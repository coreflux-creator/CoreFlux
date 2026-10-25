<?php
/** Tenant-scoped Pure//Pay connection persistence. */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/encryption.php';
require_once __DIR__ . '/purepay_adapter.php';
require_once __DIR__ . '/purepay_financial_binding.php';

/** The named financial preview is exclusively the approved tenant-999 pilot. */
function purepayAssertFinancialPreviewConnection(int $tenantId, ?string $keyScope = null): void
{
    if (purepayFinancialPreviewBase() !== null
        && ($tenantId !== 999 || ($keyScope !== null && $keyScope !== 'read_only'))) {
        throw new PurePayApiException('The financial preview requires the approved simulation company and a read-only connection.');
    }
}

/** Internal binding only; key rotation requires review of existing provider identities. */
function purepayConnectionFingerprint(int $tenantId,string $apiKey): string
{
    purepayAssertFinancialPreviewConnection($tenantId);
    if ($tenantId<=0 || $apiKey==='' || preg_match('/[\r\n]/',$apiKey)) throw new InvalidArgumentException('Valid Pure//Pay connection required');
    return hash('sha256',json_encode(['tenant_id'=>$tenantId,'base'=>purepayApiBase(),'credential_digest'=>hash('sha256',$apiKey)],JSON_THROW_ON_ERROR));
}

function purepayServiceConfigured(): bool
{
    $key = defined('COREFLUX_DATA_KEY') ? (string) constant('COREFLUX_DATA_KEY') : (string) getenv('COREFLUX_DATA_KEY');
    return trim($key) !== '';
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
    if (purepayFinancialPreviewBase() !== null && $tenantId !== 999) return null;
    try {
        $stmt = getDB()->prepare(
        'SELECT id, tenant_id, label, api_key_ct, api_key_last4, key_scope,
                    webhook_secret_ct, webhook_secret_last4, status,
                    wallet_balance_cents, last_probe_at, last_probe_error,
                    created_at, updated_at
               FROM purepay_connections WHERE tenant_id = :t LIMIT 1'
        );
        $stmt->execute(['t' => $tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return null;
        purepayAssertFinancialPreviewConnection($tenantId, (string)$row['key_scope']);
        $row['api_key'] = decryptField($row['api_key_ct'] ?? null) ?: '';
        $row['webhook_secret'] = decryptField($row['webhook_secret_ct'] ?? null) ?: '';
        unset($row['api_key_ct'], $row['webhook_secret_ct']);
        return $row;
    } catch (\Throwable $_) {
        return null;
    }
}

function purepayStoreConnection(int $tenantId, string $apiKey, ?string $label, ?int $userId, string $keyScope): array
{
    purepayAssertFinancialPreviewConnection($tenantId, $keyScope);
    $apiKey = trim($apiKey);
    if (strlen($apiKey)<12 || strlen($apiKey)>1000 || preg_match('/[\x00-\x20\x7f]/',$apiKey)) throw new PurePayApiException('Pure//Pay: enter a valid API key');
    if (!in_array($keyScope, ['read_only', 'read_write'], true)) {
        throw new PurePayApiException('Pure//Pay: select read-only or read-write key scope');
    }
    if (!purepayServiceConfigured()) {
        throw new PurePayApiException('CoreFlux data encryption key is not configured');
    }
    $pdo=getDB();
    if ($pdo->inTransaction()) throw new LogicException('Connection changes require their own transaction.');
    $context=purepayFinancialReadContext($apiKey);
    $wallet = purepayGetWallet($apiKey); // Probe before persistence.
    $balance = purepayWalletBalanceCents($wallet);
    $ct = encryptField($apiKey);
    $pdo->beginTransaction();
    try {
    $pdo->prepare(
        'INSERT INTO purepay_connections
            (tenant_id, label, api_key_ct, api_key_last4, key_scope, status,
             wallet_balance_cents, last_probe_at, last_probe_error, created_by_user_id)
         VALUES (:t, :lb, :ct, :l4, :scope, "active", :bal, NOW(), NULL, :u)
         ON DUPLICATE KEY UPDATE
            label = VALUES(label), api_key_ct = VALUES(api_key_ct),
            api_key_last4 = VALUES(api_key_last4), key_scope = VALUES(key_scope), status = "active",
            wallet_balance_cents = VALUES(wallet_balance_cents),
            last_probe_at = NOW(), last_probe_error = NULL, updated_at = NOW()'
    )->execute([
        't' => $tenantId, 'lb' => $label, 'ct' => $ct,
        'l4' => substr($apiKey, -4), 'scope' => $keyScope, 'bal' => $balance, 'u' => $userId,
    ]);
    $connection=purepayFinancialConnection($pdo,$tenantId,true);
    purepayFinancialPending($pdo,$connection,$context);
    $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction())$pdo->rollBack();throw $e; }
    return ['wallet_balance_cents' => $balance,'company_review_required'=>true];
}

function purepaySaveWebhookSecret(int $tenantId, string $secret): void
{
    purepayAssertFinancialPreviewConnection($tenantId);
    $secret = trim($secret);
    if ($secret === '' || !str_starts_with($secret, 'whsec_')) {
        throw new \InvalidArgumentException('Pure//Pay webhook secret must start with whsec_');
    }
    $pdo=getDB();
    if ($pdo->inTransaction())throw new LogicException('Callback setup requires its own transaction.');
    $pdo->beginTransaction();
    try {
    purepayFinancialRequire($pdo,$tenantId);
    $stmt = $pdo->prepare(
        'UPDATE purepay_connections
            SET webhook_secret_ct = :ct, webhook_secret_last4 = :l4, updated_at = NOW()
          WHERE tenant_id = :t'
    );
    $stmt->execute(['ct' => encryptField($secret), 'l4' => substr($secret, -4), 't' => $tenantId]);
    if ($stmt->rowCount() === 0 && purepayGetConnection($tenantId) === null) {
        throw new \RuntimeException('Connect Pure//Pay before saving a webhook secret');
    }
    $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction())$pdo->rollBack();throw $e; }
}

function purepayProbeConnection(int $tenantId): array
{
    $conn = purepayGetConnection($tenantId);
    if (!$conn || ($conn['status'] ?? '') === 'revoked') throw new PurePayApiException('Pure//Pay is not connected');
    try {
        $review=purepayFinancialReview(getDB(),$tenantId);
        if (!$review['verified']) throw new PurePayApiException('Review and confirm the PurePay company first.');
        $wallet = purepayGetWallet((string) $conn['api_key']);
        $balance = purepayWalletBalanceCents($wallet);
        $pdo=getDB();$pdo->beginTransaction();
        try {
        $current=purepayFinancialConnection($pdo,$tenantId,true);
        if (!$current || $current['status']==='revoked' || $current['key_scope']!==$conn['key_scope']
            || !hash_equals((string)$conn['api_key'],purepayFinancialKey($current))) throw new PurePayApiException('The connection changed during verification.');
        $binding=purepayFinancialBinding($pdo,$tenantId,true);
        if (!$binding || $binding['binding_hash']!==$review['review_hash']) throw new PurePayApiException('The company review changed during verification.');
        $pdo->prepare(
            'UPDATE purepay_connections SET status="active", wallet_balance_cents=:b,
                    last_probe_at=NOW(), last_probe_error=NULL, updated_at=NOW()
              WHERE tenant_id=:t'
        )->execute(['b' => $balance, 't' => $tenantId]);
        $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction())$pdo->rollBack();throw $e; }
        return ['wallet_balance_cents' => $balance];
    } catch (PurePayApiException $e) {
        // Do not overwrite a replacement key's status after a stale network read.
        $current=purepayGetConnection($tenantId);
        if ($current && $current['status']!=='revoked' && hash_equals((string)$conn['api_key'],(string)$current['api_key'])) purepayFlagConnectionError($tenantId, $e->getMessage());
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
