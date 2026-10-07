<?php
/** Match and unmatch liability statement lines without changing their journals. */
declare(strict_types=1);

require_once __DIR__ . '/bank_posting.php';

function treasuryStatementSourceId(string $type, int $lineId, bool $split, int $attempt): string
{
    if (!in_array($type, ['deposit', 'liability'], true) || $lineId <= 0 || $attempt <= 0) {
        throw new InvalidArgumentException('Invalid Treasury statement posting identity.');
    }
    $prefix = $type === 'deposit' ? 'bank_line:' : 'liab_line:';
    if ($split) $prefix .= 'split:';
    return $prefix . ($attempt === 1 ? '' : 'attempt:' . $attempt . ':') . $lineId;
}

function treasuryStatementSourceBelongsToLine(string $sourceId, string $type, int $lineId): bool
{
    $prefix = $type === 'deposit' ? 'bank_line:' : 'liab_line:';
    return $sourceId === $prefix . $lineId
        || $sourceId === $prefix . 'split:' . $lineId
        || (bool) preg_match('/^' . preg_quote($prefix, '/')
            . '(?:split:)?attempt:[2-9][0-9]*:' . $lineId . '$/D', $sourceId);
}

function treasuryStatementPostingKey(string $type, int $lineId, bool $split, int $attempt): string
{
    treasuryStatementSourceId($type, $lineId, $split, $attempt);
    $prefix = $split ? 'treasury_feed_split' : 'treasury_feed';
    return "{$prefix}:{$type}:{$lineId}"
        . ($attempt > 1 ? ":attempt:{$attempt}" : '');
}

function treasuryStatementNextAttempt(PDO $pdo, int $tenantId, string $type, int $lineId): int
{
    $stmt = $pdo->prepare(
        'SELECT COALESCE(MAX(attempt_no), 0) + 1 FROM treasury_statement_corrections
          WHERE tenant_id = :t AND line_type = :line_type AND line_id = :line_id'
    );
    $stmt->execute(['t' => $tenantId, 'line_type' => $type, 'line_id' => $lineId]);
    return (int) $stmt->fetchColumn();
}

