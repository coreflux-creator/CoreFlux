<?php
declare(strict_types=1);

require_once __DIR__ . '/billing.php';
require_once __DIR__ . '/../../accounting/lib/accounting.php';

/** Record or finish a manual receipt; invoice balances and the cash JE commit together. */
function billingPostReceivedPayment(int $tenantId, array $request, ?int $actorUserId, ?int $paymentId = null): array
{
    $bankAccountId = (int) ($request['bank_account_id'] ?? 0);
    if ($bankAccountId <= 0) throw new InvalidArgumentException('Choose the bank account that received the payment.');
    if ($paymentId === null) {
        $clientName = trim((string) ($request['client_name'] ?? ''));
        $date = (string) ($request['received_at'] ?? '');
        $amount = round((float) ($request['amount'] ?? 0), 2);
        $requestKey = trim((string) ($request['request_key'] ?? ''));
        if ($clientName === '' || strlen($clientName) > 255) throw new InvalidArgumentException('Choose a client.');
        $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) throw new InvalidArgumentException('Enter a valid receipt date.');
        if (!is_finite($amount) || $amount <= 0) throw new InvalidArgumentException('Payment amount must be greater than zero.');
        if (!preg_match('/^[A-Za-z0-9_-]{8,80}$/', $requestKey)) {
            throw new InvalidArgumentException('A unique receipt request key is required. Refresh and try again.');
        }
        $method = (string) ($request['method'] ?? 'ach');
        if (!in_array($method, ['ach', 'wire', 'check', 'card', 'cash', 'other'], true)) {
            throw new InvalidArgumentException('Choose a valid payment method.');
        }
        $currency = strtoupper(trim((string) ($request['currency'] ?? 'USD')));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) throw new InvalidArgumentException('Currency must be a three-letter code.');
    }

    $pdo = getDB();
    if ($pdo->inTransaction()) throw new RuntimeException('A receipt cannot be posted inside another transaction.');
    cf_begin_transaction();
    try {
        if ($paymentId === null) {
            $externalId = 'manual-receipt:' . $requestKey;
            $prior = $pdo->prepare(
                'SELECT * FROM billing_payments WHERE tenant_id = :tenant_id
                   AND source_system = "manual" AND external_id = :external_id FOR UPDATE'
            );
            $prior->execute(['tenant_id' => $tenantId, 'external_id' => $externalId]);
            $existing = $prior->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                if ((int) ($existing['journal_entry_id'] ?? 0) > 0
                    && $existing['voided_at'] === null
                    && (int) ($existing['bank_account_id'] ?? 0) === $bankAccountId
                    && $existing['client_name'] === $clientName
                    && $existing['received_at'] === $date
                    && abs((float) $existing['amount'] - $amount) < 0.005) {
                    $pdo->commit();
                    return ['id' => (int) $existing['id'], 'journal_entry_id' => (int) $existing['journal_entry_id'],
                        'idempotent_replay' => true, 'pwp' => []];
                }
                throw new RuntimeException('This receipt request was already used. Refresh before recording another payment.');
            }
            $paymentId = scopedInsert('billing_payments', [
                'tenant_id' => $tenantId,
                'client_name' => $clientName,
                'received_at' => $date,
                'method' => $method,
                'reference' => trim((string) ($request['reference'] ?? '')) ?: null,
                'external_id' => $externalId,
                'source_system' => 'manual',
                'amount' => $amount,
                'currency' => $currency,
                'unallocated_amount' => $amount,
                'notes' => trim((string) ($request['notes'] ?? '')) ?: null,
                'created_by_user_id' => $actorUserId,
            ]);
        }

        $paymentStmt = $pdo->prepare(
            'SELECT * FROM billing_payments WHERE tenant_id = :tenant_id AND id = :id FOR UPDATE'
        );
        $paymentStmt->execute(['tenant_id' => $tenantId, 'id' => $paymentId]);
        $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);
        if (!$payment) throw new RuntimeException('Payment not found.');
        if ($payment['voided_at'] !== null) throw new RuntimeException('A corrected payment cannot be posted.');
        if ($payment['source_system'] !== 'manual') throw new RuntimeException('Only manual receipts can be posted here.');
        if (str_starts_with((string) ($payment['external_id'] ?? ''), 'bank-line:')) {
            throw new RuntimeException('This receipt was recorded from bank reconciliation and cannot be posted again.');
        }
        if ($payment['journal_entry_id'] !== null) throw new RuntimeException('This payment is already posted.');
        if ((float) $payment['amount'] <= 0) throw new RuntimeException('Payment amount must be greater than zero.');

        $bankStmt = $pdo->prepare(
            'SELECT id, entity_id, gl_account_code, currency FROM accounting_bank_accounts
              WHERE tenant_id = :tenant_id AND id = :id AND status = "active" FOR UPDATE'
        );
        $bankStmt->execute(['tenant_id' => $tenantId, 'id' => $bankAccountId]);
        $bank = $bankStmt->fetch(PDO::FETCH_ASSOC);
        if (!$bank) throw new RuntimeException('Choose an active bank account in this organization.');
        if (strcasecmp((string) $payment['currency'], (string) ($bank['currency'] ?: 'USD')) !== 0) {
            throw new RuntimeException('Payment and bank account currencies differ.');
        }
        $entityId = !empty($bank['entity_id'])
            ? (int) $bank['entity_id'] : (int) accountingDefaultEntity($tenantId)['id'];

        $newApplied = [];
        if ((float) $payment['unallocated_amount'] > 0.005) {
            if (empty($request['allocations']) && ($request['auto'] ?? '') !== 'fifo') {
                throw new RuntimeException('Apply the full receipt to posted invoices before recording it.');
            }
            $allocation = billingAllocatePayment($paymentId, [
                'allocations' => $request['allocations'] ?? [],
                'auto' => $request['auto'] ?? null,
                'require_posted' => true,
                'entity_id' => $entityId,
                'currency' => (string) $payment['currency'],
                'receipt_date' => (string) $payment['received_at'],
                'defer_pwp' => true,
            ], $actorUserId);
            $newApplied = $allocation['applied'];
        }

        $allocationStmt = $pdo->prepare(
            'SELECT a.invoice_id, SUM(a.amount_applied) AS applied,
                    i.invoice_number, i.client_name, i.client_company_id, i.entity_id,
                    i.currency, i.issue_date, i.status, i.journal_entry_id, je.status AS je_status
               FROM billing_payment_allocations a
               JOIN billing_invoices i ON i.id = a.invoice_id AND i.tenant_id = :tenant_id
          LEFT JOIN accounting_journal_entries je ON je.id = i.journal_entry_id AND je.tenant_id = i.tenant_id
              WHERE a.payment_id = :payment_id AND a.reversed_at IS NULL
              GROUP BY a.invoice_id, i.invoice_number, i.client_name, i.client_company_id,
                       i.entity_id, i.currency, i.issue_date, i.status, i.journal_entry_id, je.status
              ORDER BY a.invoice_id'
        );
        $allocationStmt->execute(['tenant_id' => $tenantId, 'payment_id' => $paymentId]);
        $invoices = $allocationStmt->fetchAll(PDO::FETCH_ASSOC);
        $allocated = round(array_sum(array_map(static fn(array $i): float => (float) $i['applied'], $invoices)), 2);
        if (!$invoices || abs($allocated - (float) $payment['amount']) > 0.005) {
            throw new RuntimeException('The receipt must be fully applied to invoices. No payment was recorded.');
        }
        foreach ($invoices as $invoice) {
            if ($invoice['je_status'] !== 'posted'
                || !in_array($invoice['status'], ['approved', 'sent', 'partially_paid', 'paid'], true)
                || strcasecmp((string) $invoice['currency'], (string) $payment['currency']) !== 0
                || strcasecmp(trim((string) $invoice['client_name']), trim((string) $payment['client_name'])) !== 0
                || (!empty($invoice['entity_id']) && (int) $invoice['entity_id'] !== $entityId)
                || (string) $invoice['issue_date'] > (string) $payment['received_at']) {
                throw new RuntimeException('Each allocation must belong to this client, currency and entity, and have a posted invoice dated before the receipt.');
            }
        }

        $cashDims = ['legal_entity' => $entityId];
        $lines = [[
            'account_code' => (string) $bank['gl_account_code'],
            'debit' => (float) $payment['amount'], 'credit' => 0,
            'memo' => 'Customer receipt ' . (string) ($payment['reference'] ?: $paymentId),
            'dims' => $cashDims,
        ]];
        foreach ($invoices as $invoice) {
            $clientDimension = !empty($invoice['client_company_id'])
                ? (int) $invoice['client_company_id']
                : 'name:' . strtolower(trim((string) $invoice['client_name']));
            $lines[] = [
                'account_code' => '1100', 'debit' => 0, 'credit' => round((float) $invoice['applied'], 2),
                'memo' => 'Apply receipt to ' . $invoice['invoice_number'],
                'counterparty_company_id' => $invoice['client_company_id'] ?? null,
                'dims' => $cashDims + ['client' => $clientDimension],
            ];
        }
        $journal = accountingPostJe($tenantId, [
            'entity_id' => $entityId,
            'posting_date' => (string) $payment['received_at'],
            'currency' => (string) $payment['currency'],
            'source_module' => 'billing',
            'source_ref_type' => 'billing_payment',
            'source_ref_id' => $paymentId,
            'idempotency_key' => 'billing:manual-receipt:' . $paymentId,
            'memo' => 'Customer receipt / ' . $payment['client_name'],
            'lines' => $lines,
        ], $actorUserId, true);
        if (!empty($journal['idempotent_replay'])) throw new RuntimeException('Receipt journal already exists; refresh before posting.');

        $save = $pdo->prepare(
            'UPDATE billing_payments SET bank_account_id = :bank_account_id,
                    journal_entry_id = :journal_entry_id, posted_at = NOW(), unallocated_amount = 0
              WHERE tenant_id = :tenant_id AND id = :id AND journal_entry_id IS NULL AND voided_at IS NULL'
        );
        $save->execute([
            'bank_account_id' => $bankAccountId, 'journal_entry_id' => (int) $journal['je_id'],
            'tenant_id' => $tenantId, 'id' => $paymentId,
        ]);
        if ($save->rowCount() !== 1) throw new RuntimeException('Payment changed while posting. Refresh and try again.');
        $pdo->prepare(
            'INSERT INTO accounting_subledger_links
                (tenant_id, source_module, source_record_id, journal_entry_id, link_kind)
             VALUES (:tenant_id, "billing", :source_record_id, :journal_entry_id, "primary")'
        )->execute([
            'tenant_id' => $tenantId, 'source_record_id' => 'payment:' . $paymentId,
            'journal_entry_id' => (int) $journal['je_id'],
        ]);
        $pdo->commit();
        $pwp = billingReleasePayWhenPaidForAllocations($tenantId, $newApplied, $actorUserId);
        return [
            'id' => $paymentId, 'journal_entry_id' => (int) $journal['je_id'],
            'je_number' => $journal['je_number'], 'applied' => $invoices,
            'unallocated_remaining' => 0, 'pwp' => $pwp, 'idempotent_replay' => false,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Reverse a manual receipt without erasing its invoice, bank or ledger history. */
function billingCorrectPostedPayment(int $tenantId, int $paymentId, string $reason, ?int $actorUserId): array
{
    $reason = trim($reason);
    if ($reason === '' || strlen($reason) > 500) {
        throw new InvalidArgumentException('Enter a correction reason (up to 500 characters).');
    }
    $pdo = getDB();
    if ($pdo->inTransaction()) throw new RuntimeException('A receipt cannot be corrected inside another transaction.');
    cf_begin_transaction();
    try {
        $paymentStmt = $pdo->prepare(
            'SELECT p.*, je.status AS je_status, je.source_module, je.source_ref_type, je.source_ref_id
               FROM billing_payments p
               JOIN accounting_journal_entries je ON je.tenant_id = p.tenant_id AND je.id = p.journal_entry_id
              WHERE p.tenant_id = :tenant_id AND p.id = :id FOR UPDATE'
        );
        $paymentStmt->execute(['tenant_id' => $tenantId, 'id' => $paymentId]);
        $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);
        if (!$payment || $payment['voided_at'] !== null || $payment['je_status'] !== 'posted'
            || $payment['source_module'] !== 'billing'
            || $payment['source_ref_type'] !== 'billing_payment'
            || (int) $payment['source_ref_id'] !== $paymentId) {
            throw new RuntimeException('This is not an active manually posted receipt. Refresh and try again.');
        }

        $closedStmt = $pdo->prepare(
            'SELECT id FROM accounting_reconciliations
              WHERE tenant_id = :tenant_id AND bank_account_id = :bank_account_id
                AND status = "closed" AND period_end >= :received_at LIMIT 1'
        );
        $closedStmt->execute([
            'tenant_id' => $tenantId, 'bank_account_id' => (int) $payment['bank_account_id'],
            'received_at' => (string) $payment['received_at'],
        ]);
        if ($closedStmt->fetchColumn()) {
            throw new RuntimeException('This receipt falls in a closed bank reconciliation. Reopen it before correcting the payment.');
        }
        $matchedStmt = $pdo->prepare(
            'SELECT id, bank_account_id FROM accounting_bank_statement_lines
              WHERE tenant_id = :tenant_id AND matched_je_id = :journal_entry_id
                AND match_status = "matched" FOR UPDATE'
        );
        $matchedStmt->execute(['tenant_id' => $tenantId, 'journal_entry_id' => (int) $payment['journal_entry_id']]);
        $matchedLines = $matchedStmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($matchedLines) > 1
            || ($matchedLines && (int) $matchedLines[0]['bank_account_id'] !== (int) $payment['bank_account_id'])) {
            throw new RuntimeException('The receipt has inconsistent bank matches. Review it before correcting.');
        }

        $allocationStmt = $pdo->prepare(
            'SELECT id, invoice_id, amount_applied FROM billing_payment_allocations
              WHERE payment_id = :payment_id AND reversed_at IS NULL FOR UPDATE'
        );
        $allocationStmt->execute(['payment_id' => $paymentId]);
        $allocations = $allocationStmt->fetchAll(PDO::FETCH_ASSOC);
        $amountsByInvoice = [];
        foreach ($allocations as $allocation) {
            $invoiceId = (int) $allocation['invoice_id'];
            $amountsByInvoice[$invoiceId] = round(
                ($amountsByInvoice[$invoiceId] ?? 0) + (float) $allocation['amount_applied'], 2
            );
        }
        $total = round(array_sum($amountsByInvoice), 2);
        if (!$amountsByInvoice || abs($total - (float) $payment['amount']) > 0.005
            || abs((float) $payment['unallocated_amount']) > 0.005) {
            throw new RuntimeException('Receipt allocations do not agree with the payment. Review before correcting.');
        }
        $arStmt = $pdo->prepare(
            'SELECT ROUND(COALESCE(SUM(l.credit - l.debit), 0), 2)
               FROM accounting_journal_entry_lines l
               JOIN accounting_accounts a ON a.id = l.account_id AND a.tenant_id = l.tenant_id
              WHERE l.tenant_id = :tenant_id AND l.je_id = :je_id AND a.code = "1100"'
        );
        $arStmt->execute(['tenant_id' => $tenantId, 'je_id' => (int) $payment['journal_entry_id']]);
        if (abs((float) $arStmt->fetchColumn() - $total) > 0.005) {
            throw new RuntimeException('The receipt journal and invoice allocations disagree. Review before correcting.');
        }

        ksort($amountsByInvoice, SORT_NUMERIC);
        $invoiceIds = array_keys($amountsByInvoice);
        $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
        $pwpStmt = $pdo->prepare(
            'SELECT id FROM ap_bills WHERE tenant_id = ? AND linked_ar_invoice_id IN (' . $placeholders . ')
                AND pwp_status IN ("triggered", "partial_triggered") LIMIT 1'
        );
        $pwpStmt->execute(array_merge([$tenantId], $invoiceIds));
        if ($pwpStmt->fetchColumn()) {
            throw new RuntimeException('Pay-when-paid bills were released by this receipt. Correct those bills first.');
        }
        $invoiceStmt = $pdo->prepare(
            'SELECT id, total, amount_paid, sent_at, status FROM billing_invoices
              WHERE tenant_id = ? AND id IN (' . $placeholders . ') ORDER BY id FOR UPDATE'
        );
        $invoiceStmt->execute(array_merge([$tenantId], $invoiceIds));
        $invoices = $invoiceStmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($invoices) !== count($invoiceIds)) throw new RuntimeException('An allocated invoice is missing.');
        $restored = [];
        foreach ($invoices as $invoice) {
            $id = (int) $invoice['id'];
            $paid = round((float) $invoice['amount_paid'] - $amountsByInvoice[$id], 2);
            if (in_array($invoice['status'], ['draft', 'void'], true) || $paid < -0.005) {
                throw new RuntimeException('An invoice changed since this payment was applied. Review before correcting.');
            }
            $paid = max(0, $paid);
            $due = max(0, round((float) $invoice['total'] - $paid, 2));
            $status = $due < 0.005 ? 'paid' : ($paid > 0 ? 'partially_paid' : ($invoice['sent_at'] ? 'sent' : 'approved'));
            $restored[] = ['id' => $id, 'amount_paid' => $paid, 'amount_due' => $due, 'status' => $status];
        }

        $reversal = accountingReverseJe(
            $tenantId, (int) $payment['journal_entry_id'], $reason, $actorUserId
        );
        if (!empty($reversal['idempotent_replay'])) throw new RuntimeException('The receipt journal was already reversed.');
        $reversalId = (int) $reversal['je_id'];
        $reverseAllocation = $pdo->prepare(
            'UPDATE billing_payment_allocations
                SET reversed_at = NOW(), reversal_je_id = :je_id, reversed_by_user_id = :user_id
              WHERE id = :id AND reversed_at IS NULL'
        );
        foreach ($allocations as $allocation) {
            $reverseAllocation->execute(['je_id' => $reversalId, 'user_id' => $actorUserId, 'id' => (int) $allocation['id']]);
            if ($reverseAllocation->rowCount() !== 1) throw new RuntimeException('Allocation changed while correcting.');
        }
        $restoreInvoice = $pdo->prepare(
            'UPDATE billing_invoices SET amount_paid = :amount_paid, amount_due = :amount_due, status = :status
              WHERE tenant_id = :tenant_id AND id = :id'
        );
        foreach ($restored as $invoice) {
            $restoreInvoice->execute($invoice + ['tenant_id' => $tenantId]);
        }
        $void = $pdo->prepare(
            'UPDATE billing_payments
                SET voided_at = NOW(), void_reason = :reason, voided_by_user_id = :user_id,
                    void_je_id = :reversal_id, unallocated_amount = 0
              WHERE tenant_id = :tenant_id AND id = :id AND voided_at IS NULL'
        );
        $void->execute([
            'reason' => $reason, 'user_id' => $actorUserId, 'reversal_id' => $reversalId,
            'tenant_id' => $tenantId, 'id' => $paymentId,
        ]);
        if ($void->rowCount() !== 1) throw new RuntimeException('Payment changed while correcting.');
        if ($matchedLines) {
            $reopen = $pdo->prepare(
                'UPDATE accounting_bank_statement_lines
                    SET match_status = "unmatched", matched_je_id = NULL, matched_at = NULL,
                        matched_by_user_id = NULL, updated_at = NOW()
                  WHERE tenant_id = :tenant_id AND id = :id AND matched_je_id = :journal_entry_id
                    AND match_status = "matched"'
            );
            $reopen->execute([
                'tenant_id' => $tenantId, 'id' => (int) $matchedLines[0]['id'],
                'journal_entry_id' => (int) $payment['journal_entry_id'],
            ]);
            if ($reopen->rowCount() !== 1) throw new RuntimeException('Bank match changed while correcting.');
        }
        $pdo->prepare(
            'INSERT INTO accounting_subledger_links
                (tenant_id, source_module, source_record_id, journal_entry_id, link_kind)
             VALUES (:tenant_id, "billing", :source_record_id, :journal_entry_id, "reversal")'
        )->execute([
            'tenant_id' => $tenantId, 'source_record_id' => 'payment:' . $paymentId,
            'journal_entry_id' => $reversalId,
        ]);
        $pdo->commit();
        return [
            'id' => $paymentId, 'original_je_id' => (int) $payment['journal_entry_id'],
            'reversal_je_id' => $reversalId,
            'reopened_bank_line_id' => $matchedLines ? (int) $matchedLines[0]['id'] : null,
            'restored_invoices' => $restored,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
