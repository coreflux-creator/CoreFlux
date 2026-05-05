<?php
/**
 * Treasury — Deposit Accounts API.
 *
 *   GET                → list all active deposit accounts (bank + cash) with
 *                         current GL balance and last feed sync.
 *   POST               → create a new deposit account (proxies accounting's
 *                         bank_accounts create path so we don't duplicate logic).
 *
 * "Deposit account" = any GL account in the `accounting_bank_accounts`
 * table (checking / savings / cash-on-hand) plus ad-hoc asset-type COA
 * accounts flagged as deposit-class. For today, we lean on
 * accounting_bank_accounts as the authoritative source.
 */

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';

$ctx = api_require_auth();

/**
 * Self-heal: ensure the live-balance columns exist on plaid_accounts before
 * the GET LEFT-JOIN runs them. Production tenants who haven't pulled
 * migration 010 yet would otherwise hit "Unknown column pa.current_balance_cents"
 * and get an empty list back. Mirrors the same guard used by
 * plaidPersistAccountBalances() in core/plaid_service.php.
 */
function _treasuryEnsurePlaidBalanceColumns(): void {
    static $done = false;
    if ($done) return;
    $pdo = getDB();
    foreach ([
        ['current_balance_cents',   'BIGINT NULL'],
        ['available_balance_cents', 'BIGINT NULL'],
        ['limit_balance_cents',     'BIGINT NULL'],
        ['iso_currency_code',       'CHAR(3) NULL'],
        ['balance_as_of',           'TIMESTAMP NULL'],
    ] as [$col, $def]) {
        try {
            $chk = $pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.columns
                  WHERE table_schema = DATABASE()
                    AND table_name   = 'plaid_accounts'
                    AND column_name  = :c"
            );
            $chk->execute(['c' => $col]);
            if ((int) $chk->fetchColumn() === 0) {
                $pdo->exec("ALTER TABLE plaid_accounts ADD COLUMN {$col} {$def}");
            }
        } catch (\Throwable $_) { /* non-fatal */ }
    }
    $done = true;
}

