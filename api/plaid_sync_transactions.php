<?php
/**
 * Plaid — POST /api/plaid_sync_transactions.php
 *
 * Cursor-paginated /transactions/sync against a linked Plaid Item, fanning
 * out across EVERY account on that item:
 *
 *   • Depository (checking/savings) →  accounting_bank_statement_lines
 *     keyed by bank_account_id (resolved via accounting_bank_accounts.plaid_account_id)
 *
 *   • Credit cards / loans / lines of credit  →  treasury_liability_statement_lines
 *     keyed by liability_account_id (the COA accounting_accounts.id row created
 *     by /api/plaid_bank_link.php for the card/loan)
 *
 * One Plaid Item ⇒ one cursor; the cursor advances at the item level
 * regardless of how many accounts are inside.  Removed transactions flip
 * match_status='ignored' on whichever table the row originally landed in.
 *
 * Mutation-during-pagination handling: if Plaid returns
 * TRANSACTIONS_SYNC_MUTATION_DURING_PAGINATION, we restart from the saved
 * cursor (NOT the failed page's cursor) once. After that, surface the error.
 *
 * Body:
 *   item_id:                     string  (Plaid item_id)
 *   accounting_bank_account_id?: int     (legacy single-account hint; ignored
 *                                          for the fan-out resolver, only used
 *                                          to fail loud when no mapping exists)
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/plaid_service.php';

$ctx  = api_require_auth();
$user = $ctx['user'];

if (api_method() !== 'POST') api_error('Method not allowed', 405);

$body      = api_json_body();
$itemIdExt = trim((string) ($body['item_id'] ?? ''));
if ($itemIdExt === '') api_error('item_id required', 422);

RBAC::requirePermission($user, 'accounting.bank.manage');

$item = scopedFind(
    'SELECT * FROM plaid_items WHERE tenant_id = :tenant_id AND item_id = :iid',
    ['iid' => $itemIdExt]
);
if (!$item) api_error('Item not found', 404);

$accessToken = plaidDecryptAccessToken($item['access_token_ct']);
if (!$accessToken) api_error('Could not decrypt access token', 500);

// Self-heal: liability statement-lines table may not exist on a fresh tenant
// pre-migration. Create it on first sync.
$pdo = getDB();
try {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS treasury_liability_statement_lines (
            id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tenant_id             INT UNSIGNED NOT NULL,
            liability_account_id  BIGINT UNSIGNED NOT NULL,
            posted_date           DATE NOT NULL,
            description           VARCHAR(255) NULL,
            amount                DECIMAL(18,2) NOT NULL,
            merchant_name         VARCHAR(255) NULL,
            category              VARCHAR(120) NULL,
            bank_reference        VARCHAR(120) NULL,
            fitid                 VARCHAR(120) NULL,
            match_status          ENUM('unmatched','matched','ignored') NOT NULL DEFAULT 'unmatched',
            created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_tlsl_fitid (tenant_id, liability_account_id, fitid),
            INDEX idx_tlsl_acct_date (tenant_id, liability_account_id, posted_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
} catch (\Throwable $_) { /* fail loud below if write actually breaks */ }

// Build a Plaid-account-id → destination-row map for this item.
//   destination = ['kind' => 'deposit'|'liability', 'id' => N]
$tenantId = (int) $ctx['tenant_id'];
$accountMap = _plaidBuildAccountDestinationMap($tenantId, (int) $item['id']);

if (empty($accountMap)) {
    api_error(
        'No mirrored deposit or liability accounts found for this Plaid item. '
        . 'Re-run the Plaid Link exchange or use Treasury → Run diagnostics → Backfill orphans.',
        409
    );
}

$cursor = $item['transactions_cursor'];
$results = [
    'pages'         => 0,
    'added'         => 0,
    'modified'      => 0,
    'removed'       => 0,
    'unmapped'      => 0,
    'per_account'   => [],   // keyed by plaid_account_id
];
$retried = false;

