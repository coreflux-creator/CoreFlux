<?php
/** Source-owned correction for a manually cleared AP payment. */
declare(strict_types=1);

require_once __DIR__ . '/ap.php';
require_once __DIR__ . '/../../accounting/lib/accounting.php';

function apInspectClearedManualPayment(\PDO $pdo, int $tenantId, array $payment, bool $lock = false): array
{
    $paymentId = (int) ($payment['id'] ?? 0);
    $journalId = (int) ($payment['journal_entry_id'] ?? 0);
    $bankAccountId = (int) ($payment['bank_account_id'] ?? 0);
    $entityId = (int) ($payment['entity_id'] ?? 0);
    $amount = (float) ($payment['amount'] ?? 0);
    if ($paymentId <= 0 || ($payment['status'] ?? '') !== 'cleared' || $journalId <= 0
        || $bankAccountId <= 0 || $entityId <= 0 || $amount <= 0
        || empty($payment['cleared_at']) || abs((float) ($payment['unallocated_amount'] ?? 0)) > 0.005) {
        throw new \RuntimeException('Only a fully allocated, posted manual payment can be corrected here');
    }
    if (!empty($payment['disbursement_rail']) || !empty($payment['rail_external_ref'])
        || !empty($payment['plaid_transfer_id'])) {
        throw new \RuntimeException('Provider or bank-file payouts need a confirmed return or cancellation before correction');
    }

    $journalStmt = $pdo->prepare(
        'SELECT * FROM accounting_journal_entries WHERE tenant_id = :tenant_id AND id = :id'
        . ($lock ? ' FOR UPDATE' : '')
    );
    $journalStmt->execute(['tenant_id' => $tenantId, 'id' => $journalId]);
    $journal = $journalStmt->fetch(\PDO::FETCH_ASSOC);
    if (!$journal || $journal['status'] !== 'posted' || $journal['source_module'] !== 'ap'
        || (int) $journal['entity_id'] !== $entityId
        || strcasecmp((string) $journal['currency'], (string) $payment['currency']) !== 0
        || abs((float) $journal['total_debit'] - $amount) > 0.005
        || abs((float) $journal['total_credit'] - $amount) > 0.005) {
        throw new \RuntimeException('The linked payment journal is missing, changed, or does not match the payment');
    }
    if ($journal['source_ref_type'] !== null
        && !($journal['source_ref_type'] === 'ap_payment'
            && (int) $journal['source_ref_id'] === $paymentId)) {
        throw new \RuntimeException('The linked journal belongs to a different AP source');
    }

    $sourceRecordId = 'ap_payment:' . $paymentId;
    $links = $pdo->prepare(
        'SELECT journal_entry_id FROM accounting_subledger_links
          WHERE tenant_id = :tenant_id AND source_module = "ap"
            AND source_record_id = :source_record_id AND link_kind = "primary"'
    );
    $links->execute(['tenant_id' => $tenantId, 'source_record_id' => $sourceRecordId]);
    $linkedIds = array_map('intval', $links->fetchAll(\PDO::FETCH_COLUMN));
    if (!$linkedIds || array_diff($linkedIds, [$journalId])) {
        throw new \RuntimeException('The original AP payment journal linkage needs review');
    }
    $otherLink = $pdo->prepare(
        'SELECT 1 FROM accounting_subledger_links
          WHERE tenant_id = :tenant_id AND journal_entry_id = :journal_id AND link_kind = "primary"
            AND (source_module <> "ap" OR source_record_id <> :source_record_id) LIMIT 1'
    );
    $otherLink->execute([
        'tenant_id' => $tenantId, 'journal_id' => $journalId, 'source_record_id' => $sourceRecordId,
    ]);
    if ($otherLink->fetchColumn()) throw new \RuntimeException('The journal is linked to another source');
    $otherPayment = $pdo->prepare(
        'SELECT 1 FROM ap_payments
          WHERE tenant_id = :tenant_id AND journal_entry_id = :journal_id AND id <> :payment_id LIMIT 1'
    );
    $otherPayment->execute([
        'tenant_id' => $tenantId, 'journal_id' => $journalId, 'payment_id' => $paymentId,
    ]);
    if ($otherPayment->fetchColumn()) throw new \RuntimeException('Another payment shares this journal');

    $events = $pdo->prepare(
        'SELECT journal_entry_id FROM accounting_events
          WHERE tenant_id = :tenant_id AND source_module = "ap"
            AND source_record_id = :source_record_id AND event_type = "ap.payment.cleared"
            AND status = "posted"'
    );
    $events->execute(['tenant_id' => $tenantId, 'source_record_id' => $sourceRecordId]);
    $eventJournalIds = array_map('intval', $events->fetchAll(\PDO::FETCH_COLUMN));
    if (!$eventJournalIds || array_diff($eventJournalIds, [$journalId])) {
        throw new \RuntimeException('The payment accounting event does not match its journal');
    }

    $bankStmt = $pdo->prepare(
        'SELECT id, entity_id, gl_account_code, currency FROM accounting_bank_accounts
          WHERE tenant_id = :tenant_id AND id = :id'
    );
    $bankStmt->execute(['tenant_id' => $tenantId, 'id' => $bankAccountId]);
    $bank = $bankStmt->fetch(\PDO::FETCH_ASSOC);
    if (!$bank || (!empty($bank['entity_id']) && (int) $bank['entity_id'] !== $entityId)
        || strcasecmp((string) ($bank['currency'] ?: 'USD'), (string) $payment['currency']) !== 0) {
        throw new \RuntimeException('The funding bank account no longer agrees with this payment');
    }
    $lines = $pdo->prepare(
        'SELECT a.code, l.debit, l.credit
           FROM accounting_journal_entry_lines l
           JOIN accounting_accounts a ON a.id = l.account_id AND a.tenant_id = l.tenant_id
          WHERE l.tenant_id = :tenant_id AND l.je_id = :journal_id'
    );
    $lines->execute(['tenant_id' => $tenantId, 'journal_id' => $journalId]);
    $journalLines = $lines->fetchAll(\PDO::FETCH_ASSOC);
    $byCode = [];
    foreach ($journalLines as $line) $byCode[(string) $line['code']] = $line;
    if (count($journalLines) !== 2 || count($byCode) !== 2
        || !isset($byCode['2000'], $byCode[(string) $bank['gl_account_code']])
        || abs((float) $byCode['2000']['debit'] - $amount) > 0.005
        || abs((float) $byCode['2000']['credit']) > 0.005
        || abs((float) $byCode[(string) $bank['gl_account_code']]['credit'] - $amount) > 0.005
        || abs((float) $byCode[(string) $bank['gl_account_code']]['debit']) > 0.005) {
        throw new \RuntimeException('The payment journal is not the expected AP-to-cash entry');
    }

    $allocStmt = $pdo->prepare(
        'SELECT a.bill_id, a.amount_applied, b.entity_id, b.status, b.amount_paid
           FROM ap_payment_allocations a
           JOIN ap_bills b ON b.id = a.bill_id AND b.tenant_id = :tenant_id
          WHERE a.payment_id = :payment_id ORDER BY a.bill_id'
        . ($lock ? ' FOR UPDATE' : '')
    );
    $allocStmt->execute(['tenant_id' => $tenantId, 'payment_id' => $paymentId]);
    $allocations = $allocStmt->fetchAll(\PDO::FETCH_ASSOC);
    if (!$allocations || abs(array_sum(array_map(
        static fn(array $allocation): float => (float) $allocation['amount_applied'], $allocations
    )) - $amount) > 0.005) {
        throw new \RuntimeException('The bill allocations do not add up to this payment');
    }
    $byBill = [];
    foreach ($allocations as $allocation) {
        $billId = (int) $allocation['bill_id'];
        if ((int) ($allocation['entity_id'] ?? 0) !== $entityId
            || in_array($allocation['status'], ['void', 'disputed'], true)) {
            throw new \RuntimeException('An allocated bill changed and needs review');
        }
        $byBill[$billId] = ($byBill[$billId] ?? 0) + (float) $allocation['amount_applied'];
        if ($byBill[$billId] - (float) $allocation['amount_paid'] > 0.005) {
            throw new \RuntimeException('A bill payment balance no longer agrees with its allocation');
        }
    }

    $bankLines = $pdo->prepare(
        'SELECT id, bank_account_id, posted_date, amount, match_status
           FROM accounting_bank_statement_lines
          WHERE tenant_id = :tenant_id AND matched_je_id = :journal_id'
        . ($lock ? ' FOR UPDATE' : '')
    );
    $bankLines->execute(['tenant_id' => $tenantId, 'journal_id' => $journalId]);
    $matches = $bankLines->fetchAll(\PDO::FETCH_ASSOC);
    if (count($matches) > 1) throw new \RuntimeException('Multiple bank lines reference this payment journal');
    $match = $matches[0] ?? null;
    if ($match && ($match['match_status'] !== 'matched'
        || (int) $match['bank_account_id'] !== $bankAccountId
        || abs((float) $match['amount'] + $amount) > 0.005)) {
        throw new \RuntimeException('The matched bank line no longer agrees with this payment');
    }
    $closed = $pdo->prepare(
        'SELECT id FROM accounting_reconciliations
          WHERE tenant_id = :tenant_id AND bank_account_id = :bank_account_id
            AND status = "closed" AND period_end >= :posting_date LIMIT 1'
        . ($lock ? ' FOR UPDATE' : '')
    );
    $closed->execute([
        'tenant_id' => $tenantId, 'bank_account_id' => $bankAccountId,
        'posting_date' => (string) $journal['posting_date'],
    ]);
    if ($closed->fetchColumn()) {
        throw new \RuntimeException('This payment is in a closed bank reconciliation. Reopen it before correcting the payment.');
    }

    return [
        'payment_id' => $paymentId,
        'original_je_id' => $journalId,
        'source_record_id' => $sourceRecordId,
        'bank_line_id' => $match ? (int) $match['id'] : null,
        'bill_ids' => array_keys($byBill),
    ];
}

