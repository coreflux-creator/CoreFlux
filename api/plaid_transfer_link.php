<?php
/**
 * Plaid Transfer — funding-source link + exchange endpoints (tenant-scoped).
 *
 * Three-step flow per Plaid playbook:
 *   GET  /api/plaid_transfer_link.php?action=status        → { configured, linked, rail }
 *   POST /api/plaid_transfer_link.php                      → creates a Link token
 *   POST /api/plaid_transfer_link.php?action=exchange
 *      body: { public_token, account_id }                  → persists in tenant_payment_rails
 *   POST /api/plaid_transfer_link.php?action=disconnect    → soft-revokes the rail
 *
 * After exchange, PlaidTransferDriver::originate() can disburse from the
 * stored funding account_id without further user interaction.
 *
 * Permission: `accounting.bank.manage`. Audit: payment_rails.plaid.linked /
 * payment_rails.plaid.disconnected.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/plaid_service.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$tenantId = (int) $ctx['tenant_id'];
rbac_legacy_require($user, 'accounting.bank.manage');

$action = (string) ($_GET['action'] ?? 'link_token');
$method = api_method();

// GET ?action=status — UI probe (no Plaid call, no env-gate; reveals whether
// config + link are both done so the UI can render the right CTA).
if ($method === 'GET' && $action === 'status') {
    $configured = plaidConfigured();
    $row = null;
    try {
        $pdo  = getDB();
        $stmt = $pdo->prepare(
            "SELECT item_id, account_id, status, created_at, updated_at
               FROM tenant_payment_rails
              WHERE tenant_id = :t AND rail = 'plaid_transfer' LIMIT 1"
        );
        $stmt->execute(['t' => $tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $e) {
        // Migration 005 may not be run yet — degrade gracefully.
        $row = null;
    }
    api_ok([
        'configured' => $configured,
        'linked'     => $row && ($row['status'] ?? '') === 'linked',
        'rail'       => $row ? [
            'status'     => $row['status'],
            'item_id'    => $row['item_id'],
            'account_id' => $row['account_id'],
            'linked_at'  => $row['updated_at'] ?: $row['created_at'],
        ] : null,
    ]);
}

if ($method !== 'POST') api_error('Method not allowed', 405);

// POST ?action=disconnect — soft-revoke the linked funding source so the
// tenant can re-link cleanly. Keeps the row for audit, flips status='revoked'.
if ($action === 'disconnect') {
    $before = plaidPaymentRailAuditRow($tenantId, 'plaid_transfer');
    try {
        $pdo = getDB();
        $pdo->prepare(
            "UPDATE tenant_payment_rails
                SET status = 'revoked', updated_at = NOW()
              WHERE tenant_id = :t AND rail = 'plaid_transfer'"
        )->execute(['t' => $tenantId]);
    } catch (\Throwable $e) {
        api_error('Disconnect failed: ' . $e->getMessage(), 500);
    }
    $after = plaidPaymentRailAuditRow($tenantId, 'plaid_transfer');
    plaidAudit('payment_rails.plaid.disconnected', ['tenant_id' => $tenantId], null, [
        'tenant_id' => $tenantId,
        'actor_user_id' => (int) ($user['id'] ?? 0),
        'before' => $before,
        'after' => $after,
    ]);
    api_ok(['ok' => true, 'status' => 'revoked']);
}

if (!plaidConfigured()) {
    api_error('Plaid not configured (PLAID_CLIENT_ID / PLAID_SECRET_*)', 503);
}

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
    $before = plaidPaymentRailAuditRow($tenantId, 'plaid_transfer');
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
    ], null, [
        'tenant_id' => $tenantId,
        'actor_user_id' => (int) ($user['id'] ?? 0),
        'before' => $before,
        'after' => plaidPaymentRailAuditRow($tenantId, 'plaid_transfer'),
    ]);

    api_ok(['ok' => true, 'item_id' => $itemId, 'account_id' => $accountId, 'status' => 'linked']);
}

api_error('Unknown action: ' . $action, 422);
