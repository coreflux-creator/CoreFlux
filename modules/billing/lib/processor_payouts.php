<?php
/** Reviewed settlement of captured processor receipts into a bank statement line. */
declare(strict_types=1);

require_once __DIR__ . '/../../accounting/lib/accounting.php';
require_once __DIR__ . '/../../accounting/lib/bank_rec.php';

function billingProcessorPayoutBankLine(int $tenantId, int $lineId, bool $lock = false): array
{
    $stmt = getDB()->prepare(
        'SELECT bl.id, bl.bank_account_id, bl.posted_date, bl.amount, bl.match_status, bl.matched_je_id,
                ba.gl_account_code, ba.entity_id, COALESCE(NULLIF(ba.currency, ""), "USD") AS currency
           FROM accounting_bank_statement_lines bl
           JOIN accounting_bank_accounts ba ON ba.tenant_id = bl.tenant_id AND ba.id = bl.bank_account_id
          WHERE bl.tenant_id = :tenant_id AND bl.id = :id LIMIT 1' . ($lock ? ' FOR UPDATE' : '')
    );
    $stmt->execute(['tenant_id' => $tenantId, 'id' => $lineId]);
    $line = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$line) throw new RuntimeException('Bank line not found.');
    if ((float) $line['amount'] <= 0) throw new RuntimeException('A processor payout needs an incoming bank line.');
    if ((int) $line['entity_id'] <= 0 || trim((string) $line['gl_account_code']) === '') {
        throw new RuntimeException('Assign this bank account to a legal entity and cash GL account first.');
    }
    return $line;
}

function billingProcessorPayoutAssertLineUnbooked(int $tenantId, int $lineId): void
{
    $pdo = getDB();
    $source = $pdo->prepare(
        'SELECT id FROM accounting_journal_entries
          WHERE tenant_id = :tenant_id AND source_ref_type = "bank_statement_line"
            AND source_ref_id = :line_id AND status = "posted" LIMIT 1'
    );
    $source->execute(['tenant_id' => $tenantId, 'line_id' => $lineId]);
    if ($source->fetchColumn()) {
        throw new RuntimeException('This bank line already has a posted source journal. Repair its match before settling a payout.');
    }
    $linked = $pdo->prepare(
        'SELECT je.id FROM accounting_subledger_links link
           JOIN accounting_journal_entries je
             ON je.tenant_id = link.tenant_id AND je.id = link.journal_entry_id
          WHERE link.tenant_id = :tenant_id AND link.source_record_id IN
                (:bank_line, :bank_split, :invoice, :invoice_split)
            AND je.status = "posted" LIMIT 1'
    );
    $linked->execute([
        'tenant_id' => $tenantId,
        'bank_line' => 'bank_line:' . $lineId,
        'bank_split' => 'bank_line:split:' . $lineId,
        'invoice' => 'bank_line:invoice:' . $lineId,
        'invoice_split' => 'bank_line:invoice_split:' . $lineId,
    ]);
    if ($linked->fetchColumn()) {
        throw new RuntimeException('This bank line is already booked by another workflow. Repair its match before settling a payout.');
    }
}

