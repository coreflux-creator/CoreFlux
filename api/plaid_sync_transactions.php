<?php
/**
 * Plaid — POST /api/plaid_sync_transactions
 *
 * Cursor-paginated /transactions/sync against a linked Plaid Item, persisting
 * added/modified rows to accounting_bank_statement_lines (fitid = transaction_id),
 * mirroring removed rows by marking match_status='ignored'.
 *
 * Mutation-during-pagination handling: if Plaid returns
 * TRANSACTIONS_SYNC_MUTATION_DURING_PAGINATION, we restart from the saved
 * cursor (NOT the failed page's cursor) once. After that, we surface the
 * error to the caller for retry on next run.
 *
 * Body:
 *   item_id:                     string  (Plaid item_id)
 *   accounting_bank_account_id?: int     (overrides the item's bound bank_account_id)
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

$bankAccountId = (int) ($body['accounting_bank_account_id'] ?? $item['accounting_bank_account_id'] ?? 0);
if ($bankAccountId <= 0) api_error('accounting_bank_account_id required (item not bound to a bank account)', 422);

$accessToken = plaidDecryptAccessToken($item['access_token_ct']);
if (!$accessToken) api_error('Could not decrypt access token', 500);

$cursor = $item['transactions_cursor'];
$results = ['added' => 0, 'modified' => 0, 'removed' => 0, 'pages' => 0];
$retried = false;

while (true) {
    try {
        $resp = plaidSyncTransactions($accessToken, $cursor);
    } catch (PlaidApiException $e) {
        if (!$retried && stripos($e->errorCode, 'MUTATION_DURING_PAGINATION') !== false) {
            $retried = true;
            $cursor  = $item['transactions_cursor'];   // restart from the original
            $results = ['added' => 0, 'modified' => 0, 'removed' => 0, 'pages' => 0];
            continue;
        }
        plaidAudit('core.plaid.transactions_sync_failed', [
            'item_id' => $itemIdExt, 'error_code' => $e->errorCode,
        ], (int) $item['id']);
        api_error('Plaid sync failed: ' . $e->getMessage(), 502, ['plaid_error_code' => $e->errorCode]);
    }
    $results['pages']++;

    foreach (($resp['added'] ?? []) as $t)    { _plaidUpsertTxn($bankAccountId, $t, false); $results['added']++; }
    foreach (($resp['modified'] ?? []) as $t) { _plaidUpsertTxn($bankAccountId, $t, true);  $results['modified']++; }
    foreach (($resp['removed'] ?? []) as $r)  { _plaidMarkRemoved($bankAccountId, (string) ($r['transaction_id'] ?? '')); $results['removed']++; }

    $cursor = (string) ($resp['next_cursor'] ?? $cursor);
    if (empty($resp['has_more'])) break;
    if ($results['pages'] > 200)  api_error('Sync paginated past 200 pages — aborting', 500);
}

scopedUpdate('plaid_items', (int) $item['id'], [
    'transactions_cursor'      => $cursor,
    'last_transaction_sync_at' => date('Y-m-d H:i:s'),
]);

plaidAudit('core.plaid.transactions_synced', [
    'item_id' => $itemIdExt, 'bank_account_id' => $bankAccountId, 'results' => $results,
], (int) $item['id']);

api_ok($results);

// ---------------------------------------------------------------- helpers
function _plaidUpsertTxn(int $bankAccountId, array $t, bool $isModification): void
{
    $tenantId = currentTenantId();
    $pdo = getDB();
    $txnId = (string) ($t['transaction_id'] ?? '');
    if ($txnId === '') return;
    // Plaid 'amount' is positive for outflow; bank_statement_lines.amount uses
    // signed convention (+credit / -debit), so flip sign for sane bank rec.
    $signed = round(((float) ($t['amount'] ?? 0)) * -1, 2);
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
        'acc'   => $bankAccountId,
        'd'     => (string) ($t['date'] ?? date('Y-m-d')),
        'desc'  => substr((string) ($t['merchant_name'] ?? $t['name'] ?? ''), 0, 255),
        'amt'   => $signed,
        'ref'   => substr((string) ($t['payment_meta']['reference_number'] ?? ''), 0, 120) ?: null,
        'fitid' => $txnId,
    ]);
}

function _plaidMarkRemoved(int $bankAccountId, string $txnId): void
{
    if ($txnId === '') return;
    $pdo = getDB();
    $pdo->prepare(
        'UPDATE accounting_bank_statement_lines
         SET match_status = "ignored"
         WHERE tenant_id = :t AND bank_account_id = :acc AND fitid = :f
           AND match_status = "unmatched"'
    )->execute(['t' => currentTenantId(), 'acc' => $bankAccountId, 'f' => $txnId]);
}
