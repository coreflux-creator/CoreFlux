<?php
/** Rollback-only liability statement transitions on a disposable local schema. */
declare(strict_types=1);

$database = (string) getenv('DB_NAME');
if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'staging'
    || !preg_match('/^coreaccounting_blank_test\d+$/D', $database)) {
    fwrite(STDERR, "This test requires an isolated local staging schema.\n");
    exit(2);
}
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../modules/accounting/lib/accounting.php';
require_once __DIR__ . '/../modules/treasury/lib/statement_state.php';

$pdo = getDB();
if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $database) {
    throw new RuntimeException('Connected database does not match the requested test schema.');
}
$pdo->exec((string) file_get_contents(__DIR__ . '/../modules/treasury/migrations/008_statement_corrections.sql'));
$tenantId = (int) $pdo->query('SELECT id FROM tenants ORDER BY id LIMIT 1')->fetchColumn();
$entityId = (int) $pdo->query('SELECT id FROM accounting_entities ORDER BY id LIMIT 1')->fetchColumn();
if ($tenantId <= 0 || $entityId <= 0) throw new RuntimeException('Disposable tenant/entity missing.');
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    $checks++;
};
$rejects = static function (callable $action): bool {
    try {
        $action();
        return false;
    } catch (RuntimeException|InvalidArgumentException $e) {
        return true;
    }
};
$before = (int) $pdo->query('SELECT COUNT(*) FROM treasury_liability_statement_lines')->fetchColumn();
$bankBefore = (int) $pdo->query('SELECT COUNT(*) FROM accounting_bank_statement_lines')->fetchColumn();
$correctionBefore = (int) $pdo->query('SELECT COUNT(*) FROM treasury_statement_corrections')->fetchColumn();
$eventBefore = (int) $pdo->query('SELECT COUNT(*) FROM accounting_events')->fetchColumn();

