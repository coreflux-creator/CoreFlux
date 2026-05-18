<?php
/**
 * /api/mercury_transactions.php — cached mercury_transactions + sync.
 *
 *   GET ?account_pk=N&limit=50&offset=0   → cached transactions
 *   POST {action=sync, account_pk:N, limit?, start?, end?}
 *
 * RBAC: `accounting.bank.view` (read) / `accounting.bank.manage` (sync).
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/mercury_service.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$tenantId = (int) $ctx['tenant_id'];

$method = api_method();
$action = (string) ($_GET['action'] ?? '');

if ($method === 'GET') {
    if (!RBAC::hasPermission($user, 'accounting.bank.view')
        && !RBAC::hasPermission($user, 'accounting.bank.manage')) {
        api_error('Permission denied', 403);
    }
    $accountPk = (int) ($_GET['account_pk'] ?? 0);
    $limit  = max(1, min(200, (int) ($_GET['limit']  ?? 50)));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));

    try {
        $pdo  = getDB();
        $sql  = 'SELECT id, account_pk, mercury_account_id, amount_cents, currency, posted_at,
                        estimated_delivery_date, status, kind, counterparty_name, note,
                        bank_description, received_at
                   FROM mercury_transactions WHERE tenant_id = :t';
        $params = ['t' => $tenantId];
        if ($accountPk > 0) {
            $sql      .= ' AND account_pk = :pk';
            $params['pk'] = $accountPk;
        }
        $sql .= ' ORDER BY COALESCE(posted_at, received_at) DESC, id DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        $rows = [];
    }
    api_ok(['rows' => $rows, 'limit' => $limit, 'offset' => $offset]);
}

if ($method === 'POST' && $action === 'sync') {
    RBAC::requirePermission($user, 'accounting.bank.manage');
    $body = api_json_body();
    $accountPk = (int) ($body['account_pk'] ?? 0);
    if ($accountPk <= 0) api_error('account_pk required', 422);

    $opts = [];
    foreach (['limit', 'start', 'end', 'order', 'status'] as $k) {
        if (!empty($body[$k])) $opts[$k] = $body[$k];
    }
    try {
        $out = mercurySyncAccountTransactions($tenantId, $accountPk, $opts);
    } catch (MercuryApiException $e) {
        api_error($e->getMessage(), 502, ['http_status' => $e->httpStatus]);
    }
    api_ok(['ok' => true] + $out);
}

api_error('Method/action not allowed', 405);
