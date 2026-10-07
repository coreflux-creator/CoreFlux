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
require_once __DIR__ . '/../../../core/accounting/account_mutation.php';
require_once __DIR__ . '/../lib/accounting.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();
$action = (string) ($_GET['action'] ?? '');

if ($method === 'POST' && $action === 'auto_group_plaid') {
    rbac_legacy_require($user, 'accounting.coa.manage');
    require_once __DIR__ . '/../../../core/plaid_service.php';

    $pdo = getDB();

    // Find every Plaid-mirrored liability with no parent yet.
    $rows = $pdo->prepare(
        "SELECT aa.id AS aa_id, aa.code, aa.name, tla.institution_name
           FROM accounting_accounts aa
           JOIN treasury_liability_accounts tla
             ON tla.tenant_id = aa.tenant_id AND tla.account_id = aa.id
          WHERE aa.tenant_id = :t
            AND aa.parent_account_id IS NULL
            AND tla.institution_name IS NOT NULL
            AND tla.institution_name <> ''"
    );
    $rows->execute(['t' => $tid]);
    $rows = $rows->fetchAll(PDO::FETCH_ASSOC);

    $reparented = []; $errors = [];
    foreach ($rows as $r) {
        try {
            $parentId = plaidEnsureInstitutionParent($pdo, $tid, $r['institution_name'], '2100');
            if ($parentId && $parentId !== (int) $r['aa_id']) {
                $upd = $pdo->prepare(
                    'UPDATE accounting_accounts SET parent_account_id = :pid
                      WHERE tenant_id = :t AND id = :id'
                );
                $upd->execute(['pid' => $parentId, 't' => $tid, 'id' => $r['aa_id']]);
                $reparented[] = [
                    'id' => (int) $r['aa_id'], 'code' => $r['code'], 'name' => $r['name'],
                    'parent_id' => $parentId, 'institution' => $r['institution_name'],
                ];
            }
        } catch (\Throwable $e) {
            $errors[] = "{$r['name']}: " . $e->getMessage();
        }
    }

    accountingAudit('accounting.coa.auto_grouped_plaid', [
        'reparented' => count($reparented), 'errors' => count($errors),
    ], null);

    api_ok([
        'reparented' => $reparented,
        'errors'     => $errors,
        'count'      => count($reparented),
    ]);
}

if ($method === 'GET' && $action === 'tree') {
    rbac_legacy_require($user, 'accounting.coa.view');
    $type = $_GET['type'] ?? null;
    $where  = ['tenant_id = :tenant_id', 'active = 1'];
    $params = [];
    if ($type && in_array($type, ACCOUNTING_ACCOUNT_TYPES, true)) {
        $where[] = 'account_type = :t';
        $params['t'] = $type;
    }
    $flat = scopedQuery(
        'SELECT id, code, name, account_type, normal_side, parent_account_id, is_postable, currency,
                EXISTS (SELECT 1 FROM accounting_bank_accounts linked
                         WHERE linked.tenant_id = accounting_accounts.tenant_id
                           AND linked.gl_account_code = accounting_accounts.code) AS is_bank_linked
           FROM accounting_accounts WHERE ' . implode(' AND ', $where) . '
          ORDER BY code ASC LIMIT 1000',
        $params
    );
    foreach ($flat as &$account) {
        $account['direct_category_eligible'] = accountingDirectCategoryIssue($account) === null;
    }
    unset($account);
    api_ok(['rows' => $flat, 'types' => ACCOUNTING_ACCOUNT_TYPES]);
}

if ($method === 'GET' && (!empty($_GET['id']) || !empty($_GET['code']))) {
    rbac_legacy_require($user, 'accounting.coa.view');
    $row = !empty($_GET['id'])
        ? scopedFind('SELECT * FROM accounting_accounts WHERE tenant_id = :tenant_id AND id = :id', ['id' => (int) $_GET['id']])
        : scopedFind('SELECT * FROM accounting_accounts WHERE tenant_id = :tenant_id AND code = :code', ['code' => trim((string) $_GET['code'])]);
    if (!$row) api_error('Not found', 404);
    api_ok(['account' => $row]);
}

if ($method === 'GET') {
    rbac_legacy_require($user, 'accounting.coa.view');
    $where  = ['tenant_id = :tenant_id'];
    $params = [];
    if (!empty($_GET['type']))   { $where[] = 'account_type = :t'; $params['t'] = $_GET['type']; }
    if (!empty($_GET['q']))      {
        // PDO MySQL with EMULATE_PREPARES=false does not allow re-using
        // the same named placeholder. Use distinct names bound to the
        // same value to avoid HY093 "Invalid parameter number".
        $where[] = '(code LIKE :q OR name LIKE :q2)';
        $params['q']  = '%' . $_GET['q'] . '%';
        $params['q2'] = $params['q'];
    }
    if (array_key_exists('active', $_GET) && $_GET['active'] !== '') {
        $where[] = 'active = :a';
        $params['a'] = (int) ((string) $_GET['active'] === '1');
    }
    if (!empty($_GET['postable'])) { $where[] = 'is_postable = 1'; }
    $rows = scopedQuery(
        'SELECT id, code, name, account_type, normal_side, parent_account_id, is_postable, active, currency,
                (SELECT COUNT(*) FROM accounting_account_terms t
                  WHERE t.tenant_id = accounting_accounts.tenant_id
                    AND t.account_id = accounting_accounts.id
                    AND t.interest_enabled = 1) AS interest_schedule_count
         FROM accounting_accounts WHERE ' . implode(' AND ', $where) . ' ORDER BY code ASC LIMIT 500',
        $params
    );
    api_ok(['rows' => $rows, 'types' => ACCOUNTING_ACCOUNT_TYPES]);
}

