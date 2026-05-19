<?php
/**
 * Plaid — POST /api/plaid_auth_pull
 *
 * Calls /auth/get for the linked Item, encrypts the routing+account
 * numbers, and writes them into the bound owner's table.
 *
 * For purpose='vendor_banking': writes into ap_vendors_index columns
 *   (payment_routing_ct, payment_account_ct, payment_routing_last4,
 *    payment_account_last4, payment_account_type, plaid_account_id).
 *
 * For purpose='employee_banking': writes into people_bank_accounts
 *   (routing_cipher, account_cipher, routing_last4, account_last4, account_type).
 *
 * Body:
 *   item_id:      string  (Plaid item_id linked via /api/plaid_exchange)
 *   account_id?:  string  (Plaid account_id; default: primary, falling back to first)
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/encryption.php';
require_once __DIR__ . '/../core/plaid_service.php';

$ctx  = api_require_auth();
$user = $ctx['user'];

if (api_method() !== 'POST') api_error('Method not allowed', 405);

$body      = api_json_body();
$itemIdExt = trim((string) ($body['item_id'] ?? ''));
$accountId = trim((string) ($body['account_id'] ?? ''));
if ($itemIdExt === '') api_error('item_id required', 422);

$item = scopedFind(
    'SELECT * FROM plaid_items WHERE tenant_id = :tenant_id AND item_id = :iid',
    ['iid' => $itemIdExt]
);
if (!$item) api_error('Item not found', 404);

$perm = match ($item['purpose']) {
    'bank_feed'        => 'accounting.bank.manage',
    'vendor_banking'   => 'ap.payment.create',
    'employee_banking' => 'payroll.profiles.banking.manage',
    'tenant_funding'   => 'ap.payment.create',
    default            => 'ap.payment.create',
};
rbac_legacy_require($user, $perm);

$accessToken = plaidDecryptAccessToken($item['access_token_ct']);
if (!$accessToken) api_error('Could not decrypt access token', 500);

try {
    $authResp = plaidGetAuth($accessToken);
} catch (PlaidApiException $e) {
    plaidAudit('core.plaid.auth_failed', ['item_id' => $itemIdExt, 'error_code' => $e->errorCode], (int) $item['id']);
    api_error('Plaid auth failed: ' . $e->getMessage(), 502, ['plaid_error_code' => $e->errorCode]);
}

$achList = $authResp['numbers']['ach'] ?? [];
if (!$achList) api_error('No ACH-eligible accounts on this Item', 422);

// Pick the requested account, primary, or first.
$pick = null;
if ($accountId !== '') {
    foreach ($achList as $r) if (($r['account_id'] ?? '') === $accountId) { $pick = $r; break; }
    if (!$pick) api_error('account_id not found in /auth/get response', 422);
} else {
    $pick = $achList[0];
}
$routing = preg_replace('/\D+/', '', (string) ($pick['routing'] ?? ''));
$account = preg_replace('/\s+/', '', (string) ($pick['account'] ?? ''));
if (strlen($routing) !== 9) api_error('Routing not 9 digits — refusing to persist', 422);
if (strlen($account) < 4)   api_error('Account too short', 422);

$routingCt   = encryptField($routing);
$accountCt   = encryptField($account);
$routingL4   = substr($routing, -4);
$accountL4   = substr($account, -4);
$plaidAcctId = (string) ($pick['account_id'] ?? '');

// Look up the account subtype (checking|savings) from /accounts/get cache.
$row = scopedFind(
    'SELECT subtype FROM plaid_accounts WHERE tenant_id = :tenant_id AND account_id = :aid',
    ['aid' => $plaidAcctId]
);
$acctType = ($row && in_array($row['subtype'] ?? '', ['checking','savings'], true)) ? $row['subtype'] : 'checking';

$pdo = getDB();
$updated = 0;

if ($item['purpose'] === 'vendor_banking') {
    $vendorId = (int) $item['vendor_id'];
    if ($vendorId <= 0) api_error('plaid_items.vendor_id missing — was item linked correctly?', 422);
    $stmt = $pdo->prepare(
        'UPDATE ap_vendors_index
         SET payment_method        = "ach",
             payment_routing_ct    = :rc,
             payment_routing_last4 = :rl4,
             payment_account_ct    = :ac,
             payment_account_last4 = :al4,
             payment_account_type  = :at,
             kms_key_version_payment = "v1"
         WHERE tenant_id = :t AND id = :id'
    );
    $stmt->execute([
        'rc' => $routingCt, 'rl4' => $routingL4,
        'ac' => $accountCt, 'al4' => $accountL4,
        'at' => $acctType,
        't'  => $ctx['tenant_id'], 'id' => $vendorId,
    ]);
    $updated = $stmt->rowCount();
    plaidAudit('core.plaid.auth_persisted_vendor', [
        'vendor_id' => $vendorId, 'item_id' => $itemIdExt, 'routing_last4' => $routingL4, 'account_last4' => $accountL4,
    ], (int) $item['id']);
} elseif ($item['purpose'] === 'employee_banking') {
    $employeeId = (int) $item['employee_id'];
    if ($employeeId <= 0) api_error('plaid_items.employee_id missing', 422);
    // Insert as priority-1 active account (closes any prior duplicate hash).
    $hashRouting = hash('sha256', $routing);
    $hashAccount = hash('sha256', $account);
    $stmt = $pdo->prepare(
        'INSERT INTO people_bank_accounts
            (tenant_id, employee_id, priority, allocation_type, account_type,
             routing_cipher, routing_last4, routing_hash,
             account_cipher, account_last4, account_hash, status, effective_from)
         VALUES (:t, :e, 1, "remainder", :at,
                 :rc, :rl4, :rh,
                 :ac, :al4, :ah, "active", CURDATE())'
    );
    $stmt->execute([
        't'  => $ctx['tenant_id'], 'e' => $employeeId, 'at' => $acctType,
        'rc' => $routingCt, 'rl4' => $routingL4, 'rh' => $hashRouting,
        'ac' => $accountCt, 'al4' => $accountL4, 'ah' => $hashAccount,
    ]);
    $updated = $stmt->rowCount();
    plaidAudit('core.plaid.auth_persisted_employee', [
        'employee_id' => $employeeId, 'item_id' => $itemIdExt,
        'routing_last4' => $routingL4, 'account_last4' => $accountL4,
    ], (int) $item['id']);
} else {
    api_error('auth_pull is only valid for vendor_banking or employee_banking purposes', 422);
}

api_ok([
    'updated_rows'  => $updated,
    'routing_last4' => $routingL4,
    'account_last4' => $accountL4,
    'account_type'  => $acctType,
]);
