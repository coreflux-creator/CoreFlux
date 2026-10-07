<?php
/**
 * Treasury — Account Transactions API.
 *
 *   GET ?account_id=N&type=deposit|liability[&q=...&status=...&direction=...]
 *       [&date_from=YYYY-MM-DD&date_to=YYYY-MM-DD&amount_min=N&amount_max=N]
 *       [&category_account_id=N&sort_by=date|description|amount|status]
 *       [&sort_dir=asc|desc&page=1&per_page=50]
 *
 * Returns the flat list of statement / Plaid-fed lines for either a deposit
 * (accounting_bank_accounts) or liability (accounting_accounts where
 * type='liability') account, newest first. Used by the deposit / liability
 * detail drawers in Treasury so users can see the actual feed data.
 *
 *   POST ?action=bulk_update
 *        { account_id, type, line_ids[], bulk_action=ignore|restore|unmatch }
 *
 * Permission: `accounting.bank.manage`.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/treasury/bank_transaction_identity.php';
require_once __DIR__ . '/../lib/bank_posting.php';
require_once __DIR__ . '/../lib/statement_state.php';
require_once __DIR__ . '/../../accounting/lib/bank_rec.php';
require_once __DIR__ . '/../../accounting/lib/accounting.php';

$ctx      = api_require_auth();
$tenantId = (int) $ctx['tenant_id'];
rbac_legacy_require($ctx['user'], 'accounting.bank.manage');
$pdo = getDB();

function _treasuryStatementEntityId(\PDO $pdo, int $tenantId, string $type, int $accountId): int
{
    if ($type === 'deposit') {
        $stmt = $pdo->prepare(
            'SELECT ba.entity_id, ae.id AS active_entity_id
               FROM accounting_bank_accounts ba
          LEFT JOIN accounting_entities ae
                 ON ae.tenant_id = ba.tenant_id AND ae.id = ba.entity_id AND ae.active = 1
              WHERE ba.tenant_id = :tenant_id AND ba.id = :account_id
              LIMIT 1'
        );
        $table = 'accounting_bank_accounts';
        $idColumn = 'id';
    } else {
        $stmt = $pdo->prepare(
            'SELECT tla.entity_id, ae.id AS active_entity_id
               FROM treasury_liability_accounts tla
          LEFT JOIN accounting_entities ae
                 ON ae.tenant_id = tla.tenant_id AND ae.id = tla.entity_id AND ae.active = 1
              WHERE tla.tenant_id = :tenant_id AND tla.account_id = :account_id
              LIMIT 1'
        );
        $table = 'treasury_liability_accounts';
        $idColumn = 'account_id';
    }
    $stmt->execute(['tenant_id' => $tenantId, 'account_id' => $accountId]);
    $account = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$account) throw new \RuntimeException('Treasury account not found');
    if (!empty($account['active_entity_id'])) return (int) $account['active_entity_id'];
    if (!empty($account['entity_id'])) {
        throw new \RuntimeException('This Treasury account belongs to an inactive or invalid legal entity');
    }

    $entities = accountingListActiveEntities($tenantId);
    if (count($entities) !== 1) {
        throw new \RuntimeException(
            'Assign a legal entity to this Treasury account before posting its transactions'
        );
    }
    $entityId = (int) $entities[0]['id'];
    $pdo->prepare(
        "UPDATE {$table} SET entity_id = :entity_id
          WHERE tenant_id = :tenant_id AND {$idColumn} = :account_id AND entity_id IS NULL"
    )->execute([
        'entity_id' => $entityId,
        'tenant_id' => $tenantId,
        'account_id' => $accountId,
    ]);
    return $entityId;
}

if (api_method() === 'POST') {
    $action = (string) ($_GET['action'] ?? '');
    if (!in_array($action, ['ignore', 'unmatch', 'correct_categorization', 'categorize_and_post', 'match', 'split_categorize', 'bulk_update'], true)) {
        api_error(
            "POST requires action=ignore|unmatch|correct_categorization|categorize_and_post|match|split_categorize|bulk_update. "
            . "To pull from Plaid, call /api/plaid_sync_transactions.php directly.",
            422
        );
    }
    $body = api_json_body();
    $type = (string) ($body['type'] ?? $_GET['type'] ?? '');
    if (!in_array($type, ['deposit', 'liability'], true)) {
        api_error("type='deposit' or 'liability' required", 422);
    }

    $table = $type === 'deposit'
        ? 'accounting_bank_statement_lines'
        : 'treasury_liability_statement_lines';
    $col   = $type === 'deposit' ? 'bank_account_id' : 'liability_account_id';

    if ($action === 'bulk_update') {
        $accountId = (int) ($body['account_id'] ?? 0);
        $bulkAction = (string) ($body['bulk_action'] ?? '');
        $lineIds = array_values(array_unique(array_filter(
            array_map('intval', (array) ($body['line_ids'] ?? [])),
            static fn(int $id): bool => $id > 0
        )));
        if ($accountId <= 0) api_error('account_id required', 422);
        if (!$lineIds || count($lineIds) > 500) api_error('Select between 1 and 500 rows', 422);
        if (!in_array($bulkAction, ['ignore', 'restore', 'unmatch'], true)) {
            api_error('bulk_action must be ignore, restore, or unmatch', 422);
        }

        $params = ['t' => $tenantId, 'a' => $accountId];
        $placeholders = [];
        foreach ($lineIds as $i => $id) {
            $key = 'line' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }

        $statusGuard = match ($bulkAction) {
            'ignore'  => " AND match_status = 'unmatched'",
            'restore' => " AND match_status = 'ignored'",
            'unmatch' => " AND match_status = 'matched'",
        };
        if ($bulkAction === 'unmatch') {
            $eligible = $pdo->prepare(
                "SELECT id FROM {$table}
                  WHERE tenant_id = :t AND {$col} = :a
                    AND id IN (" . implode(',', $placeholders) . ")
                    AND match_status = 'matched'
                  ORDER BY id"
            );
            $eligible->execute($params);
            $eligibleIds = array_map('intval', $eligible->fetchAll(PDO::FETCH_COLUMN));
            $pdo->beginTransaction();
            try {
                foreach ($eligibleIds as $eligibleId) {
                    if ($type === 'deposit') bankRecUnmatchLine($tenantId, $eligibleId);
                    else treasuryUnmatchLiabilityLine($pdo, $tenantId, $eligibleId);
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                api_error($e->getMessage(), 409);
            }
            api_ok([
                'ok' => true,
                'action' => $bulkAction,
                'selected' => count($lineIds),
                'updated' => count($eligibleIds),
            ]);
        }

        $set = $bulkAction === 'ignore' ? "match_status = 'ignored'" : "match_status = 'unmatched'";

        $stmt = $pdo->prepare(
            "UPDATE {$table} SET {$set}
              WHERE tenant_id = :t AND {$col} = :a
                AND id IN (" . implode(',', $placeholders) . "){$statusGuard}"
        );
        $stmt->execute($params);
        api_ok([
            'ok'       => true,
            'action'   => $bulkAction,
            'selected' => count($lineIds),
            'updated'  => $stmt->rowCount(),
        ]);
    }

    $lineId = (int) ($body['line_id'] ?? 0);
    if ($lineId <= 0) api_error('line_id required', 422);

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
    if ($action === 'categorize_and_post' && ($line['match_status'] ?? '') !== 'unmatched') {
        api_error('This transaction is already resolved. Correct its source posting or restore it before recategorizing.', 409);
    }

    if ($action === 'correct_categorization') {
        try {
            api_ok(treasuryCorrectCategorization(
                $pdo,
                $tenantId,
                $type,
                $lineId,
                (string) ($body['reason'] ?? ''),
                (int) ($ctx['user']['id'] ?? 0) ?: null
            ));
        } catch (InvalidArgumentException $e) {
            api_error($e->getMessage(), 422);
        } catch (Throwable $e) {
            api_error($e->getMessage(), 409);
        }
    }

    if ($action === 'ignore') {
        if ($line['match_status'] === 'matched') {
            api_error('A matched line cannot be ignored. Correct its source transaction first.', 409);
        }
        if ($line['match_status'] === 'ignored') {
            api_ok(['ok' => true, 'line_id' => $lineId, 'match_status' => 'ignored', 'idempotent_replay' => true]);
        }
        $ignored = $pdo->prepare("UPDATE {$table} SET match_status = 'ignored'
                                  WHERE tenant_id = :t AND id = :id AND match_status = 'unmatched'");
        $ignored->execute(['t' => $tenantId, 'id' => $lineId]);
        if ($ignored->rowCount() !== 1) api_error('This line changed. Refresh and try again.', 409);
        api_ok(['ok' => true, 'line_id' => $lineId, 'match_status' => 'ignored']);
    }

    if ($action === 'unmatch') {
        if ($type === 'deposit') {
            try {
                bankRecUnmatchLine($tenantId, $lineId);
            } catch (\Throwable $e) {
                api_error($e->getMessage(), 409);
            }
        } else {
            $pdo->beginTransaction();
            try {
                treasuryUnmatchLiabilityLine($pdo, $tenantId, $lineId);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                api_error($e->getMessage(), 409);
            }
        }
        api_ok(['ok' => true, 'line_id' => $lineId, 'match_status' => 'unmatched']);
    }

    if ($action === 'match') {
        $jeId = (int) ($body['je_id'] ?? 0);
        if ($jeId <= 0) api_error('je_id required', 422);

        if ($type === 'deposit') {
            try {
                bankRecMatchLine($tenantId, $lineId, $jeId, (int) ($ctx['user']['id'] ?? 0) ?: null);
            } catch (\Throwable $e) {
                api_error($e->getMessage(), 409);
            }
        } else {
            $pdo->beginTransaction();
            try {
                $entityId = _treasuryStatementEntityId($pdo, $tenantId, 'liability', (int) $line[$col]);
                treasuryMatchLiabilityLine($pdo, $tenantId, $lineId, $jeId, $entityId);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                api_error($e->getMessage(), 409);
            }
        }
        api_ok(['ok' => true, 'line_id' => $lineId, 'matched_je_id' => $jeId]);
    }

    if ($action === 'split_categorize') {
        require_once __DIR__ . '/../../../core/module_emission_discipline.php';
        require_once __DIR__ . '/../../../core/posting_engine/process.php';

        if (($line['match_status'] ?? '') !== 'unmatched') {
            api_error('This transaction is already resolved. Correct its source posting or restore it before splitting.', 409);
        }
        try {
            $postingEntityId = _treasuryStatementEntityId(
                $pdo,
                $tenantId,
                $type,
                (int) $line[$col]
            );
        } catch (\Throwable $e) {
            api_error($e->getMessage(), 422);
        }
        $splits = (array) ($body['splits'] ?? []);
        if (count($splits) < 1) api_error('At least one split row required', 422);

        $abs = round(abs((float) $line['amount']), 2);
        $sum = 0.0;
        $normalizedSplits = [];
        foreach ($splits as $s) {
            if (empty($s['account_id']) || !is_numeric($s['amount'])) {
                api_error('Each split needs account_id + amount', 422);
            }
            $portion = round((float) $s['amount'], 2);
            if ($portion <= 0) api_error('Each split amount must be greater than zero', 422);
            try {
                $counterpartyEntityId = accountingValidateActiveEntityId($tenantId, $s['entity_id'] ?? null);
            } catch (\InvalidArgumentException $e) {
                api_error($e->getMessage(), 422);
            }
            if ($counterpartyEntityId === $postingEntityId) $counterpartyEntityId = null;
            $normalizedSplits[] = [
                'account_id' => (int) $s['account_id'],
                'amount' => $portion,
                'memo' => trim((string) ($s['memo'] ?? '')),
                'counterparty_entity_id' => $counterpartyEntityId,
            ];
            $sum += $portion;
        }
        $splits = $normalizedSplits;
        if (round($sum, 2) !== $abs) api_error("Splits sum to {$sum} but line amount is {$abs}", 422);

        $ownsTransaction = cf_tx_begin($pdo);
        try {
            $lockedStmt = $pdo->prepare("SELECT * FROM {$table} WHERE tenant_id = :t AND id = :id FOR UPDATE");
            $lockedStmt->execute(['t' => $tenantId, 'id' => $lineId]);
            $lockedLine = $lockedStmt->fetch(PDO::FETCH_ASSOC);
            if (!$lockedLine || ($lockedLine['match_status'] ?? '') !== 'unmatched'
                || round((float) $lockedLine['amount'], 2) !== round((float) $line['amount'], 2)
                || (string) $lockedLine['posted_date'] !== (string) $line['posted_date']
                || (int) $lockedLine[$col] !== (int) $line[$col]) {
                throw new \RuntimeException('This statement line changed. Refresh before posting.');
            }
            $line = $lockedLine;
            $postingAttempt = treasuryStatementNextAttempt($pdo, $tenantId, $type, $lineId);
            $sourceRecordId = treasuryStatementSourceId($type, $lineId, true, $postingAttempt);
            $entityCheck = $pdo->prepare(
                'SELECT base_currency FROM accounting_entities WHERE tenant_id = :t AND id = :id AND active = 1'
            );
            $entityCheck->execute(['t' => $tenantId, 'id' => $postingEntityId]);
            if (strtoupper((string) $entityCheck->fetchColumn()) !== 'USD') {
                throw new \RuntimeException('This posting path supports USD entities only');
            }
            $counterCheck = $pdo->prepare(
                'SELECT aa.code, aa.account_type, aa.is_postable, aa.currency, aa.active,
                        ba.id AS linked_bank_id
                   FROM accounting_accounts aa
              LEFT JOIN accounting_bank_accounts ba
                     ON ba.tenant_id = aa.tenant_id AND ba.gl_account_code = aa.code
                  WHERE aa.tenant_id = :t AND aa.id = :id LIMIT 1'
            );
            foreach ($splits as $split) {
                $counterCheck->execute(['t' => $tenantId, 'id' => $split['account_id']]);
                $counter = $counterCheck->fetch(PDO::FETCH_ASSOC);
                if (!$counter || (int) $counter['active'] !== 1) {
                    throw new \RuntimeException('A split account is no longer active. Refresh before posting.');
                }
                treasuryAssertCategoryCounterpart($counter);
            }

        if ($type === 'deposit') {
            $bank = $pdo->prepare(
                'SELECT aa.id AS account_id, ba.status, ba.currency FROM accounting_bank_accounts ba
                  JOIN accounting_accounts aa
                    ON aa.tenant_id = ba.tenant_id AND aa.code = ba.gl_account_code
                  WHERE ba.tenant_id = :t AND ba.id = :id LIMIT 1 FOR UPDATE'
            );
            $bank->execute(['t' => $tenantId, 'id' => (int) $line[$col]]);
            $side = $bank->fetch(PDO::FETCH_ASSOC);
            if (!$side || $side['status'] !== 'active' || strtoupper((string) $side['currency']) !== 'USD') {
                throw new \RuntimeException('This bank account is no longer active in USD. Refresh before posting.');
            }
            $sideAccountId = (int) $side['account_id'];
        } else {
            $sideAccountId = (int) $line[$col];
            $sideCheck = $pdo->prepare(
                'SELECT active, is_postable, currency FROM accounting_accounts
                  WHERE tenant_id = :t AND id = :id LIMIT 1'
            );
            $sideCheck->execute(['t' => $tenantId, 'id' => $sideAccountId]);
            $side = $sideCheck->fetch(PDO::FETCH_ASSOC);
            if (!$side || (int) $side['active'] !== 1 || (int) $side['is_postable'] !== 1
                || (!empty($side['currency']) && strtoupper((string) $side['currency']) !== 'USD')) {
                throw new \RuntimeException('This liability account is no longer active and postable in USD.');
            }
        }
        foreach ($splits as $split) {
            if ($split['account_id'] === $sideAccountId) {
                throw new \RuntimeException('The statement account cannot be its own split category.');
            }
        }

        $isOutflow = (float) $line['amount'] < 0;
        $jeLines = [[
            'account_id' => $sideAccountId,
            'debit'      => $isOutflow ? 0 : $abs,
            'credit'     => $isOutflow ? $abs : 0,
            'memo'       => 'split categorize',
            'dims'       => ['legal_entity' => $postingEntityId],
        ]];
        foreach ($splits as $s) {
            $portion = round((float) $s['amount'], 2);
            $jeLines[] = [
                'account_id' => (int) $s['account_id'],
                'debit'      => $isOutflow ? $portion : 0,
                'credit'     => $isOutflow ? 0 : $portion,
                'memo'       => trim((string) ($s['memo'] ?? '')) ?: ($line['description'] ?? 'split'),
                'counterparty_entity_id' => $s['counterparty_entity_id'],
                'dims' => array_filter([
                    'legal_entity' => $postingEntityId,
                    'counterparty_entity' => $s['counterparty_entity_id'],
                ], static fn($value): bool => $value !== null),
            ];
        }

        $payloadLines = array_map(static function (array $l): array {
            return [
                'account_id'  => (int) $l['account_id'],
                'debit'       => (float) ($l['debit'] ?? 0),
                'credit'      => (float) ($l['credit'] ?? 0),
                'description' => (string) ($l['memo'] ?? ''),
                'counterparty_entity_id' => $l['counterparty_entity_id'] ?? null,
                'dims' => (array) ($l['dims'] ?? []),
            ];
        }, $jeLines);

        $eventResult = null; $eventError = null;
        $pdo->exec('SAVEPOINT treasury_split_event');
        try {
            $eventResult = accountingProcessEvent($tenantId, [
                'entity_id'        => $postingEntityId,
                'event_type'       => 'treasury.bank_transaction.categorized',
                'source_module'    => 'treasury_feed',
                'source_record_id' => $sourceRecordId,
                'event_date'       => (string) $line['posted_date'],
                'payload'          => [
                    'bank_txn_id' => (int) $lineId,
                    'amount'      => $abs,
                    'currency'    => 'USD',
                    'direction'   => $isOutflow ? 'outflow' : 'inflow',
                    'memo'        => 'split categorize - ' . ($line['description'] ?? ''),
                    'split_count' => count($splits),
                    'dimensions'  => ['legal_entity' => $postingEntityId],
                    'lines'       => $payloadLines,
                ],
            ], (int) ($ctx['user']['id'] ?? 0));
        } catch (\Throwable $e) {
            $pdo->exec('ROLLBACK TO SAVEPOINT treasury_split_event');
            if ($e instanceof AccountingEventConflictException) throw $e;
            $eventError = $e->getMessage();
        }
        $pdo->exec('RELEASE SAVEPOINT treasury_split_event');

        if ($eventResult && ($eventResult['status'] ?? null) === 'posted') {
            $res = [
                'je_id'     => (int) $eventResult['journal_entry_id'],
                'je_number' => $eventResult['je_number'] ?? null,
            ];
        } else {
            try {
                $res = accountingPostJe($tenantId, [
                    'entity_id'      => $postingEntityId,
                    'posting_date'   => (string) $line['posted_date'],
                    'memo'           => 'split categorize - ' . ($line['description'] ?? ''),
                    'currency'       => 'USD',
                    'source_module'  => 'treasury_feed',
                    'source_ref_type'=> $type === 'deposit' ? 'bank_statement_line' : 'liability_statement_line',
                    'source_ref_id'  => $lineId,
                    'idempotency_key'=> treasuryStatementPostingKey($type, $lineId, true, $postingAttempt),
                    'lines'          => $jeLines,
                ], (int) ($ctx['user']['id'] ?? 0), true);
            } catch (\Throwable $e) {
                throw new \RuntimeException('Could not post split JE: ' . $e->getMessage()
                        . ($eventError ? ' | event-layer error: ' . $eventError : ''), 0, $e);
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

        $splitJournal = $pdo->prepare(
            'SELECT status, source_module, posting_date, entity_id, currency
               FROM accounting_journal_entries WHERE tenant_id = :t AND id = :je'
        );
        $splitJournal->execute(['t' => $tenantId, 'je' => (int) $res['je_id']]);
        $postedSplit = $splitJournal->fetch(PDO::FETCH_ASSOC);
        $splitLines = $pdo->prepare(
            'SELECT account_id, debit, credit, counterparty_entity_id
               FROM accounting_journal_entry_lines
              WHERE tenant_id = :t AND je_id = :je ORDER BY line_no'
        );
        $splitLines->execute(['t' => $tenantId, 'je' => (int) $res['je_id']]);
        treasuryAssertSplitCategorizationJournal(
            $postedSplit ?: [],
            $splitLines->fetchAll(PDO::FETCH_ASSOC),
            (string) $line['posted_date'],
            $postingEntityId,
            $sideAccountId,
            (int) round((float) $line['amount'] * 100),
            $splits
        );

        if ($type === 'deposit') {
            bankRecMatchLine($tenantId, $lineId, (int) $res['je_id'], (int) ($ctx['user']['id'] ?? 0) ?: null);
        } else {
            $matched = $pdo->prepare("UPDATE {$table}
                                      SET match_status = 'matched', matched_je_id = :je
                                    WHERE tenant_id = :t AND id = :id AND match_status = 'unmatched'");
            $matched->execute(['t' => $tenantId, 'id' => $lineId, 'je' => $res['je_id']]);
            if ($matched->rowCount() !== 1) {
                throw new \RuntimeException('The liability line changed before it could be matched. Nothing was saved.');
            }
        }

        try {
            $pdo->prepare(
                'INSERT IGNORE INTO accounting_subledger_links
                    (tenant_id, source_module, source_record_id, journal_entry_id, link_kind)
                 VALUES (:t, :sm, :sr, :je, "primary")'
            )->execute([
                't'  => $tenantId,
                'sm' => 'treasury_feed',
                'sr' => $sourceRecordId,
                'je' => (int) $res['je_id'],
            ]);
            if ($postingAttempt > 1) {
                $pdo->prepare(
                    'INSERT IGNORE INTO accounting_subledger_links
                        (tenant_id, source_module, source_record_id, journal_entry_id, link_kind)
                     VALUES (:t, "treasury_feed", :sr, :je, "statement_line")'
                )->execute(['t' => $tenantId, 'sr' => treasuryStatementSourceId($type, $lineId, true, 1),
                    'je' => (int) $res['je_id']]);
            }
        } catch (\Throwable $_) { /* table absent in pre-7b tenants - non-fatal */ }

        cf_tx_commit($pdo, $ownsTransaction);
        } catch (\Throwable $e) {
            cf_tx_rollback($pdo, $ownsTransaction);
            api_error($e->getMessage(), 409);
        }

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
        'SELECT aa.id, aa.code, aa.name, aa.account_type, aa.is_postable, aa.currency,
                ba.id AS linked_bank_id
           FROM accounting_accounts aa
      LEFT JOIN accounting_bank_accounts ba
             ON ba.tenant_id = aa.tenant_id AND ba.gl_account_code = aa.code
          WHERE aa.tenant_id = :t AND aa.id = :id AND aa.active = 1 LIMIT 1'
    );
    $counterCheck->execute(['t' => $tenantId, 'id' => $counterId]);
    $counter = $counterCheck->fetch(PDO::FETCH_ASSOC);
    if (!$counter) api_error('Counterpart account not found', 404);
    try {
        treasuryAssertCategoryCounterpart($counter);
    } catch (InvalidArgumentException $e) {
        api_error($e->getMessage(), 422);
    }

    // Resolve the side-of-the-line "account" — for deposits we look up the
    // accounting_accounts.id via accounting_bank_accounts.gl_account_code;
    // for liabilities the account_id IS the COA row (treasury_liability_accounts
    // joins to it directly).
    if ($type === 'deposit') {
        $bank = $pdo->prepare(
            'SELECT ba.gl_account_code, ba.currency, ba.status, aa.id AS account_id
               FROM accounting_bank_accounts ba
               JOIN accounting_accounts aa
                 ON aa.tenant_id = ba.tenant_id AND aa.code = ba.gl_account_code
              WHERE ba.tenant_id = :t AND ba.id = :id LIMIT 1'
        );
        $bank->execute(['t' => $tenantId, 'id' => (int) $line[$col]]);
        $bank = $bank->fetch(PDO::FETCH_ASSOC);
        if (!$bank) api_error('Could not resolve deposit GL account', 500);
        if ($bank['status'] !== 'active') api_error('This bank account is closed', 409);
        if (strtoupper((string) $bank['currency']) !== 'USD') {
            api_error('This posting path supports USD bank accounts only', 422);
        }
        $sideAccountId = (int) $bank['account_id'];
    } else {
        // liability_account_id IS accounting_accounts.id.
        $sideAccountId = (int) $line[$col];
        $sideCheck = $pdo->prepare(
            'SELECT active, is_postable, currency FROM accounting_accounts
              WHERE tenant_id = :t AND id = :id LIMIT 1'
        );
        $sideCheck->execute(['t' => $tenantId, 'id' => $sideAccountId]);
        $sideAccount = $sideCheck->fetch(PDO::FETCH_ASSOC);
        if (!$sideAccount || (int) $sideAccount['active'] !== 1
            || (int) $sideAccount['is_postable'] !== 1) {
            api_error('This liability account is not active and postable', 422);
        }
        if (!empty($sideAccount['currency']) && strtoupper((string) $sideAccount['currency']) !== 'USD') {
            api_error('This posting path supports USD liability accounts only', 422);
        }
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
    $ownsTransaction = cf_tx_begin($pdo);
    try {
        $lockedStmt = $pdo->prepare("SELECT * FROM {$table} WHERE tenant_id = :t AND id = :id FOR UPDATE");
        $lockedStmt->execute(['t' => $tenantId, 'id' => $lineId]);
        $lockedLine = $lockedStmt->fetch(PDO::FETCH_ASSOC);
        if (!$lockedLine || ($lockedLine['match_status'] ?? '') !== 'unmatched') {
            throw new \RuntimeException('This statement line is already resolved. Refresh before posting.');
        }
        if (round((float) $lockedLine['amount'], 2) !== $amt
            || (string) $lockedLine['posted_date'] !== (string) $line['posted_date']
            || (int) $lockedLine[$col] !== (int) $line[$col]
            || (string) ($lockedLine['description'] ?? '') !== (string) ($line['description'] ?? '')) {
            throw new \RuntimeException('This statement line changed. Refresh before posting.');
        }
        $line = $lockedLine;
        $postingAttempt = treasuryStatementNextAttempt($pdo, $tenantId, $type, $lineId);
        $sourceRecordId = treasuryStatementSourceId($type, $lineId, false, $postingAttempt);
        if ($type === 'deposit') {
            $bankLock = $pdo->prepare(
                'SELECT status, currency FROM accounting_bank_accounts
                  WHERE tenant_id = :t AND id = :id FOR UPDATE'
            );
            $bankLock->execute(['t' => $tenantId, 'id' => (int) $line[$col]]);
            $bankState = $bankLock->fetch(PDO::FETCH_ASSOC);
            if (!$bankState || $bankState['status'] !== 'active'
                || strtoupper((string) $bankState['currency']) !== 'USD') {
                throw new \RuntimeException('This bank account is no longer active in USD. Refresh before posting.');
            }
        }
        $postingEntityId = _treasuryStatementEntityId(
            $pdo,
            $tenantId,
            $type,
            (int) $line[$col]
        );
        $entityCurrency = $pdo->prepare(
            'SELECT base_currency FROM accounting_entities WHERE tenant_id = :t AND id = :id AND active = 1'
        );
        $entityCurrency->execute(['t' => $tenantId, 'id' => $postingEntityId]);
        if (strtoupper((string) $entityCurrency->fetchColumn()) !== 'USD') {
            throw new \RuntimeException('This posting path supports USD entities only');
        }
    $postingDimensions = ['legal_entity' => $postingEntityId];
    $payloadLines = [
        ['account_id' => $debitId,  'debit' => $abs, 'credit' => 0,    'description' => $memo, 'dims' => $postingDimensions],
        ['account_id' => $creditId, 'debit' => 0,    'credit' => $abs, 'description' => $memo, 'dims' => $postingDimensions],
    ];
    $eventResult = null; $eventError = null;
    $pdo->exec('SAVEPOINT treasury_categorize_event');
    try {
        $eventResult = accountingProcessEvent($tenantId, [
            'entity_id'        => $postingEntityId,
            'event_type'       => 'treasury.bank_transaction.categorized',
            'source_module'    => 'treasury_feed',
            'source_record_id' => $sourceRecordId,
            'event_date'       => (string) $line['posted_date'],
            'payload'          => [
                'bank_txn_id'             => (int) $lineId,
                'amount'                  => $abs,
                'currency'                => 'USD',
                'direction'               => $amt < 0 ? 'outflow' : 'inflow',
                'memo'                    => $memo,
                'counterpart_account_id'  => $counterId,
                'split_count'             => 1,
                'dimensions'              => $postingDimensions,
                'lines'                   => $payloadLines,
            ],
        ], (int) ($ctx['user']['id'] ?? 0));
    } catch (\Throwable $e) {
        $pdo->exec('ROLLBACK TO SAVEPOINT treasury_categorize_event');
        if ($e instanceof AccountingEventConflictException) throw $e;
        $eventError = $e->getMessage();
    }
    $pdo->exec('RELEASE SAVEPOINT treasury_categorize_event');

    if ($eventResult && ($eventResult['status'] ?? null) === 'posted') {
        $res = [
            'je_id'     => (int) $eventResult['journal_entry_id'],
            'je_number' => $eventResult['je_number'] ?? null,
            'status'    => 'posted',
            'total_debit' => (float) ($eventResult['total_debit'] ?? $abs),
            'total_credit' => (float) ($eventResult['total_credit'] ?? $abs),
            'idempotent_replay' => (bool) ($eventResult['idempotent_replay'] ?? false),
        ];
    } else {
        // ── Phase-2a Fallback: legacy direct posting ──
        // Fires when (a) no rule seeded yet, or (b) engine threw. We post
        // the JE so the books still balance, but record a discipline
        // violation so Phase-2a step-5 (kill-switch) can prove zero
        // fallback fires in production before we hard-error this path.
        try {
            $res = accountingPostJe($tenantId, [
                'entity_id'      => $postingEntityId,
                'posting_date'   => (string) $line['posted_date'],
                'memo'           => $memo,
                'currency'       => 'USD',
                'source_module'  => 'treasury_feed',
                'source_ref_type'=> $type === 'deposit' ? 'bank_statement_line' : 'liability_statement_line',
                'source_ref_id'  => $lineId,
                'idempotency_key'=> treasuryStatementPostingKey($type, $lineId, false, $postingAttempt),
                'lines'          => [
                    ['account_id' => $debitId,  'debit'  => $abs, 'credit' => 0,    'memo' => $memo, 'dims' => $postingDimensions],
                    ['account_id' => $creditId, 'debit'  => 0,    'credit' => $abs, 'memo' => $memo, 'dims' => $postingDimensions],
                ],
            ], (int) ($ctx['user']['id'] ?? 0), true);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Could not post journal entry: ' . $e->getMessage()
                    . ($eventError ? ' | event-layer error: ' . $eventError : ''), 0, $e);
        }
        moduleEmissionDisciplineLog('treasury_feed', 'treasury.bank_transaction.categorized', [
            'line_id'      => $lineId, 'type' => $type,
            'je_id'        => (int) $res['je_id'],
            'event_error'  => $eventError,
            'event_status' => $eventResult['status'] ?? null,
        ]);
    }

    $postedStmt = $pdo->prepare(
        'SELECT status, source_module, posting_date, entity_id, currency FROM accounting_journal_entries
          WHERE tenant_id = :t AND id = :je'
    );
    $postedStmt->execute(['t' => $tenantId, 'je' => (int) $res['je_id']]);
    $posted = $postedStmt->fetch(PDO::FETCH_ASSOC);
    $postedLinesStmt = $pdo->prepare(
        'SELECT account_id, debit, credit FROM accounting_journal_entry_lines
          WHERE tenant_id = :t AND je_id = :je ORDER BY line_no'
    );
    $postedLinesStmt->execute(['t' => $tenantId, 'je' => (int) $res['je_id']]);
    $postedLines = $postedLinesStmt->fetchAll(PDO::FETCH_ASSOC);
    treasuryAssertCategorizationJournal(
        $posted ?: [],
        $postedLines,
        (string) $line['posted_date'],
        $postingEntityId,
        $debitId,
        $creditId,
        (int) round($abs * 100)
    );

    if ($type === 'deposit') {
        bankRecMatchLine($tenantId, $lineId, (int) $res['je_id'], (int) ($ctx['user']['id'] ?? 0) ?: null);
    } else {
        $matched = $pdo->prepare("UPDATE {$table}
                                  SET match_status = 'matched', matched_je_id = :je
                                WHERE tenant_id = :t AND id = :id AND match_status = 'unmatched'");
        $matched->execute(['t' => $tenantId, 'id' => $lineId, 'je' => $res['je_id']]);
        if ($matched->rowCount() !== 1) {
            throw new \RuntimeException('The liability line changed before it could be matched. Nothing was posted.');
        }
    }

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
            'sr' => $sourceRecordId,
            'je' => (int) $res['je_id'],
        ]);
        if ($postingAttempt > 1) {
            $pdo->prepare(
                'INSERT IGNORE INTO accounting_subledger_links
                    (tenant_id, source_module, source_record_id, journal_entry_id, link_kind)
                 VALUES (:t, "treasury_feed", :sr, :je, "statement_line")'
            )->execute(['t' => $tenantId, 'sr' => treasuryStatementSourceId($type, $lineId, false, 1),
                'je' => (int) $res['je_id']]);
        }
    } catch (\Throwable $_) { /* table absent in pre-7b tenants — non-fatal */ }


    // Record AI suggestion outcome (accept-as-is vs override) for moat training.
    require_once __DIR__ . '/../../../core/ai_categorization.php';
    $aiSuggestionId = (int) ($body['ai_suggestion_id'] ?? 0) ?: null;
    if (trim((string) ($line['merchant_name'] ?? '')) === '') {
        $line['merchant_name'] = (string) ($line['description'] ?? '');
    }
    try {
        aiRecordCategorizationOutcome(
            $tenantId,
            $aiSuggestionId,
            $counterId,
            $line,
            (int) ($ctx['user']['id'] ?? 0)
        );

        // A changed AI suggestion is a reject of the proposed account, not
        // of the bank transaction itself.
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
    } catch (\Throwable $e) {
        error_log('[treasury-feed] categorization learning skipped for line ' . $lineId . ': ' . $e->getMessage());
    }

    cf_tx_commit($pdo, $ownsTransaction);
    } catch (\Throwable $e) {
        cf_tx_rollback($pdo, $ownsTransaction);
        api_error($e->getMessage(), 409);
    }

    api_ok([
        'ok'             => true,
        'line_id'        => $lineId,
        'matched_je_id'  => $res['je_id'],
        'je_number'      => $res['je_number'] ?? null,
        'status'         => $res['status'] ?? 'posted',
        'total_debit'    => $res['total_debit'] ?? $abs,
        'total_credit'   => $res['total_credit'] ?? $abs,
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
$limit     = max(10, min(200, (int) ($_GET['per_page'] ?? $_GET['limit'] ?? 50)));
$page      = max(1, (int) ($_GET['page'] ?? 1));
$offset    = ($page - 1) * $limit;
if ($accountId <= 0) api_error('account_id required', 422);
if (!in_array($type, ['deposit', 'liability'], true)) {
    api_error("type must be 'deposit' or 'liability'", 422);
}

$table = $type === 'deposit'
    ? 'accounting_bank_statement_lines'
    : 'treasury_liability_statement_lines';
$accountColumn = $type === 'deposit' ? 'bank_account_id' : 'liability_account_id';

$where = ['tenant_id = :t', $accountColumn . ' = :a'];
$params = ['t' => $tenantId, 'a' => $accountId];
if ($type === 'deposit') {
    if (bankTxnHasColumn($pdo, 'accounting_bank_statement_lines', 'duplicate_of_line_id')) {
        $where[] = 'duplicate_of_line_id IS NULL';
    }
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

}

$query = trim((string) ($_GET['q'] ?? ''));
if ($query !== '') {
    $parts = ['description LIKE :qd', 'bank_reference LIKE :qr'];
    $params['qd'] = '%' . $query . '%';
    $params['qr'] = '%' . $query . '%';
    if ($type === 'liability') {
        $parts[] = 'merchant_name LIKE :qm';
        $parts[] = 'category LIKE :qc';
        $params['qm'] = '%' . $query . '%';
        $params['qc'] = '%' . $query . '%';
    }
    $where[] = '(' . implode(' OR ', $parts) . ')';
}

$status = (string) ($_GET['status'] ?? '');
if (in_array($status, ['unmatched', 'matched', 'ignored'], true)) {
    $where[] = 'match_status = :status';
    $params['status'] = $status;
}

$direction = (string) ($_GET['direction'] ?? '');
if ($direction === 'inflow') $where[] = 'amount >= 0';
if ($direction === 'outflow') $where[] = 'amount < 0';

$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
if ($dateFrom !== '') { $where[] = 'posted_date >= :date_from'; $params['date_from'] = $dateFrom; }
if ($dateTo !== '') { $where[] = 'posted_date <= :date_to'; $params['date_to'] = $dateTo; }

if (isset($_GET['amount_min']) && $_GET['amount_min'] !== '') {
    $where[] = 'amount >= :amount_min';
    $params['amount_min'] = (float) $_GET['amount_min'];
}
if (isset($_GET['amount_max']) && $_GET['amount_max'] !== '') {
    $where[] = 'amount <= :amount_max';
    $params['amount_max'] = (float) $_GET['amount_max'];
}

$categoryAccountId = (int) ($_GET['category_account_id'] ?? 0);
if ($categoryAccountId > 0) {
    $where[] = 'EXISTS (SELECT 1 FROM accounting_journal_entry_lines filter_jel
                         WHERE filter_jel.je_id = matched_je_id
                           AND filter_jel.account_id = :category_account_id)';
    $params['category_account_id'] = $categoryAccountId;
}

$sortColumns = [
    'date'        => 'posted_date',
    'description' => 'description',
    'amount'      => 'amount',
    'status'      => 'match_status',
    'created'     => 'created_at',
];
$sortBy = (string) ($_GET['sort_by'] ?? 'date');
$sortColumn = $sortColumns[$sortBy] ?? $sortColumns['date'];
$sortDir = strtolower((string) ($_GET['sort_dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
$whereSql = implode(' AND ', $where);

$summaryStmt = $pdo->prepare(
    "SELECT COUNT(*) AS row_count,
            COALESCE(SUM(CASE WHEN amount >= 0 THEN amount ELSE 0 END), 0) AS inflow_total,
            COALESCE(SUM(CASE WHEN amount < 0 THEN ABS(amount) ELSE 0 END), 0) AS outflow_total,
            SUM(match_status = 'unmatched') AS unmatched_count,
            SUM(match_status = 'matched') AS matched_count,
            SUM(match_status = 'ignored') AS ignored_count
       FROM {$table}
      WHERE {$whereSql}"
);
$summaryStmt->execute($params);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$totalWhere = ['tenant_id = :tt', $accountColumn . ' = :ta'];
if ($type === 'deposit' && bankTxnHasColumn($pdo, 'accounting_bank_statement_lines', 'duplicate_of_line_id')) {
    $totalWhere[] = 'duplicate_of_line_id IS NULL';
}
$totalStmt = $pdo->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . implode(' AND ', $totalWhere));
$totalStmt->execute(['tt' => $tenantId, 'ta' => $accountId]);
$totalCount = (int) $totalStmt->fetchColumn();

$selectFields = $type === 'deposit'
    ? 'id, posted_date, description, amount, bank_reference, fitid,
       match_status, matched_je_id, created_at,
       NULL AS merchant_name, NULL AS category,
       ai_suggested_account_code, ai_suggested_rule_id, applied_rule_id'
    : 'id, posted_date, description, amount, bank_reference, fitid,
       merchant_name, category, match_status, matched_je_id, created_at,
       NULL AS ai_suggested_account_code, NULL AS ai_suggested_rule_id, NULL AS applied_rule_id';

$stmt = $pdo->prepare(
    "SELECT {$selectFields}
       FROM {$table}
      WHERE {$whereSql}
      ORDER BY {$sortColumn} {$sortDir}, id {$sortDir}
      LIMIT {$limit} OFFSET {$offset}"
);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$count   = (int) ($summary['row_count'] ?? 0);
$inflow  = (float) ($summary['inflow_total'] ?? 0);
$outflow = (float) ($summary['outflow_total'] ?? 0);

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

// Enrich matched statement rows in one batch so Treasury can show the posted
// category inline and preview the full JE without issuing one request per row.
$matchedJeIds = [];
foreach ($rows as $r) {
    $jeId = (int) ($r['matched_je_id'] ?? 0);
    if ($jeId > 0) $matchedJeIds[$jeId] = $jeId;
}

$journalById = [];
$treasuryLinksByJournal = [];
if ($matchedJeIds) {
    $jeParams = ['t' => $tenantId];
    $jePlaceholders = [];
    foreach (array_values($matchedJeIds) as $i => $jeId) {
        $key = 'je' . $i;
        $jePlaceholders[] = ':' . $key;
        $jeParams[$key] = $jeId;
    }

    $jeStmt = $pdo->prepare(
        'SELECT je.id, je.je_number, je.posting_date, je.status,
                je.source_module, je.source_ref_type, je.source_ref_id,
                je.memo AS je_memo, je.total_debit, je.total_credit,
                jel.line_no, jel.account_id, jel.debit, jel.credit,
                jel.memo AS line_memo, aa.code AS account_code,
                aa.name AS account_name
           FROM accounting_journal_entries je
           JOIN accounting_journal_entry_lines jel ON jel.je_id = je.id
           JOIN accounting_accounts aa
             ON aa.tenant_id = je.tenant_id AND aa.id = jel.account_id
          WHERE je.tenant_id = :t
            AND je.id IN (' . implode(',', $jePlaceholders) . ')
          ORDER BY je.id, jel.line_no'
    );
    $jeStmt->execute($jeParams);

    foreach ($jeStmt->fetchAll(PDO::FETCH_ASSOC) as $detail) {
        $jeId = (int) $detail['id'];
        if (!isset($journalById[$jeId])) {
            $journalById[$jeId] = [
                'id'           => $jeId,
                'je_number'    => (string) $detail['je_number'],
                'posting_date' => (string) $detail['posting_date'],
                'status'       => (string) $detail['status'],
                'source_module' => $detail['source_module'],
                'source_ref_type' => $detail['source_ref_type'],
                'source_ref_id' => $detail['source_ref_id'],
                'memo'         => $detail['je_memo'],
                'total_debit'  => (float) $detail['total_debit'],
                'total_credit' => (float) $detail['total_credit'],
                'lines'        => [],
            ];
        }
        $journalById[$jeId]['lines'][] = [
            'line_no'      => (int) $detail['line_no'],
            'account_id'   => (int) $detail['account_id'],
            'account_code' => (string) $detail['account_code'],
            'account_name' => (string) $detail['account_name'],
            'debit'        => (float) $detail['debit'],
            'credit'       => (float) $detail['credit'],
            'memo'         => $detail['line_memo'],
        ];
    }

    try {
        $linkStmt = $pdo->prepare(
            'SELECT journal_entry_id, source_record_id FROM accounting_subledger_links
              WHERE tenant_id = :t AND source_module = "treasury_feed"
                AND journal_entry_id IN (' . implode(',', $jePlaceholders) . ')'
        );
        $linkStmt->execute($jeParams);
        foreach ($linkStmt->fetchAll(PDO::FETCH_ASSOC) as $link) {
            $treasuryLinksByJournal[(int) $link['journal_entry_id']][(string) $link['source_record_id']] = true;
        }
    } catch (Throwable $_) {
        // Older tenants may not have the lineage table.
    }
}

foreach ($rows as $i => $r) {
    $jeId = (int) ($r['matched_je_id'] ?? 0);
    if ($r['match_status'] === 'matched') {
        $journal = $journalById[$jeId] ?? [];
        $prefix = $type === 'deposit' ? 'bank_line:' : 'liab_line:';
        $hasOwnLink = isset($treasuryLinksByJournal[$jeId][$prefix . $r['id']])
            || isset($treasuryLinksByJournal[$jeId][$prefix . 'split:' . $r['id']]);
        $directRef = (string) ($journal['source_ref_type'] ?? '')
            === ($type === 'deposit' ? 'bank_statement_line' : 'liability_statement_line')
            && (int) ($journal['source_ref_id'] ?? 0) === (int) $r['id'];
        $rows[$i]['can_correct_categorization'] = ($journal['status'] ?? '') === 'posted'
            && ($journal['source_module'] ?? '') === 'treasury_feed'
            && ($hasOwnLink || $directRef);
        $rows[$i]['unmatch_blocker'] = !$journal
            ? 'The matched journal is missing. Review this line before changing its status.'
            : ($type === 'deposit'
                ? bankRecUnmatchBlocker(['id' => $r['id']] + $journal, $hasOwnLink)
                : treasuryLiabilityUnmatchBlocker((int) $r['id'], $journal, $hasOwnLink));
    }
    if ($jeId <= 0 || !isset($journalById[$jeId])) continue;

    $rows[$i]['journal_entry'] = $journalById[$jeId];
    $rows[$i]['categorization'] = array_values(array_filter(
        $journalById[$jeId]['lines'],
        static fn(array $line): bool => (int) $line['account_id'] !== $sideAccountId
    ));
}

if ($rows) {
    $correctionParams = ['t' => $tenantId, 'line_type' => $type];
    $correctionIds = [];
    foreach ($rows as $i => $row) {
        $key = 'line' . $i;
        $correctionIds[] = ':' . $key;
        $correctionParams[$key] = (int) $row['id'];
    }
    try {
        $correctionStmt = $pdo->prepare(
            'SELECT line_id, COUNT(*) AS correction_count, MAX(reversal_je_id) AS last_reversal_je_id
               FROM treasury_statement_corrections
              WHERE tenant_id = :t AND line_type = :line_type
                AND line_id IN (' . implode(',', $correctionIds) . ') GROUP BY line_id'
        );
        $correctionStmt->execute($correctionParams);
        $history = [];
        foreach ($correctionStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $history[(int) $item['line_id']] = $item;
        }
        foreach ($rows as $i => $row) {
            $rows[$i]['correction_count'] = (int) ($history[(int) $row['id']]['correction_count'] ?? 0);
            $rows[$i]['last_reversal_je_id'] = (int) ($history[(int) $row['id']]['last_reversal_je_id'] ?? 0);
        }
    } catch (Throwable $_) {
        // Keep older tenants readable until the correction migration runs.
    }
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

    // Suggestions are generated only when the operator clicks "AI cat."
    // Rendering a bank feed must stay read-only, fast, and independent of
    // model or analytics-table availability.
    $rows[$i]['ai_suggestion'] = null;
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

$balance = [
    'institution_balance' => null,
    'available_balance'   => null,
    'ledger_balance'      => 0.0,
    'difference'          => null,
    'currency'            => 'USD',
    'institution_as_of'   => null,
    'ledger_as_of'        => date('Y-m-d H:i:s'),
];
if ($type === 'deposit') {
    $balanceStmt = $pdo->prepare(
        "SELECT ba.currency, ba.last_feed_synced_at,
                pa.current_balance_cents, pa.available_balance_cents, pa.balance_as_of,
                COALESCE(SUM(CASE WHEN je.status = 'posted' THEN jel.debit - jel.credit ELSE 0 END), 0) AS ledger_balance
           FROM accounting_bank_accounts ba
           LEFT JOIN plaid_accounts pa
             ON pa.tenant_id = ba.tenant_id AND pa.account_id = ba.plaid_account_id
           LEFT JOIN accounting_accounts aa
             ON aa.tenant_id = ba.tenant_id AND aa.code = ba.gl_account_code
           LEFT JOIN accounting_journal_entry_lines jel ON jel.account_id = aa.id
           LEFT JOIN accounting_journal_entries je
             ON je.tenant_id = ba.tenant_id AND je.id = jel.je_id
          WHERE ba.tenant_id = :t AND ba.id = :a
          GROUP BY ba.id"
    );
} else {
    $balanceStmt = $pdo->prepare(
        "SELECT COALESCE(aa.currency, pa.iso_currency_code, 'USD') AS currency,
                pa.current_balance_cents, pa.available_balance_cents, pa.balance_as_of,
                COALESCE(SUM(CASE WHEN je.status = 'posted' THEN jel.credit - jel.debit ELSE 0 END), 0) AS ledger_balance
           FROM accounting_accounts aa
           JOIN treasury_liability_accounts tla
             ON tla.tenant_id = aa.tenant_id AND tla.account_id = aa.id
           LEFT JOIN plaid_accounts pa
             ON pa.tenant_id = aa.tenant_id AND pa.account_id = tla.plaid_account_id
           LEFT JOIN accounting_journal_entry_lines jel ON jel.account_id = aa.id
           LEFT JOIN accounting_journal_entries je
             ON je.tenant_id = aa.tenant_id AND je.id = jel.je_id
          WHERE aa.tenant_id = :t AND aa.id = :a
          GROUP BY aa.id"
    );
}
$balanceStmt->execute(['t' => $tenantId, 'a' => $accountId]);
$balanceRow = $balanceStmt->fetch(PDO::FETCH_ASSOC) ?: [];
if ($type === 'liability' && !$balanceRow) {
    api_error('Treasury liability account not found', 404);
}
$balance['currency'] = (string) ($balanceRow['currency'] ?? 'USD');
$balance['ledger_balance'] = round((float) ($balanceRow['ledger_balance'] ?? 0), 2);
$balance['institution_as_of'] = $balanceRow['balance_as_of']
    ?? $balanceRow['last_feed_synced_at']
    ?? null;
if (isset($balanceRow['current_balance_cents'])) {
    $balance['institution_balance'] = round(((int) $balanceRow['current_balance_cents']) / 100, 2);
    $balance['difference'] = round($balance['institution_balance'] - $balance['ledger_balance'], 2);
}
if (isset($balanceRow['available_balance_cents'])) {
    $balance['available_balance'] = round(((int) $balanceRow['available_balance_cents']) / 100, 2);
}

$entities = accountingListActiveEntities($tenantId);

api_ok([
    'rows'                  => $rows,
    'entities'              => $entities,
    'count'                 => $count,
    'total_count'           => $totalCount,
    'inflow_total'          => round($inflow, 2),
    'outflow_total'         => round($outflow, 2),
    'status_counts'         => [
        'unmatched' => (int) ($summary['unmatched_count'] ?? 0),
        'matched'   => (int) ($summary['matched_count'] ?? 0),
        'ignored'   => (int) ($summary['ignored_count'] ?? 0),
    ],
    'balance'               => $balance,
    'pagination'            => [
        'page'        => $page,
        'per_page'    => $limit,
        'total_pages' => max(1, (int) ceil($count / $limit)),
    ],
    'plaid_item_pk'         => $plaidItemPk,
    'plaid_item_external_id'=> $plaidItemExternalId,
    'plaid_account_id'      => $plaidAccountId,
]);
