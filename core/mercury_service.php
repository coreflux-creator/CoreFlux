<?php
/**
 * core/mercury_service.php — connection + account + transaction persistence.
 *
 * Sits between the bare HTTP adapter (`mercury_adapter.php`) and the REST API
 * endpoints (`api/mercury_*.php`). Stateful operations live here:
 *
 *   mercuryGetConnection(int $tenantId): ?array       — read row + decrypt token
 *   mercuryStoreConnection(...)                       — upsert encrypted token
 *   mercuryRevokeConnection(int $tenantId): void
 *   mercurySyncAccounts(int $tenantId): array         — Mercury → mercury_accounts
 *   mercurySyncAccountTransactions(int $tenantId, int $accountPk, array $opts = []): array
 *
 * All token plaintext is held in-memory just long enough to make the Mercury
 * call. Storage at rest is always the AES-256-GCM ciphertext from
 * encryptField(). last4 stored separately for masked display.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/encryption.php';
require_once __DIR__ . '/mercury_adapter.php';

function mercuryServiceConfigured(): bool
{
    // Mercury is tenant-owned (each tenant pastes their own token) so there
    // is no pod-level "configured" gate — but the encryption key MUST be set
    // before any token can be stored.
    return (bool) (defined('COREFLUX_DATA_KEY') || getenv('COREFLUX_DATA_KEY'));
}

/**
 * Return the active connection row for the tenant, decrypted, or null.
 * Shape: ['id'=>int,'tenant_id'=>int,'label'=>string,'api_token'=>string,
 *         'api_token_last4'=>string,'status'=>string,'workspace_name'=>?string,...]
 */
