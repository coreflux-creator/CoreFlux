<?php
/**
 * core/mercury_recipients.php — Recipient Vault (Slice 2).
 *
 * Models BOTH outgoing payment recipients (vendors → Mercury counterparties)
 * AND tenant-owned external funding accounts (Mercury external_accounts that
 * Mercury can debit to pre-fund the operating account before pushing ACH to
 * a vendor).
 *
 * Public surface:
 *   mercuryRecipientCreate($tenantId, $data, $userId): array      // local + bank method
 *   mercuryRecipientUpdate($tenantId, $id, $data): void
 *   mercuryRecipientList($tenantId, $kind = null): array
 *   mercuryRecipientGet($tenantId, $id): ?array
 *   mercuryRecipientRevoke($tenantId, $id): void
 *   mercuryRecipientPushToMercury($tenantId, $id, $userId): array // calls adapter; stores mapping
 *   mercuryRecipientSetFundingDefault($tenantId, $recipientId, $mercuryAcctId, $userId): void
 *   mercuryRecipientGetFundingDefault($tenantId): ?array
 *
 * All bank-detail plaintext is hashed and dropped immediately after writes;
 * decryption only happens when pushing to Mercury (one round trip per push).
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/encryption.php';
require_once __DIR__ . '/mercury_adapter.php';
require_once __DIR__ . '/mercury_service.php';

/**
 * Create a recipient + its primary bank method in one transaction.
 *
 * @param array $data {
 *   kind: 'vendor'|'funding_source',
 *   name: string,
 *   email?: string,
 *   payment_method?: 'ach'|'wire'|'check',
 *   notes?: string,
 *   bank: { routing_number, account_number, account_type?, nickname? }
 * }
 */
function mercuryRecipientCreate(int $tenantId, array $data, ?int $userId = null): array
{
    $kind = (string) ($data['kind'] ?? '');
    if (!in_array($kind, ['vendor', 'funding_source', 'sweep_destination'], true)) {
        throw new \InvalidArgumentException('recipient.kind must be vendor|funding_source|sweep_destination');
    }
    $name = trim((string) ($data['name'] ?? ''));
    if ($name === '') throw new \InvalidArgumentException('recipient.name required');

    $bank = is_array($data['bank'] ?? null) ? $data['bank'] : [];
    $routing = preg_replace('/\D+/', '', (string) ($bank['routing_number'] ?? '')) ?? '';
    $account = preg_replace('/\s+/', '', (string) ($bank['account_number'] ?? '')) ?? '';
    if (strlen($routing) !== 9) throw new \InvalidArgumentException('routing_number must be 9 digits');
    if (strlen($account) < 4 || strlen($account) > 17) throw new \InvalidArgumentException('account_number length invalid');
    $acctType = (string) ($bank['account_type'] ?? 'checking');
    if (!in_array($acctType, ['checking', 'savings'], true)) $acctType = 'checking';

    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO mercury_recipients
                (tenant_id, kind, name, email, payment_method, status, notes, created_by_user_id)
             VALUES (:t, :k, :n, :em, :pm, "active", :nt, :u)'
        )->execute([
            't'  => $tenantId,
            'k'  => $kind,
            'n'  => $name,
            'em' => $data['email']  ?? null,
            'pm' => $data['payment_method'] ?? 'ach',
            'nt' => $data['notes']  ?? null,
            'u'  => $userId,
        ]);
        $recipientId = (int) $pdo->lastInsertId();

        $rCt = encryptField($routing);
        $aCt = encryptField($account);
        $pdo->prepare(
            'INSERT INTO mercury_recipient_bank_methods
                (tenant_id, recipient_id, routing_number_ct, account_number_ct, account_number_last4,
                 account_type, nickname, is_default)
             VALUES (:t, :r, :rc, :ac, :l4, :tp, :nk, 1)'
        )->execute([
            't'  => $tenantId,
            'r'  => $recipientId,
            'rc' => $rCt,
            'ac' => $aCt,
            'l4' => substr($account, -4),
            'tp' => $acctType,
            'nk' => $bank['nickname'] ?? null,
        ]);

        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return mercuryRecipientGet($tenantId, $recipientId) ?? ['id' => $recipientId];
}

function mercuryRecipientUpdate(int $tenantId, int $id, array $data): void
{
    $sets = []; $bind = ['t' => $tenantId, 'id' => $id];
    foreach (['name', 'email', 'payment_method', 'status', 'notes'] as $k) {
        if (array_key_exists($k, $data)) {
            $sets[] = "$k = :{$k}";
            $bind[$k] = $data[$k];
        }
    }
    if (!$sets) return;
    $pdo = getDB();
    $pdo->prepare(
        'UPDATE mercury_recipients SET ' . implode(', ', $sets)
        . ', updated_at = NOW() WHERE tenant_id = :t AND id = :id'
    )->execute($bind);
}

