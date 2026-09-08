<?php
/** Preview or repair duplicate bank-feed transaction rows for one account. */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/plaid_service.php';
require_once __DIR__ . '/../core/treasury/bank_transaction_dedupe.php';

$ctx = api_require_auth();
$tenantId = (int) $ctx['tenant_id'];
rbac_legacy_require($ctx['user'], 'accounting.bank.manage');
$pdo = getDB();

if (api_method() === 'GET') {
    $accountId = (int) ($_GET['account_id'] ?? 0);
    if ($accountId <= 0) api_error('account_id required', 422);
    $account = scopedFind(
        'SELECT id, name FROM accounting_bank_accounts WHERE tenant_id = :tenant_id AND id = :id LIMIT 1',
        ['id' => $accountId]
    );
    if (!$account) api_error('Bank account not found', 404);
    api_ok(['account_id' => $accountId] + bankTxnDuplicatePreview($pdo, $tenantId, $accountId));
}

if (api_method() === 'POST' && (string) ($_GET['action'] ?? '') === 'run') {
    $body = api_json_body();
    $accountId = (int) ($body['account_id'] ?? 0);
    if ($accountId <= 0) api_error('account_id required', 422);
    $account = scopedFind(
        'SELECT id, name FROM accounting_bank_accounts WHERE tenant_id = :tenant_id AND id = :id LIMIT 1',
        ['id' => $accountId]
    );
    if (!$account) api_error('Bank account not found', 404);

    $result = bankTxnRepairDuplicates(
        $pdo,
        $tenantId,
        $accountId,
        (int) ($ctx['user']['id'] ?? 0) ?: null
    );
    plaidAudit('treasury.bank_transactions.deduplicated', [
        'bank_account_id' => $accountId,
        'result' => $result,
    ], null);
    api_ok(['ok' => true, 'account_id' => $accountId] + $result);
}

api_error('Method not allowed', 405);
