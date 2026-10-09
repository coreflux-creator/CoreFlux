<?php
/**
 * Accounting API — Bank accounts CRUD.
 *
 *   GET  /api/accounting/bank_accounts                       → list active accounts
 *   GET  /api/accounting/bank_accounts?id=N                  → detail (including unmatched line count + last-rec status)
 *   POST /api/accounting/bank_accounts                       → create
 *   PUT  /api/accounting/bank_accounts?id=N                  → update
 *   POST /api/accounting/bank_accounts?action=close&id=N     → set status=closed
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/active_entity.php';
require_once __DIR__ . '/../lib/accounting.php';
require_once __DIR__ . '/../lib/bank_account_ledger.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$method = api_method();
$action = $_GET['action'] ?? '';

function bankAccountResolveEntity(int $tenantId, ?int $requestedId): array
{
    if ($requestedId !== null) {
        $entity = scopedFind(
            'SELECT id, base_currency FROM accounting_entities
              WHERE tenant_id = :tenant_id AND id = :id AND active = 1',
            ['id' => $requestedId]
        );
        if (!$entity) api_error('Choose an active legal entity in this organization', 422);
        return $entity;
    }
    $entity = activeEntityResolveForTenant($tenantId);
    if (!$entity) api_error('No accounting entity is configured for this tenant', 422);
    return $entity;
}

if ($method === 'GET' && !empty($_GET['id'])) {
    rbac_legacy_require($user, 'accounting.coa.view');
    $id  = (int) $_GET['id'];
    $row = scopedFind(
        'SELECT ba.*, aa.id AS gl_account_id, aa.account_type AS gl_account_type,
                aa.normal_side AS gl_normal_side
           FROM accounting_bank_accounts ba
           LEFT JOIN accounting_accounts aa
             ON aa.tenant_id = ba.tenant_id AND aa.code = ba.gl_account_code
          WHERE ba.tenant_id = :tenant_id AND ba.id = :id',
        ['id' => $id]
    );
    if (!$row) api_error('Not found', 404);
    unset($row['plaid_access_token_ct']);  // never expose the cipher
    $unmatched = scopedQuery(
        'SELECT COUNT(*) AS c FROM accounting_bank_statement_lines
         WHERE tenant_id = :tenant_id AND bank_account_id = :id AND match_status = "unmatched"',
        ['id' => $id]
    );
    api_ok([
        'account' => $row,
        'unmatched_line_count' => (int) ($unmatched[0]['c'] ?? 0),
    ]);
}

if ($method === 'GET') {
    rbac_legacy_require($user, 'accounting.coa.view');
    // Sprint 6f — default to active accounts only so the list isn't cluttered
    // by accounts the user closed (or accidentally connected via Plaid and
    // never used). Pass ?include_closed=1 to see everything, or ?status=closed
    // to filter to the archive itself.
    $statusFilter = (string) ($_GET['status'] ?? '');
    $includeClosed = !empty($_GET['include_closed']);
    $where = ['accounting_bank_accounts.tenant_id = :tenant_id'];
    $params = [];
    if ($statusFilter) {
        $where[] = 'accounting_bank_accounts.status = :s';
        $params['s'] = $statusFilter;
    } elseif (!$includeClosed) {
        $where[] = "accounting_bank_accounts.status <> 'closed'";
    }
    $rows = scopedQuery(
        'SELECT accounting_bank_accounts.id, accounting_bank_accounts.entity_id,
                accounting_bank_accounts.name, accounting_bank_accounts.gl_account_code,
                accounting_bank_accounts.bank_name, accounting_bank_accounts.last4,
                accounting_bank_accounts.currency, accounting_bank_accounts.feed_provider,
                accounting_bank_accounts.last_feed_synced_at, accounting_bank_accounts.plaid_account_id,
                accounting_bank_accounts.status, accounting_bank_accounts.created_at,
                accounting_accounts.id AS gl_account_id
           FROM accounting_bank_accounts
           LEFT JOIN accounting_accounts
             ON accounting_accounts.tenant_id = accounting_bank_accounts.tenant_id
            AND accounting_accounts.code = accounting_bank_accounts.gl_account_code
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY accounting_bank_accounts.status, accounting_bank_accounts.name',
        $params
    );
    // Surface counts so the UI can show "12 active · 3 closed".
    $countStmt = scopedQuery(
        'SELECT status, COUNT(*) AS c FROM accounting_bank_accounts
          WHERE tenant_id = :tenant_id GROUP BY status'
    );
    $counts = [];
    foreach ($countStmt as $r) { $counts[$r['status']] = (int) $r['c']; }
    api_ok(['rows' => $rows, 'counts' => $counts]);
}

if ($method === 'POST' && $action === 'reopen') {
    rbac_legacy_require($user, 'accounting.coa.edit');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    scopedUpdate('accounting_bank_accounts', $id, ['status' => 'active']);
    accountingAudit('accounting.bank_account.reopened', [], $id);
    api_ok(['ok' => true]);
}

if ($method === 'POST' && $action === 'close') {
    rbac_legacy_require($user, 'accounting.coa.edit');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    scopedUpdate('accounting_bank_accounts', $id, ['status' => 'closed']);
    accountingAudit('accounting.bank_account.closed', [], $id);
    api_ok(['ok' => true]);
}

if ($method === 'POST') {
    rbac_legacy_require($user, 'accounting.coa.edit');
    $body = api_json_body();
    api_require_fields($body, ['name', 'gl_account_code']);
    $entity = bankAccountResolveEntity((int) $ctx['tenant_id'],
        !empty($body['entity_id']) ? (int) $body['entity_id'] : null);
    $code = trim((string) $body['gl_account_code']);
    $currency = strtoupper(trim((string) ($body['currency'] ?? $entity['base_currency'])));
    if (!preg_match('/^[A-Z]{3}$/', $currency)) api_error('Currency must be a three-letter code', 422);
    if (strcasecmp((string) $entity['base_currency'], $currency) !== 0) {
        api_error('The bank account currency must match the legal entity currency', 422);
    }
    bankAccountValidateLedger($code, $currency);
    try {
        $id = scopedInsert('accounting_bank_accounts', [
            'entity_id'        => (int) $entity['id'],
            'name'             => (string) $body['name'],
            'gl_account_code'  => $code,
            'bank_name'        => $body['bank_name']      ?? null,
            'routing_number'   => $body['routing_number'] ?? null,
            'last4'            => $body['last4']          ?? null,
            'currency'         => $currency,
            'feed_provider'    => $body['feed_provider']  ?? null,
            'plaid_account_id' => $body['plaid_account_id'] ?? null,
        ]);
    } catch (\PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            api_error('This cash ledger account is already linked to a bank account. Choose a different cash account.', 409);
        }
        throw $e;
    }
    accountingAudit('accounting.bank_account.created', ['name' => $body['name']], $id);
    api_ok(['id' => $id], 201);
}

if ($method === 'PUT') {
    rbac_legacy_require($user, 'accounting.coa.edit');
    $id   = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    $body = api_json_body();
    $current = scopedFind(
        'SELECT id, entity_id, gl_account_code, currency FROM accounting_bank_accounts
          WHERE tenant_id = :tenant_id AND id = :id',
        ['id' => $id]
    );
    if (!$current) api_error('Bank account not found', 404);
    $nextEntityId = (int) ($body['entity_id'] ?? $current['entity_id']);
    $nextCode = trim((string) ($body['gl_account_code'] ?? $current['gl_account_code']));
    $nextCurrency = strtoupper(trim((string) ($body['currency'] ?? $current['currency'])));
    if (!preg_match('/^[A-Z]{3}$/', $nextCurrency)) api_error('Currency must be a three-letter code', 422);
    $mappingChanged = $nextEntityId !== (int) $current['entity_id']
        || $nextCode !== (string) $current['gl_account_code']
        || $nextCurrency !== (string) $current['currency'];
    if ($mappingChanged) {
        $activity = scopedFind(
            'SELECT id FROM accounting_bank_statement_lines
              WHERE tenant_id = :tenant_id AND bank_account_id = :id LIMIT 1',
            ['id' => $id]
        );
        if ($activity) api_error('Bank account entity, cash account, and currency cannot change after statement activity', 409);
    }
    if (array_key_exists('entity_id', $body)) {
        $body['entity_id'] = $nextEntityId;
    }
    if ($mappingChanged) {
        $entity = bankAccountResolveEntity((int) $ctx['tenant_id'], $nextEntityId);
        if (strcasecmp((string) $entity['base_currency'], $nextCurrency) !== 0) {
            api_error('The bank account currency must match the legal entity currency', 422);
        }
        bankAccountValidateLedger($nextCode, $nextCurrency, $id);
    }
    if (array_key_exists('gl_account_code', $body)) $body['gl_account_code'] = $nextCode;
    if (array_key_exists('currency', $body)) $body['currency'] = $nextCurrency;
    $allowed = ['entity_id','name','gl_account_code','bank_name','routing_number','last4','currency','feed_provider','plaid_account_id','status'];
    $data = [];
    foreach ($allowed as $f) if (array_key_exists($f, $body)) $data[$f] = $body[$f];
    if ($data) {
        try {
            scopedUpdate('accounting_bank_accounts', $id, $data);
        } catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                api_error('This cash ledger account is already linked to a bank account. Choose a different cash account.', 409);
            }
            throw $e;
        }
    }
    accountingAudit('accounting.bank_account.updated', ['fields' => array_keys($data)], $id);
    api_ok(['ok' => true]);
}

api_error('Method not allowed', 405);