function mercuryRecipientList(int $tenantId, ?string $kind = null): array
{
    try {
        $pdo = getDB();
        $sql = 'SELECT r.*,
                       (SELECT account_number_last4 FROM mercury_recipient_bank_methods
                          WHERE tenant_id = r.tenant_id AND recipient_id = r.id AND is_default = 1
                          AND deleted_at IS NULL LIMIT 1) AS bank_last4,
                       (SELECT mercury_id FROM mercury_recipient_mappings
                          WHERE tenant_id = r.tenant_id AND recipient_id = r.id
                          ORDER BY id DESC LIMIT 1) AS mercury_id
                  FROM mercury_recipients r
                 WHERE r.tenant_id = :t AND r.deleted_at IS NULL';
        $params = ['t' => $tenantId];
        if ($kind !== null && $kind !== '') {
            if (!in_array($kind, ['vendor', 'funding_source', 'sweep_destination'], true)) return [];
            $sql .= ' AND r.kind = :k';
            $params['k'] = $kind;
        }
        $sql .= ' ORDER BY r.kind, r.name';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return [];
    }
}

function mercuryRecipientGet(int $tenantId, int $id): ?array
{
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare(
            'SELECT * FROM mercury_recipients WHERE tenant_id = :t AND id = :id AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute(['t' => $tenantId, 'id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return null;
        // Eager-load primary bank method (last4 + type only, NOT plaintext).
        $bm = $pdo->prepare(
            'SELECT id, account_number_last4, account_type, nickname
               FROM mercury_recipient_bank_methods
              WHERE tenant_id = :t AND recipient_id = :r AND is_default = 1 AND deleted_at IS NULL LIMIT 1'
        );
        $bm->execute(['t' => $tenantId, 'r' => $id]);
        $row['bank_method'] = $bm->fetch(\PDO::FETCH_ASSOC) ?: null;
        // Eager-load mercury mappings.
        $mp = $pdo->prepare(
            'SELECT mercury_id, mercury_kind, pushed_at FROM mercury_recipient_mappings
              WHERE tenant_id = :t AND recipient_id = :r'
        );
        $mp->execute(['t' => $tenantId, 'r' => $id]);
        $row['mercury_mappings'] = $mp->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        return $row;
    } catch (\Throwable $e) {
        return null;
    }
}

function mercuryRecipientRevoke(int $tenantId, int $id): void
{
    $pdo = getDB();
    $pdo->prepare(
        "UPDATE mercury_recipients SET status='revoked', deleted_at = NOW(), updated_at = NOW()
          WHERE tenant_id = :t AND id = :id"
    )->execute(['t' => $tenantId, 'id' => $id]);
}

/**
 * Push a vendor recipient up to Mercury as a counterparty. Decrypts bank
 * details for the duration of the API call only, then drops them. Idempotent
 * via UNIQUE (tenant_id, recipient_id, mercury_kind) — re-pushing updates
 * the existing mapping row's last_synced_at.
 *
 * For funding_source recipients: the Mercury external_account id must
 * already exist (the operator manually links the external bank inside the
 * Mercury web UI per Mercury policy). This function will NOT call
 * /recipients for funding_source kind — Slice 3 will instead let the
 * operator paste the existing external_account id when designating the
 * funding default.
 */