function treasuryCorrectCategorization(
    PDO $pdo,
    int $tenantId,
    string $type,
    int $lineId,
    string $reason,
    ?int $actorUserId
): array {
    $reason = trim($reason);
    if ($reason === '' || strlen($reason) > 500) {
        throw new InvalidArgumentException('Enter a correction reason (up to 500 characters).');
    }
    if (!in_array($type, ['deposit', 'liability'], true) || $lineId <= 0) {
        throw new InvalidArgumentException('Choose a Treasury statement line to correct.');
    }
    require_once __DIR__ . '/../../accounting/lib/accounting.php';
    if ($pdo !== getDB()) {
        throw new RuntimeException('Treasury correction must use the shared accounting transaction.');
    }

    $table = $type === 'deposit' ? 'accounting_bank_statement_lines' : 'treasury_liability_statement_lines';
    $owns = !$pdo->inTransaction();
    if ($owns) $pdo->beginTransaction();
    else $pdo->exec('SAVEPOINT treasury_statement_correction');
    try {
        $lineStmt = $pdo->prepare("SELECT * FROM {$table} WHERE tenant_id = :t AND id = :id FOR UPDATE");
        $lineStmt->execute(['t' => $tenantId, 'id' => $lineId]);
        $line = $lineStmt->fetch(PDO::FETCH_ASSOC);
        if (!$line || $line['match_status'] !== 'matched' || (int) ($line['matched_je_id'] ?? 0) <= 0) {
            throw new RuntimeException('This statement line has no active Treasury posting to correct.');
        }
        $jeId = (int) $line['matched_je_id'];
        $jeStmt = $pdo->prepare(
            'SELECT * FROM accounting_journal_entries WHERE tenant_id = :t AND id = :id FOR UPDATE'
        );
        $jeStmt->execute(['t' => $tenantId, 'id' => $jeId]);
        $journal = $jeStmt->fetch(PDO::FETCH_ASSOC);
        if (!$journal || $journal['status'] !== 'posted' || $journal['source_module'] !== 'treasury_feed'
            || (string) $journal['posting_date'] !== (string) $line['posted_date']
            || strtoupper((string) $journal['currency']) !== 'USD') {
            throw new RuntimeException('The matched journal is not an active Treasury posting for this date.');
        }

        if ($type === 'deposit') {
            $accountStmt = $pdo->prepare(
                'SELECT ba.entity_id, ba.currency, aa.id AS side_account_id
                   FROM accounting_bank_accounts ba
                   JOIN accounting_accounts aa ON aa.tenant_id = ba.tenant_id AND aa.code = ba.gl_account_code
                  WHERE ba.tenant_id = :t AND ba.id = :id LIMIT 1'
            );
            $accountStmt->execute(['t' => $tenantId, 'id' => (int) $line['bank_account_id']]);
            $account = $accountStmt->fetch(PDO::FETCH_ASSOC);
            $closed = $pdo->prepare(
                'SELECT id FROM accounting_reconciliations
                  WHERE tenant_id = :t AND bank_account_id = :account_id
                    AND status = "closed" AND period_end >= :posted_date LIMIT 1'
            );
            $closed->execute(['t' => $tenantId, 'account_id' => (int) $line['bank_account_id'],
                'posted_date' => (string) $line['posted_date']]);
            if ($closed->fetchColumn()) {
                throw new RuntimeException('Reopen the closed bank reconciliation before correcting this posting.');
            }
        } else {
            $accountStmt = $pdo->prepare(
                'SELECT tla.entity_id, aa.currency, aa.id AS side_account_id
                   FROM treasury_liability_accounts tla
                   JOIN accounting_accounts aa ON aa.tenant_id = tla.tenant_id AND aa.id = tla.account_id
                  WHERE tla.tenant_id = :t AND tla.account_id = :id LIMIT 1'
            );
            $accountStmt->execute(['t' => $tenantId, 'id' => (int) $line['liability_account_id']]);
            $account = $accountStmt->fetch(PDO::FETCH_ASSOC);
        }
        if (!$account || strtoupper((string) ($account['currency'] ?: 'USD')) !== 'USD'
            || (!empty($account['entity_id']) && (int) $account['entity_id'] !== (int) $journal['entity_id'])) {
            throw new RuntimeException('The statement account does not match the journal currency or legal entity.');
        }
        $expectedCents = (int) round((float) $line['amount'] * 100);
        $totalCents = abs($expectedCents);
        $movementStmt = $pdo->prepare(
            'SELECT COALESCE(SUM(debit - credit), 0)
               FROM accounting_journal_entry_lines
              WHERE tenant_id = :t AND je_id = :je AND account_id = :account_id'
        );
        $movementStmt->execute(['t' => $tenantId, 'je' => $jeId,
            'account_id' => (int) $account['side_account_id']]);
        if ($totalCents <= 0
            || (int) round((float) $movementStmt->fetchColumn() * 100) !== $expectedCents
            || (int) round((float) $journal['total_debit'] * 100) !== $totalCents
            || (int) round((float) $journal['total_credit'] * 100) !== $totalCents) {
            throw new RuntimeException('The journal movement does not match this statement line.');
        }

        $linkStmt = $pdo->prepare(
            'SELECT source_record_id FROM accounting_subledger_links
              WHERE tenant_id = :t AND journal_entry_id = :je
                AND source_module = "treasury_feed" AND link_kind = "primary" FOR UPDATE'
        );
        $linkStmt->execute(['t' => $tenantId, 'je' => $jeId]);
        $sourceIds = array_unique(array_map('strval', $linkStmt->fetchAll(PDO::FETCH_COLUMN)));
        foreach ($sourceIds as $sourceId) {
            if (!treasuryStatementSourceBelongsToLine($sourceId, $type, $lineId)) {
                throw new RuntimeException('The Treasury source link belongs to another statement line.');
            }
        }
        if (count($sourceIds) > 1) throw new RuntimeException('The Treasury posting has ambiguous source links.');
        $directRef = (string) ($journal['source_ref_type'] ?? '')
            === ($type === 'deposit' ? 'bank_statement_line' : 'liability_statement_line')
            && (int) ($journal['source_ref_id'] ?? 0) === $lineId;
        if (!$sourceIds && !$directRef) {
            throw new RuntimeException('This journal has no verified Treasury statement source link.');
        }

        $eventStmt = $pdo->prepare(
            'SELECT id, status, source_module, source_record_id, event_type
               FROM accounting_events WHERE tenant_id = :t AND journal_entry_id = :je FOR UPDATE'
        );
        $eventStmt->execute(['t' => $tenantId, 'je' => $jeId]);
        $events = $eventStmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($events) > 1) throw new RuntimeException('The Treasury journal has more than one source event.');
        $event = $events[0] ?? null;
        $sourceId = $sourceIds[0] ?? ($event['source_record_id'] ?? treasuryStatementSourceId($type, $lineId, false, 1));
        if ($event && ($event['status'] !== 'posted' || $event['source_module'] !== 'treasury_feed'
            || $event['event_type'] !== 'treasury.bank_transaction.categorized'
            || $event['source_record_id'] !== $sourceId
            || !treasuryStatementSourceBelongsToLine($sourceId, $type, $lineId))) {
            throw new RuntimeException('The Treasury event does not match this posted journal.');
        }

        $attempt = treasuryStatementNextAttempt($pdo, $tenantId, $type, $lineId);
        $reversal = accountingReverseJe($tenantId, $jeId, $reason, $actorUserId);
        if (!empty($reversal['idempotent_replay'])) {
            throw new RuntimeException('This Treasury posting was already reversed.');
        }
        if ($event) {
            $eventUpdate = $pdo->prepare(
                'UPDATE accounting_events SET status = "reversed"
                  WHERE tenant_id = :t AND id = :id AND status = "posted"'
            );
            $eventUpdate->execute(['t' => $tenantId, 'id' => (int) $event['id']]);
            if ($eventUpdate->rowCount() !== 1) throw new RuntimeException('The Treasury event changed during correction.');
        }
        $lineSet = $type === 'deposit'
            ? 'match_status = "unmatched", matched_je_id = NULL, matched_at = NULL, matched_by_user_id = NULL'
            : 'match_status = "unmatched", matched_je_id = NULL';
        $reopen = $pdo->prepare(
            "UPDATE {$table} SET {$lineSet}
              WHERE tenant_id = :t AND id = :id AND match_status = 'matched' AND matched_je_id = :je"
        );
        $reopen->execute(['t' => $tenantId, 'id' => $lineId, 'je' => $jeId]);
        if ($reopen->rowCount() !== 1) throw new RuntimeException('The statement line changed during correction.');
        $pdo->prepare(
            'INSERT INTO accounting_subledger_links
                (tenant_id, source_module, source_record_id, journal_entry_id, accounting_event_id, link_kind)
             VALUES (:t, "treasury_feed", :source_id, :je, :event_id, "reversal")'
        )->execute(['t' => $tenantId, 'source_id' => $sourceId,
            'je' => (int) $reversal['je_id'], 'event_id' => $event['id'] ?? null]);
        $pdo->prepare(
            'INSERT INTO treasury_statement_corrections
                (tenant_id, line_type, line_id, attempt_no, original_je_id,
                 reversal_je_id, accounting_event_id, source_record_id, reason, corrected_by_user_id)
             VALUES (:t, :line_type, :line_id, :attempt, :original_je, :reversal_je,
                     :event_id, :source_id, :reason, :actor)'
        )->execute(['t' => $tenantId, 'line_type' => $type, 'line_id' => $lineId,
            'attempt' => $attempt, 'original_je' => $jeId,
            'reversal_je' => (int) $reversal['je_id'], 'event_id' => $event['id'] ?? null,
            'source_id' => $sourceId, 'reason' => $reason, 'actor' => $actorUserId]);
        if ($owns) $pdo->commit();
        else $pdo->exec('RELEASE SAVEPOINT treasury_statement_correction');
        return ['line_id' => $lineId, 'line_type' => $type, 'attempt_no' => $attempt,
            'original_je_id' => $jeId, 'reversal_je_id' => (int) $reversal['je_id']];
    } catch (Throwable $e) {
        if ($owns && $pdo->inTransaction()) $pdo->rollBack();
        elseif (!$owns && $pdo->inTransaction()) {
            $pdo->exec('ROLLBACK TO SAVEPOINT treasury_statement_correction');
            $pdo->exec('RELEASE SAVEPOINT treasury_statement_correction');
        }
        throw $e;
    }
}

