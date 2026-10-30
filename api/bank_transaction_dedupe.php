<?php
/** Preview similar bank lines and record fact-bound review decisions. */
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
    api_error('Bulk bank-line repair is unavailable. Review each candidate against its bank and source records.', 409);
}

if (api_method() === 'POST' && (string) ($_GET['action'] ?? '') === 'review_pair') {
    $body = api_json_body();
    $accountId = (int) ($body['account_id'] ?? 0);
    if ($accountId <= 0) api_error('account_id required', 422);
    $account = scopedFind(
        'SELECT id, name FROM accounting_bank_accounts WHERE tenant_id = :tenant_id AND id = :id LIMIT 1',
        ['id' => $accountId]
    );
    if (!$account) api_error('Bank account not found', 404);

    try {
        $result = bankTxnDecideReview(
            $pdo,
            $tenantId,
            $accountId,
            (int) ($body['first_line_id'] ?? 0),
            (int) ($body['second_line_id'] ?? 0),
            (string) ($body['fingerprint'] ?? ''),
            (string) ($body['decision'] ?? ''),
            (string) ($body['reason'] ?? ''),
            isset($body['evidence_ref']) ? (string) $body['evidence_ref'] : null,
            (int) ($ctx['user']['id'] ?? 0)
        );
    } catch (InvalidArgumentException $e) {
        api_error($e->getMessage(), 422);
    } catch (DomainException $e) {
        api_error($e->getMessage(), 409);
    }

    if (!$result['idempotent_replay']) {
        plaidAudit('treasury.bank_lines.reviewed', [
            'bank_account_id' => $accountId,
            'first_line_id' => (int) ($body['first_line_id'] ?? 0),
            'second_line_id' => (int) ($body['second_line_id'] ?? 0),
            'decision' => (string) ($body['decision'] ?? ''),
            'review_id' => $result['id'],
        ], null);
    }
    api_ok(['ok' => true, 'account_id' => $accountId, 'review' => $result]
        + bankTxnDuplicatePreview($pdo, $tenantId, $accountId));
}

api_error('Method not allowed', 405);
