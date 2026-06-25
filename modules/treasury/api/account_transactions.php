<?php
/**
 * Treasury — Account Transactions API.
 *
 *   GET ?account_id=N&type=deposit|liability[&limit=100]
 *
 * Returns the flat list of statement / Plaid-fed lines for either a deposit
 * (accounting_bank_accounts) or liability (accounting_accounts where
 * type='liability') account, newest first. Used by the deposit / liability
 * detail drawers in Treasury so users can see the actual feed data.
 *
 *   POST ?action=sync (body: { plaid_item_pk: int })
 *
 * Convenience trigger that calls /api/plaid_sync_transactions.php for the
 * given Plaid item PK so users can refresh from the same place they're
 * viewing the data.
 *
 * Permission: `accounting.bank.manage`.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';

$ctx      = api_require_auth();
$tenantId = (int) $ctx['tenant_id'];
rbac_legacy_require($ctx['user'], 'accounting.bank.manage');
$pdo = getDB();

if (api_method() === 'POST') {
    $action = (string) ($_GET['action'] ?? '');
    if (!in_array($action, ['ignore', 'unmatch', 'categorize_and_post', 'match', 'split_categorize'], true)) {
        api_error(
            "POST requires action=ignore|unmatch|categorize_and_post|match|split_categorize. "
            . "To pull from Plaid, call /api/plaid_sync_transactions.php directly.",
            422
        );
    }
    $body = api_json_body();
    $type = (string) ($body['type'] ?? $_GET['type'] ?? '');
    if (!in_array($type, ['deposit', 'liability'], true)) {
        api_error("type='deposit' or 'liability' required", 422);
    }
    $lineId = (int) ($body['line_id'] ?? 0);
    if ($lineId <= 0) api_error('line_id required', 422);

    $table = $type === 'deposit'
        ? 'accounting_bank_statement_lines'
        : 'treasury_liability_statement_lines';
    $col   = $type === 'deposit' ? 'bank_account_id' : 'liability_account_id';

    // Ensure migration 004 cols exist on first POST in case the deploy hasn't run yet.
    if ($type === 'liability') {
        try {
            $pdo->exec("ALTER TABLE treasury_liability_statement_lines
                ADD COLUMN matched_je_id BIGINT UNSIGNED NULL AFTER match_status");
        } catch (\Throwable $_) { /* already exists */ }
    }

    // Load the line scoped to tenant.
    $line = $pdo->prepare("SELECT * FROM {$table} WHERE tenant_id = :t AND id = :id LIMIT 1");
    $line->execute(['t' => $tenantId, 'id' => $lineId]);
    $line = $line->fetch(PDO::FETCH_ASSOC);
    if (!$line) api_error('Statement line not found', 404);

    if ($action === 'ignore') {
        $pdo->prepare("UPDATE {$table} SET match_status = 'ignored'
                       WHERE tenant_id = :t AND id = :id")
            ->execute(['t' => $tenantId, 'id' => $lineId]);
        api_ok(['ok' => true, 'line_id' => $lineId, 'match_status' => 'ignored']);
    }

    if ($action === 'unmatch') {
        $pdo->prepare("UPDATE {$table}
                          SET match_status = 'unmatched', matched_je_id = NULL
                        WHERE tenant_id = :t AND id = :id")
            ->execute(['t' => $tenantId, 'id' => $lineId]);
        api_ok(['ok' => true, 'line_id' => $lineId, 'match_status' => 'unmatched']);
    }

    if ($action === 'match') {
        $jeId = (int) ($body['je_id'] ?? 0);
        if ($jeId <= 0) api_error('je_id required', 422);
        $jeOk = $pdo->prepare(
            'SELECT 1 FROM accounting_journal_entries
              WHERE tenant_id = :t AND id = :id LIMIT 1'
        );
        $jeOk->execute(['t' => $tenantId, 'id' => $jeId]);
        if (!$jeOk->fetchColumn()) api_error('Journal entry not found', 404);

        $pdo->prepare("UPDATE {$table}
                          SET match_status = 'matched', matched_je_id = :je
                        WHERE tenant_id = :t AND id = :id")
            ->execute(['t' => $tenantId, 'id' => $lineId, 'je' => $jeId]);
        api_ok(['ok' => true, 'line_id' => $lineId, 'matched_je_id' => $jeId]);
    }

    if ($action === 'split_categorize') {
        require_once __DIR__ . '/../../accounting/lib/accounting.php';
        require_once __DIR__ . '/../../../core/module_emission_discipline.php';
        require_once __DIR__ . '/../../../core/posting_engine/process.php';

        if (($line['match_status'] ?? '') !== 'unmatched') api_error('Already matched', 422);
        $splits = (array) ($body['splits'] ?? []);
        if (count($splits) < 1) api_error('At least one split row required', 422);

        $abs = round(abs((float) $line['amount']), 2);
        $sum = 0.0;
        foreach ($splits as $s) {
            if (empty($s['account_id']) || !is_numeric($s['amount'])) {
                api_error('Each split needs account_id + amount', 422);
            }
            $sum += round((float) $s['amount'], 2);
        }
        if (round($sum, 2) !== $abs) api_error("Splits sum to {$sum} but line amount is {$abs}", 422);

        if ($type === 'deposit') {
            $bank = $pdo->prepare(
                'SELECT aa.id AS account_id FROM accounting_bank_accounts ba
                  JOIN accounting_accounts aa
                    ON aa.tenant_id = ba.tenant_id AND aa.code = ba.gl_account_code
                  WHERE ba.tenant_id = :t AND ba.id = :id LIMIT 1'
            );
            $bank->execute(['t' => $tenantId, 'id' => (int) $line[$col]]);
            $side = $bank->fetch(PDO::FETCH_ASSOC);
            if (!$side) api_error('Could not resolve deposit GL account', 500);
            $sideAccountId = (int) $side['account_id'];
        } else {
            $sideAccountId = (int) $line[$col];
        }

        $isOutflow = (float) $line['amount'] < 0;
        $jeLines = [[
            'account_id' => $sideAccountId,
            'debit'      => $isOutflow ? 0 : $abs,
            'credit'     => $isOutflow ? $abs : 0,
            'memo'       => 'split categorize',
        ]];
        foreach ($splits as $s) {
            $portion = round((float) $s['amount'], 2);
            $jeLines[] = [
                'account_id' => (int) $s['account_id'],
                'debit'      => $isOutflow ? $portion : 0,
                'credit'     => $isOutflow ? 0 : $portion,
                'memo'       => trim((string) ($s['memo'] ?? '')) ?: ($line['description'] ?? 'split'),
                'entity_id'  => !empty($s['entity_id']) ? (int) $s['entity_id'] : null,
            ];
        }

        $payloadLines = array_map(static function (array $l): array {
            return [
                'account_id'  => (int) $l['account_id'],
                'debit'       => (float) ($l['debit'] ?? 0),
                'credit'      => (float) ($l['credit'] ?? 0),
                'description' => (string) ($l['memo'] ?? ''),
                'entity_id'   => $l['entity_id'] ?? null,
            ];
        }, $jeLines);

        $eventResult = null; $eventError = null;
        try {
            $eventResult = accountingProcessEvent($tenantId, [
                'entity_id'        => 0,
                'event_type'       => 'treasury.bank_transaction.categorized',
                'source_module'    => 'treasury_feed',
                'source_record_id' => ($type === 'deposit' ? 'bank_line:split:' : 'liab_line:split:') . $lineId,
                'event_date'       => (string) $line['posted_date'],
                'payload'          => [
                    'bank_txn_id' => (int) $lineId,
                    'amount'      => $abs,
                    'currency'    => 'USD',
                    'direction'   => $isOutflow ? 'outflow' : 'inflow',
                    'memo'        => 'split categorize - ' . ($line['description'] ?? ''),
                    'split_count' => count($splits),
                    'lines'       => $payloadLines,
                ],
            ], (int) ($ctx['user']['id'] ?? 0));
        } catch (\Throwable $e) {
            $eventError = $e->getMessage();
        }

        if ($eventResult && ($eventResult['status'] ?? null) === 'posted') {
            $res = [
                'je_id'     => (int) $eventResult['journal_entry_id'],
                'je_number' => $eventResult['je_number'] ?? null,
            ];
        } else {
            try {
                $res = accountingPostJe($tenantId, [
                    'posting_date'   => (string) $line['posted_date'],
                    'memo'           => 'split categorize - ' . ($line['description'] ?? ''),
                    'currency'       => 'USD',
                    'source_module'  => 'treasury_feed',
                    'source_ref_type'=> $type === 'deposit' ? 'bank_statement_line' : 'liability_statement_line',
                    'source_ref_id'  => $lineId,
                    'idempotency_key'=> "treasury_feed_split:{$type}:{$lineId}",
                    'lines'          => $jeLines,
                ], (int) ($ctx['user']['id'] ?? 0), true);
            } catch (\Throwable $e) {
                api_error('Could not post split JE: ' . $e->getMessage()
                        . ($eventError ? ' | event-layer error: ' . $eventError : ''), 422);
            }
            moduleEmissionDisciplineLog('treasury_feed', 'treasury.bank_transaction.categorized', [
                'line_id'      => $lineId,
                'type'         => $type,
                'split_count'  => count($splits),
                'je_id'        => (int) $res['je_id'],
                'event_error'  => $eventError,
                'event_status' => $eventResult['status'] ?? null,
            ]);
        }

        $pdo->prepare("UPDATE {$table}
                          SET match_status = 'matched', matched_je_id = :je
                        WHERE tenant_id = :t AND id = :id")
            ->execute(['t' => $tenantId, 'id' => $lineId, 'je' => $res['je_id']]);

        try {
            $pdo->prepare(
                'INSERT IGNORE INTO accounting_subledger_links
                    (tenant_id, source_module, source_record_id, journal_entry_id, link_kind)
                 VALUES (:t, :sm, :sr, :je, "primary")'
            )->execute([
                't'  => $tenantId,
                'sm' => 'treasury_feed',
                'sr' => ($type === 'deposit' ? 'bank_line:split:' : 'liab_line:split:') . $lineId,
                'je' => (int) $res['je_id'],
            ]);
        } catch (\Throwable $_) { /* table absent in pre-7b tenants - non-fatal */ }

        api_ok([
            'ok'            => true,
            'line_id'       => $lineId,
            'matched_je_id' => $res['je_id'],
            'je_number'     => $res['je_number'] ?? null,
            'split_count'   => count($splits),
        ]);
    }

    // categorize_and_post — auto-create a balanced JE from the statement line.
    //
    //   Charge / outflow (line.amount < 0):
    //     DR counterpart_account (e.g. expense)   abs(amount)
    //     CR account (deposit bank acct OR liability GL)  abs(amount)
    //
    //   Payment / inflow (line.amount > 0):
    //     DR account                              amount
    //     CR counterpart_account (e.g. revenue / expense reversal)  amount
    //
    // Source-module 'treasury_feed', source_ref tagged so the matched JE
    // can be traced back to the statement line. Idempotency-keyed so
    // double-clicks don't double-post.
    require_once __DIR__ . '/../../accounting/lib/accounting.php';
    require_once __DIR__ . '/../../../core/module_emission_discipline.php';

    $counterId = (int) ($body['counterpart_account_id'] ?? 0);
    if ($counterId <= 0) api_error('counterpart_account_id required', 422);

    $counterCheck = $pdo->prepare(
        "SELECT id, code, name, account_type
           FROM accounting_accounts
          WHERE tenant_id = :t AND id = :id AND active = 1 LIMIT 1"
    );
    $counterCheck->execute(['t' => $tenantId, 'id' => $counterId]);
    $counter = $counterCheck->fetch(PDO::FETCH_ASSOC);
    if (!$counter) api_error('Counterpart account not found', 404);

    // Resolve the side-of-the-line "account" — for deposits we look up the
    // accounting_accounts.id via accounting_bank_accounts.gl_account_code;
    // for liabilities the account_id IS the COA row (treasury_liability_accounts
    // joins to it directly).
    if ($type === 'deposit') {
        $bank = $pdo->prepare(
            'SELECT ba.gl_account_code, aa.id AS account_id
               FROM accounting_bank_accounts ba
               JOIN accounting_accounts aa
                 ON aa.tenant_id = ba.tenant_id AND aa.code = ba.gl_account_code
              WHERE ba.tenant_id = :t AND ba.id = :id LIMIT 1'
        );
        $bank->execute(['t' => $tenantId, 'id' => (int) $line[$col]]);
        $bank = $bank->fetch(PDO::FETCH_ASSOC);
        if (!$bank) api_error('Could not resolve deposit GL account', 500);
        $sideAccountId = (int) $bank['account_id'];
    } else {
        // liability_account_id IS accounting_accounts.id.
        $sideAccountId = (int) $line[$col];
    }

    if ($sideAccountId === $counterId) {
        api_error('Counterpart cannot be the same as the statement-line account', 422);
    }

    $amt = round((float) $line['amount'], 2);
    $abs = abs($amt);
    if ($abs <= 0) api_error('Cannot post a zero-amount line', 422);

    if ($amt < 0) {
        // Outflow / charge.
        $debitId  = $counterId;
        $creditId = $sideAccountId;
    } else {
        // Inflow / payment.
        $debitId  = $sideAccountId;
        $creditId = $counterId;
    }

    $memo = trim((string) ($body['memo'] ?? ''));
    if ($memo === '') {
        $memo = trim((string) ($line['description'] ?? $line['merchant_name'] ?? 'Treasury feed posting'));
        if ($memo === '') $memo = 'Treasury feed posting';
    }

    // Phase 2a — preferred path: emit treasury.bank_transaction.categorized
    // into the event engine so this categorize action flows through the same
    // posting_rules + accounting_events trail as AP/Billing. Falls back to
    // the legacy direct JE posting path when the engine returns
    // 'ignored' (no rule seeded) or throws — same pattern as ap.bill.approved.
    require_once __DIR__ . '/../../../core/posting_engine/process.php';
    $payloadLines = [
        ['account_id' => $debitId,  'debit' => $abs, 'credit' => 0,    'description' => $memo],
        ['account_id' => $creditId, 'debit' => 0,    'credit' => $abs, 'description' => $memo],
    ];
    $eventResult = null; $eventError = null;
    try {
        $eventResult = accountingProcessEvent($tenantId, [
            'entity_id'        => 0,
            'event_type'       => 'treasury.bank_transaction.categorized',
            'source_module'    => 'treasury_feed',
            'source_record_id' => ($type === 'deposit' ? 'bank_line:' : 'liab_line:') . $lineId,
            'event_date'       => (string) $line['posted_date'],
            'payload'          => [
                'bank_txn_id'             => (int) $lineId,
                'amount'                  => $abs,
                'currency'                => 'USD',
                'direction'               => $amt < 0 ? 'outflow' : 'inflow',
                'memo'                    => $memo,
                'counterpart_account_id'  => $counterId,
                'split_count'             => 1,
                'lines'                   => $payloadLines,
            ],
        ], (int) ($ctx['user']['id'] ?? 0));
    } catch (\Throwable $e) {
        $eventError = $e->getMessage();
    }

    if ($eventResult && ($eventResult['status'] ?? null) === 'posted') {
        $res = [
            'je_id'     => (int) $eventResult['journal_entry_id'],
            'je_number' => $eventResult['je_number'] ?? null,
        ];
    } else {
        // ── Phase-2a Fallback: legacy direct posting ──
        // Fires when (a) no rule seeded yet, or (b) engine threw. We post
        // the JE so the books still balance, but record a discipline
        // violation so Phase-2a step-5 (kill-switch) can prove zero
        // fallback fires in production before we hard-error this path.
        try {
            $res = accountingPostJe($tenantId, [
                'posting_date'   => (string) $line['posted_date'],
                'memo'           => $memo,
                'currency'       => 'USD',
                'source_module'  => 'treasury_feed',
                'source_ref_type'=> $type === 'deposit' ? 'bank_statement_line' : 'liability_statement_line',
                'source_ref_id'  => $lineId,
                'idempotency_key'=> "treasury_feed:{$type}:{$lineId}",
                'lines'          => [
                    ['account_id' => $debitId,  'debit'  => $abs, 'credit' => 0,    'memo' => $memo],
                    ['account_id' => $creditId, 'debit'  => 0,    'credit' => $abs, 'memo' => $memo],
                ],
            ], (int) ($ctx['user']['id'] ?? 0), true);
        } catch (\Throwable $e) {
            api_error('Could not post journal entry: ' . $e->getMessage()
                    . ($eventError ? ' | event-layer error: ' . $eventError : ''), 422);
        }
        moduleEmissionDisciplineLog('treasury_feed', 'treasury.bank_transaction.categorized', [
            'line_id'      => $lineId, 'type' => $type,
            'je_id'        => (int) $res['je_id'],
            'event_error'  => $eventError,
            'event_status' => $eventResult['status'] ?? null,
        ]);
    }

    $pdo->prepare("UPDATE {$table}
                      SET match_status = 'matched', matched_je_id = :je
                    WHERE tenant_id = :t AND id = :id")
        ->execute(['t' => $tenantId, 'id' => $lineId, 'je' => $res['je_id']]);

    // Sprint 7b — exercise subledger_links. Full event-layer reroute is
    // Sprint 7e; this gives us audit-trace on every treasury post today.
    try {
        $pdo->prepare(
            'INSERT IGNORE INTO accounting_subledger_links
                (tenant_id, source_module, source_record_id, journal_entry_id, link_kind)
             VALUES (:t, :sm, :sr, :je, "primary")'
        )->execute([
            't'  => $tenantId,
            'sm' => 'treasury_feed',
            'sr' => ($type === 'deposit' ? 'bank_line:' : 'liab_line:') . $lineId,
            'je' => (int) $res['je_id'],
        ]);
    } catch (\Throwable $_) { /* table absent in pre-7b tenants — non-fatal */ }


    // Record AI suggestion outcome (accept-as-is vs override) for moat training.
    require_once __DIR__ . '/../../../core/ai_categorization.php';
    $aiSuggestionId = (int) ($body['ai_suggestion_id'] ?? 0) ?: null;
    aiRecordCategorizationOutcome(
        $tenantId,
        $aiSuggestionId,
        $counterId,
        $line,
        (int) ($ctx['user']['id'] ?? 0)
    );

    // If there WAS an AI suggestion and the user picked something different,
    // record the reject so the saved-rules dashboard can de-rank that
    // (merchant → suggested-account) pairing on future syncs.
    if ($aiSuggestionId) {
        $sug = scopedFind(
            'SELECT suggested_value FROM ai_suggestions
              WHERE tenant_id = :tenant_id AND id = :id LIMIT 1',
            ['id' => $aiSuggestionId]
        );
        $suggestedAccountId = (int) ($sug['suggested_value'] ?? 0);
        if ($suggestedAccountId > 0 && $suggestedAccountId !== $counterId) {
            aiRecordCategorizationReject($tenantId, $line, $suggestedAccountId);
        }
    }

    api_ok([
        'ok'             => true,
        'line_id'        => $lineId,
        'matched_je_id'  => $res['je_id'],
        'je_number'      => $res['je_number'],
        'status'         => $res['status'],
        'total_debit'    => $res['total_debit'],
        'total_credit'   => $res['total_credit'],
        'idempotent_replay' => $res['idempotent_replay'] ?? false,
    ]);
}

// ─── split_categorize ─────────────────────────────────────────────────────
// Sprint 6h — split a single bank-feed line across multiple counter
// accounts (with optional per-row entity_id for intercompany splits).
// Body: { line_id, type, splits: [ { account_id, amount, entity_id?, memo? } ] }
//   • Sum(splits.amount) MUST equal abs(line.amount). 422 otherwise.
//   • Posts ONE balanced JE: bank/card side gets the full amount; each
//     split row hits the chosen counter account for its own portion.
/*
 * Duplicate legacy split_categorize handler removed from the live path.
 * The active handler above runs inside the canonical POST switch, uses the
 * current treasury_liability_statement_lines table, and routes event-first.
 *
if ($method === 'POST' && $action === 'split_categorize') {
    require_once __DIR__ . '/../../accounting/lib/accounting.php';
    require_once __DIR__ . '/../../../core/module_emission_discipline.php';
    $lineId = (int) ($body['line_id'] ?? 0);
    if ($lineId <= 0) api_error('line_id required', 422);
    $type = (string) ($body['type'] ?? '');
    $col   = $type === 'liability' ? 'card_account_id' : 'bank_account_id';
    $table = $type === 'liability' ? 'accounting_liability_statement_lines' : 'accounting_bank_statement_lines';

    $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE tenant_id = :t AND id = :id LIMIT 1");
    $stmt->execute(['t' => $tenantId, 'id' => $lineId]);
    $line = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$line) api_error('Statement line not found', 404);
    if (($line['match_status'] ?? '') !== 'unmatched') api_error('Already matched', 422);

    $splits = (array) ($body['splits'] ?? []);
    if (count($splits) < 1) api_error('At least one split row required', 422);

    $abs = round(abs((float) $line['amount']), 2);
    $sum = 0.0;
    foreach ($splits as $s) {
        if (empty($s['account_id']) || !is_numeric($s['amount'])) api_error('Each split needs account_id + amount', 422);
        $sum += round((float) $s['amount'], 2);
    }
    if (round($sum, 2) !== $abs) api_error("Splits sum to {$sum} but line amount is {$abs}", 422);

    // Resolve "side account" (bank GL / liability GL) the same way
    // categorize_and_post does.
    if ($type === 'deposit') {
        $bank = $pdo->prepare(
            'SELECT aa.id AS account_id FROM accounting_bank_accounts ba
              JOIN accounting_accounts aa
                ON aa.tenant_id = ba.tenant_id AND aa.code = ba.gl_account_code
              WHERE ba.tenant_id = :t AND ba.id = :id LIMIT 1'
        );
        $bank->execute(['t' => $tenantId, 'id' => (int) $line[$col]]);
        $row = $bank->fetch(PDO::FETCH_ASSOC);
        if (!$row) api_error('Could not resolve deposit GL account', 500);
        $sideAccountId = (int) $row['account_id'];
    } else {
        $sideAccountId = (int) $line[$col];
    }

    $isOutflow = (float) $line['amount'] < 0;
    $jeLines   = [];
    // Bank/card side absorbs the full amount on the opposite side.
    $jeLines[] = [
        'account_id' => $sideAccountId,
        'debit'      => $isOutflow ? 0    : $abs,
        'credit'     => $isOutflow ? $abs : 0,
        'memo'       => 'split categorize',
    ];
    foreach ($splits as $s) {
        $portion = round((float) $s['amount'], 2);
        $jeLines[] = [
            'account_id' => (int) $s['account_id'],
            'debit'      => $isOutflow ? $portion : 0,
            'credit'     => $isOutflow ? 0        : $portion,
            'memo'       => trim((string) ($s['memo'] ?? '')) ?: ($line['description'] ?? 'split'),
            'entity_id'  => !empty($s['entity_id']) ? (int) $s['entity_id'] : null,
        ];
    }

    // Phase 2a — preferred path: emit treasury.bank_transaction.categorized
    // (with split_count > 1) into the event engine. Same passthrough rule
    // handles single- and multi-line categorization since payload carries
    // the rendered JE lines.
    require_once __DIR__ . '/../../../core/posting_engine/process.php';
    $payloadLines = array_map(static function ($l) {
        return [
            'account_id'  => (int) $l['account_id'],
            'debit'       => (float) ($l['debit']  ?? 0),
            'credit'      => (float) ($l['credit'] ?? 0),
            'description' => (string) ($l['memo']  ?? ''),
            'entity_id'   => $l['entity_id'] ?? null,
        ];
    }, $jeLines);

    $eventResult = null; $eventError = null;
    try {
        $eventResult = accountingProcessEvent($tenantId, [
            'entity_id'        => 0,
            'event_type'       => 'treasury.bank_transaction.categorized',
            'source_module'    => 'treasury_feed',
            'source_record_id' => ($type === 'deposit' ? 'bank_line:split:' : 'liab_line:split:') . $lineId,
            'event_date'       => (string) $line['posted_date'],
            'payload'          => [
                'bank_txn_id' => (int) $lineId,
                'amount'      => $abs,
                'currency'    => 'USD',
                'direction'   => $isOutflow ? 'outflow' : 'inflow',
                'memo'        => 'split categorize · ' . ($line['description'] ?? ''),
                'split_count' => count($splits),
                'lines'       => $payloadLines,
            ],
        ], (int) ($ctx['user']['id'] ?? 0));
    } catch (\Throwable $e) {
        $eventError = $e->getMessage();
    }

    if ($eventResult && ($eventResult['status'] ?? null) === 'posted') {
        $res = [
            'je_id'     => (int) $eventResult['journal_entry_id'],
            'je_number' => $eventResult['je_number'] ?? null,
        ];
    } else {
        // ── Phase-2a Fallback: legacy direct posting for split ──
        try {
            $res = accountingPostJeLegacy($tenantId, [
                'posting_date'   => (string) $line['posted_date'],
                'memo'           => 'split categorize · ' . ($line['description'] ?? ''),
                'currency'       => 'USD',
                'source_module'  => 'treasury_feed',
                'source_ref_type'=> $type === 'deposit' ? 'bank_statement_line' : 'liability_statement_line',
                'source_ref_id'  => $lineId,
                'idempotency_key'=> "treasury_feed_split:{$type}:{$lineId}",
                'lines'          => $jeLines,
            ], (int) ($ctx['user']['id'] ?? 0), true);
        } catch (\Throwable $e) {
            api_error('Could not post split JE: ' . $e->getMessage()
                    . ($eventError ? ' | event-layer error: ' . $eventError : ''), 422);
        }
        moduleEmissionDisciplineLog('treasury_feed', 'treasury.bank_transaction.categorized', [
            'line_id'      => $lineId, 'type' => $type, 'split_count' => count($splits),
            'je_id'        => (int) $res['je_id'],
            'event_error'  => $eventError,
            'event_status' => $eventResult['status'] ?? null,
        ]);
    }

    $pdo->prepare("UPDATE {$table} SET match_status = 'matched', matched_je_id = :je
                    WHERE tenant_id = :t AND id = :id")
        ->execute(['t' => $tenantId, 'id' => $lineId, 'je' => $res['je_id']]);

    api_ok([
        'ok'            => true,
        'line_id'       => $lineId,
        'matched_je_id' => $res['je_id'],
        'je_number'     => $res['je_number'],
        'split_count'   => count($splits),
    ]);
}
*/

if (api_method() !== 'GET') api_error('Method not allowed', 405);

$accountId = (int) ($_GET['account_id'] ?? 0);
$type      = (string) ($_GET['type']     ?? 'deposit');
$limit     = max(1, min(500, (int) ($_GET['limit'] ?? 100)));
if ($accountId <= 0) api_error('account_id required', 422);
if (!in_array($type, ['deposit', 'liability'], true)) {
    api_error("type must be 'deposit' or 'liability'", 422);
}

if ($type === 'deposit') {
    $stmt = $pdo->prepare(
        'SELECT id, posted_date, description, amount, bank_reference, fitid,
                match_status, matched_je_id, created_at,
                NULL AS merchant_name, NULL AS category
           FROM accounting_bank_statement_lines
          WHERE tenant_id = :t AND bank_account_id = :a
          ORDER BY posted_date DESC, id DESC
          LIMIT ' . $limit
    );
} else {
    // Auto-create the table if a tenant hasn't run migration 003 yet —
    // mirrors the sync-endpoint guard so the first GET on a fresh deploy
    // doesn't 500 with "table not found".
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS treasury_liability_statement_lines (
                id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tenant_id             INT UNSIGNED NOT NULL,
                liability_account_id  BIGINT UNSIGNED NOT NULL,
                posted_date           DATE NOT NULL,
                description           VARCHAR(255) NULL,
                amount                DECIMAL(18,2) NOT NULL,
                merchant_name         VARCHAR(255) NULL,
                category              VARCHAR(120) NULL,
                bank_reference        VARCHAR(120) NULL,
                fitid                 VARCHAR(120) NULL,
                match_status          ENUM('unmatched','matched','ignored') NOT NULL DEFAULT 'unmatched',
                matched_je_id         BIGINT UNSIGNED NULL,
                created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_tlsl_fitid (tenant_id, liability_account_id, fitid),
                INDEX idx_tlsl_acct_date (tenant_id, liability_account_id, posted_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        // Self-heal for tenants that ran migration 003 before 004 was added.
        try {
            $pdo->exec("ALTER TABLE treasury_liability_statement_lines
                          ADD COLUMN matched_je_id BIGINT UNSIGNED NULL AFTER match_status");
        } catch (\Throwable $_) {}
    } catch (\Throwable $_) {}

    $stmt = $pdo->prepare(
        'SELECT id, posted_date, description, amount, bank_reference, fitid,
                merchant_name, category, match_status, matched_je_id, created_at
           FROM treasury_liability_statement_lines
          WHERE tenant_id = :t AND liability_account_id = :a
          ORDER BY posted_date DESC, id DESC
          LIMIT ' . $limit
    );
}
$stmt->execute(['t' => $tenantId, 'a' => $accountId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Summary so the UI can render headline stats without re-summing client-side.
$count   = count($rows);
$inflow  = 0.0;
$outflow = 0.0;
foreach ($rows as $r) {
    $a = (float) $r['amount'];
    if ($a >= 0) $inflow  += $a;
    else         $outflow += abs($a);
}

// Run AI categorization for every UNMATCHED row. Cached: if a draft suggestion
// already exists for a (line_id, feature_key) we re-use it instead of calling
// the cascade again — keeps GET cheap and avoids duplicate ai_suggestions rows.
require_once __DIR__ . '/../../../core/ai_categorization.php';
$accountsList = $pdo->prepare(
    'SELECT id, code, name, account_type, is_postable
       FROM accounting_accounts
      WHERE tenant_id = :t AND active = 1
      ORDER BY code ASC LIMIT 1000'
);
$accountsList->execute(['t' => $tenantId]);
$allAccounts = $accountsList->fetchAll(PDO::FETCH_ASSOC);

if ($type === 'deposit') {
    $s = $pdo->prepare(
        'SELECT aa.id FROM accounting_bank_accounts ba
           JOIN accounting_accounts aa ON aa.tenant_id = ba.tenant_id AND aa.code = ba.gl_account_code
          WHERE ba.tenant_id = :t AND ba.id = :id LIMIT 1'
    );
    $s->execute(['t' => $tenantId, 'id' => $accountId]);
    $sideAccountId = (int) $s->fetchColumn();
} else {
    $sideAccountId = $accountId;  // liability_account_id IS accounting_accounts.id
}

$subjectType = $type === 'deposit' ? 'bank_statement_line' : 'liability_statement_line';
$cacheStmt = $pdo->prepare(
    "SELECT id, suggested_value, confidence_score, suggestion_source, draft_content
       FROM ai_suggestions
      WHERE tenant_id    = :t
        AND feature_key  = :fk
        AND subject_type = :st
        AND subject_id   = :sid
        AND status       = 'draft'
      ORDER BY id DESC LIMIT 1"
);

foreach ($rows as $i => $r) {
    if ($r['match_status'] !== 'unmatched') continue;
    $cacheStmt->execute([
        't'   => $tenantId,
        'fk'  => AI_CATEGORIZATION_FEATURE_KEY,
        'st'  => $subjectType,
        'sid' => (int) $r['id'],
    ]);
    $cached = $cacheStmt->fetch(PDO::FETCH_ASSOC);

    if ($cached) {
        $aid  = $cached['suggested_value'] ? (int) $cached['suggested_value'] : null;
        $conf = $cached['confidence_score'] !== null ? (float) $cached['confidence_score'] : 0.0;
        $rows[$i]['ai_suggestion'] = [
            'suggestion_id'        => (int) $cached['id'],
            'suggested_account_id' => $aid,
            'confidence'           => $conf,
            'source'               => (string) ($cached['suggestion_source'] ?? 'none'),
            'reasoning'            => (string) ($cached['draft_content']     ?? ''),
            'auto_accept'          => $conf >= AI_CATEGORIZATION_AUTO_ACCEPT,
        ];
        continue;
    }

    $sug = aiSuggestCounterpartAccount($tenantId, $r, $type, $sideAccountId, $allAccounts);
    $rows[$i]['ai_suggestion'] = [
        'suggestion_id'        => $sug['suggestion_id'],
        'suggested_account_id' => $sug['suggested_account_id'],
        'confidence'           => $sug['confidence'],
        'source'               => $sug['source'],
        'reasoning'            => $sug['reasoning'],
        'auto_accept'          => $sug['auto_accept'],
    ];
}

// Locate the Plaid item for the "Sync from Plaid" button (if linked).
// Returns the Plaid string item_id so the UI can call /api/plaid_sync_transactions.php
// directly — no localhost proxy, no curl-back, no cookie round-trip.
$plaidItemPk        = null;
$plaidItemExternalId = null;
$plaidAccountId     = null;
if ($type === 'deposit') {
    $row = $pdo->prepare(
        'SELECT pi.id AS pk, pi.item_id AS external_id, pa.account_id
           FROM accounting_bank_accounts ba
           JOIN plaid_accounts pa
             ON pa.tenant_id = ba.tenant_id AND pa.account_id = ba.plaid_account_id
           JOIN plaid_items   pi
             ON pi.id = pa.plaid_item_pk AND pi.tenant_id = pa.tenant_id
          WHERE ba.tenant_id = :t AND ba.id = :id LIMIT 1'
    );
    $row->execute(['t' => $tenantId, 'id' => $accountId]);
    $r = $row->fetch(PDO::FETCH_ASSOC);
    if ($r) {
        $plaidItemPk         = (int) $r['pk'];
        $plaidItemExternalId = (string) $r['external_id'];
        $plaidAccountId      = (string) $r['account_id'];
    }
} else {
    try {
        $row = $pdo->prepare(
            'SELECT pi.id AS pk, pi.item_id AS external_id, pa.account_id
               FROM treasury_liability_accounts tla
               JOIN plaid_accounts pa
                 ON pa.tenant_id = tla.tenant_id AND pa.account_id = tla.plaid_account_id
               JOIN plaid_items   pi
                 ON pi.id = pa.plaid_item_pk AND pi.tenant_id = pa.tenant_id
              WHERE tla.tenant_id = :t AND tla.account_id = :id LIMIT 1'
        );
        $row->execute(['t' => $tenantId, 'id' => $accountId]);
        $r = $row->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            $plaidItemPk         = (int) $r['pk'];
            $plaidItemExternalId = (string) $r['external_id'];
            $plaidAccountId      = (string) $r['account_id'];
        }
    } catch (\Throwable $_) {}
}

api_ok([
    'rows'                  => $rows,
    'count'                 => $count,
    'inflow_total'          => round($inflow, 2),
    'outflow_total'         => round($outflow, 2),
    'plaid_item_pk'         => $plaidItemPk,
    'plaid_item_external_id'=> $plaidItemExternalId,
    'plaid_account_id'      => $plaidAccountId,
]);
