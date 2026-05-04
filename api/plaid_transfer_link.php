<?php
/**
 * Plaid Transfer — funding-source link + exchange endpoints (tenant-scoped).
 *
 * Two-step flow per Plaid playbook:
 *   1. POST /api/plaid_transfer_link.php           → creates a Link token
 *   2. POST /api/plaid_transfer_link.php?action=exchange
 *      body: { public_token, account_id }         → persists in tenant_payment_rails
 *
 * After step 2, PlaidTransferDriver::originate() can disburse from the
 * stored funding account_id without further user interaction.
 *
 * Permission: `accounting.bank.manage`. Audit: payment_rails.plaid.linked.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/plaid_service.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$tenantId = (int) $ctx['tenant_id'];
RBAC::requirePermission($user, 'accounting.bank.manage');

if (api_method() !== 'POST') api_error('Method not allowed', 405);
if (!plaidConfigured()) {
    api_error('Plaid not configured (PLAID_CLIENT_ID / PLAID_SECRET_*)', 503);
}

$action = (string) ($_GET['action'] ?? 'link_token');

if ($action === 'link_token') {
    // Create a Link token scoped to Transfer authorization.
    try {
        $resp = plaidPost('/link/token/create', [
            'client_name'   => 'CoreFlux Treasury',
            'user'          => ['client_user_id' => 'cf_tenant_' . $tenantId . '_u' . ($user['id'] ?? 0)],
            'language'      => 'en',
            'country_codes' => ['US'],
            'products'      => ['transfer'],
            'transfer'      => [
                // Transfer-specific Link config; allows the user to pick a
                // funding account that will be authorized for outbound credits.
                'intent_id' => null,
            ],
            'webhook'       => plaidWebhookUrl(),
        ]);
        api_ok(['link_token' => $resp['link_token'] ?? null, 'expiration' => $resp['expiration'] ?? null]);
    } catch (PlaidApiException $e) {
        api_error('Plaid link_token create failed: ' . $e->getMessage(), 502, [
            'plaid_error_code' => $e->errorCode,
        ]);
    }
}

if ($action === 'exchange') {
    $body        = api_json_body();
    $publicToken = trim((string) ($body['public_token'] ?? ''));
    $accountId   = trim((string) ($body['account_id']   ?? ''));
    if ($publicToken === '' || $accountId === '') api_error('public_token + account_id required', 422);

    try {
        $exchange = plaidExchangePublicToken($publicToken);
    } catch (PlaidApiException $e) {
        api_error('Exchange failed: ' . $e->getMessage(), 502);
    }
    $accessToken = (string) ($exchange['access_token'] ?? '');
    $itemId      = (string) ($exchange['item_id']      ?? '');
    if ($accessToken === '' || $itemId === '') api_error('Plaid did not return access_token / item_id', 502);

    $ct = plaidEncryptAccessToken($accessToken);

    $pdo = getDB();
    $pdo->prepare(
        "INSERT INTO tenant_payment_rails
           (tenant_id, rail, access_token_ct, item_id, account_id, status, created_at)
         VALUES (:t, 'plaid_transfer', :ct, :iid, :acc, 'linked', NOW())
         ON DUPLICATE KEY UPDATE
           access_token_ct = VALUES(access_token_ct),
           item_id         = VALUES(item_id),
           account_id      = VALUES(account_id),
           status          = 'linked',
           updated_at      = NOW()"
    )->execute([
        't'   => $tenantId,
        'ct'  => $ct,
        'iid' => $itemId,
        'acc' => $accountId,
    ]);

    plaidAudit('payment_rails.plaid.linked', [
        'item_id' => $itemId, 'account_id' => $accountId,
    ], null);

    api_ok(['ok' => true, 'item_id' => $itemId, 'account_id' => $accountId, 'status' => 'linked']);
}

api_error('Unknown action: ' . $action, 422);
