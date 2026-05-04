<?php
/**
 * /api/plaid_diagnostics.php — tenant-scoped Plaid connection inspector.
 *
 * Returns everything the platform persisted for the current tenant after a
 * Plaid Link / exchange flow. Use this to debug "I connected my bank, where
 * did the account go?" reports.
 *
 *   GET /api/plaid_diagnostics.php
 *
 * Returns:
 *   {
 *     plaid_items:                  [{ id, item_id, institution_name, purpose, status, last_webhook_at, last_error_message, created_at }],
 *     plaid_accounts:               [{ id, plaid_item_pk, account_id, name, mask, type, subtype }],
 *     accounting_bank_accounts_for_plaid: [{ id, name, gl_account_code, plaid_account_id, feed_provider, status }],
 *     treasury_liability_accounts_for_plaid: [{ id, subtype, last4, plaid_account_id, account_id }],
 *     orphaned_plaid_accounts:      [...]   // Plaid accounts NOT mirrored anywhere
 *   }
 *
 * Permission: `accounting.bank.manage`. Read-only.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';

$ctx      = api_require_auth();
$tenantId = (int) $ctx['tenant_id'];
RBAC::requirePermission($ctx['user'], 'accounting.bank.manage');
if (api_method() !== 'GET') api_error('Method not allowed', 405);

$pdo = getDB();

$items = $pdo->prepare(
    "SELECT id, item_id, institution_id, institution_name, products_json,
            purpose, status, last_webhook_at, last_error_code, last_error_message,
            created_at, updated_at
       FROM plaid_items WHERE tenant_id = :t ORDER BY id DESC"
);
$items->execute(['t' => $tenantId]);
$items = $items->fetchAll(PDO::FETCH_ASSOC);

$accounts = $pdo->prepare(
    "SELECT id, plaid_item_pk, account_id, name, official_name, mask, type, subtype,
            created_at, updated_at
       FROM plaid_accounts WHERE tenant_id = :t ORDER BY plaid_item_pk DESC, id"
);
$accounts->execute(['t' => $tenantId]);
$accounts = $accounts->fetchAll(PDO::FETCH_ASSOC);

$bankRows = $pdo->prepare(
    "SELECT id, name, gl_account_code, bank_name, last4, currency, feed_provider,
            status, plaid_account_id, last_feed_synced_at, created_at
       FROM accounting_bank_accounts
      WHERE tenant_id = :t AND plaid_account_id IS NOT NULL"
);
$bankRows->execute(['t' => $tenantId]);
$bankRows = $bankRows->fetchAll(PDO::FETCH_ASSOC);

// treasury_liability_accounts may not have plaid_account_id yet (migration 002
// pending); guard the query.
$liabRows = [];
try {
    $col = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE()
            AND table_name   = 'treasury_liability_accounts'
            AND column_name  = 'plaid_account_id'"
    );
    $col->execute();
    if ((int) $col->fetchColumn() > 0) {
        $stmt = $pdo->prepare(
            "SELECT id, account_id, subtype, institution_name, last4, plaid_account_id, created_at
               FROM treasury_liability_accounts
              WHERE tenant_id = :t AND plaid_account_id IS NOT NULL"
        );
        $stmt->execute(['t' => $tenantId]);
        $liabRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (\Throwable $e) { /* table may not exist on a fresh tenant */ }

// Compute orphans: plaid_accounts whose account_id isn't mirrored.
$mirroredAccIds = [];
foreach ($bankRows as $r)  $mirroredAccIds[$r['plaid_account_id']] = true;
foreach ($liabRows as $r)  $mirroredAccIds[$r['plaid_account_id']] = true;
$orphans = array_values(array_filter($accounts, fn ($a) => !isset($mirroredAccIds[$a['account_id']])));

api_ok([
    'plaid_items'                              => $items,
    'plaid_accounts'                           => $accounts,
    'accounting_bank_accounts_for_plaid'       => $bankRows,
    'treasury_liability_accounts_for_plaid'    => $liabRows,
    'orphaned_plaid_accounts'                  => $orphans,
    'tenant_id'                                => $tenantId,
    'as_of'                                    => date('c'),
]);