if ($method === 'POST' && $action === 'bulk_update') {
    rbac_legacy_require($user, 'accounting.coa.manage');
    $body = api_json_body();
    $ids = is_array($body['ids'] ?? null)
        ? array_values(array_unique(array_map('intval', $body['ids'])))
        : [];
    $ids = array_values(array_filter($ids, static fn($id) => $id > 0));
    $field = (string) ($body['field'] ?? '');
    $value = (string) ($body['value'] ?? '');
    if (!$ids) api_error('ids[] required', 422);
    if (count($ids) > 500) api_error('Too many ids (max 500 per call)', 422);
    if (!in_array($field, ['active', 'is_postable'], true)) api_error('Invalid bulk field', 422);
    if (!in_array($value, ['0', '1'], true)) api_error('Value must be 0 or 1', 422);

    $updated = 0;
    $skipped = 0;
    $failed = 0;
    $errors = [];
    foreach ($ids as $id) {
        try {
            $account = scopedFind(
                'SELECT * FROM accounting_accounts WHERE tenant_id = :tenant_id AND id = :id',
                ['id' => $id]
            );
            if (!$account) {
                $skipped++;
                continue;
            }
            if ((int) $account[$field] === (int) $value) {
                $skipped++;
                continue;
            }
            $change = accountingReviewAccountChange($tid, $account, [$field => $value]);
            scopedUpdate('accounting_accounts', $id, $change);
            accountingAudit('accounting.account.updated', [
                'id' => $id,
                'source' => 'bulk_update',
                'fields' => [$field],
                'before' => [$field => (int) $account[$field]],
                'after' => [$field => (int) $value],
            ], $id);
            $updated++;
        } catch (\Throwable $e) {
            $failed++;
            $errors[$id] = $e->getMessage();
        }
    }
    api_ok(['ok' => $failed === 0, 'updated' => $updated, 'skipped' => $skipped,
        'failed' => $failed, 'errors' => $errors]);
}

if ($method === 'POST') {
    rbac_legacy_require($user, 'accounting.coa.manage');
    $body = api_json_body();
    api_require_fields($body, ['code','name','account_type']);
    try {
        $fields = accountingReviewAccountChange($tid, null, array_intersect_key($body, array_flip([
            'code', 'name', 'account_type', 'normal_side', 'parent_account_id',
            'is_postable', 'currency', 'cash_flow_tag', 'description', 'active',
        ])));
    } catch (InvalidArgumentException $error) {
        api_error($error->getMessage(), 422);
    }
    $id = scopedInsert('accounting_accounts', array_merge(['tenant_id' => $tid], $fields));
    accountingAudit('accounting.account.created', ['id' => $id, 'code' => $body['code']], $id);
    api_ok(['id' => $id], 201);
}

if ($method === 'PATCH') {
    rbac_legacy_require($user, 'accounting.coa.manage');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    $current = scopedFind(
        'SELECT * FROM accounting_accounts WHERE tenant_id = :tenant_id AND id = :id',
        ['id' => $id]
    );
    if (!$current) api_error('Not found', 404);
    $body = api_json_body();
    $body = array_intersect_key($body, array_flip([
        'code', 'name', 'account_type', 'normal_side', 'parent_account_id',
        'is_postable', 'currency', 'cash_flow_tag', 'description', 'active',
    ]));
    try {
        $body = accountingReviewAccountChange($tid, $current, $body);
    } catch (InvalidArgumentException $error) {
        api_error($error->getMessage(), 422);
    }
    $rows = scopedUpdate('accounting_accounts', $id, $body);
    if ($rows === 0) api_error('Not found or no change', 404);
    accountingAudit('accounting.account.updated', ['id' => $id, 'fields' => array_keys($body)], $id);
    api_ok(['ok' => true]);
}

if ($method === 'DELETE') {
    rbac_legacy_require($user, 'accounting.coa.manage');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    $current = scopedFind(
        'SELECT * FROM accounting_accounts WHERE tenant_id = :tenant_id AND id = :id',
        ['id' => $id]
    );
    if (!$current) api_error('Not found', 404);
    try {
        accountingReviewAccountChange($tid, $current, ['active' => 0]);
    } catch (InvalidArgumentException $error) {
        api_error($error->getMessage(), 422);
    }
    $rows = scopedUpdate('accounting_accounts', $id, ['active' => 0]);
    if ($rows === 0) api_error('Not found', 404);
    accountingAudit('accounting.account.deactivated', ['id' => $id], $id);
    api_ok(['ok' => true]);
}

api_error('Method not allowed', 405);
