<?php
/**
 * Plaid Bank Link — read-only bank feed connection (Auth + Transactions).
 *
 *   POST /api/plaid_bank_link.php                  → returns Link token
 *   POST /api/plaid_bank_link.php?action=exchange  → persists item + account
 *      body: {
 *        public_token: "public-sandbox-…",
 *        accounts:    [{ id, name, mask, subtype }],   // from Link metadata
 *        institution: { name, institution_id }
 *      }
 *
 * On exchange we:
 *   1. /item/public_token/exchange → access_token + item_id
 *   2. Insert encrypted token into plaid_items (purpose='bank_feed')
 *   3. /accounts/get → enumerate accounts, store in plaid_accounts
 *   4. For each Plaid account, create or update a row in
 *      accounting_bank_accounts so it shows up under Treasury → Deposit
 *      Accounts with feed_provider='plaid'.
 *
 * This endpoint is the read-only counterpart to /api/plaid_transfer_link.php
 * (which sets up outbound disbursements). They live side-by-side: a tenant
 * can connect a bank for read-only feeds without ever enrolling in Transfer.
 *
 * Permission: `accounting.bank.manage`. Audit: payment_rails.plaid.bank_linked.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/plaid_service.php';

$ctx      = api_require_auth();
$user     = $ctx['user'];
$tenantId = (int) $ctx['tenant_id'];
RBAC::requirePermission($user, 'accounting.bank.manage');

if (api_method() !== 'POST') api_error('Method not allowed', 405);
if (!plaidConfigured()) {
    api_error('Plaid not configured (PLAID_CLIENT_ID / PLAID_SECRET_*)', 503);
}

$action = (string) ($_GET['action'] ?? 'link_token');

if ($action === 'link_token') {
    try {
        $resp = plaidPost('/link/token/create', [
            'client_name'   => 'CoreFlux Treasury',
            'user'          => ['client_user_id' => 'cf_tenant_' . $tenantId . '_u' . ($user['id'] ?? 0)],
            'language'      => 'en',
            'country_codes' => ['US'],
            'products'      => ['auth', 'transactions'],
            'webhook'       => plaidWebhookUrl(),
        ]);
        api_ok([
            'link_token' => $resp['link_token'] ?? null,
            'expiration' => $resp['expiration'] ?? null,
        ]);
    } catch (PlaidApiException $e) {
        api_error('Plaid link_token create failed: ' . $e->getMessage(), 502, [
            'plaid_error_code' => $e->errorCode,
        ]);
    }
}

if ($action === 'exchange') {
    $body        = api_json_body();
    $publicToken = trim((string) ($body['public_token'] ?? ''));
    if ($publicToken === '') api_error('public_token required', 422);
    $institution = is_array($body['institution'] ?? null) ? $body['institution'] : [];

    try {
        $exchange = plaidExchangePublicToken($publicToken);
    } catch (PlaidApiException $e) {
        api_error('Exchange failed: ' . $e->getMessage(), 502);
    }
    $accessToken = (string) ($exchange['access_token'] ?? '');
    $itemId      = (string) ($exchange['item_id']      ?? '');
    if ($accessToken === '' || $itemId === '') api_error('Plaid did not return access_token / item_id', 502);

    $tokenCt = plaidEncryptAccessToken($accessToken);
    $pdo = getDB();

    // Idempotent insert. If this item_id already exists for the tenant
    // (re-link / re-auth flow), update encrypted token + status.
    $pdo->prepare(
        "INSERT INTO plaid_items
           (tenant_id, item_id, access_token_ct, institution_id, institution_name,
            products_json, purpose, status, created_by_user_id, created_at)
         VALUES (:t, :iid, :ct, :iiid, :iname, :prods, 'bank_feed', 'linked', :u, NOW())
         ON DUPLICATE KEY UPDATE
           access_token_ct = VALUES(access_token_ct),
           institution_id  = VALUES(institution_id),
           institution_name= VALUES(institution_name),
           products_json   = VALUES(products_json),
           status          = 'linked',
           updated_at      = NOW()"
    )->execute([
        't'    => $tenantId,
        'iid'  => $itemId,
        'ct'   => $tokenCt,
        'iiid' => (string) ($institution['institution_id'] ?? '') ?: null,
        'iname'=> (string) ($institution['name']            ?? '') ?: null,
        'prods'=> json_encode(['auth', 'transactions']),
        'u'    => (int) ($user['id'] ?? 0),
    ]);
    $stmt = $pdo->prepare(
        'SELECT id FROM plaid_items WHERE tenant_id = :t AND item_id = :iid LIMIT 1'
    );
    $stmt->execute(['t' => $tenantId, 'iid' => $itemId]);
    $itemPk = (int) $stmt->fetchColumn();

    // Hydrate accounts from /accounts/get for canonical metadata.
    try {
        $resp = plaidGetAccounts($accessToken);
    } catch (PlaidApiException $e) {
        api_error('accounts/get failed: ' . $e->getMessage(), 502);
    }
    $plaidAccounts = is_array($resp['accounts'] ?? null) ? $resp['accounts'] : [];

    $createdBank = [];
    $createdLiab = [];
    foreach ($plaidAccounts as $acc) {
        $accId   = (string) ($acc['account_id'] ?? '');
        if ($accId === '') continue;
        $name    = (string) ($acc['name']          ?? '');
        $official= (string) ($acc['official_name'] ?? '') ?: null;
        $mask    = (string) ($acc['mask']          ?? '') ?: null;
        $type    = (string) ($acc['type']          ?? '') ?: null;
        $subtype = (string) ($acc['subtype']       ?? '') ?: null;

        $pdo->prepare(
            "INSERT INTO plaid_accounts
                 (tenant_id, plaid_item_pk, account_id, name, official_name,
                  mask, type, subtype, created_at)
             VALUES (:t, :pk, :a, :n, :o, :m, :ty, :st, NOW())
             ON DUPLICATE KEY UPDATE
                 name = VALUES(name), official_name = VALUES(official_name),
                 mask = VALUES(mask), type = VALUES(type), subtype = VALUES(subtype),
                 updated_at = NOW()"
        )->execute([
            't' => $tenantId, 'pk' => $itemPk, 'a' => $accId,
            'n' => $name, 'o' => $official, 'm' => $mask,
            'ty' => $type, 'st' => $subtype,
        ]);

        // Mirror as a deposit account so it surfaces in Treasury immediately.
        // Depository (checking/savings) → accounting_bank_accounts.
        // Credit / loan → treasury_liability_accounts (via accounting_accounts FK).
        if ($type === 'depository') {
            $check = $pdo->prepare(
                'SELECT id FROM accounting_bank_accounts
                  WHERE tenant_id = :t AND plaid_account_id = :a LIMIT 1'
            );
            $check->execute(['t' => $tenantId, 'a' => $accId]);
            $existingId = (int) $check->fetchColumn();
            if ($existingId > 0) { $createdBank[] = $existingId; continue; }

            // Pick a sensible default GL code.
            $glCode = $subtype === 'savings' ? '1010' : '1000';
            $instLabel = $institution['name'] ?? '';
            $bankName = $instLabel !== '' ? $instLabel : ($name ?: 'Bank');
            $insName  = trim(($instLabel ? "{$instLabel} — " : '') . ($name ?: 'Account'));

            $pdo->prepare(
                'INSERT INTO accounting_bank_accounts
                    (tenant_id, name, gl_account_code, bank_name, last4, currency,
                     feed_provider, status, plaid_account_id, last_feed_synced_at,
                     created_at)
                 VALUES (:t, :nm, :gl, :bk, :l4, :c, "plaid", "active", :pa, NULL, NOW())'
            )->execute([
                't'  => $tenantId, 'nm' => $insName, 'gl' => $glCode,
                'bk' => $bankName, 'l4' => $mask, 'c'  => 'USD', 'pa' => $accId,
            ]);
            $createdBank[] = (int) $pdo->lastInsertId();
            continue;
        }

        if ($type === 'credit' || $type === 'loan') {
            $check = $pdo->prepare(
                'SELECT id FROM treasury_liability_accounts
                  WHERE tenant_id = :t AND plaid_account_id = :a LIMIT 1'
            );
            $check->execute(['t' => $tenantId, 'a' => $accId]);
            $existingId = (int) $check->fetchColumn();
            if ($existingId > 0) { $createdLiab[] = $existingId; continue; }

            // Map Plaid type/subtype → GL code + treasury subtype.
            $glCode = $type === 'loan' ? '2200' : '2100';
            $glName = $type === 'loan' ? 'Notes Payable' : 'Credit Card Payable';
            $treasurySubtype = match (true) {
                $subtype === 'credit card'           => 'credit_card',
                in_array($subtype, ['line of credit'], true) => 'line_of_credit',
                $type    === 'loan'                  => 'loan',
                default                              => 'other_liability',
            };

            // Find or create the underlying accounting_accounts row (one per
            // GL code per tenant). Liability accounts have credit normal-side.
            $aaCheck = $pdo->prepare(
                'SELECT id FROM accounting_accounts
                  WHERE tenant_id = :t AND code = :c LIMIT 1'
            );
            $aaCheck->execute(['t' => $tenantId, 'c' => $glCode]);
            $aaId = (int) $aaCheck->fetchColumn();
            if ($aaId === 0) {
                $pdo->prepare(
                    "INSERT INTO accounting_accounts
                        (tenant_id, code, name, account_type, normal_side, active, created_at)
                     VALUES (:t, :c, :n, 'liability', 'credit', 1, NOW())"
                )->execute(['t' => $tenantId, 'c' => $glCode, 'n' => $glName]);
                $aaId = (int) $pdo->lastInsertId();
            }

            $pdo->prepare(
                'INSERT INTO treasury_liability_accounts
                    (tenant_id, account_id, subtype, institution_name, last4,
                     plaid_account_id, created_at)
                 VALUES (:t, :aid, :st, :inst, :l4, :pa, NOW())'
            )->execute([
                't'   => $tenantId,
                'aid' => $aaId,
                'st'  => $treasurySubtype,
                'inst'=> (string) ($institution['name'] ?? '') ?: null,
                'l4'  => $mask,
                'pa'  => $accId,
            ]);
            $createdLiab[] = (int) $pdo->lastInsertId();
            continue;
        }

        // Unknown / investment / other — leave on plaid_accounts only.
    }

    plaidAudit('payment_rails.plaid.bank_linked', [
        'item_id'                  => $itemId,
        'institution'              => $institution['name'] ?? null,
        'bank_accounts_created'    => $createdBank,
        'liability_accounts_created' => $createdLiab,
    ], null);

    api_ok([
        'ok'                         => true,
        'item_id'                    => $itemId,
        'plaid_item_pk'              => $itemPk,
        'accounts_linked'            => count($plaidAccounts),
        'bank_accounts_created'      => $createdBank,
        'liability_accounts_created' => $createdLiab,
    ]);
}

api_error('Unknown action: ' . $action, 422);