function treasuryUnmatchLiabilityLine(PDO $pdo, int $tenantId, int $lineId): bool
{
    $stmt = $pdo->prepare(
        'SELECT id, match_status, matched_je_id FROM treasury_liability_statement_lines
          WHERE tenant_id = :t AND id = :id FOR UPDATE'
    );
    $stmt->execute(['t' => $tenantId, 'id' => $lineId]);
    $line = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$line) throw new RuntimeException('Statement line not found.');
    if ($line['match_status'] === 'unmatched') return false;

    if ($line['match_status'] === 'matched' && !empty($line['matched_je_id'])) {
        $journalStmt = $pdo->prepare(
            'SELECT source_module, source_ref_type, source_ref_id FROM accounting_journal_entries
              WHERE tenant_id = :t AND id = :id'
        );
        $journalStmt->execute(['t' => $tenantId, 'id' => (int) $line['matched_je_id']]);
        $journal = $journalStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $hasTreasuryLineage = false;
        try {
            $linkStmt = $pdo->prepare(
                'SELECT id FROM accounting_subledger_links
                  WHERE tenant_id = :t AND journal_entry_id = :je
                    AND source_module = "treasury_feed"
                    AND source_record_id IN (:single_ref, :split_ref) LIMIT 1'
            );
            $linkStmt->execute([
                't' => $tenantId, 'je' => (int) $line['matched_je_id'],
                'single_ref' => 'liab_line:' . $lineId,
                'split_ref' => 'liab_line:split:' . $lineId,
            ]);
            $hasTreasuryLineage = (bool) $linkStmt->fetchColumn();
        } catch (Throwable $_) {
            // Pre-lineage tenants still have the journal source reference.
        }
        $blocker = treasuryLiabilityUnmatchBlocker($lineId, $journal, $hasTreasuryLineage);
        if ($blocker !== null) throw new RuntimeException($blocker);
    }

    $update = $pdo->prepare(
        'UPDATE treasury_liability_statement_lines
            SET match_status = "unmatched", matched_je_id = NULL
          WHERE tenant_id = :t AND id = :id AND match_status = :status'
    );
    $update->execute(['t' => $tenantId, 'id' => $lineId, 'status' => $line['match_status']]);
    if ($update->rowCount() !== 1) throw new RuntimeException('Statement line changed. Refresh and try again.');
    return true;
}

