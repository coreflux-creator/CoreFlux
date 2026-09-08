<?php
/** Account workspace data and entity-specific commercial terms. */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/account_interest.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$tid = (int) $ctx['tenant_id'];
$method = api_method();
$action = (string) ($_GET['action'] ?? '');
$accountId = (int) ($_GET['account_id'] ?? 0);
$entityId = (int) ($_GET['entity_id'] ?? 0);

if ($accountId <= 0) api_error('account_id required', 400);
$account = scopedFind(
    'SELECT * FROM accounting_accounts WHERE tenant_id = :tenant_id AND id = :id',
    ['id' => $accountId]
);
if (!$account) api_error('Account not found', 404);

if ($method === 'GET') {
    rbac_legacy_require($user, 'accounting.coa.view');
    $entities = scopedQuery(
        'SELECT id, legal_name, base_currency, active
           FROM accounting_entities WHERE tenant_id = :tenant_id AND active = 1
          ORDER BY legal_name, id'
    );
    $terms = accountInterestTermsForLedgerAccount($tid, $accountId);
    $balances = [];
    foreach ($entities as $entity) {
        try {
            $balances[] = [
                'entity_id' => (int) $entity['id'],
                'legal_name' => (string) $entity['legal_name'],
                'currency' => (string) ($entity['base_currency'] ?: $account['currency'] ?: 'USD'),
                'balance' => accountInterestLedgerBalance($tid, (int) $entity['id'], $accountId, date('Y-m-d')),
            ];
        } catch (\Throwable $e) {
            $balances[] = [
                'entity_id' => (int) $entity['id'],
                'legal_name' => (string) $entity['legal_name'],
                'currency' => (string) ($entity['base_currency'] ?: $account['currency'] ?: 'USD'),
                'balance' => null,
            ];
        }
    }
    $offsetAccounts = scopedQuery(
        "SELECT id, code, name, account_type
           FROM accounting_accounts
          WHERE tenant_id = :tenant_id AND active = 1 AND is_postable = 1
            AND account_type IN ('revenue','expense') ORDER BY code"
    );
    $postingAccounts = scopedQuery(
        "SELECT id, code, name, account_type
           FROM accounting_accounts
          WHERE tenant_id = :tenant_id AND active = 1 AND is_postable = 1
            AND account_type IN ('asset','liability') ORDER BY code"
    );
    $runs = scopedQuery(
        'SELECT r.*, e.legal_name AS entity_name, je.je_number,
                pa.code AS posting_account_code, pa.name AS posting_account_name,
                oa.code AS offset_account_code, oa.name AS offset_account_name
           FROM accounting_account_interest_runs r
           JOIN accounting_entities e ON e.id = r.entity_id AND e.tenant_id = r.tenant_id
           LEFT JOIN accounting_journal_entries je ON je.id = r.journal_entry_id AND je.tenant_id = r.tenant_id
           LEFT JOIN accounting_accounts pa ON pa.id = r.posting_account_id AND pa.tenant_id = r.tenant_id
           LEFT JOIN accounting_accounts oa ON oa.id = r.offset_account_id AND oa.tenant_id = r.tenant_id
          WHERE r.tenant_id = :tenant_id AND r.account_id = :account_id
          ORDER BY r.period_end DESC, r.id DESC LIMIT 24',
        ['account_id' => $accountId]
    );
    $bankLinks = scopedQuery(
        'SELECT id, name, bank_name, last4, feed_provider, status, entity_id
           FROM accounting_bank_accounts
          WHERE tenant_id = :tenant_id AND gl_account_code = :code ORDER BY status, name',
        ['code' => $account['code']]
    );
    $liabilityLinks = [];
    try {
        $liabilityLinks = scopedQuery(
            'SELECT id, account_id, subtype, institution_name, last4
               FROM treasury_liability_accounts
              WHERE tenant_id = :tenant_id AND account_id = :account_id ORDER BY id',
            ['account_id' => $accountId]
        );
    } catch (\Throwable $e) {
        // Treasury is optional for accounting-only tenants.
    }
    api_ok([
        'account' => $account,
        'entities' => $entities,
        'balances' => $balances,
        'terms' => $terms,
        'offset_accounts' => $offsetAccounts,
        'posting_accounts' => $postingAccounts,
        'interest_runs' => $runs,
        'connections' => ['bank_accounts' => $bankLinks, 'liability_accounts' => $liabilityLinks],
    ]);
}