function billingProcessorPayoutCandidates(int $tenantId, int $lineId): array
{
    $line = billingProcessorPayoutBankLine($tenantId, $lineId);
    if ($line['match_status'] !== 'unmatched' || $line['matched_je_id'] !== null) {
        throw new RuntimeException('Review the existing bank match before settling a processor payout.');
    }
    billingProcessorPayoutAssertLineUnbooked($tenantId, $lineId);
    $stmt = getDB()->prepare(
        'SELECT p.id AS payment_id, p.client_name, p.reference, p.received_at, p.amount,
                p.journal_entry_id, q.qbo_charge_id, q.status AS charge_status
           FROM billing_payments p
           JOIN qbo_payment_charges q ON q.tenant_id = p.tenant_id
                AND q.coreflux_payment_id = p.id AND q.qbo_charge_id = p.external_id
           JOIN accounting_journal_entries je ON je.tenant_id = p.tenant_id AND je.id = p.journal_entry_id
          WHERE p.tenant_id = :tenant_id AND p.source_system = "qbo"
            AND p.voided_at IS NULL AND p.posted_at IS NOT NULL
            AND ABS(p.unallocated_amount) < 0.005
            AND p.received_at <= :posted_date AND p.currency = :currency
            AND je.status = "posted" AND je.entity_id = :entity_id
            AND je.source_module = "billing" AND je.source_ref_type = "billing_payment"
            AND je.source_ref_id = p.id AND je.currency = :je_currency
            AND q.status IN ("CAPTURED", "SETTLED")
            AND NOT EXISTS (
                SELECT 1 FROM billing_processor_payout_payments link
                JOIN billing_processor_payouts payout
                  ON payout.tenant_id = link.tenant_id AND payout.id = link.payout_id
                 AND payout.status = "posted"
                WHERE link.tenant_id = p.tenant_id AND link.payment_id = p.id
            )
          ORDER BY p.received_at, p.id LIMIT 200'
    );
    $stmt->execute([
        'tenant_id' => $tenantId, 'posted_date' => $line['posted_date'],
        'currency' => $line['currency'], 'je_currency' => $line['currency'],
        'entity_id' => (int) $line['entity_id'],
    ]);
    $feeStmt = getDB()->prepare(
        'SELECT id, code, name FROM accounting_accounts
          WHERE tenant_id = :tenant_id AND account_type = "expense" AND active = 1 AND is_postable = 1
          ORDER BY code'
    );
    $feeStmt->execute(['tenant_id' => $tenantId]);
    return [
        'bank_line_id' => $lineId,
        'net_amount' => round((float) $line['amount'], 2),
        'currency' => (string) $line['currency'],
        'payments' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'fee_accounts' => $feeStmt->fetchAll(PDO::FETCH_ASSOC),
    ];
}

function billingProcessorPayoutCapturedPayment(int $tenantId, int $paymentId, array $line): array
{
    $pdo = getDB();
    $stmt = $pdo->prepare(
        'SELECT p.* FROM billing_payments p WHERE p.tenant_id = :tenant_id AND p.id = :id LIMIT 1 FOR UPDATE'
    );
    $stmt->execute(['tenant_id' => $tenantId, 'id' => $paymentId]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$payment || $payment['source_system'] !== 'qbo' || $payment['voided_at'] !== null
        || $payment['posted_at'] === null || (int) $payment['journal_entry_id'] <= 0
        || abs((float) $payment['unallocated_amount']) > 0.005) {
        throw new RuntimeException("Payment {$paymentId} is not an active, fully posted processor capture.");
    }
    if (strcasecmp((string) $payment['currency'], (string) $line['currency']) !== 0
        || (string) $payment['received_at'] > (string) $line['posted_date']) {
        throw new RuntimeException("Payment {$paymentId} has a different currency or a later capture date.");
    }

    $shadow = $pdo->prepare(
        'SELECT amount_cents, currency, status, coreflux_payment_id FROM qbo_payment_charges
          WHERE tenant_id = :tenant_id AND qbo_charge_id = :charge_id LIMIT 1'
    );
    $shadow->execute(['tenant_id' => $tenantId, 'charge_id' => (string) $payment['external_id']]);
    $charge = $shadow->fetch(PDO::FETCH_ASSOC);
    $cents = (int) round((float) $payment['amount'] * 100);
    if (!$charge || (int) $charge['coreflux_payment_id'] !== $paymentId
        || !in_array(strtoupper((string) $charge['status']), ['CAPTURED', 'SETTLED'], true)
        || (int) $charge['amount_cents'] !== $cents
        || strcasecmp((string) $charge['currency'], (string) $line['currency']) !== 0) {
        throw new RuntimeException("Payment {$paymentId} does not agree with its captured processor charge.");
    }

    $posted = $pdo->prepare(
        'SELECT je.status, je.entity_id, je.currency, je.source_module, je.source_ref_type, je.source_ref_id,
                ROUND(COALESCE(SUM(CASE WHEN a.code = "1010" THEN l.debit - l.credit ELSE 0 END), 0), 2)
                    AS clearing_debit
           FROM accounting_journal_entries je
           JOIN accounting_journal_entry_lines l ON l.tenant_id = je.tenant_id AND l.je_id = je.id
           JOIN accounting_accounts a ON a.tenant_id = l.tenant_id AND a.id = l.account_id
          WHERE je.tenant_id = :tenant_id AND je.id = :id
          GROUP BY je.id, je.status, je.entity_id, je.currency, je.source_module,
                   je.source_ref_type, je.source_ref_id'
    );
    $posted->execute(['tenant_id' => $tenantId, 'id' => (int) $payment['journal_entry_id']]);
    $journal = $posted->fetch(PDO::FETCH_ASSOC);
    if (!$journal || $journal['status'] !== 'posted' || $journal['source_module'] !== 'billing'
        || $journal['source_ref_type'] !== 'billing_payment'
        || (int) $journal['source_ref_id'] !== $paymentId
        || (int) $journal['entity_id'] !== (int) $line['entity_id']
        || strcasecmp((string) $journal['currency'], (string) $line['currency']) !== 0
        || abs((float) $journal['clearing_debit'] - (float) $payment['amount']) > 0.005) {
        throw new RuntimeException("Payment {$paymentId} has no matching posted clearing debit.");
    }
    $active = $pdo->prepare(
        'SELECT payout.id FROM billing_processor_payout_payments link
           JOIN billing_processor_payouts payout ON payout.tenant_id = link.tenant_id
                AND payout.id = link.payout_id AND payout.status = "posted"
          WHERE link.tenant_id = :tenant_id AND link.payment_id = :payment_id LIMIT 1'
    );
    $active->execute(['tenant_id' => $tenantId, 'payment_id' => $paymentId]);
    if ($active->fetchColumn()) throw new RuntimeException("Payment {$paymentId} is already in a posted payout.");
    return $payment;
}