while (true) {
    try {
        $resp = plaidSyncTransactions($accessToken, $cursor);
    } catch (PlaidApiException $e) {
        if (!$retried && stripos($e->errorCode, 'MUTATION_DURING_PAGINATION') !== false) {
            $retried = true;
            $cursor  = $item['transactions_cursor'];
            $results = ['pages' => 0, 'added' => 0, 'modified' => 0, 'removed' => 0, 'unmapped' => 0, 'per_account' => []];
            continue;
        }
        plaidAudit('core.plaid.transactions_sync_failed', [
            'item_id' => $itemIdExt, 'error_code' => $e->errorCode,
        ], (int) $item['id']);
        api_error('Plaid sync failed: ' . $e->getMessage(), 502, ['plaid_error_code' => $e->errorCode]);
    }
    $results['pages']++;

    foreach (($resp['added'] ?? []) as $t) {
        if (_plaidRouteTxn($accountMap, $t, false, $results)) $results['added']++;
        else $results['unmapped']++;
    }
    foreach (($resp['modified'] ?? []) as $t) {
        if (_plaidRouteTxn($accountMap, $t, true, $results)) $results['modified']++;
        else $results['unmapped']++;
    }
    foreach (($resp['removed'] ?? []) as $r) {
        $accId = (string) ($r['account_id'] ?? '');
        $txnId = (string) ($r['transaction_id'] ?? '');
        if ($accId === '' || $txnId === '' || !isset($accountMap[$accId])) { $results['unmapped']++; continue; }
        _plaidMarkRemovedRouted($accountMap[$accId], $txnId);
        $results['removed']++;
    }

    $cursor = (string) ($resp['next_cursor'] ?? $cursor);
    if (empty($resp['has_more'])) break;
    if ($results['pages'] > 200) api_error('Sync paginated past 200 pages — aborting', 500);
}

scopedUpdate('plaid_items', (int) $item['id'], [
    'transactions_cursor'      => $cursor,
    'last_transaction_sync_at' => date('Y-m-d H:i:s'),
]);

// Touch last_feed_synced_at on every mirrored bank account in this item.
foreach ($accountMap as $dest) {
    if ($dest['kind'] === 'deposit') {
        $pdo->prepare(
            'UPDATE accounting_bank_accounts SET last_feed_synced_at = NOW()
              WHERE tenant_id = :t AND id = :i'
        )->execute(['t' => $tenantId, 'i' => $dest['id']]);
    }
}

plaidAudit('core.plaid.transactions_synced', [
    'item_id' => $itemIdExt, 'results' => $results, 'accounts' => count($accountMap),
], (int) $item['id']);

// Also refresh balance cache so the Treasury list pages can show the live
// current balance without firing /accounts/balance/get on every render.
try {
    $balResp = plaidGetAccounts($accessToken);
    if (is_array($balResp['accounts'] ?? null)) {
        plaidPersistAccountBalances($pdo, $tenantId, $balResp['accounts']);
        $results['balances_refreshed'] = count($balResp['accounts']);
    }
} catch (\Throwable $_) { /* non-fatal — sync results still returned */ }

api_ok($results);

// ---------------------------------------------------------------- helpers

/**
 * Returns: array<plaid_account_id, ['kind' => 'deposit'|'liability', 'id' => int]>
 */
function _plaidBuildAccountDestinationMap(int $tenantId, int $itemPk): array {
    $pdo = getDB();
    $map = [];

    // Deposits — accounting_bank_accounts joined via plaid_account_id.
    $stmt = $pdo->prepare(
        'SELECT pa.account_id AS plaid_acc, ba.id AS dest_id
           FROM plaid_accounts pa
           JOIN accounting_bank_accounts ba
             ON ba.tenant_id = pa.tenant_id AND ba.plaid_account_id = pa.account_id
          WHERE pa.tenant_id = :t AND pa.plaid_item_pk = :i'
    );
    $stmt->execute(['t' => $tenantId, 'i' => $itemPk]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $map[(string) $r['plaid_acc']] = ['kind' => 'deposit', 'id' => (int) $r['dest_id']];
    }

    // Liabilities — treasury_liability_accounts → accounting_accounts via FK.
    try {
        $stmt = $pdo->prepare(
            'SELECT pa.account_id AS plaid_acc, tla.account_id AS dest_id
               FROM plaid_accounts pa
               JOIN treasury_liability_accounts tla
                 ON tla.tenant_id = pa.tenant_id AND tla.plaid_account_id = pa.account_id
              WHERE pa.tenant_id = :t AND pa.plaid_item_pk = :i'
        );
        $stmt->execute(['t' => $tenantId, 'i' => $itemPk]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $map[(string) $r['plaid_acc']] = ['kind' => 'liability', 'id' => (int) $r['dest_id']];
        }
    } catch (\Throwable $_) { /* migration 002 missing — no liability rows */ }

    return $map;
}

