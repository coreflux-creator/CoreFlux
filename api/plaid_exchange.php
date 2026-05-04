<?php
/**
 * Plaid — POST /api/plaid_exchange
 *
 * Exchanges a public_token for an access_token, persists the encrypted
 * token + Plaid Item metadata, hydrates plaid_accounts. Optionally binds
 * the new Item to a vendor / employee / accounting bank account by id.
 *
 * Body:
 *   public_token:                string  (from Plaid Link onSuccess)
 *   purpose:                     'bank_feed'|'vendor_banking'|'employee_banking'|'tenant_funding'
 *   vendor_id?:                  int     (purpose=vendor_banking)
 *   employee_id?:                int     (purpose=employee_banking)
 *   accounting_bank_account_id?: int     (purpose=bank_feed)
 *   institution?: { institution_id, name }
 *   products?:                   string[]
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/plaid_service.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];

if (api_method() !== 'POST') api_error('Method not allowed', 405);

$body         = api_json_body();
$publicToken  = trim((string) ($body['public_token'] ?? ''));
$purpose      = (string) ($body['purpose'] ?? '');
$validPurposes = ['bank_feed','vendor_banking','employee_banking','tenant_funding'];
if ($publicToken === '')                          api_error('public_token required', 422);
if (!in_array($purpose, $validPurposes, true))    api_error('Invalid purpose', 422, ['valid' => $validPurposes]);

$perm = match ($purpose) {
    'bank_feed'        => 'accounting.bank.manage',
    'vendor_banking'   => 'ap.payment.create',
    'employee_banking' => 'payroll.profiles.banking.manage',
    'tenant_funding'   => 'ap.payment.create',
};
RBAC::requirePermission($user, $perm);

// 1) exchange
try {
    $exch = plaidExchangePublicToken($publicToken);
} catch (PlaidApiException $e) {
    api_error('Plaid exchange failed: ' . $e->getMessage(), 502, ['plaid_error_code' => $e->errorCode]);
}
$accessToken = $exch['access_token'];
$itemId      = $exch['item_id'];
$tokenCt     = plaidEncryptAccessToken($accessToken);

// 2) fetch institution + accounts in parallel-ish
$inst = $body['institution'] ?? [];
$institutionId   = (string) ($inst['institution_id']  ?? '');
$institutionName = (string) ($inst['name']            ?? '');

try {
    $acctResp = plaidGetAccounts($accessToken);
} catch (PlaidApiException $e) {
    $acctResp = ['accounts' => [], 'item' => []];
}
if ($institutionId === '' && !empty($acctResp['item']['institution_id'])) {
    $institutionId = (string) $acctResp['item']['institution_id'];
}
if ($institutionName === '' && $institutionId !== '') {
    $instLookup      = plaidGetInstitution($institutionId);
    $institutionName = (string) ($instLookup['institution']['name'] ?? '');
}

// 3) persist plaid_items
$products = $body['products'] ?? ['auth','transactions'];
$products = is_array($products) ? array_values($products) : ['auth','transactions'];

$itemPk = scopedInsert('plaid_items', [
    'item_id'                    => $itemId,
    'access_token_ct'            => $tokenCt,
    'institution_id'             => $institutionId ?: null,
    'institution_name'           => $institutionName ?: null,
    'products_json'              => json_encode($products),
    'purpose'                    => $purpose,
    'vendor_id'                  => $purpose === 'vendor_banking'   ? (int) ($body['vendor_id']                  ?? 0) ?: null : null,
    'employee_id'                => $purpose === 'employee_banking' ? (int) ($body['employee_id']                ?? 0) ?: null : null,
    'accounting_bank_account_id' => $purpose === 'bank_feed'        ? (int) ($body['accounting_bank_account_id'] ?? 0) ?: null : null,
    'status'                     => 'linked',
    'created_by_user_id'         => $user['id'] ?? null,
]);

// 4) hydrate plaid_accounts
$primaryFlagged = false;
foreach (($acctResp['accounts'] ?? []) as $a) {
    $aid = (string) ($a['account_id'] ?? '');
    if ($aid === '') continue;
    $isPrimary = !$primaryFlagged && ($a['subtype'] ?? '') === 'checking' ? 1 : 0;
    if ($isPrimary) $primaryFlagged = true;
    scopedInsert('plaid_accounts', [
        'plaid_item_pk' => $itemPk,
        'account_id'    => $aid,
        'name'          => substr((string) ($a['name'] ?? ''), 0, 160),
        'official_name' => substr((string) ($a['official_name'] ?? ''), 0, 200),
        'mask'          => substr((string) ($a['mask'] ?? ''), 0, 4) ?: null,
        'type'          => substr((string) ($a['type'] ?? ''), 0, 40) ?: null,
        'subtype'       => substr((string) ($a['subtype'] ?? ''), 0, 40) ?: null,
        'is_primary'    => $isPrimary,
    ]);
}

plaidAudit('core.plaid.item_linked', [
    'plaid_item_pk' => $itemPk, 'item_id' => $itemId, 'purpose' => $purpose,
    'institution'   => $institutionName, 'account_count' => count($acctResp['accounts'] ?? []),
], $itemPk);

api_ok([
    'plaid_item_pk' => $itemPk,
    'item_id'       => $itemId,
    'institution'   => $institutionName ?: null,
    'accounts'      => array_map(fn($a) => [
        'account_id'    => $a['account_id']    ?? null,
        'name'          => $a['name']          ?? null,
        'mask'          => $a['mask']          ?? null,
        'type'          => $a['type']          ?? null,
        'subtype'       => $a['subtype']       ?? null,
    ], $acctResp['accounts'] ?? []),
], 201);