function billingSettleProcessorPayout(
    int $tenantId, int $lineId, array $paymentIds, float $expectedFee,
    ?int $feeAccountId, ?int $actorUserId
): array {
    $ids = array_map('intval', $paymentIds);
    if (!$ids || count($ids) > 200 || count(array_unique($ids)) !== count($ids)
        || min($ids) <= 0 || !is_finite($expectedFee)) {
        throw new InvalidArgumentException('Select one or more distinct captured payments.');
    }
    sort($ids, SORT_NUMERIC);
    $expectedFeeCents = (int) round($expectedFee * 100);
    if ($expectedFeeCents < 0 || abs($expectedFee * 100 - $expectedFeeCents) > 0.001) {
        throw new InvalidArgumentException('Fee amount must be a nonnegative amount in cents.');
    }

    $pdo = getDB();
    if ($pdo->inTransaction()) throw new RuntimeException('Processor payout requires its own transaction.');
    $pdo->beginTransaction();
    try {
        $line = billingProcessorPayoutBankLine($tenantId, $lineId, true);
        if ($line['match_status'] === 'matched') {
            $priorStmt = $pdo->prepare(
                'SELECT * FROM billing_processor_payouts
                  WHERE tenant_id = :tenant_id AND bank_line_id = :line_id AND status = "posted"
                  ORDER BY attempt_no DESC LIMIT 1'
            );
            $priorStmt->execute(['tenant_id' => $tenantId, 'line_id' => $lineId]);
            $prior = $priorStmt->fetch(PDO::FETCH_ASSOC);
            if (!$prior || (int) $prior['journal_entry_id'] !== (int) $line['matched_je_id']) {
                throw new RuntimeException('Bank line is already matched to another transaction.');
            }
            $linked = $pdo->prepare(
                'SELECT payment_id FROM billing_processor_payout_payments
                  WHERE tenant_id = :tenant_id AND payout_id = :payout_id ORDER BY payment_id'
            );
            $linked->execute(['tenant_id' => $tenantId, 'payout_id' => (int) $prior['id']]);
            $priorIds = array_map('intval', $linked->fetchAll(PDO::FETCH_COLUMN));
            if ($priorIds !== $ids || (int) round((float) $prior['fee_amount'] * 100) !== $expectedFeeCents
                || (int) round((float) $prior['net_amount'] * 100) !== (int) round((float) $line['amount'] * 100)
                || (int) $prior['bank_account_id'] !== (int) $line['bank_account_id']
                || (int) $prior['entity_id'] !== (int) $line['entity_id']
                || strcasecmp((string) $prior['currency'], (string) $line['currency']) !== 0
                || ($expectedFeeCents > 0 && (int) $prior['fee_account_id'] !== (int) $feeAccountId)) {
                throw new RuntimeException('This bank line has a different processor payout. Review it before retrying.');
            }
            $pdo->commit();
            return ['payout_id' => (int) $prior['id'], 'journal_entry_id' => (int) $prior['journal_entry_id'],
                'bank_line_id' => $lineId, 'idempotent_replay' => true];
        }
        if ($line['match_status'] !== 'unmatched' || $line['matched_je_id'] !== null) {
            throw new RuntimeException('Bank line has an existing or inconsistent match. Repair it before settling a payout.');
        }
        billingProcessorPayoutAssertLineUnbooked($tenantId, $lineId);
        $closed = $pdo->prepare(
            'SELECT id FROM accounting_reconciliations
              WHERE tenant_id = :tenant_id AND bank_account_id = :bank_account_id
                AND status = "closed" AND period_end >= :posted_date LIMIT 1'
        );
        $closed->execute(['tenant_id' => $tenantId, 'bank_account_id' => (int) $line['bank_account_id'],
            'posted_date' => (string) $line['posted_date']]);
        if ($closed->fetchColumn()) throw new RuntimeException('Reopen the closed bank reconciliation before posting this payout.');

        $grossCents = 0;
        $payments = [];
        foreach ($ids as $id) {
            $payments[] = billingProcessorPayoutCapturedPayment($tenantId, $id, $line);
            $grossCents += (int) round((float) end($payments)['amount'] * 100);
        }
        $netCents = (int) round((float) $line['amount'] * 100);
        $feeCents = $grossCents - $netCents;
        if ($netCents <= 0 || $feeCents < 0 || $feeCents !== $expectedFeeCents) {
            throw new RuntimeException('Selected gross captures minus fees must equal this net bank deposit.');
        }
        if ($feeCents > 0) {
            if (!$feeAccountId || $feeAccountId <= 0) throw new InvalidArgumentException('Choose an expense account for processor fees.');
            $feeStmt = $pdo->prepare(
                'SELECT id, code FROM accounting_accounts WHERE tenant_id = :tenant_id AND id = :id
                  AND account_type = "expense" AND active = 1 AND is_postable = 1 LIMIT 1'
            );
            $feeStmt->execute(['tenant_id' => $tenantId, 'id' => $feeAccountId]);
            $feeAccount = $feeStmt->fetch(PDO::FETCH_ASSOC);
            if (!$feeAccount) throw new RuntimeException('Processor fee account is not an active expense account.');
        } else {
            $feeAccount = null;
            $feeAccountId = null;
        }

        $attemptStmt = $pdo->prepare(
            'SELECT COALESCE(MAX(attempt_no), 0) FROM billing_processor_payouts
              WHERE tenant_id = :tenant_id AND bank_line_id = :bank_line_id'
        );
        $attemptStmt->execute(['tenant_id' => $tenantId, 'bank_line_id' => $lineId]);
        $attempt = (int) $attemptStmt->fetchColumn() + 1;
        $pdo->prepare(
            'INSERT INTO billing_processor_payouts
                (tenant_id, processor, bank_line_id, bank_account_id, attempt_no, entity_id,
                 currency, posted_date, gross_amount, fee_amount, net_amount, fee_account_id,
                 created_by_user_id)
             VALUES (:tenant_id, "qbo", :line_id, :bank_account_id, :attempt_no, :entity_id,
                     :currency, :posted_date, :gross_amount, :fee_amount, :net_amount, :fee_account_id,
                     :actor_user_id)'
        )->execute([
            'tenant_id' => $tenantId, 'line_id' => $lineId,
            'bank_account_id' => (int) $line['bank_account_id'], 'attempt_no' => $attempt,
            'entity_id' => (int) $line['entity_id'], 'currency' => (string) $line['currency'],
            'posted_date' => (string) $line['posted_date'], 'gross_amount' => $grossCents / 100,
            'fee_amount' => $feeCents / 100, 'net_amount' => $netCents / 100,
            'fee_account_id' => $feeAccountId, 'actor_user_id' => $actorUserId,
        ]);
        $payoutId = (int) $pdo->lastInsertId();
        $linkStmt = $pdo->prepare(
            'INSERT INTO billing_processor_payout_payments (tenant_id, payout_id, payment_id, gross_amount)
             VALUES (:tenant_id, :payout_id, :payment_id, :gross_amount)'
        );
        foreach ($payments as $payment) {
            $linkStmt->execute(['tenant_id' => $tenantId, 'payout_id' => $payoutId,
                'payment_id' => (int) $payment['id'], 'gross_amount' => (float) $payment['amount']]);
        }

        $lines = [
            ['account_code' => (string) $line['gl_account_code'], 'debit' => $netCents / 100, 'credit' => 0,
                'memo' => 'Processor payout bank deposit', 'dims' => ['legal_entity' => (int) $line['entity_id']]],
        ];
        if ($feeCents > 0) {
            $lines[] = ['account_id' => (int) $feeAccount['id'], 'debit' => $feeCents / 100, 'credit' => 0,
                'memo' => 'Processor fees', 'dims' => ['legal_entity' => (int) $line['entity_id']]];
        }
        $lines[] = ['account_code' => '1010', 'debit' => 0, 'credit' => $grossCents / 100,
            'memo' => 'Clear captured processor receipts', 'dims' => ['legal_entity' => (int) $line['entity_id']]];
        $journal = accountingPostJe($tenantId, [
            'entity_id' => (int) $line['entity_id'], 'posting_date' => (string) $line['posted_date'],
            'currency' => (string) $line['currency'], 'source_module' => 'billing',
            'source_ref_type' => 'processor_payout', 'source_ref_id' => $payoutId,
            'idempotency_key' => 'billing:processor-payout:' . $lineId . ':attempt:' . $attempt,
            'memo' => 'Processor payout / bank line ' . $lineId, 'lines' => $lines,
        ], $actorUserId, true);
        if (!empty($journal['idempotent_replay'])) throw new RuntimeException('Payout journal exists without its source record.');
        bankRecMatchLine($tenantId, $lineId, (int) $journal['je_id'], $actorUserId);
        $savePayout = $pdo->prepare(
            'UPDATE billing_processor_payouts SET journal_entry_id = :journal_id, status = "posted"
              WHERE tenant_id = :tenant_id AND id = :id AND status = "posting"'
        );
        $savePayout->execute(['journal_id' => (int) $journal['je_id'], 'tenant_id' => $tenantId, 'id' => $payoutId]);
        if ($savePayout->rowCount() !== 1) throw new RuntimeException('Payout changed while posting.');
        $pdo->prepare(
            'INSERT INTO accounting_subledger_links
                (tenant_id, source_module, source_record_id, journal_entry_id, link_kind)
             VALUES (:tenant_id, "billing", :source_record_id, :journal_entry_id, "primary")'
        )->execute(['tenant_id' => $tenantId, 'source_record_id' => 'processor_payout:' . $payoutId,
            'journal_entry_id' => (int) $journal['je_id']]);
        $pdo->commit();
        return ['payout_id' => $payoutId, 'journal_entry_id' => (int) $journal['je_id'],
            'bank_line_id' => $lineId, 'gross_amount' => $grossCents / 100,
            'fee_amount' => $feeCents / 100, 'net_amount' => $netCents / 100,
            'idempotent_replay' => false];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function billingCorrectProcessorPayout(int $tenantId, int $lineId, string $reason, ?int $actorUserId): array
{
    $reason = trim($reason);
    if ($reason === '' || strlen($reason) > 500) throw new InvalidArgumentException('Enter a correction reason (up to 500 characters).');
    $pdo = getDB();
    if ($pdo->inTransaction()) throw new RuntimeException('Processor payout correction requires its own transaction.');
    $pdo->beginTransaction();
    try {
        $line = billingProcessorPayoutBankLine($tenantId, $lineId, true);
        $stmt = $pdo->prepare(
            'SELECT * FROM billing_processor_payouts
              WHERE tenant_id = :tenant_id AND bank_line_id = :line_id AND status = "posted"
              ORDER BY attempt_no DESC LIMIT 1 FOR UPDATE'
        );
        $stmt->execute(['tenant_id' => $tenantId, 'line_id' => $lineId]);
        $payout = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$payout || $line['match_status'] !== 'matched'
            || (int) $line['matched_je_id'] !== (int) $payout['journal_entry_id']) {
            throw new RuntimeException('No active processor payout is matched to this bank line.');
        }
        $closed = $pdo->prepare(
            'SELECT id FROM accounting_reconciliations
              WHERE tenant_id = :tenant_id AND bank_account_id = :bank_account_id
                AND status = "closed" AND period_end >= :posted_date LIMIT 1'
        );
        $closed->execute(['tenant_id' => $tenantId, 'bank_account_id' => (int) $line['bank_account_id'],
            'posted_date' => (string) $line['posted_date']]);
        if ($closed->fetchColumn()) throw new RuntimeException('Reopen the closed bank reconciliation before correcting this payout.');
        $journalStmt = $pdo->prepare(
            'SELECT status, source_module, source_ref_type, source_ref_id
               FROM accounting_journal_entries WHERE tenant_id = :tenant_id AND id = :id LIMIT 1'
        );
        $journalStmt->execute(['tenant_id' => $tenantId, 'id' => (int) $payout['journal_entry_id']]);
        $journal = $journalStmt->fetch(PDO::FETCH_ASSOC);
        if (!$journal || $journal['status'] !== 'posted' || $journal['source_module'] !== 'billing'
            || $journal['source_ref_type'] !== 'processor_payout'
            || (int) $journal['source_ref_id'] !== (int) $payout['id']) {
            throw new RuntimeException('The payout journal does not match its source record.');
        }
        $reversal = accountingReverseJe($tenantId, (int) $payout['journal_entry_id'], $reason, $actorUserId);
        $saveCorrection = $pdo->prepare(
            'UPDATE billing_processor_payouts
                SET status = "corrected", reversal_je_id = :reversal_je_id,
                    correction_reason = :reason, corrected_at = NOW(), corrected_by_user_id = :actor_user_id
              WHERE tenant_id = :tenant_id AND id = :id AND status = "posted"'
        );
        $saveCorrection->execute(['reversal_je_id' => (int) $reversal['je_id'], 'reason' => $reason,
            'actor_user_id' => $actorUserId, 'tenant_id' => $tenantId, 'id' => (int) $payout['id']]);
        if ($saveCorrection->rowCount() !== 1) throw new RuntimeException('Payout changed during correction.');
        $reopenLine = $pdo->prepare(
            'UPDATE accounting_bank_statement_lines
                SET match_status = "unmatched", matched_je_id = NULL,
                    matched_at = NULL, matched_by_user_id = NULL
              WHERE tenant_id = :tenant_id AND id = :id AND matched_je_id = :journal_id'
        );
        $reopenLine->execute(['tenant_id' => $tenantId, 'id' => $lineId,
            'journal_id' => (int) $payout['journal_entry_id']]);
        if ($reopenLine->rowCount() !== 1) throw new RuntimeException('Bank line changed during correction.');
        $pdo->prepare(
            'INSERT INTO accounting_subledger_links
                (tenant_id, source_module, source_record_id, journal_entry_id, link_kind)
             VALUES (:tenant_id, "billing", :source_record_id, :journal_entry_id, "reversal")'
        )->execute(['tenant_id' => $tenantId, 'source_record_id' => 'processor_payout:' . (int) $payout['id'],
            'journal_entry_id' => (int) $reversal['je_id']]);
        $pdo->commit();
        return ['payout_id' => (int) $payout['id'], 'reversal_je_id' => (int) $reversal['je_id'],
            'bank_line_id' => $lineId, 'status' => 'corrected'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