if ($method === 'PUT') {
    rbac_legacy_require($user, 'accounting.coa.manage');
    $body = api_json_body();
    $entityId = (int) ($body['entity_id'] ?? $entityId);
    if ($entityId <= 0) api_error('Choose a legal entity', 422);
    $entity = scopedFind(
        'SELECT id FROM accounting_entities WHERE tenant_id = :tenant_id AND id = :id AND active = 1',
        ['id' => $entityId]
    );
    if (!$entity) api_error('Legal entity not found', 404);

    $enabled = !empty($body['interest_enabled']) ? 1 : 0;
    $direction = (string) ($body['interest_direction'] ?? 'earned');
    $balanceMethod = (string) ($body['balance_method'] ?? 'average_daily_balance');
    $dayCountBasis = (string) ($body['day_count_basis'] ?? 'actual_365');
    $cadence = (string) ($body['posting_cadence'] ?? 'monthly');
    $rate = (float) ($body['annual_rate_percent'] ?? 0);
    $postingAccountId = !empty($body['posting_account_id']) ? (int) $body['posting_account_id'] : $accountId;
    $offsetAccountId = !empty($body['offset_account_id']) ? (int) $body['offset_account_id'] : null;
    $autoPost = array_key_exists('auto_post', $body) ? (int) !!$body['auto_post'] : 1;

    if (!in_array($direction, ['earned','charged'], true)) api_error('Invalid interest direction', 422);
    if (!in_array($balanceMethod, ['average_daily_balance','closing_balance'], true)) api_error('Invalid balance method', 422);
    if (!in_array($dayCountBasis, ['actual_365','actual_360'], true)) api_error('Invalid day-count basis', 422);
    if (!in_array($cadence, ['weekly','monthly','quarterly','annual'], true)) api_error('Invalid posting cadence', 422);
    if ($rate < 0 || $rate > 1000) api_error('Annual rate must be between 0% and 1000%', 422);
    if ($enabled && !in_array($account['account_type'], ['asset','liability'], true)) {
        api_error('Automatic interest applies to asset and liability accounts', 422);
    }
    if ($enabled && $rate <= 0) api_error('Enter an annual interest rate greater than 0%', 422);
    if ($enabled && empty($body['next_post_date'])) api_error('Choose the next interest posting date', 422);

    foreach (['effective_from','maturity_date','next_post_date'] as $dateField) {
        if (!empty($body[$dateField])) {
            try { accountInterestDate((string) $body[$dateField]); }
            catch (\InvalidArgumentException $e) { api_error(ucfirst(str_replace('_', ' ', $dateField)) . ' must be YYYY-MM-DD', 422); }
        }
    }
    if (!empty($body['effective_from']) && !empty($body['maturity_date']) && $body['maturity_date'] < $body['effective_from']) {
        api_error('Maturity date must be on or after the effective date', 422);
    }
    if ($enabled && !empty($body['effective_from']) && $body['next_post_date'] < $body['effective_from']) {
        api_error('Next posting date must be on or after the effective date', 422);
    }

    if ($enabled) {
        $posting = scopedFind(
            "SELECT id FROM accounting_accounts
              WHERE tenant_id = :tenant_id AND id = :id AND active = 1 AND is_postable = 1
                AND account_type IN ('asset','liability')",
            ['id' => $postingAccountId]
        );
        if (!$posting) api_error('Choose an active asset or liability account for the interest posting', 422);
        $requiredOffsetType = $direction === 'earned' ? 'revenue' : 'expense';
        $offset = $offsetAccountId ? scopedFind(
            'SELECT id, account_type FROM accounting_accounts
              WHERE tenant_id = :tenant_id AND id = :id AND active = 1 AND is_postable = 1',
            ['id' => $offsetAccountId]
        ) : null;
        if (!$offset || $offset['account_type'] !== $requiredOffsetType) {
            api_error($direction === 'earned'
                ? 'Choose an active income account for interest earned'
                : 'Choose an active expense account for interest charged', 422);
        }
    }

    $text = static fn(string $field, int $max): ?string =>
        ($value = trim((string) ($body[$field] ?? ''))) !== '' ? substr($value, 0, $max) : null;
    $params = [
        'tenant_id' => $tid,
        'account_id' => $accountId,
        'entity_id' => $entityId,
        'interest_enabled' => $enabled,
        'interest_direction' => $direction,
        'annual_rate_percent' => $rate,
        'balance_method' => $balanceMethod,
        'day_count_basis' => $dayCountBasis,
        'posting_cadence' => $cadence,
        'next_post_date' => !empty($body['next_post_date']) ? (string) $body['next_post_date'] : null,
        'auto_post' => $autoPost,
        'posting_account_id' => $postingAccountId,
        'offset_account_id' => $offsetAccountId,
        'effective_from' => !empty($body['effective_from']) ? (string) $body['effective_from'] : null,
        'maturity_date' => !empty($body['maturity_date']) ? (string) $body['maturity_date'] : null,
        'counterparty_name' => $text('counterparty_name', 180),
        'agreement_reference' => $text('agreement_reference', 120),
        'terms_note' => $text('terms_note', 12000),
    ];
    getDB()->prepare(
        'INSERT INTO accounting_account_terms
            (tenant_id, account_id, entity_id, interest_enabled, interest_direction,
             annual_rate_percent, balance_method, day_count_basis, posting_cadence,
             next_post_date, auto_post, posting_account_id, offset_account_id,
             effective_from, maturity_date, counterparty_name, agreement_reference,
             terms_note, created_at)
         VALUES
            (:tenant_id, :account_id, :entity_id, :interest_enabled, :interest_direction,
             :annual_rate_percent, :balance_method, :day_count_basis, :posting_cadence,
             :next_post_date, :auto_post, :posting_account_id, :offset_account_id,
             :effective_from, :maturity_date, :counterparty_name, :agreement_reference,
             :terms_note, NOW())
         ON DUPLICATE KEY UPDATE
             interest_enabled = VALUES(interest_enabled), interest_direction = VALUES(interest_direction),
             annual_rate_percent = VALUES(annual_rate_percent), balance_method = VALUES(balance_method),
             day_count_basis = VALUES(day_count_basis), posting_cadence = VALUES(posting_cadence),
             next_post_date = VALUES(next_post_date), auto_post = VALUES(auto_post),
             posting_account_id = VALUES(posting_account_id), offset_account_id = VALUES(offset_account_id),
             effective_from = VALUES(effective_from), maturity_date = VALUES(maturity_date),
             counterparty_name = VALUES(counterparty_name), agreement_reference = VALUES(agreement_reference),
             terms_note = VALUES(terms_note), updated_at = NOW()'
    )->execute($params);
    accountingAudit('accounting.account.terms_updated', [
        'account_id' => $accountId, 'entity_id' => $entityId,
        'interest_enabled' => (bool) $enabled, 'annual_rate_percent' => $rate,
        'posting_cadence' => $cadence, 'next_post_date' => $params['next_post_date'],
    ], $accountId);
    api_ok(['ok' => true, 'terms' => accountInterestTermsForLedgerAccount($tid, $accountId, $entityId)[0] ?? null]);
}

if ($method === 'POST' && $action === 'run_due') {
    rbac_legacy_require($user, 'accounting.recurring.manage');
    if ($entityId <= 0) api_error('entity_id required', 400);
    $terms = accountInterestTermsForLedgerAccount($tid, $accountId, $entityId)[0] ?? null;
    if (!$terms) api_error('Save account terms first', 404);
    try {
        api_ok(accountInterestRunOnce($tid, (int) $terms['id'], isset($user['id']) ? (int) $user['id'] : null));
    } catch (\Throwable $e) {
        api_error('Interest run failed: ' . $e->getMessage(), 422);
    }
}

api_error('Method not allowed', 405);