switch (api_method()) {
    case 'GET': {
        _treasuryEnsurePlaidBalanceColumns();
        // Left-join GL balance (sum of journal lines to the matching
        // gl_account_code for posted entries) so the UI has a current
        // ledger balance without an extra round-trip. Also surface the
        // live Plaid balance (cached on plaid_accounts) so users see a
        // useful number even before any reconciliation has happened.
        $rows = scopedQuery(
            "SELECT
                ba.id, ba.name, ba.gl_account_code, ba.bank_name, ba.last4,
                ba.currency, ba.feed_provider, ba.last_feed_synced_at,
                ba.status, ba.plaid_account_id,
                pa.current_balance_cents   AS plaid_current_cents,
                pa.available_balance_cents AS plaid_available_cents,
                pa.balance_as_of           AS plaid_balance_as_of,
                COALESCE(SUM(jel.debit - jel.credit), 0) AS gl_balance
             FROM accounting_bank_accounts ba
             LEFT JOIN plaid_accounts pa
               ON pa.tenant_id = ba.tenant_id AND pa.account_id = ba.plaid_account_id
             LEFT JOIN accounting_accounts aa
               ON aa.tenant_id = ba.tenant_id AND aa.code = ba.gl_account_code
             LEFT JOIN accounting_journal_entries je
               ON je.tenant_id = aa.tenant_id AND je.status = 'posted'
             LEFT JOIN accounting_journal_entry_lines jel
               ON jel.je_id = je.id AND jel.account_id = aa.id
             WHERE ba.tenant_id = :tenant_id AND ba.status = 'active'
             GROUP BY ba.id
             ORDER BY ba.name"
        );
        // Plaid connect URL helper — one link-token per account.
        foreach ($rows as &$r) {
            $r['gl_balance']     = (float) $r['gl_balance'];
            $r['plaid_connected']= !empty($r['plaid_account_id']);
            $r['bank_balance']   = isset($r['plaid_current_cents'])   ? (int) $r['plaid_current_cents']   / 100 : null;
            $r['available_balance'] = isset($r['plaid_available_cents']) ? (int) $r['plaid_available_cents'] / 100 : null;
            unset($r['plaid_current_cents'], $r['plaid_available_cents']);
        }
        api_ok(['rows' => $rows, 'count' => count($rows)]);
    }

    case 'POST': {
        RBAC::requirePermission($ctx['user'], 'treasury.deposit.manage');
        $body = api_json_body();
        api_require_fields($body, ['name', 'gl_account_code']);

        $id = scopedInsert('accounting_bank_accounts', [
            'name'            => trim((string) $body['name']),
            'gl_account_code' => trim((string) $body['gl_account_code']),
            'bank_name'       => $body['bank_name'] ?? null,
            'last4'           => $body['last4'] ?? null,
            'currency'        => $body['currency'] ?? 'USD',
            'entity_id'       => $body['entity_id'] ?? null,
            'status'          => 'active',
        ]);

        try {
            $pdo = getDB();
            $pdo->prepare(
                'INSERT INTO audit_log (tenant_id, actor_user_id, event, target_id, meta_json, created_at)
                 VALUES (:t, :u, :e, :tid, :m, NOW())'
            )->execute([
                't' => currentTenantId(), 'u' => (int) ($ctx['user']['id'] ?? 0),
                'e' => 'treasury.deposit.created',
                'tid' => $id, 'm' => json_encode(['name' => $body['name']]),
            ]);
        } catch (\Throwable $_) {}

        api_ok(['id' => $id], 201);
    }

    case 'DELETE': {
        RBAC::requirePermission($ctx['user'], 'treasury.deposit.manage');
        $id   = (int) ($_GET['id'] ?? 0);
        $mode = (string) ($_GET['mode'] ?? 'hide');
        if ($id <= 0) api_error('id required', 400);
        if (!in_array($mode, ['hide', 'delete'], true)) {
            api_error('mode must be "hide" or "delete"', 422);
        }

        $row = scopedFind(
            'SELECT id, name, plaid_account_id FROM accounting_bank_accounts
              WHERE tenant_id = :tenant_id AND id = :id',
            ['id' => $id]
        );
        if (!$row) api_error('Deposit account not found', 404);

        $pdo = getDB();
        if ($mode === 'delete') {
            // Hard delete is only allowed when no posted journal entry references
            // the matching GL code — otherwise the ledger goes inconsistent.
            $usage = scopedFind(
                "SELECT COUNT(*) AS c
                   FROM accounting_journal_entry_lines jel
                   JOIN accounting_journal_entries je ON je.id = jel.je_id
                   JOIN accounting_accounts aa ON aa.id = jel.account_id
                   JOIN accounting_bank_accounts ba ON ba.gl_account_code = aa.code AND ba.tenant_id = aa.tenant_id
                  WHERE ba.tenant_id = :tenant_id AND ba.id = :id AND je.status = 'posted'",
                ['id' => $id]
            );
            if ((int) ($usage['c'] ?? 0) > 0) {
                api_error('Cannot hard-delete: posted journal entries reference this account. Use mode=hide instead.', 409, [
                    'posted_lines' => (int) $usage['c'],
                ]);
            }
            // Wipe statement lines, then the bank account row itself. Plaid_accounts
            // intentionally NOT touched (caller can disconnect the item separately).
            $pdo->prepare(
                'DELETE FROM accounting_bank_statement_lines
                  WHERE tenant_id = :t AND bank_account_id = :id'
            )->execute(['t' => currentTenantId(), 'id' => $id]);
            scopedDelete('accounting_bank_accounts', $id);
        } else {
            scopedUpdate('accounting_bank_accounts', $id, ['status' => 'closed']);
        }

        try {
            $pdo->prepare(
                'INSERT INTO audit_log (tenant_id, actor_user_id, event, target_id, meta_json, created_at)
                 VALUES (:t, :u, :e, :tid, :m, NOW())'
            )->execute([
                't' => currentTenantId(), 'u' => (int) ($ctx['user']['id'] ?? 0),
                'e' => 'treasury.deposit.' . ($mode === 'delete' ? 'deleted' : 'hidden'),
                'tid' => $id,
                'm' => json_encode(['name' => $row['name'], 'plaid_account_id' => $row['plaid_account_id']]),
            ]);
        } catch (\Throwable $_) {}

        api_ok(['ok' => true, 'mode' => $mode]);
    }
}

api_error('Method not allowed', 405);