function apCorrectClearedManualPayment(int $tenantId, int $paymentId, string $reason, ?int $actorUserId): array
{
    $reason = trim($reason);
    if ($tenantId <= 0 || $paymentId <= 0 || $reason === '' || strlen($reason) > 500) {
        throw new \InvalidArgumentException('Payment and correction reason (up to 500 characters) are required');
    }

    $pdo = getDB();
    $ownsTransaction = cf_tx_begin($pdo);
    try {
        $stmt = $pdo->prepare('SELECT * FROM ap_payments WHERE tenant_id = :tenant_id AND id = :id FOR UPDATE');
        $stmt->execute(['tenant_id' => $tenantId, 'id' => $paymentId]);
        $payment = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$payment) throw new \RuntimeException('Payment not found');
        $review = apInspectClearedManualPayment($pdo, $tenantId, $payment, true);

        $reversal = accountingReverseJe($tenantId, $review['original_je_id'], $reason, $actorUserId);
        if (!empty($reversal['idempotent_replay'])) throw new \RuntimeException('This payment journal was already reversed');
        $reversalId = (int) $reversal['je_id'];
        $void = $pdo->prepare(
            'UPDATE ap_payments SET status = "void", voided_at = NOW(), void_reason = :reason
              WHERE tenant_id = :tenant_id AND id = :id AND status = "cleared" AND journal_entry_id = :journal_id'
        );
        $void->execute([
            'reason' => $reason, 'tenant_id' => $tenantId, 'id' => $paymentId,
            'journal_id' => $review['original_je_id'],
        ]);
        if ($void->rowCount() !== 1) throw new \RuntimeException('Payment changed while correcting it');

        apRefreshReleasedPaymentBillsForPayment($pdo, $tenantId, $paymentId);
        if ($review['bank_line_id']) {
            $reopen = $pdo->prepare(
                'UPDATE accounting_bank_statement_lines
                    SET match_status = "unmatched", matched_je_id = NULL, matched_at = NULL,
                        matched_by_user_id = NULL, updated_at = NOW()
                  WHERE tenant_id = :tenant_id AND id = :id AND match_status = "matched"
                    AND matched_je_id = :journal_id'
            );
            $reopen->execute([
                'tenant_id' => $tenantId, 'id' => $review['bank_line_id'],
                'journal_id' => $review['original_je_id'],
            ]);
            if ($reopen->rowCount() !== 1) throw new \RuntimeException('The bank line changed while correcting the payment');
        }
        $pdo->prepare(
            'INSERT INTO accounting_subledger_links
                (tenant_id, source_module, source_record_id, journal_entry_id, link_kind)
             VALUES (:tenant_id, "ap", :source_record_id, :journal_entry_id, "reversal")'
        )->execute([
            'tenant_id' => $tenantId, 'source_record_id' => $review['source_record_id'],
            'journal_entry_id' => $reversalId,
        ]);
        cf_tx_commit($pdo, $ownsTransaction);
        return [
            'payment_id' => $paymentId,
            'original_je_id' => $review['original_je_id'],
            'reversal_je_id' => $reversalId,
            'bank_line_id' => $review['bank_line_id'],
            'reopened_bill_ids' => $review['bill_ids'],
        ];
    } catch (\Throwable $e) {
        cf_tx_rollback($pdo, $ownsTransaction);
        throw $e;
    }
}
