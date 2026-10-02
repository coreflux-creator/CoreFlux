<?php
declare(strict_types=1);

require_once __DIR__ . '/billing.php';
require_once __DIR__ . '/../../accounting/lib/accounting.php';

function billingBankReceiptAttempt(int $tenantId, int $bankLineId): int
{
    $stmt = getDB()->prepare(
        'SELECT COUNT(*) FROM billing_receipt_corrections
          WHERE tenant_id = :tenant_id AND bank_line_id = :bank_line_id'
    );
    $stmt->execute(['tenant_id' => $tenantId, 'bank_line_id' => $bankLineId]);
    return (int) $stmt->fetchColumn();
}

function billingCorrectBankReceipt(int $tenantId, int $bankLineId, string $reason, ?int $actorUserId): array
{
    $reason = trim($reason);
    if ($reason === '' || strlen($reason) > 500) {
        throw new InvalidArgumentException('Enter a correction reason (up to 500 characters).');
    }

    $pdo = getDB();
    cf_begin_transaction();
    try {
        $lineStmt = $pdo->prepare(
            'SELECT bl.id, bl.bank_account_id, bl.posted_date, bl.match_status, bl.matched_je_id,
                    je.status AS je_status, je.source_module, je.source_ref_type, je.source_ref_id
               FROM accounting_bank_statement_lines bl
               LEFT JOIN accounting_journal_entries je
                 ON je.tenant_id = bl.tenant_id AND je.id = bl.matched_je_id
              WHERE bl.tenant_id = :tenant_id AND bl.id = :id FOR UPDATE'
        );
        $lineStmt->execute(['tenant_id' => $tenantId, 'id' => $bankLineId]);
        $line = $lineStmt->fetch(PDO::FETCH_ASSOC);
        if (!$line) throw new RuntimeException('Bank line not found.');
        if ($line['match_status'] !== 'matched'
            || $line['je_status'] !== 'posted'
            || $line['source_module'] !== 'billing'
            || $line['source_ref_type'] !== 'bank_statement_line'
            || (int) $line['source_ref_id'] !== $bankLineId) {
            throw new RuntimeException('This bank line is not an active invoice receipt. Refresh before correcting it.');
        }

        $closed = $pdo->prepare(
            'SELECT id FROM accounting_reconciliations
              WHERE tenant_id = :tenant_id AND bank_account_id = :bank_account_id
                AND status = "closed" AND period_end >= :posted_date LIMIT 1'
        );
        $closed->execute([
            'tenant_id' => $tenantId,
            'bank_account_id' => (int) $line['bank_account_id'],
            'posted_date' => (string) $line['posted_date'],
        ]);
        if ($closed->fetchColumn()) {
            throw new RuntimeException('This receipt is in a closed bank reconciliation. Reopen that reconciliation first.');
        }

        $paymentStmt = $pdo->prepare(
            'SELECT id, amount, unallocated_amount FROM billing_payments
              WHERE tenant_id = :tenant_id AND source_system = "manual"
                AND (external_id = :exact_id OR external_id LIKE :split_prefix)
                AND voided_at IS NULL FOR UPDATE'
        );
        $paymentStmt->execute([
            'tenant_id' => $tenantId,
            'exact_id' => 'bank-line:' . $bankLineId,
            'split_prefix' => 'bank-line:' . $bankLineId . ':%',
        ]);
        $payments = $paymentStmt->fetchAll(PDO::FETCH_ASSOC);
        $paymentIds = array_map('intval', array_column($payments, 'id'));
        if (!$paymentIds) {
            throw new RuntimeException('No active receipt payment was found. Contact support before changing this bank line.');
        }

        $placeholders = implode(',', array_fill(0, count($paymentIds), '?'));
        $allocationStmt = $pdo->prepare(
            'SELECT a.id, a.payment_id, a.invoice_id, a.amount_applied
               FROM billing_payment_allocations a
              WHERE a.payment_id IN (' . $placeholders . ') AND a.reversed_at IS NULL FOR UPDATE'
        );
        $allocationStmt->execute($paymentIds);
        $allocations = $allocationStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$allocations) {
            throw new RuntimeException('The receipt has no active invoice allocations. Contact support before changing it.');
        }

        $amountsByInvoice = [];
        foreach ($allocations as $allocation) {
            $invoiceId = (int) $allocation['invoice_id'];
            $amountsByInvoice[$invoiceId] = round(
                ($amountsByInvoice[$invoiceId] ?? 0) + (float) $allocation['amount_applied'], 2
            );
        }
        $allocatedTotal = round(array_sum($amountsByInvoice), 2);
        $paymentTotal = round(array_sum(array_map(static fn(array $payment): float => (float) $payment['amount'], $payments)), 2);
        if (abs($allocatedTotal - $paymentTotal) > 0.005) {
            throw new RuntimeException('The receipt payment and invoice allocations do not agree. Review the payment before correcting it.');
        }
        foreach ($payments as $payment) {
            if (abs((float) $payment['unallocated_amount']) > 0.005) {
                throw new RuntimeException('The receipt has unapplied cash. Review the payment before correcting it.');
            }
        }
        $arStmt = $pdo->prepare(
            'SELECT ROUND(COALESCE(SUM(l.credit - l.debit), 0), 2)
               FROM accounting_journal_entry_lines l
               JOIN accounting_accounts a ON a.id = l.account_id AND a.tenant_id = l.tenant_id
              WHERE l.tenant_id = :tenant_id AND l.je_id = :je_id AND a.code = "1100"'
        );
        $arStmt->execute(['tenant_id' => $tenantId, 'je_id' => (int) $line['matched_je_id']]);
        if (abs((float) $arStmt->fetchColumn() - $allocatedTotal) > 0.005) {
            throw new RuntimeException('The receipt journal and invoice allocations do not agree. Contact support before correcting it.');
        }
        ksort($amountsByInvoice, SORT_NUMERIC);
        $invoiceIds = array_keys($amountsByInvoice);
        $invoicePlaceholders = implode(',', array_fill(0, count($invoiceIds), '?'));

        $pwpStmt = $pdo->prepare(
            'SELECT id FROM ap_bills WHERE tenant_id = ?
                AND linked_ar_invoice_id IN (' . $invoicePlaceholders . ')
                AND pwp_status IN ("triggered", "partial_triggered") LIMIT 1'
        );
        $pwpStmt->execute(array_merge([$tenantId], $invoiceIds));
        if ($pwpStmt->fetchColumn()) {
            throw new RuntimeException('Pay-when-paid bills were released by this invoice. Correct those bills before reversing the receipt.');
        }

        $invoiceStmt = $pdo->prepare(
            'SELECT id, total, amount_paid, sent_at, status
               FROM billing_invoices WHERE tenant_id = ?
                AND id IN (' . $invoicePlaceholders . ') ORDER BY id FOR UPDATE'
        );
        $invoiceStmt->execute(array_merge([$tenantId], $invoiceIds));
        $invoices = $invoiceStmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($invoices) !== count($invoiceIds)) {
            throw new RuntimeException('An allocated invoice is missing. Contact support before changing this receipt.');
        }
        $restored = [];
        foreach ($invoices as $invoice) {
            $invoiceId = (int) $invoice['id'];
            $newPaid = round((float) $invoice['amount_paid'] - $amountsByInvoice[$invoiceId], 2);
            if ($invoice['status'] === 'void' || $invoice['status'] === 'draft' || $newPaid < -0.005) {
                throw new RuntimeException('An invoice changed since this receipt was applied. Review it before correcting the bank line.');
            }
            $newPaid = max(0.0, $newPaid);
            $newDue = round((float) $invoice['total'] - $newPaid, 2);
            $newStatus = $newDue < 0.005 ? 'paid'
                : ($newPaid > 0 ? 'partially_paid' : ($invoice['sent_at'] ? 'sent' : 'approved'));
            $restored[] = ['id' => $invoiceId, 'paid' => $newPaid, 'due' => max(0.0, $newDue), 'status' => $newStatus];
        }

        $originalJeId = (int) $line['matched_je_id'];
        $reversal = accountingReverseJe($tenantId, $originalJeId, $reason, $actorUserId);
        if (!empty($reversal['idempotent_replay'])) {
            throw new RuntimeException('This receipt journal was already reversed. Refresh before correcting it.');
        }
        $reversalJeId = (int) $reversal['je_id'];
        $reverseAlloc = $pdo->prepare(
            'UPDATE billing_payment_allocations
                SET reversed_at = NOW(), reversal_je_id = :je_id, reversed_by_user_id = :user_id
              WHERE id = :id AND reversed_at IS NULL'
        );
        foreach ($allocations as $allocation) {
            $reverseAlloc->execute([
                'je_id' => $reversalJeId,
                'user_id' => $actorUserId,
                'id' => (int) $allocation['id'],
            ]);
            if ($reverseAlloc->rowCount() !== 1) throw new RuntimeException('An allocation changed. Refresh and try again.');
        }

        $voidPayment = $pdo->prepare(
            'UPDATE billing_payments
                SET voided_at = NOW(), void_reason = :reason, voided_by_user_id = :user_id,
                    void_je_id = :je_id, unallocated_amount = 0
              WHERE tenant_id = :tenant_id AND id = :id AND voided_at IS NULL'
        );
        foreach ($paymentIds as $paymentId) {
            $voidPayment->execute([
                'reason' => $reason, 'user_id' => $actorUserId, 'je_id' => $reversalJeId,
                'tenant_id' => $tenantId, 'id' => $paymentId,
            ]);
            if ($voidPayment->rowCount() !== 1) throw new RuntimeException('A payment changed. Refresh and try again.');
        }

        $restoreInvoice = $pdo->prepare(
            'UPDATE billing_invoices SET amount_paid = :paid, amount_due = :due, status = :status
              WHERE tenant_id = :tenant_id AND id = :id'
        );
        foreach ($restored as $invoice) {
            $restoreInvoice->execute([
                'paid' => $invoice['paid'], 'due' => $invoice['due'], 'status' => $invoice['status'],
                'tenant_id' => $tenantId, 'id' => $invoice['id'],
            ]);
        }

        $reopen = $pdo->prepare(
            'UPDATE accounting_bank_statement_lines
                SET match_status = "unmatched", matched_je_id = NULL, matched_at = NULL,
                    matched_by_user_id = NULL, updated_at = NOW()
              WHERE tenant_id = :tenant_id AND id = :id AND match_status = "matched"
                AND matched_je_id = :original_je_id'
        );
        $reopen->execute(['tenant_id' => $tenantId, 'id' => $bankLineId, 'original_je_id' => $originalJeId]);
        if ($reopen->rowCount() !== 1) throw new RuntimeException('The bank line changed. Refresh and try again.');

        $pdo->prepare(
            'INSERT INTO billing_receipt_corrections
                (tenant_id, bank_line_id, original_je_id, reversal_je_id, reason, corrected_by_user_id)
             VALUES (:tenant_id, :bank_line_id, :original_je_id, :reversal_je_id, :reason, :user_id)'
        )->execute([
            'tenant_id' => $tenantId, 'bank_line_id' => $bankLineId,
            'original_je_id' => $originalJeId, 'reversal_je_id' => $reversalJeId,
            'reason' => $reason, 'user_id' => $actorUserId,
        ]);
        $pdo->prepare(
            'INSERT INTO accounting_subledger_links
                (tenant_id, source_module, source_record_id, journal_entry_id, link_kind)
             VALUES (:tenant_id, "billing", :source_record_id, :journal_entry_id, "reversal")'
        )->execute([
            'tenant_id' => $tenantId,
            'source_record_id' => 'bank_line:receipt:' . $bankLineId,
            'journal_entry_id' => $reversalJeId,
        ]);
        $pdo->commit();
        return [
            'ok' => true, 'line_id' => $bankLineId, 'original_je_id' => $originalJeId,
            'reversal_je_id' => $reversalJeId, 'payment_ids' => $paymentIds,
            'restored_invoices' => $restored,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
