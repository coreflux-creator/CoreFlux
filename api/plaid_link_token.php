<?php
/**
 * Plaid — POST /api/plaid_link_token
 *
 * Returns a short-lived link_token for the React Plaid Link modal.
 *
 * Body:
 *   purpose:        'bank_feed' | 'vendor_banking' | 'employee_banking' | 'tenant_funding'
 *   products?:      string[]  (defaults to ['auth','transactions'])
 *   webhook_url?:   string    (defaults to PLAID_WEBHOOK_URL env)
 *   update_item_id?: string   (existing item_id → update mode for re-auth)
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/plaid_service.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];

if (api_method() !== 'POST') api_error('Method not allowed', 405);

$body    = api_json_body();
$purpose = (string) ($body['purpose'] ?? '');
$validPurposes = ['bank_feed','vendor_banking','employee_banking','tenant_funding'];
if (!in_array($purpose, $validPurposes, true)) {
    api_error('Invalid purpose', 422, ['valid' => $validPurposes]);
}

// Permission gate per purpose.
$perm = match ($purpose) {
    'bank_feed'        => 'accounting.bank.manage',
    'vendor_banking'   => 'ap.payment.create',
    'employee_banking' => 'payroll.profiles.banking.manage',
    'tenant_funding'   => 'ap.payment.create',
};
RBAC::requirePermission($user, $perm);

$products = $body['products'] ?? ['auth','transactions'];
if (!is_array($products) || !$products) $products = ['auth','transactions'];
$allowed  = ['auth','transactions','identity'];
$products = array_values(array_intersect($allowed, array_map('strval', $products)));
if (!$products) api_error('No valid products requested', 422);

$req = [
    'client_name'   => 'CoreFlux',
    'user'          => ['client_user_id' => sprintf('t%d_u%d', $tid, (int) ($user['id'] ?? 0))],
    'language'      => 'en',
    'country_codes' => ['US'],
    'products'      => $products,
];
$webhookUrl = (string) ($body['webhook_url'] ?? plaidGet('PLAID_WEBHOOK_URL', ''));
if ($webhookUrl !== '') $req['webhook'] = $webhookUrl;

// Update mode: re-auth an existing item after ITEM_LOGIN_REQUIRED.
if (!empty($body['update_item_id'])) {
    $row = scopedFind(
        'SELECT access_token_ct FROM plaid_items WHERE tenant_id = :tenant_id AND item_id = :iid',
        ['iid' => (string) $body['update_item_id']]
    );
    if (!$row) api_error('item not found for tenant', 404);
    $accessToken = plaidDecryptAccessToken($row['access_token_ct']);
    if (!$accessToken) api_error('Could not decrypt access token', 500);
    $req['access_token'] = $accessToken;
    unset($req['products']);  // not allowed in update mode
}

try {
    $resp = plaidPost('/link/token/create', $req);
} catch (PlaidApiException $e) {
    api_error($e->getMessage(), 502, ['plaid_error_code' => $e->errorCode]);
}

api_ok([
    'link_token' => $resp['link_token'],
    'expiration' => $resp['expiration'],
    'request_id' => $resp['request_id'] ?? null,
]);
