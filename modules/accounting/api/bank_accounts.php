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
require_once __DIR__ . '/../lib/accounting.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$method = api_method();
$action = $_GET['action'] ?? '';

if ($method === 'GET' && !empty($_GET['id'])) {
    rbac_legacy_require($user, 'accounting.coa.view');
    $id  = (int) $_GET['id'];
    $row = scopedFind(
        'SELECT * FROM accounting_bank_accounts WHERE tenant_id = :tenant_id AND id = :id',
        ['id' => $id]
    );
    if (!$row) api_error('Not found', 404);
    unset($row['plaid_access_token_ct']);  // never expose the cipher
    $unmatched = scopedQuery(
        'SELECT COUNT(*) AS c FROM accounting_bank_statement_lines
         WHERE tenant_id = :tenant_id AND bank_account_id = :id AND match_status = "unmatched"',
        ['id' => $id]
    );
    api_ok(['account' => $row, 'unmatched_line_count' => (int) ($unmatched[0]['c'] ?? 0)]);
}

if ($method === 'GET') {
    rbac_legacy_require($user, 'accounting.coa.view');
    // Sprint 6f — default to active accounts only so the list isn't cluttered
    // by accounts the user closed (or accidentally connected via Plaid and
    // never used). Pass ?include_closed=1 to see everything, or ?status=closed
    // to filter to the archive itself.
    $statusFilter = (string) ($_GET['status'] ?? '');
    $includeClosed = !empty($_GET['include_closed']);
    $where = ['tenant_id = :tenant_id'];
    $params = [];
    if ($statusFilter) {
        $where[] = 'status = :s';
        $params['s'] = $statusFilter;
    } elseif (!$includeClosed) {
        $where[] = "status <> 'closed'";
    }
    $rows = scopedQuery(
        'SELECT id, entity_id, name, gl_account_code, bank_name, last4, currency,
                feed_provider, last_feed_synced_at, plaid_account_id, status, created_at
         FROM accounting_bank_accounts
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY status, name',
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
    $id = scopedInsert('accounting_bank_accounts', [
        'entity_id'        => isset($body['entity_id']) ? (int) $body['entity_id'] : null,
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

if ($method === 'PUT') {
    rbac_legacy_require($user, 'accounting.coa.edit');
    $id   = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    $body = api_json_body();
    $allowed = ['name','gl_account_code','bank_name','routing_number','last4','currency','feed_provider','plaid_account_id','status'];
    $data = [];
    foreach ($allowed as $f) if (array_key_exists($f, $body)) $data[$f] = $body[$f];
    if ($data) scopedUpdate('accounting_bank_accounts', $id, $data);
    accountingAudit('accounting.bank_account.updated', ['fields' => array_keys($data)], $id);
    api_ok(['ok' => true]);
}

api_error('Method not allowed', 405);
