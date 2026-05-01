<?php
/**
 * Accounting API — Chart of Accounts (CRUD)
 *
 *   GET    /api/accounting/accounts
 *   GET    /api/accounting/accounts?id=N
 *   POST   /api/accounting/accounts           {code,name,account_type,normal_side, parent_account_id?, is_postable?}
 *   PATCH  /api/accounting/accounts?id=N
 *   DELETE /api/accounting/accounts?id=N      (soft-deactivate)
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/accounting.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();

const ACCT_TYPES = ['asset','liability','equity','revenue','expense'];
const NORMAL_DEFAULT = ['asset' => 'debit','expense' => 'debit','liability' => 'credit','equity' => 'credit','revenue' => 'credit'];

if ($method === 'GET' && !empty($_GET['id'])) {
    RBAC::requirePermission($user, 'accounting.coa.view');
    $row = scopedFind('SELECT * FROM accounting_accounts WHERE tenant_id = :tenant_id AND id = :id', ['id' => (int) $_GET['id']]);
    if (!$row) api_error('Not found', 404);
    api_ok(['account' => $row]);
}

if ($method === 'GET') {
    RBAC::requirePermission($user, 'accounting.coa.view');
    $where  = ['tenant_id = :tenant_id'];
    $params = [];
    if (!empty($_GET['type']))   { $where[] = 'account_type = :t'; $params['t'] = $_GET['type']; }
    if (!empty($_GET['q']))      { $where[] = '(code LIKE :q OR name LIKE :q)'; $params['q'] = '%' . $_GET['q'] . '%'; }
    if (!empty($_GET['active'])) { $where[] = 'active = :a'; $params['a'] = (int) !!$_GET['active']; }
    $rows = scopedQuery(
        'SELECT id, code, name, account_type, normal_side, parent_account_id, is_postable, active
         FROM accounting_accounts WHERE ' . implode(' AND ', $where) . ' ORDER BY code ASC LIMIT 500',
        $params
    );
    api_ok(['rows' => $rows, 'types' => ACCT_TYPES]);
}

if ($method === 'POST') {
    RBAC::requirePermission($user, 'accounting.coa.manage');
    $body = api_json_body();
    api_require_fields($body, ['code','name','account_type']);
    if (!in_array($body['account_type'], ACCT_TYPES, true)) {
        api_error('Invalid account_type', 422, ['allowed' => ACCT_TYPES]);
    }
    $normal = $body['normal_side'] ?? NORMAL_DEFAULT[$body['account_type']];
    if (!in_array($normal, ['debit','credit'], true)) api_error('normal_side must be debit|credit', 422);

    $id = scopedInsert('accounting_accounts', [
        'tenant_id'         => $tid,
        'code'              => (string) $body['code'],
        'name'              => (string) $body['name'],
        'account_type'      => $body['account_type'],
        'normal_side'       => $normal,
        'parent_account_id' => !empty($body['parent_account_id']) ? (int) $body['parent_account_id'] : null,
        'is_postable'       => array_key_exists('is_postable', $body) ? (int) !!$body['is_postable'] : 1,
        'currency'          => $body['currency'] ?? null,
        'description'       => $body['description'] ?? null,
        'active'            => 1,
    ]);
    accountingAudit('accounting.account.created', ['id' => $id, 'code' => $body['code']], $id);
    api_ok(['id' => $id], 201);
}

if ($method === 'PATCH') {
    RBAC::requirePermission($user, 'accounting.coa.manage');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    $body = api_json_body();
    foreach (['id','tenant_id','created_at'] as $k) unset($body[$k]);
    if (isset($body['account_type']) && !in_array($body['account_type'], ACCT_TYPES, true)) {
        api_error('Invalid account_type', 422);
    }
    if (!$body) api_error('No fields to update', 422);
    $rows = scopedUpdate('accounting_accounts', $id, $body);
    if ($rows === 0) api_error('Not found or no change', 404);
    accountingAudit('accounting.account.updated', ['id' => $id, 'fields' => array_keys($body)], $id);
    api_ok(['ok' => true]);
}

if ($method === 'DELETE') {
    RBAC::requirePermission($user, 'accounting.coa.manage');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    $rows = scopedUpdate('accounting_accounts', $id, ['active' => 0]);
    if ($rows === 0) api_error('Not found', 404);
    accountingAudit('accounting.account.deactivated', ['id' => $id], $id);
    api_ok(['ok' => true]);
}

api_error('Method not allowed', 405);