function mercuryGetConnection(int $tenantId): ?array
{
    try {
        $pdo  = getDB();
        $stmt = $pdo->prepare(
            'SELECT id, tenant_id, label, api_token_ct, api_token_last4, status,
                    last_probe_at, last_probe_error, workspace_name, created_at, updated_at
               FROM mercury_connections
              WHERE tenant_id = :t LIMIT 1'
        );
        $stmt->execute(['t' => $tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return null;
        $token = decryptField($row['api_token_ct'] ?? null);
        unset($row['api_token_ct']);
        $row['api_token'] = $token ?: '';
        return $row;
    } catch (\Throwable $e) {
        // Migration 048 may not be run yet — degrade gracefully.
        return null;
    }
}

/**
 * Upsert the tenant's Mercury connection. Probes the token immediately by
 * calling /accounts so a bad token never persists in active state.
 *
 * On success: status='active', workspace_name populated from the first
 * account row's name (Mercury doesn't expose a workspace label endpoint).
 * On failure: throws MercuryApiException; nothing persisted.
 */
function mercuryStoreConnection(int $tenantId, string $apiToken, ?string $label, ?int $userId): array
{
    $apiToken = trim($apiToken);
    if ($apiToken === '') {
        throw new MercuryApiException('Mercury: api token required');
    }
    // Probe BEFORE persisting — refuse to save a token we can't authenticate.
    $probe = mercuryListAccounts($apiToken);
    $accounts = is_array($probe['accounts'] ?? null) ? $probe['accounts'] : [];

    $ct    = encryptField($apiToken);
    $last4 = substr($apiToken, -4);
    $workspaceName = null;
    foreach ($accounts as $a) {
        if (!empty($a['name'])) { $workspaceName = (string) $a['name']; break; }
    }

    $pdo = getDB();
    $pdo->prepare(
        'INSERT INTO mercury_connections
            (tenant_id, label, api_token_ct, api_token_last4, status,
             last_probe_at, last_probe_error, workspace_name, created_by_user_id)
         VALUES (:t, :lb, :ct, :l4, "active", NOW(), NULL, :wn, :u)
         ON DUPLICATE KEY UPDATE
            label              = VALUES(label),
            api_token_ct       = VALUES(api_token_ct),
            api_token_last4    = VALUES(api_token_last4),
            status             = "active",
            last_probe_at      = NOW(),
            last_probe_error   = NULL,
            workspace_name     = VALUES(workspace_name),
            updated_at         = NOW()'
    )->execute([
        't'  => $tenantId,
        'lb' => $label,
        'ct' => $ct,
        'l4' => $last4,
        'wn' => $workspaceName,
        'u'  => $userId,
    ]);

    // Hydrate accounts cache eagerly so the UI shows balances on first load.
    // NB: PHP 8 PDO::lastInsertId() expects ?string (or no argument); passing
    // `true` raises a TypeError on PHP 8.0+. Use bare call. UPSERT returns 0
    // when a row was updated rather than inserted — the fallback SELECT handles
    // that case so we always end up with a valid connection_id.
    $insertedId = (int) $pdo->lastInsertId();
    if ($insertedId <= 0) {
        $insertedId = (int) ($pdo->query("SELECT id FROM mercury_connections WHERE tenant_id = {$tenantId}")->fetchColumn() ?: 0);
    }
    mercurySyncAccountsFromList($tenantId, $insertedId, $accounts);

    return [
        'workspace_name' => $workspaceName,
        'accounts_count' => count($accounts),
    ];
}

/** Soft-revoke (preserves audit trail). */
function mercuryRevokeConnection(int $tenantId): void
{
    $pdo = getDB();
    $pdo->prepare(
        "UPDATE mercury_connections SET status='revoked', updated_at=NOW() WHERE tenant_id = :t"
    )->execute(['t' => $tenantId]);
}

/**
 * Mark the connection as failing without nuking the token — operator can
 * fix the underlying issue and re-probe.
 */
function mercuryFlagConnectionError(int $tenantId, string $msg): void
{
    try {
        $pdo = getDB();
        $pdo->prepare(
            "UPDATE mercury_connections
                SET status='error', last_probe_error = :m, last_probe_at = NOW(), updated_at = NOW()
              WHERE tenant_id = :t"
        )->execute(['t' => $tenantId, 'm' => substr($msg, 0, 240)]);
    } catch (\Throwable $e) {
        // best-effort
    }
}

/**
 * Refresh the accounts list (and balances) from Mercury → mercury_accounts.
 * Returns the upserted rows. Idempotent on (tenant_id, mercury_account_id).
 */
function mercurySyncAccounts(int $tenantId): array
{
    $conn = mercuryGetConnection($tenantId);
    if (!$conn || $conn['status'] !== 'active') {
        throw new MercuryApiException('Mercury: no active connection for tenant');
    }
    try {
        $resp = mercuryListAccounts($conn['api_token']);
    } catch (MercuryApiException $e) {
        mercuryFlagConnectionError($tenantId, $e->getMessage());
        throw $e;
    }
    $accounts = is_array($resp['accounts'] ?? null) ? $resp['accounts'] : [];
    return mercurySyncAccountsFromList($tenantId, (int) $conn['id'], $accounts);
}

/**
 * Lower-level helper — given an already-fetched accounts list, upsert all
 * rows. Used by both mercurySyncAccounts() and mercuryStoreConnection().
 */
function mercurySyncAccountsFromList(int $tenantId, int $connectionId, array $accounts): array
{
    if (!$accounts || $connectionId <= 0) return [];
    $pdo = getDB();
    $stmt = $pdo->prepare(
        'INSERT INTO mercury_accounts
            (tenant_id, connection_id, mercury_account_id, nickname, account_number_last4,
             routing_number, kind, status, available_balance_cents, current_balance_cents,
             currency, last_synced_at)
         VALUES (:t, :c, :mid, :nn, :l4, :rt, :kd, :st, :ab, :cb, :cur, NOW())
         ON DUPLICATE KEY UPDATE
            connection_id            = VALUES(connection_id),
            nickname                 = VALUES(nickname),
            account_number_last4     = VALUES(account_number_last4),
            routing_number           = VALUES(routing_number),
            kind                     = VALUES(kind),
            status                   = VALUES(status),
            available_balance_cents  = VALUES(available_balance_cents),
            current_balance_cents    = VALUES(current_balance_cents),
            currency                 = VALUES(currency),
            last_synced_at           = NOW(),
            updated_at               = NOW()'
    );
    $out = [];
    foreach ($accounts as $a) {
        $accId = (string) ($a['id'] ?? '');
        if ($accId === '') continue;
        $acctNum = (string) ($a['accountNumber'] ?? '');
        $last4   = $acctNum !== '' ? substr($acctNum, -4) : null;
        $availCents = isset($a['availableBalance']) ? (int) round(((float) $a['availableBalance']) * 100) : null;
        $currCents  = isset($a['currentBalance'])   ? (int) round(((float) $a['currentBalance'])   * 100) : null;
        $stmt->execute([
            't'   => $tenantId,
            'c'   => $connectionId,
            'mid' => $accId,
            'nn'  => (string) ($a['nickname'] ?? $a['name'] ?? ''),
            'l4'  => $last4,
            'rt'  => (string) ($a['routingNumber'] ?? ''),
            'kd'  => (string) ($a['kind'] ?? ''),
            'st'  => (string) ($a['status'] ?? ''),
            'ab'  => $availCents,
            'cb'  => $currCents,
            'cur' => (string) ($a['currency'] ?? 'USD'),
        ]);
        $out[] = [
            'mercury_account_id' => $accId,
            'nickname'           => $a['nickname'] ?? $a['name'] ?? '',
            'available_balance_cents' => $availCents,
            'current_balance_cents'   => $currCents,
        ];
    }
    return $out;
}

/**
 * Pull transactions for a single mercury_accounts row. Idempotent via the
 * UNIQUE (tenant_id, mercury_txn_id) key — re-running the same window is
 * safe. Returns counts for the caller (UI / cron log).
 */
function mercurySyncAccountTransactions(int $tenantId, int $accountPk, array $opts = []): array
{
    $conn = mercuryGetConnection($tenantId);
    if (!$conn || $conn['status'] !== 'active') {
        throw new MercuryApiException('Mercury: no active connection for tenant');
    }
    $pdo = getDB();
    $acct = $pdo->prepare('SELECT id, mercury_account_id FROM mercury_accounts WHERE tenant_id = :t AND id = :pk LIMIT 1');
    $acct->execute(['t' => $tenantId, 'pk' => $accountPk]);
    $arow = $acct->fetch(\PDO::FETCH_ASSOC);
    if (!$arow) throw new MercuryApiException('Mercury: account not found for tenant');

    try {
        $resp = mercuryListTransactions($conn['api_token'], (string) $arow['mercury_account_id'], $opts);
    } catch (MercuryApiException $e) {
        mercuryFlagConnectionError($tenantId, $e->getMessage());
        throw $e;
    }
    $txns = is_array($resp['transactions'] ?? null) ? $resp['transactions'] : [];
    $ins = $pdo->prepare(
        'INSERT IGNORE INTO mercury_transactions
            (tenant_id, account_pk, mercury_txn_id, mercury_account_id, amount_cents, currency,
             posted_at, estimated_delivery_date, status, kind, counterparty_name, note,
             bank_description, payload_json)
         VALUES (:t, :pk, :tid, :mid, :amt, :cur, :pa, :edd, :st, :kd, :cp, :nt, :bd, :pl)'
    );
    $inserted = 0;
    foreach ($txns as $tx) {
        $tid = (string) ($tx['id'] ?? '');
        if ($tid === '') continue;
        $amt = isset($tx['amount']) ? (int) round(((float) $tx['amount']) * 100) : 0;
        $ins->execute([
            't'   => $tenantId,
            'pk'  => (int) $arow['id'],
            'tid' => $tid,
            'mid' => (string) $arow['mercury_account_id'],
            'amt' => $amt,
            'cur' => (string) ($tx['currency'] ?? 'USD'),
            'pa'  => isset($tx['postedAt']) ? date('Y-m-d H:i:s', strtotime((string) $tx['postedAt']) ?: time()) : null,
            'edd' => isset($tx['estimatedDeliveryDate']) ? substr((string) $tx['estimatedDeliveryDate'], 0, 10) : null,
            'st'  => (string) ($tx['status'] ?? ''),
            'kd'  => (string) ($tx['kind'] ?? ''),
            'cp'  => (string) ($tx['counterpartyName'] ?? $tx['counterparty_name'] ?? ''),
            'nt'  => (string) ($tx['note'] ?? ''),
            'bd'  => (string) ($tx['bankDescription'] ?? ''),
            'pl'  => json_encode($tx),
        ]);
        if ($ins->rowCount() > 0) $inserted++;
    }
    return [
        'fetched'  => count($txns),
        'inserted' => $inserted,
        'account_pk' => $accountPk,
    ];
}
