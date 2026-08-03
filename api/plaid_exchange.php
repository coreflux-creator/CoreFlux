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
require_once __DIR__ . '/../core/active_entity.php';

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
rbac_legacy_require($user, $perm);

$bankEntityId = null;
if ($purpose === 'bank_feed') {
    $entity = activeEntityResolveForTenant($tid);
    if (!$entity) api_error('No accounting entity is configured for this tenant', 422);
    $bankEntityId = (int) $entity['id'];
}

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

// A bank-rec connection targets an existing accounting_bank_accounts row.
// Persist the actual Plaid account mapping; storing only the item-level hint
// leaves transaction sync unable to route statement lines to the account.
if ($purpose === 'bank_feed' && !empty($body['accounting_bank_account_id'])) {
    $bankId = (int) $body['accounting_bank_account_id'];
    $bank = scopedFind(
        'SELECT id, last4 FROM accounting_bank_accounts
          WHERE tenant_id = :tenant_id AND id = :id LIMIT 1',
        ['id' => $bankId]
    );
    if (!$bank) api_error('Bank account not found', 404);

    $selectedIds = array_values(array_filter(array_map(
        'strval',
        is_array($body['selected_account_ids'] ?? null) ? $body['selected_account_ids'] : []
    )));
    $selectedSet = $selectedIds ? array_flip($selectedIds) : null;
    $depositories = array_values(array_filter(
        $acctResp['accounts'] ?? [],
        static fn(array $a): bool =>
            (string) ($a['type'] ?? '') === 'depository'
            && ($selectedSet === null || isset($selectedSet[(string) ($a['account_id'] ?? '')]))
    ));
    $chosen = null;
    foreach ($depositories as $a) {
        if (!empty($bank['last4']) && (string) ($a['mask'] ?? '') === (string) $bank['last4']) {
            $chosen = $a;
            break;
        }
    }
    if (!$chosen && count($depositories) === 1) $chosen = $depositories[0];
    if (!$chosen) {
        api_error('Could not identify one Plaid deposit account for this bank record; select only the matching account', 422);
    }

    $pdo = getDB();
    $pdo->prepare(
        "UPDATE accounting_bank_accounts
            SET plaid_account_id = :pa,
                feed_provider = 'plaid_transactions',
                entity_id = COALESCE(entity_id, :eid),
                status = 'active',
                updated_at = NOW()
          WHERE tenant_id = :t AND id = :id"
    )->execute([
        'pa' => (string) $chosen['account_id'],
        'eid' => $bankEntityId,
        't' => $tid,
        'id' => $bankId,
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