function treasuryMatchLiabilityLine(PDO $pdo, int $tenantId, int $lineId, int $journalId, int $entityId): bool
{
    $lineStmt = $pdo->prepare(
        'SELECT id, liability_account_id, amount, match_status, matched_je_id
           FROM treasury_liability_statement_lines
          WHERE tenant_id = :t AND id = :id FOR UPDATE'
    );
    $lineStmt->execute(['t' => $tenantId, 'id' => $lineId]);
    $line = $lineStmt->fetch(PDO::FETCH_ASSOC);
    if (!$line) throw new RuntimeException('Statement line not found.');
    if ($line['match_status'] === 'matched' && (int) $line['matched_je_id'] === $journalId) return false;
    if ($line['match_status'] !== 'unmatched') {
        throw new RuntimeException('Restore or unmatch this statement line before matching a journal.');
    }

    $journalStmt = $pdo->prepare(
        'SELECT id, status, entity_id, currency FROM accounting_journal_entries
          WHERE tenant_id = :t AND id = :id FOR UPDATE'
    );
    $journalStmt->execute(['t' => $tenantId, 'id' => $journalId]);
    $journal = $journalStmt->fetch(PDO::FETCH_ASSOC);
    if (!$journal || $journal['status'] !== 'posted') {
        throw new RuntimeException('Choose a posted journal entry in this organization.');
    }
    if ((int) $journal['entity_id'] !== $entityId) {
        throw new RuntimeException('The journal belongs to a different legal entity.');
    }
    $accountStmt = $pdo->prepare(
        'SELECT currency FROM accounting_accounts WHERE tenant_id = :t AND id = :id'
    );
    $accountStmt->execute(['t' => $tenantId, 'id' => (int) $line['liability_account_id']]);
    $accountCurrency = $accountStmt->fetchColumn();
    if ($accountCurrency === false
        || strcasecmp((string) ($accountCurrency ?: 'USD'), (string) $journal['currency']) !== 0) {
        throw new RuntimeException('The journal uses a different currency from this liability account.');
    }
    $movementStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(debit - credit), 0)
           FROM accounting_journal_entry_lines
          WHERE tenant_id = :t AND je_id = :je AND account_id = :account_id'
    );
    $movementStmt->execute([
        't' => $tenantId, 'je' => $journalId,
        'account_id' => (int) $line['liability_account_id'],
    ]);
    if (abs((float) $movementStmt->fetchColumn() - (float) $line['amount']) > 0.005) {
        throw new RuntimeException('The journal does not contain this liability account movement and amount.');
    }
    $usedStmt = $pdo->prepare(
        'SELECT id FROM treasury_liability_statement_lines
          WHERE tenant_id = :t AND matched_je_id = :je AND match_status = "matched" AND id <> :line_id
          LIMIT 1'
    );
    $usedStmt->execute(['t' => $tenantId, 'je' => $journalId, 'line_id' => $lineId]);
    if ($usedStmt->fetchColumn()) {
        throw new RuntimeException('That journal is already matched to another liability statement line.');
    }

    $update = $pdo->prepare(
        'UPDATE treasury_liability_statement_lines
            SET match_status = "matched", matched_je_id = :je
          WHERE tenant_id = :t AND id = :id AND match_status = "unmatched"'
    );
    $update->execute(['t' => $tenantId, 'id' => $lineId, 'je' => $journalId]);
    if ($update->rowCount() !== 1) throw new RuntimeException('Statement line changed. Refresh and try again.');
    return true;
}
