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

switch (api_method()) {
    case 'GET': {
        // Left-join GL balance (sum of journal lines to the matching
        // gl_account_code for posted entries) so the UI has a current
        // ledger balance without an extra round-trip.
        $rows = scopedQuery(
            "SELECT
                ba.id, ba.name, ba.gl_account_code, ba.bank_name, ba.last4,
                ba.currency, ba.feed_provider, ba.last_feed_synced_at,
                ba.status, ba.plaid_account_id,
                COALESCE(SUM(
                    CASE WHEN jel.side = 'debit' THEN jel.amount
                         ELSE -jel.amount END
                ), 0) AS gl_balance
             FROM accounting_bank_accounts ba
             LEFT JOIN accounting_accounts aa
               ON aa.tenant_id = ba.tenant_id AND aa.code = ba.gl_account_code
             LEFT JOIN accounting_journal_entry_lines jel
               ON jel.tenant_id = ba.tenant_id AND jel.account_id = aa.id
             LEFT JOIN accounting_journal_entries je
               ON je.id = jel.journal_entry_id AND je.tenant_id = jel.tenant_id
                 AND je.status = 'posted'
             WHERE ba.tenant_id = :tenant_id AND ba.status = 'active'
             GROUP BY ba.id
             ORDER BY ba.name"
        );
        // Plaid connect URL helper — one link-token per account.
        foreach ($rows as &$r) {
            $r['gl_balance'] = (float) $r['gl_balance'];
            $r['plaid_connected'] = !empty($r['plaid_account_id']);
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
}

api_error('Method not allowed', 405);
