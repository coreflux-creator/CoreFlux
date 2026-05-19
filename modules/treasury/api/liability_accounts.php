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

/**
 * Self-heal: ensure the live-balance columns exist on plaid_accounts before
 * the GET LEFT-JOIN runs them. Production tenants who haven't pulled
 * migration 010 yet would otherwise hit "Unknown column pa.current_balance_cents"
 * and get an empty list back.
 */
function _treasuryLiabilityEnsurePlaidBalanceColumns(): void {
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
    // Also ensure tla.plaid_account_id exists (from migration 002).
    try {
        $chk = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name   = 'treasury_liability_accounts'
                AND column_name  = 'plaid_account_id'"
        );
        $chk->execute();
        if ((int) $chk->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE treasury_liability_accounts
                          ADD COLUMN plaid_account_id VARCHAR(80) NULL");
        }
    } catch (\Throwable $_) { /* non-fatal */ }
    $done = true;
}

switch (api_method()) {
    case 'GET': {
        _treasuryLiabilityEnsurePlaidBalanceColumns();
        // Liabilities are CREDIT-normal accounts, so we flip the sign so
        // the UI shows a positive outstanding balance. Also surface the
        // live Plaid balance (cached on plaid_accounts) so users see the
        // outstanding even before any JE has posted.
        $rows = scopedQuery(
            "SELECT
                aa.id, aa.code, aa.name, aa.account_type, aa.active,
                tla.subtype, tla.last4, tla.institution_name, tla.credit_limit_cents,
                tla.apr_bps, tla.statement_day, tla.autopay_from_bank_account_id,
                tla.plaid_account_id,
                pa.current_balance_cents   AS plaid_current_cents,
                pa.available_balance_cents AS plaid_available_cents,
                pa.limit_balance_cents     AS plaid_limit_cents,
                pa.balance_as_of           AS plaid_balance_as_of,
                COALESCE(SUM(jel.credit - jel.debit), 0) AS gl_balance
             FROM accounting_accounts aa
             LEFT JOIN treasury_liability_accounts tla
               ON tla.tenant_id = aa.tenant_id AND tla.account_id = aa.id
             LEFT JOIN plaid_accounts pa
               ON pa.tenant_id = aa.tenant_id AND pa.account_id = tla.plaid_account_id
             LEFT JOIN accounting_journal_entries je
               ON je.tenant_id = aa.tenant_id AND je.status = 'posted'
             LEFT JOIN accounting_journal_entry_lines jel
               ON jel.je_id = je.id AND jel.account_id = aa.id
             WHERE aa.tenant_id = :tenant_id
               AND aa.account_type = 'liability'
               AND aa.active = 1
             GROUP BY aa.id
             ORDER BY aa.code"
        );
        foreach ($rows as &$r) {
            $r['gl_balance']    = (float) $r['gl_balance'];
            $r['credit_limit']  = isset($r['credit_limit_cents']) ? (int) $r['credit_limit_cents'] / 100 : null;
            $r['apr_pct']       = isset($r['apr_bps']) ? (int) $r['apr_bps'] / 100 : null;
            // Plaid bank balance: for credit cards this is the outstanding amount owed
            // (already a positive number from Plaid); for loans it's the outstanding principal.
            $r['bank_balance']     = isset($r['plaid_current_cents'])   ? (int) $r['plaid_current_cents']   / 100 : null;
            $r['available_balance']= isset($r['plaid_available_cents']) ? (int) $r['plaid_available_cents'] / 100 : null;
            // Plaid-reported credit limit takes precedence over the manual one when present.
            if ($r['credit_limit'] === null && isset($r['plaid_limit_cents'])) {
                $r['credit_limit'] = (int) $r['plaid_limit_cents'] / 100;
            }
            $r['plaid_connected']= !empty($r['plaid_account_id']);
            unset($r['plaid_current_cents'], $r['plaid_available_cents'], $r['plaid_limit_cents']);
        }
        api_ok(['rows' => $rows, 'count' => count($rows)]);
    }

    case 'POST': {
        rbac_legacy_require($ctx['user'], 'treasury.liability.manage');
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

    case 'DELETE': {
        rbac_legacy_require($ctx['user'], 'treasury.liability.manage');
        $id   = (int) ($_GET['id'] ?? 0);
        $mode = (string) ($_GET['mode'] ?? 'hide');
        if ($id <= 0) api_error('id required', 400);
        if (!in_array($mode, ['hide', 'delete'], true)) {
            api_error('mode must be "hide" or "delete"', 422);
        }

        $row = scopedFind(
            "SELECT aa.id, aa.code, aa.name
               FROM accounting_accounts aa
              WHERE aa.tenant_id = :tenant_id AND aa.id = :id AND aa.account_type = 'liability'",
            ['id' => $id]
        );
        if (!$row) api_error('Liability account not found', 404);

        $pdo = getDB();
        if ($mode === 'delete') {
            $usage = scopedFind(
                "SELECT COUNT(*) AS c
                   FROM accounting_journal_entry_lines jel
                   JOIN accounting_journal_entries je ON je.id = jel.je_id
                  WHERE je.tenant_id = :tenant_id AND jel.account_id = :id AND je.status = 'posted'",
                ['id' => $id]
            );
            if ((int) ($usage['c'] ?? 0) > 0) {
                api_error('Cannot hard-delete: posted journal entries reference this liability. Use mode=hide instead.', 409, [
                    'posted_lines' => (int) $usage['c'],
                ]);
            }
            // Best-effort: clear liability statement lines + companion row, then COA row.
            try {
                $pdo->prepare(
                    'DELETE FROM treasury_liability_statement_lines
                      WHERE tenant_id = :t AND liability_account_id = :id'
                )->execute(['t' => currentTenantId(), 'id' => $id]);
            } catch (\Throwable $_) { /* table may not exist on fresh tenant */ }
            // Companion row in treasury_liability_accounts is keyed by account_id.
            $pdo->prepare(
                'DELETE FROM treasury_liability_accounts
                  WHERE tenant_id = :t AND account_id = :aid'
            )->execute(['t' => currentTenantId(), 'aid' => $id]);
            scopedDelete('accounting_accounts', $id);
        } else {
            scopedUpdate('accounting_accounts', $id, ['active' => 0]);
        }

        try {
            $pdo->prepare(
                'INSERT INTO audit_log (tenant_id, actor_user_id, event, target_id, meta_json, created_at)
                 VALUES (:t, :u, :e, :tid, :m, NOW())'
            )->execute([
                't' => currentTenantId(), 'u' => (int) ($ctx['user']['id'] ?? 0),
                'e' => 'treasury.liability.' . ($mode === 'delete' ? 'deleted' : 'hidden'),
                'tid' => $id,
                'm' => json_encode(['code' => $row['code'], 'name' => $row['name']]),
            ]);
        } catch (\Throwable $_) {}

        api_ok(['ok' => true, 'mode' => $mode]);
    }
}

api_error('Method not allowed', 405);