$pdo->beginTransaction();
try {
    $code = '29' . random_int(100000, 999999);
    $pdo->prepare(
        'INSERT INTO accounting_accounts
            (tenant_id, code, name, account_type, normal_side, currency, is_postable, active)
         VALUES (:t, :code, "Rollback-only liability", "liability", "credit", "USD", 1, 1)'
    )->execute(['t' => $tenantId, 'code' => $code]);
    $accountId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO treasury_liability_accounts (tenant_id, account_id, entity_id, subtype)
         VALUES (:t, :account_id, :entity_id, "other_liability")'
    )->execute(['t' => $tenantId, 'account_id' => $accountId, 'entity_id' => $entityId]);

    $makeLine = static function (float $amount, string $status = 'unmatched') use ($pdo, $tenantId, $accountId): int {
        $pdo->prepare(
            'INSERT INTO treasury_liability_statement_lines
                (tenant_id, liability_account_id, posted_date, description, amount, match_status, fitid)
             VALUES (:t, :account_id, "2025-06-01", "Rollback-only test", :amount, :status, :fitid)'
        )->execute([
            't' => $tenantId, 'account_id' => $accountId, 'amount' => $amount,
            'status' => $status, 'fitid' => 'QA-' . bin2hex(random_bytes(8)),
        ]);
        return (int) $pdo->lastInsertId();
    };
    $post = static function (float $amount, ?int $sourceLineId = null) use ($tenantId, $entityId, $code): int {
        $payload = [
            'entity_id' => $entityId, 'posting_date' => '2025-06-01',
            'currency' => 'USD', 'memo' => 'Rollback-only liability match',
            'idempotency_key' => 'qa-liability-' . bin2hex(random_bytes(8)),
            'lines' => [
                ['account_code' => '6990', 'debit' => $amount, 'credit' => 0],
                ['account_code' => $code, 'debit' => 0, 'credit' => $amount],
            ],
        ];
        if ($sourceLineId !== null) {
            $payload['source_module'] = 'treasury_feed';
            $payload['source_ref_type'] = 'liability_statement_line';
            $payload['source_ref_id'] = $sourceLineId;
        }
        return (int) accountingPostJe($tenantId, $payload, null, true)['je_id'];
    };

    $lineId = $makeLine(-10.00);
    $otherLineId = $makeLine(-10.00);
    $wrongAmountLineId = $makeLine(-9.00);
    $journalId = $post(10.00);
    $check($rejects(static fn() => treasuryMatchLiabilityLine(
        $pdo, $tenantId, $wrongAmountLineId, $journalId, $entityId
    )), 'different statement amount is refused');
    $check($rejects(static fn() => treasuryMatchLiabilityLine(
        $pdo, $tenantId, $lineId, $journalId, $entityId + 1000
    )), 'different legal entity is refused');
    $pdo->prepare('UPDATE accounting_journal_entries SET currency = "EUR" WHERE id = :id')
        ->execute(['id' => $journalId]);
    $check($rejects(static fn() => treasuryMatchLiabilityLine(
        $pdo, $tenantId, $lineId, $journalId, $entityId
    )), 'different currency is refused');
    $pdo->prepare('UPDATE accounting_journal_entries SET currency = "USD", status = "draft" WHERE id = :id')
        ->execute(['id' => $journalId]);
    $check($rejects(static fn() => treasuryMatchLiabilityLine(
        $pdo, $tenantId, $lineId, $journalId, $entityId
    )), 'unposted journal is refused');
    $pdo->prepare('UPDATE accounting_journal_entries SET status = "posted" WHERE id = :id')
        ->execute(['id' => $journalId]);

    $check(treasuryMatchLiabilityLine($pdo, $tenantId, $lineId, $journalId, $entityId),
        'matching posted liability movement succeeds');
    $check(!treasuryMatchLiabilityLine($pdo, $tenantId, $lineId, $journalId, $entityId),
        'identical match retry is a no-op');
    $check($rejects(static fn() => treasuryMatchLiabilityLine(
        $pdo, $tenantId, $otherLineId, $journalId, $entityId
    )), 'one journal cannot match a second liability line');
    $check(treasuryUnmatchLiabilityLine($pdo, $tenantId, $lineId),
        'unrelated journal match may be removed');
    $check(!treasuryUnmatchLiabilityLine($pdo, $tenantId, $lineId),
        'unmatch retry is a no-op');

    $sourceLineId = $makeLine(-3.00);
    $sourceJournalId = $post(3.00, $sourceLineId);
    $pdo->prepare(
        'UPDATE treasury_liability_statement_lines SET match_status = "matched", matched_je_id = :je
          WHERE id = :id'
    )->execute(['je' => $sourceJournalId, 'id' => $sourceLineId]);
    $check($rejects(static fn() => treasuryUnmatchLiabilityLine($pdo, $tenantId, $sourceLineId)),
        'source-owned posted journal cannot be orphaned');
    $check(treasuryStatementSourceId('liability', $sourceLineId, false, 1)
        === 'liab_line:' . $sourceLineId, 'first posting retains legacy event identity');
    $check(treasuryStatementPostingKey('liability', $sourceLineId, false, 1)
        === 'treasury_feed:liability:' . $sourceLineId, 'first posting retains legacy journal key');
    $check($rejects(static fn() => treasuryCorrectCategorization(
        $pdo, $tenantId, 'liability', $sourceLineId, '', null
    )), 'blank correction reason is refused');
    $correction = treasuryCorrectCategorization(
        $pdo, $tenantId, 'liability', $sourceLineId, 'Wrong category', null
    );
    $check($correction['original_je_id'] === $sourceJournalId
        && $correction['reversal_je_id'] > 0, 'correction links original and reversal journals');
    $state = $pdo->prepare(
        'SELECT match_status, matched_je_id FROM treasury_liability_statement_lines WHERE id = :id'
    );
    $state->execute(['id' => $sourceLineId]);
    $correctedLine = $state->fetch(PDO::FETCH_ASSOC);
    $check($correctedLine['match_status'] === 'unmatched' && $correctedLine['matched_je_id'] === null,
        'correction reopens the statement line');
    $state = $pdo->prepare(
        'SELECT status, reversed_by_je_id FROM accounting_journal_entries WHERE id = :id'
    );
    $state->execute(['id' => $sourceJournalId]);
    $correctedJournal = $state->fetch(PDO::FETCH_ASSOC);
    $check($correctedJournal['status'] === 'reversed'
        && (int) $correctedJournal['reversed_by_je_id'] === $correction['reversal_je_id'],
        'original journal remains with its reversal');
    $check(treasuryStatementNextAttempt($pdo, $tenantId, 'liability', $sourceLineId) === 2,
        'replacement posting advances to attempt two');
    $check(treasuryStatementSourceId('liability', $sourceLineId, true, 2)
        === 'liab_line:split:attempt:2:' . $sourceLineId,
        'replacement split has a fresh event identity');
    $check(treasuryStatementPostingKey('liability', $sourceLineId, false, 2)
        === 'treasury_feed:liability:' . $sourceLineId . ':attempt:2',
        'replacement posting has a fresh journal key');
    $check($rejects(static fn() => treasuryCorrectCategorization(
        $pdo, $tenantId, 'liability', $sourceLineId, 'Again', null
    )), 'repeated correction cannot reverse twice');
    $linkedLineId = $makeLine(-2.00);
    $linkedJournalId = $post(2.00);
    $pdo->prepare(
        'INSERT INTO accounting_subledger_links
            (tenant_id, source_module, source_record_id, journal_entry_id, link_kind)
         VALUES (:t, "treasury_feed", :source_ref, :je, "primary")'
    )->execute([
        't' => $tenantId, 'source_ref' => 'liab_line:split:' . $linkedLineId,
        'je' => $linkedJournalId,
    ]);
    $pdo->prepare(
        'UPDATE treasury_liability_statement_lines SET match_status = "matched", matched_je_id = :je
          WHERE id = :id'
    )->execute(['je' => $linkedJournalId, 'id' => $linkedLineId]);
    $check($rejects(static fn() => treasuryUnmatchLiabilityLine($pdo, $tenantId, $linkedLineId)),
        'event-linked split journal cannot be orphaned');

    $cashCode = '18' . random_int(100000, 999999);
    $pdo->prepare(
        'INSERT INTO accounting_accounts
            (tenant_id, code, name, account_type, normal_side, currency, is_postable, active)
         VALUES (:t, :code, "Rollback-only bank cash", "asset", "debit", "USD", 1, 1)'
    )->execute(['t' => $tenantId, 'code' => $cashCode]);
    $cashAccountId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO accounting_bank_accounts (tenant_id, entity_id, name, gl_account_code, currency)
         VALUES (:t, :entity_id, "Rollback-only bank", :code, "USD")'
    )->execute(['t' => $tenantId, 'entity_id' => $entityId, 'code' => $cashCode]);
    $bankId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO accounting_bank_statement_lines
            (tenant_id, bank_account_id, posted_date, description, amount, match_status, fitid)
         VALUES (:t, :bank_id, "2025-06-01", "Rollback-only charge", -4.00, "matched", :fitid)'
    )->execute(['t' => $tenantId, 'bank_id' => $bankId,
        'fitid' => 'QA-' . bin2hex(random_bytes(8))]);
    $bankLineId = (int) $pdo->lastInsertId();
    $bankSourceId = treasuryStatementSourceId('deposit', $bankLineId, false, 1);
    $bankJournalId = (int) accountingPostJe($tenantId, [
        'entity_id' => $entityId, 'posting_date' => '2025-06-01', 'currency' => 'USD',
        'memo' => 'Rollback-only bank charge', 'source_module' => 'treasury_feed',
        'source_ref_type' => 'bank_statement_line', 'source_ref_id' => $bankLineId,
        'idempotency_key' => treasuryStatementPostingKey('deposit', $bankLineId, false, 1),
        'lines' => [
            ['account_code' => '6990', 'debit' => 4.00, 'credit' => 0],
            ['account_code' => $cashCode, 'debit' => 0, 'credit' => 4.00],
        ],
    ], null, true)['je_id'];
    $pdo->prepare(
        'UPDATE accounting_bank_statement_lines SET matched_je_id = :je WHERE id = :id'
    )->execute(['je' => $bankJournalId, 'id' => $bankLineId]);
    $pdo->prepare(
        'INSERT INTO accounting_events
            (tenant_id, entity_id, event_type, source_module, source_record_id,
             event_date, payload, status, journal_entry_id)
         VALUES (:t, :entity_id, "treasury.bank_transaction.categorized", "treasury_feed",
                 :source_id, "2025-06-01", "{}", "posted", :je)'
    )->execute(['t' => $tenantId, 'entity_id' => $entityId,
        'source_id' => $bankSourceId, 'je' => $bankJournalId]);
    $bankEventId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO accounting_subledger_links
            (tenant_id, source_module, source_record_id, journal_entry_id, accounting_event_id, link_kind)
         VALUES (:t, "treasury_feed", :source_id, :je, :event_id, "primary")'
    )->execute(['t' => $tenantId, 'source_id' => $bankSourceId,
        'je' => $bankJournalId, 'event_id' => $bankEventId]);
    $pdo->prepare(
        'INSERT INTO accounting_reconciliations
            (tenant_id, bank_account_id, period_end, statement_balance, gl_balance, status)
         VALUES (:t, :bank_id, "2025-06-30", 0, 0, "closed")'
    )->execute(['t' => $tenantId, 'bank_id' => $bankId]);
    $reconciliationId = (int) $pdo->lastInsertId();
    $check($rejects(static fn() => treasuryCorrectCategorization(
        $pdo, $tenantId, 'deposit', $bankLineId, 'Wrong category', null
    )), 'closed reconciliation blocks correction');
    $pdo->prepare('UPDATE accounting_reconciliations SET status = "reopened" WHERE id = :id')
        ->execute(['id' => $reconciliationId]);
    $bankCorrection = treasuryCorrectCategorization(
        $pdo, $tenantId, 'deposit', $bankLineId, 'Wrong category', null
    );
    $check($bankCorrection['original_je_id'] === $bankJournalId
        && $bankCorrection['reversal_je_id'] > 0, 'bank correction reverses its own journal');
    $eventState = $pdo->prepare('SELECT status FROM accounting_events WHERE id = :id');
    $eventState->execute(['id' => $bankEventId]);
    $check($eventState->fetchColumn() === 'reversed', 'bank correction reverses its source event');
    $reversalLink = $pdo->prepare(
        'SELECT COUNT(*) FROM accounting_subledger_links
          WHERE tenant_id = :t AND source_module = "treasury_feed"
            AND source_record_id = :source_id AND journal_entry_id = :je AND link_kind = "reversal"'
    );
    $reversalLink->execute(['t' => $tenantId, 'source_id' => $bankSourceId,
        'je' => $bankCorrection['reversal_je_id']]);
    $check((int) $reversalLink->fetchColumn() === 1, 'reversal retains source lineage');
    $check(treasuryStatementNextAttempt($pdo, $tenantId, 'deposit', $bankLineId) === 2,
        'corrected bank line advances to replacement attempt');
    $replacementSourceId = treasuryStatementSourceId('deposit', $bankLineId, true, 2);
    $replacementJournalId = (int) accountingPostJe($tenantId, [
        'entity_id' => $entityId, 'posting_date' => '2025-06-01', 'currency' => 'USD',
        'memo' => 'Rollback-only replacement split', 'source_module' => 'treasury_feed',
        'source_ref_type' => 'bank_statement_line', 'source_ref_id' => $bankLineId,
        'idempotency_key' => treasuryStatementPostingKey('deposit', $bankLineId, true, 2),
        'lines' => [
            ['account_code' => '6990', 'debit' => 4.00, 'credit' => 0],
            ['account_code' => $cashCode, 'debit' => 0, 'credit' => 4.00],
        ],
    ], null, true)['je_id'];
    $check($replacementJournalId !== $bankJournalId,
        'replacement key posts a new journal instead of replaying the reversed one');
    $pdo->prepare(
        'UPDATE accounting_bank_statement_lines SET match_status = "matched", matched_je_id = :je
          WHERE id = :id'
    )->execute(['je' => $replacementJournalId, 'id' => $bankLineId]);
    $pdo->prepare(
        'INSERT INTO accounting_subledger_links
            (tenant_id, source_module, source_record_id, journal_entry_id, link_kind)
         VALUES (:t, "treasury_feed", :source_id, :je, "primary")'
    )->execute(['t' => $tenantId, 'source_id' => $replacementSourceId,
        'je' => $replacementJournalId]);
    $replacementCorrection = treasuryCorrectCategorization(
        $pdo, $tenantId, 'deposit', $bankLineId, 'Replace again', null
    );
    $check($replacementCorrection['attempt_no'] === 2
        && treasuryStatementNextAttempt($pdo, $tenantId, 'deposit', $bankLineId) === 3,
        'second correction preserves a third unique attempt');
    $check(treasuryStatementSourceBelongsToLine($replacementSourceId, 'deposit', $bankLineId),
        'replacement source remains tied to its bank line');
    $check(!treasuryStatementSourceBelongsToLine($replacementSourceId, 'liability', $bankLineId),
        'replacement source cannot be mistaken for a liability line');
    $ignoredLineId = $makeLine(-1.00, 'ignored');
    $check(treasuryUnmatchLiabilityLine($pdo, $tenantId, $ignoredLineId),
        'ignored line can be restored');
    $check($pdo->inTransaction(), 'fixture remains inside its caller transaction');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}

