<?php
/**
 * Treasury — Liability Accounts API.
 *
 *   GET  → list all liability COA accounts (credit cards, loans, lines of
 *          credit) with current GL balance.
 *   POST → create a new liability COA account.
 *
 * Liability accounts live in `accounting_accounts` with account_type =
 * 'liability'. We tag them with a treasury subtype (credit_card / loan /
 * line_of_credit / other_liability) in a lightweight companion table so
 * we can render the right icon and ledger treatment, without polluting
 * the core COA.
 */

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';

$ctx = api_require_auth();

switch (api_method()) {
    case 'GET': {
        // Liabilities are CREDIT-normal accounts, so we flip the sign so
        // the UI shows a positive outstanding balance.
        $rows = scopedQuery(
            "SELECT
                aa.id, aa.code, aa.name, aa.account_type, aa.active,
                tla.subtype, tla.last4, tla.institution_name, tla.credit_limit_cents,
                tla.apr_bps, tla.statement_day, tla.autopay_from_bank_account_id,
                COALESCE(SUM(
                    CASE WHEN jel.side = 'credit' THEN jel.amount
                         ELSE -jel.amount END
                ), 0) AS gl_balance
             FROM accounting_accounts aa
             LEFT JOIN treasury_liability_accounts tla
               ON tla.tenant_id = aa.tenant_id AND tla.account_id = aa.id
             LEFT JOIN accounting_journal_entry_lines jel
               ON jel.tenant_id = aa.tenant_id AND jel.account_id = aa.id
             LEFT JOIN accounting_journal_entries je
               ON je.id = jel.journal_entry_id AND je.tenant_id = jel.tenant_id
                 AND je.status = 'posted'
             WHERE aa.tenant_id = :tenant_id
               AND aa.account_type = 'liability'
               AND aa.active = 1
             GROUP BY aa.id
             ORDER BY aa.code"
        );
        foreach ($rows as &$r) {
            $r['gl_balance']   = (float) $r['gl_balance'];
            $r['credit_limit'] = isset($r['credit_limit_cents']) ? (int) $r['credit_limit_cents'] / 100 : null;
            $r['apr_pct']      = isset($r['apr_bps']) ? (int) $r['apr_bps'] / 100 : null;
        }
        api_ok(['rows' => $rows, 'count' => count($rows)]);
    }

    case 'POST': {
        RBAC::requirePermission($ctx['user'], 'treasury.liability.manage');
        $body = api_json_body();
        api_require_fields($body, ['code', 'name', 'subtype']);
        $allowedSubtypes = ['credit_card', 'loan', 'line_of_credit', 'other_liability'];
        if (!in_array((string) $body['subtype'], $allowedSubtypes, true)) {
            api_error('subtype must be one of: ' . implode(', ', $allowedSubtypes), 422);
        }

        $pdo = getDB();
        $pdo->beginTransaction();
        try {
            $accountId = scopedInsert('accounting_accounts', [
                'code'         => trim((string) $body['code']),
                'name'         => trim((string) $body['name']),
                'account_type' => 'liability',
                'active'       => 1,
            ]);
            scopedInsert('treasury_liability_accounts', [
                'account_id'          => $accountId,
                'subtype'             => $body['subtype'],
                'last4'               => $body['last4'] ?? null,
                'institution_name'    => $body['institution_name'] ?? null,
                'credit_limit_cents'  => isset($body['credit_limit']) ? (int) round(((float) $body['credit_limit']) * 100) : null,
                'apr_bps'             => isset($body['apr_pct']) ? (int) round(((float) $body['apr_pct']) * 100) : null,
                'statement_day'       => isset($body['statement_day']) ? (int) $body['statement_day'] : null,
                'autopay_from_bank_account_id' => $body['autopay_from_bank_account_id'] ?? null,
            ]);
            $pdo->prepare(
                'INSERT INTO audit_log (tenant_id, actor_user_id, event, target_id, meta_json, created_at)
                 VALUES (:t, :u, :e, :tid, :m, NOW())'
            )->execute([
                't' => currentTenantId(), 'u' => (int) ($ctx['user']['id'] ?? 0),
                'e' => 'treasury.liability.created',
                'tid' => $accountId,
                'm' => json_encode(['subtype' => $body['subtype'], 'name' => $body['name']]),
            ]);
            $pdo->commit();
            api_ok(['id' => $accountId, 'account_id' => $accountId], 201);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            api_error('Failed to create liability account: ' . $e->getMessage(), 422);
        }
    }
}

api_error('Method not allowed', 405);