function mercuryRecipientPushToMercury(int $tenantId, int $id, ?int $userId = null): array
{
    $rec = mercuryRecipientGet($tenantId, $id);
    if (!$rec) throw new \RuntimeException('recipient not found');
    if ($rec['kind'] === 'funding_source') {
        throw new \RuntimeException('funding_source recipients are not pushed via API — link inside Mercury web UI and paste the external_account id when setting as default.');
    }
    $conn = mercuryGetConnection($tenantId);
    if (!$conn || $conn['status'] !== 'active') {
        throw new MercuryApiException('no active Mercury connection for tenant');
    }

    // Pull the encrypted bank method directly (we deliberately strip plaintext
    // from mercuryRecipientGet to avoid accidental leakage).
    $pdo = getDB();
    $bmStmt = $pdo->prepare(
        'SELECT routing_number_ct, account_number_ct, account_type
           FROM mercury_recipient_bank_methods
          WHERE tenant_id = :t AND recipient_id = :r AND is_default = 1 AND deleted_at IS NULL LIMIT 1'
    );
    $bmStmt->execute(['t' => $tenantId, 'r' => $id]);
    $bm = $bmStmt->fetch(\PDO::FETCH_ASSOC);
    if (!$bm) throw new \RuntimeException('recipient has no default bank method');

    $routing = decryptField($bm['routing_number_ct']);
    $account = decryptField($bm['account_number_ct']);
    if (!$routing || !$account) throw new \RuntimeException('failed to decrypt bank details');

    $payload = [
        'name'           => $rec['name'],
        'emails'         => $rec['email'] ? [$rec['email']] : [],
        'paymentMethod'  => $rec['payment_method'] ?: 'ach',
        'defaultPaymentMethod' => $rec['payment_method'] ?: 'ach',
        'electronicRoutingInfo' => [
            'electronicAccountType' => $bm['account_type'] === 'savings' ? 'savings' : 'checking',
            'routingNumber'         => $routing,
            'accountNumber'         => $account,
        ],
    ];

    try {
        $resp = mercuryCreateCounterparty($conn['api_token'], $payload);
    } finally {
        // Aggressively drop plaintext from in-memory references.
        $routing = null; $account = null; unset($payload['electronicRoutingInfo']);
    }
    $mercuryId = (string) ($resp['id'] ?? $resp['recipientId'] ?? '');
    if ($mercuryId === '') {
        throw new MercuryApiException('Mercury did not return a recipient id');
    }

    $pdo->prepare(
        'INSERT INTO mercury_recipient_mappings
            (tenant_id, recipient_id, mercury_id, mercury_kind, pushed_at, last_synced_at)
         VALUES (:t, :r, :mid, "counterparty", NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            mercury_id     = VALUES(mercury_id),
            last_synced_at = NOW(),
            last_sync_error = NULL'
    )->execute(['t' => $tenantId, 'r' => $id, 'mid' => $mercuryId]);

    return [
        'mercury_id'   => $mercuryId,
        'mercury_kind' => 'counterparty',
    ];
}

/**
 * Designate the tenant-default funding source — the funding_source recipient
 * Mercury will debit when AP needs to top up the operating account, plus the
 * specific Mercury operating account that will be credited.
 *
 * Validates both inputs against the local DB before persisting.
 */
function mercuryRecipientSetFundingDefault(int $tenantId, int $recipientId, string $mercuryAccountId, ?int $userId = null): void
{
    $rec = mercuryRecipientGet($tenantId, $recipientId);
    if (!$rec || $rec['kind'] !== 'funding_source') {
        throw new \InvalidArgumentException('recipient must exist and be of kind=funding_source');
    }
    if ($mercuryAccountId === '') {
        throw new \InvalidArgumentException('mercury_account_id required (Mercury operating account to credit)');
    }
    $pdo = getDB();
    // Validate the mercury account belongs to this tenant.
    $chk = $pdo->prepare(
        'SELECT id FROM mercury_accounts WHERE tenant_id = :t AND mercury_account_id = :m LIMIT 1'
    );
    $chk->execute(['t' => $tenantId, 'm' => $mercuryAccountId]);
    if (!$chk->fetchColumn()) {
        throw new \InvalidArgumentException('mercury_account_id is not in the tenant\'s synced accounts; run Refresh accounts first');
    }
    $pdo->prepare(
        'UPDATE mercury_connections
            SET default_funding_recipient_id = :r,
                default_mercury_account_id   = :m,
                updated_at = NOW()
          WHERE tenant_id = :t'
    )->execute(['t' => $tenantId, 'r' => $recipientId, 'm' => $mercuryAccountId]);
}

/**
 * Read back the configured funding default (recipient + Mercury account).
 * Returns null if either side is unset.
 */
function mercuryRecipientGetFundingDefault(int $tenantId): ?array
{
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare(
            'SELECT default_funding_recipient_id, default_mercury_account_id
               FROM mercury_connections WHERE tenant_id = :t LIMIT 1'
        );
        $stmt->execute(['t' => $tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row || !$row['default_funding_recipient_id'] || !$row['default_mercury_account_id']) return null;
        $rec = mercuryRecipientGet($tenantId, (int) $row['default_funding_recipient_id']);
        return [
            'recipient_id'              => (int) $row['default_funding_recipient_id'],
            'recipient'                 => $rec,
            'mercury_account_id'        => $row['default_mercury_account_id'],
        ];
    } catch (\Throwable $e) {
        return null;
    }
}