$after = (int) $pdo->query('SELECT COUNT(*) FROM treasury_liability_statement_lines')->fetchColumn();
$check($after === $before, 'rollback leaves no test statement lines');
$accountStmt = $pdo->prepare('SELECT COUNT(*) FROM accounting_accounts WHERE tenant_id = :t AND code = :code');
$accountStmt->execute(['t' => $tenantId, 'code' => $code]);
$check((int) $accountStmt->fetchColumn() === 0, 'rollback leaves no test account');
$journalStmt = $pdo->prepare('SELECT COUNT(*) FROM accounting_journal_entries WHERE tenant_id = :t AND id = :id');
$journalStmt->execute(['t' => $tenantId, 'id' => $journalId]);
$check((int) $journalStmt->fetchColumn() === 0, 'rollback leaves no test journal');
$check((int) $pdo->query('SELECT COUNT(*) FROM accounting_bank_statement_lines')->fetchColumn() === $bankBefore,
    'rollback leaves no test bank line');
$check((int) $pdo->query('SELECT COUNT(*) FROM treasury_statement_corrections')->fetchColumn() === $correctionBefore,
    'rollback leaves no correction audit rows');
$check((int) $pdo->query('SELECT COUNT(*) FROM accounting_events')->fetchColumn() === $eventBefore,
    'rollback leaves no source event');
echo "Treasury statement state MariaDB: {$checks} checks passed.\n";
