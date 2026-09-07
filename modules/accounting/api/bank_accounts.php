<?php
/**
 * Accounting API — Bank accounts CRUD.
 *
 *   GET  /api/accounting/bank_accounts                       → list active accounts
 *   GET  /api/accounting/bank_accounts?id=N                  → detail (including unmatched line count + last-rec status)
 *   POST /api/accounting/bank_accounts                       → create
 *   PUT  /api/accounting/bank_accounts?id=N                  → update
 *   POST /api/accounting/bank_accounts?action=close&id=N     → set status=closed
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/active_entity.php';
require_once __DIR__ . '/../lib/accounting.php';
require_once __DIR__ . '/../lib/account_interest.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$method = api_method();
$action = $_GET['action'] ?? '';

if ($method === 'GET' && !empty($_GET['id'])) {
    rbac_legacy_require($user, 'accounting.coa.view');
    $id  = (int) $_GET['id'];
    $row = scopedFind(
        'SELECT ba.*, aa.account_type AS gl_account_type, aa.normal_side AS gl_normal_side
           FROM accounting_bank_accounts ba
           LEFT JOIN accounting_accounts aa
             ON aa.tenant_id = ba.tenant_id AND aa.code = ba.gl_account_code
          WHERE ba.tenant_id = :tenant_id AND ba.id = :id',
        ['id' => $id]
    );
    if (!$row) api_error('Not found', 404);
    unset($row['plaid_access_token_ct']);  // never expose the cipher
    $unmatched = scopedQuery(
        'SELECT COUNT(*) AS c FROM accounting_bank_statement_lines
         WHERE tenant_id = :tenant_id AND bank_account_id = :id AND match_status = "unmatched"',
        ['id' => $id]
    );
    $offsetAccounts = scopedQuery(
        "SELECT id, code, name, account_type
           FROM accounting_accounts
          WHERE tenant_id = :tenant_id AND active = 1 AND is_postable = 1
            AND account_type IN ('revenue','other_income','expense','other_expense','cost_of_goods_sold')
          ORDER BY code"
    );
    $recentRuns = scopedQuery(
        'SELECT r.*, je.je_number, oa.code AS offset_account_code, oa.name AS offset_account_name
           FROM accounting_bank_interest_runs r
           LEFT JOIN accounting_journal_entries je
             ON je.id = r.journal_entry_id AND je.tenant_id = r.tenant_id
           LEFT JOIN accounting_accounts oa
             ON oa.id = r.offset_account_id AND oa.tenant_id = r.tenant_id
          WHERE r.tenant_id = :tenant_id AND r.bank_account_id = :id
          ORDER BY r.period_end DESC, r.id DESC LIMIT 12',
        ['id' => $id]
    );
    api_ok([
        'account' => $row,
        'unmatched_line_count' => (int) ($unmatched[0]['c'] ?? 0),
        'interest_terms' => accountInterestTermsForAccount((int) $ctx['tenant_id'], $id),
        'interest_offset_accounts' => $offsetAccounts,
        'interest_runs' => $recentRuns,
    ]);
}

if ($method === 'GET') {
    rbac_legacy_require($user, 'accounting.coa.view');
    // Sprint 6f — default to active accounts only so the list isn't cluttered
    // by accounts the user closed (or accidentally connected via Plaid and
    // never used). Pass ?include_closed=1 to see everything, or ?status=closed
    // to filter to the archive itself.
    $statusFilter = (string) ($_GET['status'] ?? '');
    $includeClosed = !empty($_GET['include_closed']);
    $where = ['accounting_bank_accounts.tenant_id = :tenant_id'];
    $params = [];
    if ($statusFilter) {
        $where[] = 'accounting_bank_accounts.status = :s';
        $params['s'] = $statusFilter;
    } elseif (!$includeClosed) {
        $where[] = "accounting_bank_accounts.status <> 'closed'";
    }
    $rows = scopedQuery(
        'SELECT accounting_bank_accounts.id, accounting_bank_accounts.entity_id,
                accounting_bank_accounts.name, accounting_bank_accounts.gl_account_code,
                accounting_bank_accounts.bank_name, accounting_bank_accounts.last4,
                accounting_bank_accounts.currency, accounting_bank_accounts.feed_provider,
                accounting_bank_accounts.last_feed_synced_at, accounting_bank_accounts.plaid_account_id,
                accounting_bank_accounts.status, accounting_bank_accounts.created_at,
                COALESCE(terms.interest_enabled, 0) AS interest_enabled,
                terms.annual_rate_percent, terms.interest_direction
           FROM accounting_bank_accounts
           LEFT JOIN accounting_bank_account_terms terms
             ON terms.tenant_id = accounting_bank_accounts.tenant_id
            AND terms.bank_account_id = accounting_bank_accounts.id
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY accounting_bank_accounts.status, accounting_bank_accounts.name',
        $params
    );
    // Surface counts so the UI can show "12 active · 3 closed".
    $countStmt = scopedQuery(
        'SELECT status, COUNT(*) AS c FROM accounting_bank_accounts
          WHERE tenant_id = :tenant_id GROUP BY status'
    );
    $counts = [];
    foreach ($countStmt as $r) { $counts[$r['status']] = (int) $r['c']; }
    api_ok(['rows' => $rows, 'counts' => $counts]);
}

if ($method === 'POST' && $action === 'reopen') {
    rbac_legacy_require($user, 'accounting.coa.edit');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    scopedUpdate('accounting_bank_accounts', $id, ['status' => 'active']);
    accountingAudit('accounting.bank_account.reopened', [], $id);
    api_ok(['ok' => true]);
}

if ($method === 'POST' && $action === 'close') {
    rbac_legacy_require($user, 'accounting.coa.edit');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    scopedUpdate('accounting_bank_accounts', $id, ['status' => 'closed']);
    accountingAudit('accounting.bank_account.closed', [], $id);
    api_ok(['ok' => true]);
}

if ($method === 'POST') {
    rbac_legacy_require($user, 'accounting.coa.edit');
    $body = api_json_body();
    api_require_fields($body, ['name', 'gl_account_code']);
    try {
        $entity = activeEntityResolveForTenant(
            (int) $ctx['tenant_id'],
            !empty($body['entity_id']) ? (int) $body['entity_id'] : null
        );
    } catch (\Throwable $e) {
        api_error($e->getMessage(), 422);
    }
    if (!$entity) api_error('No accounting entity is configured for this tenant', 422);
    $id = scopedInsert('accounting_bank_accounts', [
        'entity_id'        => (int) $entity['id'],
        'name'             => (string) $body['name'],
        'gl_account_code'  => (string) $body['gl_account_code'],
        'bank_name'        => $body['bank_name']      ?? null,
        'routing_number'   => $body['routing_number'] ?? null,
        'last4'            => $body['last4']          ?? null,
        'currency'         => $body['currency']       ?? 'USD',
        'feed_provider'    => $body['feed_provider']  ?? null,
        'plaid_account_id' => $body['plaid_account_id'] ?? null,
    ]);
    accountingAudit('accounting.bank_account.created', ['name' => $body['name']], $id);
    api_ok(['id' => $id], 201);
}

if ($method === 'PUT' && $action === 'terms') {
    rbac_legacy_require($user, 'accounting.coa.edit');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    $bankAccount = scopedFind(
        'SELECT id FROM accounting_bank_accounts WHERE tenant_id = :tenant_id AND id = :id',
        ['id' => $id]
    );
    if (!$bankAccount) api_error('Bank account not found', 404);

    $body = api_json_body();
    $enabled = !empty($body['interest_enabled']) ? 1 : 0;
    $direction = (string) ($body['interest_direction'] ?? 'earned');
    $balanceMethod = (string) ($body['balance_method'] ?? 'average_daily_balance');
    $dayCountBasis = (string) ($body['day_count_basis'] ?? 'actual_365');
    $cadence = (string) ($body['statement_cadence'] ?? 'monthly');
    $rate = (float) ($body['annual_rate_percent'] ?? 0);
    $offsetAccountId = !empty($body['offset_account_id']) ? (int) $body['offset_account_id'] : null;

    if (!in_array($direction, ['earned','charged'], true)) api_error('Invalid interest direction', 422);
    if (!in_array($balanceMethod, ['average_daily_balance','closing_balance'], true)) api_error('Invalid balance method', 422);
    if (!in_array($dayCountBasis, ['actual_365','actual_360'], true)) api_error('Invalid day-count basis', 422);
    if (!in_array($cadence, ['monthly','quarterly','annual','custom'], true)) api_error('Invalid statement cadence', 422);
    if ($rate < 0 || $rate > 1000) api_error('Annual rate must be between 0% and 1000%', 422);
    if ($enabled && $rate <= 0) api_error('Enter an annual interest rate greater than 0%', 422);

    foreach (['effective_from','maturity_date'] as $dateField) {
        if (!empty($body[$dateField])) {
            try { accountInterestDate((string) $body[$dateField]); }
            catch (\InvalidArgumentException $e) { api_error(str_replace('Date', ucfirst(str_replace('_', ' ', $dateField)), $e->getMessage()), 422); }
        }
    }
    if (!empty($body['effective_from']) && !empty($body['maturity_date']) && $body['maturity_date'] < $body['effective_from']) {
        api_error('Maturity date must be on or after the effective date', 422);
    }

    if ($enabled && !$offsetAccountId) {
        $defaultCode = $direction === 'earned' ? '7100' : '9100';
        $default = scopedFind(
            'SELECT id FROM accounting_accounts
              WHERE tenant_id = :tenant_id AND code = :code AND active = 1 AND is_postable = 1 LIMIT 1',
            ['code' => $defaultCode]
        );
        $offsetAccountId = $default ? (int) $default['id'] : null;
    }
    if ($enabled) {
        $allowedTypes = $direction === 'earned'
            ? ['revenue','other_income']
            : ['expense','other_expense','cost_of_goods_sold'];
        $offset = scopedFind(
            'SELECT id, account_type FROM accounting_accounts
              WHERE tenant_id = :tenant_id AND id = :id AND active = 1 AND is_postable = 1',
            ['id' => $offsetAccountId]
        );
        if (!$offset || !in_array($offset['account_type'], $allowedTypes, true)) {
            api_error($direction === 'earned'
                ? 'Choose an active income account for interest earned'
                : 'Choose an active expense account for interest charged', 422);
        }
    }

    $params = [
        'tenant_id' => (int) $ctx['tenant_id'],
        'bank_account_id' => $id,
        'statement_cadence' => $cadence,
        'interest_enabled' => $enabled,
        'interest_direction' => $direction,
        'annual_rate_percent' => $rate,
        'balance_method' => $balanceMethod,
        'day_count_basis' => $dayCountBasis,
        'offset_account_id' => $offsetAccountId,
        'effective_from' => !empty($body['effective_from']) ? (string) $body['effective_from'] : null,
        'maturity_date' => !empty($body['maturity_date']) ? (string) $body['maturity_date'] : null,
        'terms_note' => ($note = trim((string) ($body['terms_note'] ?? ''))) !== '' ? substr($note, 0, 4000) : null,
    ];
    getDB()->prepare(
        'INSERT INTO accounting_bank_account_terms
            (tenant_id, bank_account_id, statement_cadence, interest_enabled,
             interest_direction, annual_rate_percent, balance_method, day_count_basis,
             offset_account_id, effective_from, maturity_date, terms_note, created_at)
         VALUES
            (:tenant_id, :bank_account_id, :statement_cadence, :interest_enabled,
             :interest_direction, :annual_rate_percent, :balance_method, :day_count_basis,
             :offset_account_id, :effective_from, :maturity_date, :terms_note, NOW())
         ON DUPLICATE KEY UPDATE
             statement_cadence = VALUES(statement_cadence),
             interest_enabled = VALUES(interest_enabled),
             interest_direction = VALUES(interest_direction),
             annual_rate_percent = VALUES(annual_rate_percent),
             balance_method = VALUES(balance_method), day_count_basis = VALUES(day_count_basis),
             offset_account_id = VALUES(offset_account_id), effective_from = VALUES(effective_from),
             maturity_date = VALUES(maturity_date), terms_note = VALUES(terms_note), updated_at = NOW()'
    )->execute($params);
    accountingAudit('accounting.bank_account.terms_updated', [
        'bank_account_id' => $id,
        'interest_enabled' => (bool) $enabled,
        'interest_direction' => $direction,
        'annual_rate_percent' => $rate,
        'balance_method' => $balanceMethod,
        'day_count_basis' => $dayCountBasis,
    ], $id);
    api_ok(['ok' => true, 'interest_terms' => accountInterestTermsForAccount((int) $ctx['tenant_id'], $id)]);
}

if ($method === 'PUT') {
    rbac_legacy_require($user, 'accounting.coa.edit');
    $id   = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    $body = api_json_body();
    if (array_key_exists('entity_id', $body)) {
        try {
            $entity = activeEntityResolveForTenant((int) $ctx['tenant_id'], (int) $body['entity_id']);
            if (!$entity) api_error('No accounting entity is configured for this tenant', 422);
            $body['entity_id'] = (int) $entity['id'];
        } catch (\Throwable $e) {
            api_error($e->getMessage(), 422);
        }
    }
    $allowed = ['entity_id','name','gl_account_code','bank_name','routing_number','last4','currency','feed_provider','plaid_account_id','status'];
    $data = [];
    foreach ($allowed as $f) if (array_key_exists($f, $body)) $data[$f] = $body[$f];
    if ($data) scopedUpdate('accounting_bank_accounts', $id, $data);
    accountingAudit('accounting.bank_account.updated', ['fields' => array_keys($data)], $id);
    api_ok(['ok' => true]);
}

api_error('Method not allowed', 405);
