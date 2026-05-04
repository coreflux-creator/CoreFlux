<?php
/**
 * Plaid nightly transactions sync (cron driver).
 *
 * Cron: 0 2 * * * php /home/master/applications/<app>/public_html/cron/plaid_sync_nightly.php
 *
 * Iterates all linked plaid_items with status='linked' and a bound
 * accounting_bank_account_id, runs the same sync logic as
 * /api/plaid_sync_transactions but without the auth gate. Logs per-item
 * results and audits each sync.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/encryption.php';
require_once __DIR__ . '/../core/plaid_service.php';

if (!plaidConfigured()) {
    fwrite(STDERR, "Plaid is not configured — skipping nightly sync.\n");
    exit(0);
}

$pdo = getDB();
$rows = $pdo->query(
    "SELECT id, tenant_id, item_id, access_token_ct, accounting_bank_account_id, transactions_cursor
     FROM plaid_items
     WHERE status = 'linked' AND accounting_bank_account_id IS NOT NULL
     ORDER BY tenant_id, id"
)->fetchAll(\PDO::FETCH_ASSOC);

$totalAdded = $totalModified = $totalRemoved = 0;
$ok = $fail = 0;

foreach ($rows as $item) {
    $accessToken = plaidDecryptAccessToken($item['access_token_ct']);
    if (!$accessToken) { fwrite(STDERR, "[skip] item {$item['item_id']}: decrypt failed\n"); $fail++; continue; }

    $cursor  = $item['transactions_cursor'];
    $bankAcc = (int) $item['accounting_bank_account_id'];
    $added = $modified = $removed = $pages = 0;
    $retried = false;

    while (true) {
        try {
            $resp = plaidSyncTransactions($accessToken, $cursor);
        } catch (PlaidApiException $e) {
            if (!$retried && stripos($e->errorCode, 'MUTATION_DURING_PAGINATION') !== false) {
                $retried = true; $cursor = $item['transactions_cursor']; $added = $modified = $removed = $pages = 0;
                continue;
            }
            fwrite(STDERR, "[fail] item {$item['item_id']}: {$e->errorCode} — {$e->getMessage()}\n");
            $fail++; break 1;
        }
        $pages++;
        foreach (($resp['added'] ?? []) as $t) {
            _cron_upsert_txn($pdo, (int) $item['tenant_id'], $bankAcc, $t); $added++;
        }
        foreach (($resp['modified'] ?? []) as $t) {
            _cron_upsert_txn($pdo, (int) $item['tenant_id'], $bankAcc, $t); $modified++;
        }
        foreach (($resp['removed'] ?? []) as $r) {
            _cron_mark_removed($pdo, (int) $item['tenant_id'], $bankAcc, (string) ($r['transaction_id'] ?? ''));
            $removed++;
        }
        $cursor = (string) ($resp['next_cursor'] ?? $cursor);
        if (empty($resp['has_more'])) break;
        if ($pages > 200) { fwrite(STDERR, "[fail] item {$item['item_id']}: exceeded 200 pages\n"); $fail++; break 2; }
    }

    $pdo->prepare(
        'UPDATE plaid_items SET transactions_cursor = :c, last_transaction_sync_at = NOW() WHERE id = :id'
    )->execute(['c' => $cursor, 'id' => $item['id']]);

    fprintf(STDOUT, "[ok] item %s tenant=%d added=%d modified=%d removed=%d\n",
        $item['item_id'], $item['tenant_id'], $added, $modified, $removed);
    $totalAdded += $added; $totalModified += $modified; $totalRemoved += $removed; $ok++;
}

fprintf(STDOUT, "Done — %d items synced, %d failed. totals: added=%d modified=%d removed=%d\n",
    $ok, $fail, $totalAdded, $totalModified, $totalRemoved);

exit($fail > 0 ? 1 : 0);

function _cron_upsert_txn(\PDO $pdo, int $tenantId, int $bankAccountId, array $t): void
{
    $txnId = (string) ($t['transaction_id'] ?? '');
    if ($txnId === '') return;
    $signed = round(((float) ($t['amount'] ?? 0)) * -1, 2);
    $pdo->prepare(
        'INSERT INTO accounting_bank_statement_lines
            (tenant_id, bank_account_id, posted_date, description, amount, bank_reference, fitid, match_status)
         VALUES (:t, :acc, :d, :desc, :amt, :ref, :fitid, "unmatched")
         ON DUPLICATE KEY UPDATE
            posted_date    = VALUES(posted_date),
            description    = VALUES(description),
            amount         = VALUES(amount),
            bank_reference = VALUES(bank_reference)'
    )->execute([
        't'     => $tenantId,
        'acc'   => $bankAccountId,
        'd'     => (string) ($t['date'] ?? date('Y-m-d')),
        'desc'  => substr((string) ($t['merchant_name'] ?? $t['name'] ?? ''), 0, 255),
        'amt'   => $signed,
        'ref'   => substr((string) ($t['payment_meta']['reference_number'] ?? ''), 0, 120) ?: null,
        'fitid' => $txnId,
    ]);
}

function _cron_mark_removed(\PDO $pdo, int $tenantId, int $bankAccountId, string $txnId): void
{
    if ($txnId === '') return;
    $pdo->prepare(
        'UPDATE accounting_bank_statement_lines SET match_status = "ignored"
         WHERE tenant_id = :t AND bank_account_id = :acc AND fitid = :f AND match_status = "unmatched"'
    )->execute(['t' => $tenantId, 'acc' => $bankAccountId, 'f' => $txnId]);
}
