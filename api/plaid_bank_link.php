<?php
/**
 * Plaid Bank Link — read-only bank feed connection (Auth + Transactions).
 *
 *   POST /api/plaid_bank_link.php                  → returns Link token
 *   POST /api/plaid_bank_link.php?action=exchange  → persists item + account
 *      body: {
 *        public_token: "public-sandbox-…",
 *        entity_id:   123, // required when tenant has multiple legal entities
 *        account_entity_ids: { plaid_account_id: 123 }, // optional per-account owner
 *        selected_account_ids: ["plaid_account_id"], // omitted = legacy all
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
require_once __DIR__ . '/../core/active_entity.php';

$ctx      = api_require_auth();
$user     = $ctx['user'];
$tenantId = (int) $ctx['tenant_id'];
rbac_legacy_require($user, 'accounting.bank.manage');

if (api_method() !== 'POST') api_error('Method not allowed', 405);
if (!plaidConfigured()) {
    api_error('Plaid not configured (PLAID_CLIENT_ID / PLAID_SECRET_*)', 503);
}

$action = (string) ($_GET['action'] ?? 'link_token');

if ($action === 'link_token') {
    try {
        // IMPORTANT: Putting 'auth' in `products` restricts Link to depository
        // accounts only — credit cards, loans, and lines of credit are hidden
        // (Auth only supports debitable depository accounts). For a unified
        // read-only bank feed we want EVERY account the user authorizes
        // (deposits + credit cards + loans), so 'transactions' is the required
        // product and 'auth' is attached opportunistically where the
        // institution supports it (depository accounts). 'liabilities'
        // enrichment (APR, statement balance, min payment) is requested as
        // optional so credit/loan accounts surface their extra data when
        // available without blocking institutions that don't support it.
        //
        // `account_filters` keeps investment / brokerage / payroll cards out of
        // the picker entirely so the user only sees account types CoreFlux can
        // mirror into Treasury. Per-account opt-in is then enforced in our own
        // post-Link picker (see exchange branch + UI).
        $resp = plaidPost('/link/token/create', [
            'client_name'                    => 'CoreFlux Treasury',
            'user'                           => ['client_user_id' => 'cf_tenant_' . $tenantId . '_u' . ($user['id'] ?? 0)],
            'language'                       => 'en',
            'country_codes'                  => ['US'],
            'products'                       => ['transactions'],
            'required_if_supported_products' => ['auth'],
            'optional_products'              => ['liabilities'],
            'account_filters'                => [
                'depository' => ['account_subtypes' => ['checking', 'savings', 'money market', 'cd']],
                'credit'     => ['account_subtypes' => ['credit card', 'paypal']],
                'loan'       => ['account_subtypes' => ['line of credit', 'student', 'mortgage', 'auto', 'commercial', 'home equity', 'consumer', 'other']],
            ],
            'webhook'                        => plaidWebhookUrl(),
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
    $availableEntities = activeEntityAvailable($tenantId);
    if (!$availableEntities) api_error('No accounting entity is configured for this tenant', 422);
    $validEntityIds = array_fill_keys(array_column($availableEntities, 'id'), true);
    $requestedEntityId = !empty($body['entity_id']) ? (int) $body['entity_id'] : null;
    if (count($availableEntities) > 1 && !$requestedEntityId) {
        api_error('Choose a legal entity before connecting bank accounts', 422);
    }
    try {
        $entity = activeEntityResolveForTenant($tenantId, $requestedEntityId);
    } catch (\Throwable $e) {
        api_error($e->getMessage(), 422);
    }
    $entityId = (int) $entity['id'];
    $accountEntityIds = [];
    if (isset($body['account_entity_ids'])) {
        if (!is_array($body['account_entity_ids'])) api_error('account_entity_ids must be an object', 422);
        foreach ($body['account_entity_ids'] as $accountId => $ownerId) {
            if ((string) $accountId === '' || !is_scalar($ownerId)) {
                api_error('Invalid bank account entity assignment', 422);
            }
            $ownerId = filter_var($ownerId, FILTER_VALIDATE_INT);
            if (!$ownerId || !isset($validEntityIds[$ownerId])) {
                api_error('Bank account entity does not belong to the active tenant', 422);
            }
            $accountEntityIds[(string) $accountId] = $ownerId;
        }
    }

    // An omitted selection mirrors all accounts for legacy callers; an
    // explicitly empty selection mirrors none.
    $selectedIds = [];
    if (array_key_exists('selected_account_ids', $body)) {
        if (!is_array($body['selected_account_ids'])) api_error('selected_account_ids must be an array', 422);
        foreach ($body['selected_account_ids'] as $selectedId) {
            if (!is_scalar($selectedId)) api_error('Invalid bank account selection', 422);
        }
        $selectedIds = array_values(array_filter(array_map(
            fn ($v) => (string) $v,
            $body['selected_account_ids']
        ), fn ($v) => $v !== ''));
    }
    $selectedSet = array_key_exists('selected_account_ids', $body)
        ? array_flip($selectedIds)
        : null;

    // The former shared-GL option is ignored. A feed needs its own ledger
    // account so a bank line can be tied to an unambiguous cash movement.

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
        'prods'=> json_encode(['transactions', 'auth_optional', 'liabilities_optional']),
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

    // Defensive: ensure the liability-link column exists. Migration 002 in the
    // treasury module adds it idempotently; some hosts may have linked the API
    // before pulling the migration. Auto-add at runtime so credit/loan inserts
    // don't blow up with "Unknown column 'plaid_account_id'".
    try {
        $colCheck = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name   = 'treasury_liability_accounts'
                AND column_name  = 'plaid_account_id'"
        );
        $colCheck->execute();
        if ((int) $colCheck->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE treasury_liability_accounts
                          ADD COLUMN plaid_account_id VARCHAR(80) NULL");
        }
    } catch (\Throwable $e) {
        // Non-fatal: liability mirror will fail per-account and surface to UI.
    }

    $createdBank = [];
    $createdLiab = [];
    $itemErrors  = [];
    $skippedOptOut = [];
    foreach ($plaidAccounts as $acc) {
        $accId   = (string) ($acc['account_id'] ?? '');
        if ($accId === '') continue;
        $name    = (string) ($acc['name']          ?? '');
        $official= (string) ($acc['official_name'] ?? '') ?: null;
        $mask    = (string) ($acc['mask']          ?? '') ?: null;
        $type    = (string) ($acc['type']          ?? '') ?: null;
        $subtype = (string) ($acc['subtype']       ?? '') ?: null;

        try {
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
        } catch (\Throwable $e) {
            $itemErrors[] = "plaid_accounts insert failed for {$name}: " . $e->getMessage();
            continue;
        }

        // Per-account opt-in gate: if the UI sent an explicit allow-list,
        // skip mirroring everything else. The plaid_accounts row above is
        // still kept so the orphan-backfill diagnostic can ingest it later.
        if ($selectedSet !== null && !isset($selectedSet[$accId])) {
            $skippedOptOut[] = "{$name}" . ($mask ? " …{$mask}" : '');
            continue;
        }
        $accountEntityId = $accountEntityIds[$accId] ?? $entityId;

        if ($type === 'depository') {
            try {
                // 1) Exact re-link match — same Plaid account_id seen before.
                $check = $pdo->prepare(
                    'SELECT id, entity_id FROM accounting_bank_accounts
                      WHERE tenant_id = :t AND plaid_account_id = :a LIMIT 1'
                );
                $check->execute(['t' => $tenantId, 'a' => $accId]);
                $existing = $check->fetch(PDO::FETCH_ASSOC);
                if ($existing) {
                    if ((int) ($existing['entity_id'] ?? 0) !== $accountEntityId) {
                        throw new RuntimeException('This connected bank account belongs to another or unassigned legal entity');
                    }
                    // Re-activate in case it was previously hidden by a disconnect.
                    $pdo->prepare(
                        "UPDATE accounting_bank_accounts
                            SET status = 'active',
                                updated_at = NOW()
                          WHERE tenant_id = :t AND id = :id AND entity_id = :eid"
                    )->execute(['t' => $tenantId, 'id' => (int) $existing['id'], 'eid' => $accountEntityId]);
                    $createdBank[] = (int) $existing['id'];
                    continue;
                }

                // 2) Adopt only an unambiguous same-bank, same-last4 account
                //    when Plaid changes its account id during a reconnect.
                $instLabel = (string) ($institution['name'] ?? '');
                if ($mask && $instLabel !== '') {
                    // A missing institution is not enough identity to adopt.
                    // Bind :bk once: PDO with EMULATE_PREPARES=false rejects
                    // the former duplicated-placeholder OR with HY093.
                    $sql = 'SELECT id FROM accounting_bank_accounts
                              WHERE tenant_id = :t
                                AND entity_id = :eid
                                AND last4     = :l4
                                AND bank_name = :bk
                             ORDER BY (status = "active") DESC, updated_at DESC, id DESC
                             LIMIT 2';
                    $stmt   = $pdo->prepare($sql);
                    $params = ['t' => $tenantId, 'eid' => $accountEntityId, 'l4' => $mask,
                        'bk' => $instLabel];
                    $stmt->execute($params);
                    $candidates = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    $adoptId = count($candidates) === 1 ? (int) $candidates[0] : 0;
                    if ($adoptId > 0) {
                        $pdo->prepare(
                            "UPDATE accounting_bank_accounts
                                SET plaid_account_id = :pa,
                                    feed_provider    = 'plaid_transactions',
                                    status           = 'active',
                                    updated_at       = NOW()
                              WHERE tenant_id = :t AND id = :id AND entity_id = :eid"
                        )->execute(['t' => $tenantId, 'pa' => $accId, 'id' => $adoptId, 'eid' => $accountEntityId]);
                        $createdBank[] = $adoptId;
                        continue;
                    }
                }

                // 3) Brand-new bank account — allocate a distinct cash ledger.
                $baseCode = $subtype === 'savings' ? '1010' : '1000';
                $glCode = plaidAllocateBankGlCode($pdo, $tenantId, $baseCode, $mask, $accId);

                $bankName  = $instLabel !== '' ? $instLabel : ($name ?: 'Bank');
                $insName   = trim(($instLabel ? "{$instLabel} — " : '') . ($name ?: 'Account'));

                // 3a) Ensure the COMPANION COA (accounting_accounts) row exists
                // so the bank shows up on the Chart of Accounts page. Without
                // this, accounting_bank_accounts has a gl_account_code that
                // doesn't resolve to a real GL account and the CoA list is empty.
                //
                $aaCheck = $pdo->prepare(
                    'SELECT id FROM accounting_accounts
                      WHERE tenant_id = :t AND code = :c LIMIT 1'
                );
                $aaCheck->execute(['t' => $tenantId, 'c' => $glCode]);
                $aaExisting = (int) $aaCheck->fetchColumn();
                if ($aaExisting === 0) {
                    $glLabel = $insName !== '' ? $insName : ($name ?: 'Bank account');
                    $aaTypeName = $subtype === 'savings' ? 'Cash — Savings' : 'Cash — Checking';
                    // Default name for the GL row carries the institution +
                    // account nickname + last4, so the operator can identify it
                    // on Chart of Accounts at a glance.
                    $glFull = $glLabel . ($mask ? " …{$mask}" : '');
                    try {
                        $pdo->prepare(
                            "INSERT INTO accounting_accounts
                                (tenant_id, code, name, account_type, normal_side,
                                 cash_flow_tag, is_postable, parent_account_id, active, created_at)
                             VALUES (:t, :c, :n, 'asset', 'debit',
                                     'cash_and_equivalents', 1, NULL, 1, NOW())"
                        )->execute(['t' => $tenantId, 'c' => $glCode, 'n' => $glFull]);
                    } catch (\Throwable $e) {
                        // If we lose a race on the UNIQUE (tenant, code) constraint
                        // we re-resolve below; otherwise re-throw so the outer
                        // catch records it in $itemErrors.
                        $aaCheck->execute(['t' => $tenantId, 'c' => $glCode]);
                        if ((int) $aaCheck->fetchColumn() === 0) throw $e;
                    }
                }

                $pdo->prepare(
                    'INSERT INTO accounting_bank_accounts
                        (tenant_id, entity_id, name, gl_account_code, bank_name, last4, currency,
                         feed_provider, status, plaid_account_id, last_feed_synced_at,
                         created_at)
                     VALUES (:t, :eid, :nm, :gl, :bk, :l4, :c, "plaid_transactions", "active", :pa, NULL, NOW())'
                )->execute([
                    't'  => $tenantId, 'eid' => $accountEntityId, 'nm' => $insName, 'gl' => $glCode,
                    'bk' => $bankName, 'l4' => $mask, 'c'  => 'USD', 'pa' => $accId,
                ]);
                $createdBank[] = (int) $pdo->lastInsertId();
            } catch (\Throwable $e) {
                $itemErrors[] = "deposit account '{$name}' (...{$mask}): " . $e->getMessage();
            }
            continue;
        }

        if ($type === 'credit' || $type === 'loan') {
            try {
                // 1) Exact re-link match.
                $check = $pdo->prepare(
                    'SELECT id, account_id, entity_id FROM treasury_liability_accounts
                      WHERE tenant_id = :t AND plaid_account_id = :a LIMIT 1'
                );
                $check->execute(['t' => $tenantId, 'a' => $accId]);
                $existingRow = $check->fetch(PDO::FETCH_ASSOC);
                if ($existingRow) {
                    if ((int) ($existingRow['entity_id'] ?? 0) !== $accountEntityId) {
                        throw new RuntimeException('This connected liability belongs to another or unassigned legal entity');
                    }
                    // Re-activate companion COA row in case it was deactivated.
                    $pdo->prepare(
                        "UPDATE accounting_accounts
                            SET active = 1, updated_at = NOW()
                          WHERE tenant_id = :t AND id = :id AND active = 0"
                    )->execute(['t' => $tenantId, 'id' => (int) $existingRow['account_id']]);
                    $createdLiab[] = (int) $existingRow['id'];
                    continue;
                }

                // 2) Adopt only an unambiguous same-institution, same-last4
                //    liability when Plaid changes its account id.
                $instLabelRaw = (string) ($institution['name'] ?? '');
                if ($mask && $instLabelRaw !== '') {
                    // Require institution identity and bind :inst once;
                    // EMULATE_PREPARES=false rejects the former OR with HY093.
                    $sql = 'SELECT tla.id, tla.account_id FROM treasury_liability_accounts tla
                              WHERE tla.tenant_id = :t
                                AND tla.entity_id = :eid
                                AND tla.last4     = :l4
                                AND tla.institution_name = :inst
                             ORDER BY tla.updated_at DESC, tla.id DESC
                             LIMIT 2';
                    $stmt   = $pdo->prepare($sql);
                    $params = ['t' => $tenantId, 'eid' => $accountEntityId,
                        'l4' => $mask, 'inst' => $instLabelRaw];
                    $stmt->execute($params);
                    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $adopt = count($candidates) === 1 ? $candidates[0] : null;
                    if ($adopt) {
                        $pdo->prepare(
                            'UPDATE treasury_liability_accounts
                                SET plaid_account_id = :pa, updated_at = NOW()
                              WHERE tenant_id = :t AND id = :id AND entity_id = :eid'
                        )->execute(['t' => $tenantId, 'pa' => $accId,
                            'id' => (int) $adopt['id'], 'eid' => $accountEntityId]);
                        $pdo->prepare(
                            'UPDATE accounting_accounts
                                SET active = 1, updated_at = NOW()
                              WHERE tenant_id = :t AND id = :id'
                        )->execute(['t' => $tenantId, 'id' => (int) $adopt['account_id']]);
                        $createdLiab[] = (int) $adopt['id'];
                        continue;
                    }
                }

                // 3) Brand-new card / loan — allocate a distinct ledger row.
                $baseCode = $type === 'loan' ? '2200' : '2100';
                $glName   = $type === 'loan' ? 'Notes Payable' : 'Credit Card Payable';
                $glCode = plaidAllocateBankGlCode($pdo, $tenantId, $baseCode, $mask, $accId);

                $treasurySubtype = match (true) {
                    $subtype === 'credit card'                  => 'credit_card',
                    in_array($subtype, ['line of credit'], true)=> 'line_of_credit',
                    $type    === 'loan'                         => 'loan',
                    default                                     => 'other_liability',
                };

                // Find or create the GL account row.
                $aaCheck = $pdo->prepare(
                    'SELECT id FROM accounting_accounts
                      WHERE tenant_id = :t AND code = :c LIMIT 1'
                );
                $aaCheck->execute(['t' => $tenantId, 'c' => $glCode]);
                $aaId = (int) $aaCheck->fetchColumn();
                if ($aaId === 0) {
                    $glLabel = $name !== '' ? "{$glName} — {$name}" . ($mask ? " …{$mask}" : '') : $glName;

                    // Auto-grouping: ensure an institution-level parent exists
                    // (e.g. "American Express", "Chase") so cards from the
                    // same issuer roll up together. User can manually
                    // re-parent later via /modules/accounting/accounts.
                    $instLabel = trim((string) ($institution['name'] ?? ''));
                    $parentId  = null;
                    if ($instLabel !== '') {
                        $parentId = plaidEnsureInstitutionParent(
                            $pdo, $tenantId, $instLabel, $baseCode
                        );
                    }

                    $pdo->prepare(
                        "INSERT INTO accounting_accounts
                            (tenant_id, code, name, account_type, normal_side, parent_account_id, active, created_at)
                         VALUES (:t, :c, :n, 'liability', 'credit', :pa, 1, NOW())"
                    )->execute(['t' => $tenantId, 'c' => $glCode, 'n' => $glLabel, 'pa' => $parentId]);
                    $aaId = (int) $pdo->lastInsertId();
                }

                $pdo->prepare(
                    'INSERT INTO treasury_liability_accounts
                        (tenant_id, account_id, entity_id, subtype, institution_name, last4,
                         plaid_account_id, created_at)
                     VALUES (:t, :aid, :eid, :st, :inst, :l4, :pa, NOW())'
                )->execute([
                    't'   => $tenantId,
                    'aid' => $aaId,
                    'eid' => $accountEntityId,
                    'st'  => $treasurySubtype,
                    'inst'=> (string) ($institution['name'] ?? '') ?: null,
                    'l4'  => $mask,
                    'pa'  => $accId,
                ]);
                $createdLiab[] = (int) $pdo->lastInsertId();
            } catch (\Throwable $e) {
                $itemErrors[] = "liability account '{$name}' (...{$mask}): " . $e->getMessage();
            }
            continue;
        }

        // Unknown / investment / other — leave on plaid_accounts only.
        $itemErrors[] = "skipped {$type}/{$subtype} account '{$name}' (not a deposit or liability)";
    }

    plaidAudit('payment_rails.plaid.bank_linked', [
        'item_id'                  => $itemId,
        'institution'              => $institution['name'] ?? null,
        'bank_accounts_created'    => $createdBank,
        'liability_accounts_created' => $createdLiab,
        'selected_account_ids'     => $selectedIds,
        'skipped_opt_out'          => $skippedOptOut,
        'errors'                   => $itemErrors,
    ], null);

    // Cache live Plaid balances so the Treasury list shows a non-zero "Bank
    // balance" column the moment the user lands back on the page. Done AFTER
    // the upsert loop above so plaid_accounts rows exist.
    try { plaidPersistAccountBalances($pdo, $tenantId, $plaidAccounts); }
    catch (\Throwable $_) { /* non-fatal */ }

    api_ok([
        'ok'                         => true,
        'item_id'                    => $itemId,
        'plaid_item_pk'              => $itemPk,
        'accounts_linked'            => count($plaidAccounts),
        'bank_accounts_created'      => $createdBank,
        'liability_accounts_created' => $createdLiab,
        'skipped_opt_out'            => $skippedOptOut,
        'errors'                     => $itemErrors,
    ]);
}

api_error('Unknown action: ' . $action, 422);