function _plaidRouteTxn(array $map, array $t, bool $isModification, array &$results): bool {
    $accId = (string) ($t['account_id'] ?? '');
    if ($accId === '' || !isset($map[$accId])) return false;
    $dest  = $map[$accId];
    $txnId = (string) ($t['transaction_id'] ?? '');
    if ($txnId === '') return false;

    // Plaid 'amount' convention: positive = outflow (charge / debit).
    // Bank statement lines convention: signed (+ = credit/inflow, - = debit/outflow).
    $signed = round(((float) ($t['amount'] ?? 0)) * -1, 2);
    $tenantId = currentTenantId();
    $pdo = getDB();

    if ($dest['kind'] === 'deposit') {
        $pdo->prepare(
            'INSERT INTO accounting_bank_statement_lines
                (tenant_id, bank_account_id, posted_date, description, amount, bank_reference, fitid, match_status)
             VALUES
                (:t, :acc, :d, :desc, :amt, :ref, :fitid, "unmatched")
             ON DUPLICATE KEY UPDATE
                posted_date    = VALUES(posted_date),
                description    = VALUES(description),
                amount         = VALUES(amount),
                bank_reference = VALUES(bank_reference)'
        )->execute([
            't'     => $tenantId,
            'acc'   => $dest['id'],
            'd'     => (string) ($t['date'] ?? date('Y-m-d')),
            'desc'  => substr((string) ($t['merchant_name'] ?? $t['name'] ?? ''), 0, 255),
            'amt'   => $signed,
            'ref'   => substr((string) ($t['payment_meta']['reference_number'] ?? ''), 0, 120) ?: null,
            'fitid' => $txnId,
        ]);
    } else {
        $pcat = $t['personal_finance_category']['primary']
              ?? (is_array($t['category'] ?? null) ? implode(' / ', $t['category']) : null);
        $pdo->prepare(
            'INSERT INTO treasury_liability_statement_lines
                (tenant_id, liability_account_id, posted_date, description, amount,
                 merchant_name, category, bank_reference, fitid, match_status)
             VALUES
                (:t, :acc, :d, :desc, :amt, :mn, :cat, :ref, :fitid, "unmatched")
             ON DUPLICATE KEY UPDATE
                posted_date    = VALUES(posted_date),
                description    = VALUES(description),
                amount         = VALUES(amount),
                merchant_name  = VALUES(merchant_name),
                category       = VALUES(category),
                bank_reference = VALUES(bank_reference)'
        )->execute([
            't'     => $tenantId,
            'acc'   => $dest['id'],
            'd'     => (string) ($t['date'] ?? date('Y-m-d')),
            'desc'  => substr((string) ($t['name'] ?? $t['merchant_name'] ?? ''), 0, 255),
            'amt'   => $signed,
            'mn'    => substr((string) ($t['merchant_name'] ?? ''), 0, 255) ?: null,
            'cat'   => $pcat ? substr((string) $pcat, 0, 120) : null,
            'ref'   => substr((string) ($t['payment_meta']['reference_number'] ?? ''), 0, 120) ?: null,
            'fitid' => $txnId,
        ]);
    }

    $results['per_account'][$accId] = ($results['per_account'][$accId] ?? 0) + 1;
    return true;
}

function _plaidMarkRemovedRouted(array $dest, string $txnId): void {
    $pdo = getDB();
    $tenantId = currentTenantId();
    $table = $dest['kind'] === 'deposit'
        ? 'accounting_bank_statement_lines'
        : 'treasury_liability_statement_lines';
    $col   = $dest['kind'] === 'deposit' ? 'bank_account_id' : 'liability_account_id';
    $pdo->prepare(
        "UPDATE {$table}
            SET match_status = 'ignored'
          WHERE tenant_id = :t AND {$col} = :acc AND fitid = :f
            AND match_status = 'unmatched'"
    )->execute(['t' => $tenantId, 'acc' => $dest['id'], 'f' => $txnId]);
}
