<?php
/** Match and unmatch liability statement lines without changing their journals. */
declare(strict_types=1);

require_once __DIR__ . '/bank_posting.php';

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
            'SELECT source_ref_type, source_ref_id FROM accounting_journal_entries
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
