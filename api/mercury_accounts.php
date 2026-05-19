<?php
/**
 * /api/mercury_accounts.php — cached mercury_accounts read + manual sync.
 *
 *   GET                              → list cached accounts for tenant
 *   POST {action=sync}               → call Mercury /accounts, upsert cache
 *
 * RBAC: `accounting.bank.manage` (sync writes balances) for POST,
 * `accounting.bank.view` for GET.
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
    if (!rbac_legacy_can($user, 'accounting.bank.view')
        && !rbac_legacy_can($user, 'accounting.bank.manage')) {
        api_error('Permission denied', 403);
    }
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare(
            'SELECT id, mercury_account_id, nickname, account_number_last4, routing_number,
                    kind, status, available_balance_cents, current_balance_cents, currency,
                    last_synced_at, updated_at
               FROM mercury_accounts WHERE tenant_id = :t ORDER BY id'
        );
        $stmt->execute(['t' => $tenantId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        // Migration not applied yet
        $rows = [];
    }
    api_ok(['rows' => $rows]);
}

if ($method === 'POST' && $action === 'sync') {
    rbac_legacy_require($user, 'accounting.bank.manage');
    try {
        $out = mercurySyncAccounts($tenantId);
    } catch (MercuryApiException $e) {
        api_error($e->getMessage(), 502, ['http_status' => $e->httpStatus]);
    }
    api_ok(['ok' => true, 'accounts' => $out, 'count' => count($out)]);
}

api_error('Method/action not allowed', 405);
