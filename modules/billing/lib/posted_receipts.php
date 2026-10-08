<?php
declare(strict_types=1);

require_once __DIR__ . '/billing.php';
require_once __DIR__ . '/../../accounting/lib/accounting.php';
require_once __DIR__ . '/../../../core/posting_engine/process.php';

function billingReceiptParentEventId(int $tenantId, int $paymentId): ?int
{
    $stmt = getDB()->prepare(
        'SELECT id, status FROM accounting_events
          WHERE tenant_id = :tenant_id AND source_module = "billing"
            AND source_record_id = :source_record_id
            AND event_type = "billing.manual_receipt.posted" LIMIT 1'
    );
    $stmt->execute(['tenant_id' => $tenantId, 'source_record_id' => 'payment:' . $paymentId]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$event) return null;
    if ($event['status'] !== 'posted') {
        throw new RuntimeException('The deposit receipt event is no longer posted. Review before continuing.');
    }
    return (int) $event['id'];
}

function billingReceiptEventForCorrection(int $tenantId, string $eventType, string $sourceRecordId, int $journalId): ?int
{
    $stmt = getDB()->prepare(
        'SELECT id, status, journal_entry_id FROM accounting_events
          WHERE tenant_id = :tenant_id AND source_module = "billing"
            AND source_record_id = :source_record_id AND event_type = :event_type FOR UPDATE'
    );
    $stmt->execute(['tenant_id' => $tenantId, 'source_record_id' => $sourceRecordId, 'event_type' => $eventType]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$event) return null; // Historic receipts predate the event contract.
    if ($event['status'] !== 'posted' || (int) $event['journal_entry_id'] !== $journalId) {
        throw new RuntimeException('The billing event does not match its posted journal. Review before correcting.');
    }
    return (int) $event['id'];
}

function billingReverseReceiptEvent(int $tenantId, ?int $eventId): void
{
    if ($eventId === null) return;
    $stmt = getDB()->prepare(
        'UPDATE accounting_events SET status = "reversed"
          WHERE tenant_id = :tenant_id AND id = :id AND status = "posted"'
    );
    $stmt->execute(['tenant_id' => $tenantId, 'id' => $eventId]);
    if ($stmt->rowCount() !== 1) throw new RuntimeException('Billing event changed while correcting.');
}

function billingRequireCustomerDepositAccount(int $tenantId): void
{
    $stmt = getDB()->prepare(
        'SELECT id FROM accounting_accounts
          WHERE tenant_id = :t AND code = "2300" AND account_type = "liability"
            AND normal_side = "credit" AND is_system_account = 1
            AND is_postable = 1 AND active = 1 LIMIT 1'
    );
    $stmt->execute(['t' => $tenantId]);
    if (!$stmt->fetchColumn()) {
        throw new RuntimeException('The Customer Deposits control account is missing or misconfigured.');
    }
}

function billingReceiptRequestHash(array $request, int $bankAccountId, string $clientName,
    string $date, float $amount, string $method, string $currency, bool $holdUnapplied): string
{
    $allocations = $request['allocations'] ?? [];
    if (!is_array($allocations)) throw new InvalidArgumentException('Receipt allocations must be a list.');
    $normalizedAllocations = [];
    foreach ($allocations as $allocation) {
        if (!is_array($allocation)) throw new InvalidArgumentException('Each receipt allocation must name an invoice and amount.');
        $normalizedAllocations[] = [
            'invoice_id' => (int) ($allocation['invoice_id'] ?? 0),
            'amount' => number_format((float) ($allocation['amount'] ?? 0), 2, '.', ''),
        ];
    }
    usort($normalizedAllocations, static fn(array $a, array $b): int =>
        [$a['invoice_id'], $a['amount']] <=> [$b['invoice_id'], $b['amount']]);
    $intent = [
        'bank_account_id' => $bankAccountId,
        'client_name' => $clientName,
        'received_at' => $date,
        'amount' => number_format($amount, 2, '.', ''),
        'method' => $method,
        'currency' => $currency,
        'reference' => trim((string) ($request['reference'] ?? '')),
        'notes' => trim((string) ($request['notes'] ?? '')),
        'hold_unapplied' => $holdUnapplied,
        'auto' => ($request['auto'] ?? '') === 'fifo' ? 'fifo' : '',
        'allocations' => $normalizedAllocations,
    ];
    return hash('sha256', json_encode($intent, JSON_THROW_ON_ERROR));
}

/** Record a manual receipt or post a pending import; allocations and cash commit together. */
function billingPostReceivedPayment(int $tenantId, array $request, ?int $actorUserId, ?int $paymentId = null): array
{
    $bankAccountId = (int) ($request['bank_account_id'] ?? 0);
    $holdUnapplied = !empty($request['hold_unapplied']);
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
        $requestHash = billingReceiptRequestHash($request, $bankAccountId, $clientName,
            $date, $amount, $method, $currency, $holdUnapplied);
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
                    && abs((float) $existing['amount'] - $amount) < 0.005
                    && !empty($existing['receipt_request_hash'])
                    && hash_equals((string) $existing['receipt_request_hash'], $requestHash)) {
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
                'receipt_request_hash' => $requestHash,
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
        // Imported receipts are still operator-posted through this same journal path.
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
                if (!$holdUnapplied) {
                    throw new RuntimeException('Apply the receipt to posted invoices or hold the remainder as a customer deposit.');
                }
            } else {
                $allocation = billingAllocatePayment($paymentId, [
                    'allocations' => $request['allocations'] ?? [],
                    'auto' => $request['auto'] ?? null,
                    'require_posted' => true,
                    'entity_id' => $entityId,
                    'currency' => (string) $payment['currency'],
                    'receipt_date' => (string) $payment['received_at'],
                    'allocation_date' => (string) $payment['received_at'],
                    'defer_pwp' => true,
                ], $actorUserId);
                $newApplied = $allocation['applied'];
            }
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
        $unapplied = round((float) $payment['amount'] - $allocated, 2);
        if ($unapplied < -0.005 || (!$holdUnapplied && $unapplied > 0.005)) {
            throw new RuntimeException('The receipt must be fully applied or its remainder held as a customer deposit. No payment was recorded.');
        }
        $balanceStmt = $pdo->prepare('SELECT unallocated_amount FROM billing_payments WHERE tenant_id = :t AND id = :id');
        $balanceStmt->execute(['t' => $tenantId, 'id' => $paymentId]);
        if (abs((float) $balanceStmt->fetchColumn() - $unapplied) > 0.005) {
            throw new RuntimeException('Receipt allocations and its unallocated balance disagree.');
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
        if ($unapplied > 0.005) {
            billingRequireCustomerDepositAccount($tenantId);
            $lines[] = [
                'account_code' => '2300', 'debit' => 0, 'credit' => $unapplied,
                'memo' => 'Unapplied customer deposit for ' . $payment['client_name'],
                'dims' => $cashDims + ['client' => 'name:' . strtolower(trim((string) $payment['client_name']))],
            ];
        }
        $event = accountingProcessEvent($tenantId, [
            'entity_id' => $entityId,
            'event_type' => 'billing.manual_receipt.posted',
            'source_module' => 'billing',
            'source_record_id' => 'payment:' . $paymentId,
            'event_date' => (string) $payment['received_at'],
            'payload' => [
                'payment_id' => $paymentId, 'bank_account_id' => $bankAccountId,
                'client_name' => (string) $payment['client_name'],
                'invoice_ids' => array_map('intval', array_column($invoices, 'invoice_id')),
                'amount' => (float) $payment['amount'], 'unapplied_amount' => $unapplied,
                'currency' => (string) $payment['currency'],
                'source_ref_type' => 'billing_payment', 'source_ref_id' => $paymentId,
                'memo' => 'Customer receipt / ' . $payment['client_name'], 'lines' => $lines,
            ],
        ], $actorUserId);
        if (($event['status'] ?? '') !== 'posted') {
            throw new RuntimeException('Receipt could not be posted: ' . ($event['error'] ?? 'no posting rule matched'));
        }
        if (!empty($event['idempotent_replay'])) throw new RuntimeException('Receipt journal already exists; refresh before posting.');
        $journalId = (int) $event['journal_entry_id'];

        $save = $pdo->prepare(
            'UPDATE billing_payments SET bank_account_id = :bank_account_id,
                    journal_entry_id = :journal_entry_id, posted_at = NOW(), unallocated_amount = :unallocated
              WHERE tenant_id = :tenant_id AND id = :id AND journal_entry_id IS NULL AND voided_at IS NULL'
        );
        $save->execute([
            'bank_account_id' => $bankAccountId, 'journal_entry_id' => $journalId,
            'unallocated' => $unapplied, 'tenant_id' => $tenantId, 'id' => $paymentId,
        ]);
        if ($save->rowCount() !== 1) throw new RuntimeException('Payment changed while posting. Refresh and try again.');
        $pdo->commit();
        $pwp = billingReleasePayWhenPaidForAllocations($tenantId, $newApplied, $actorUserId);
        return [
            'id' => $paymentId, 'journal_entry_id' => $journalId,
            'je_number' => $event['je_number'], 'applied' => $invoices,
            'unallocated_remaining' => $unapplied, 'pwp' => $pwp, 'idempotent_replay' => false,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Apply an already-posted customer deposit without recording cash a second time. */
function billingApplyCustomerDeposit(int $tenantId, int $paymentId, array $request, ?int $actorUserId): array
{
    $invoiceId = (int) ($request['invoice_id'] ?? 0);
    $amount = round((float) ($request['amount'] ?? 0), 2);
    $requestKey = trim((string) ($request['request_key'] ?? ''));
    $appliedAt = (string) ($request['applied_at'] ?? date('Y-m-d'));
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $appliedAt);
    if ($paymentId <= 0 || $invoiceId <= 0 || !is_finite($amount) || $amount <= 0) {
        throw new InvalidArgumentException('Choose an invoice and a positive amount to apply.');
    }
    if (!preg_match('/^[A-Za-z0-9_-]{8,80}$/', $requestKey)) {
        throw new InvalidArgumentException('A unique application request key is required. Refresh and try again.');
    }
    if (!$date || $date->format('Y-m-d') !== $appliedAt) {
        throw new InvalidArgumentException('Enter a valid application date.');
    }

    $pdo = getDB();
    if ($pdo->inTransaction()) throw new RuntimeException('A deposit application requires its own transaction.');
    cf_begin_transaction();
    try {
        $paymentStmt = $pdo->prepare(
            'SELECT p.*, je.status AS je_status, je.entity_id AS je_entity_id,
                    je.source_module, je.source_ref_type, je.source_ref_id
               FROM billing_payments p
               JOIN accounting_journal_entries je ON je.tenant_id = p.tenant_id AND je.id = p.journal_entry_id
              WHERE p.tenant_id = :t AND p.id = :id FOR UPDATE'
        );
        $paymentStmt->execute(['t' => $tenantId, 'id' => $paymentId]);
        $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);
        if (!$payment || $payment['voided_at'] !== null || $payment['je_status'] !== 'posted'
            || !$payment['bank_account_id']
            || str_starts_with((string) ($payment['external_id'] ?? ''), 'bank-line:')
            || $payment['source_module'] !== 'billing'
            || $payment['source_ref_type'] !== 'billing_payment'
            || (int) $payment['source_ref_id'] !== $paymentId) {
            throw new RuntimeException('Choose an active, posted customer deposit.');
        }
        $priorStmt = $pdo->prepare(
            'SELECT a.*, je.status AS je_status, alloc.reversed_at AS allocation_reversed_at,
                    alloc.application_je_id, alloc.amount_applied
               FROM billing_deposit_applications a
               JOIN accounting_journal_entries je ON je.tenant_id = a.tenant_id AND je.id = a.journal_entry_id
               JOIN billing_payment_allocations alloc ON alloc.id = a.allocation_id
              WHERE a.tenant_id = :t AND a.payment_id = :p AND a.request_key = :k LIMIT 1'
        );
        $priorStmt->execute(['t' => $tenantId, 'p' => $paymentId, 'k' => $requestKey]);
        $prior = $priorStmt->fetch(PDO::FETCH_ASSOC);
        if ($prior) {
            if ((int) $prior['invoice_id'] !== $invoiceId
                || abs((float) $prior['amount'] - $amount) > 0.005
                || $prior['applied_at'] !== $appliedAt
                || $prior['je_status'] !== 'posted' || $prior['reversed_at'] !== null
                || $prior['allocation_reversed_at'] !== null
                || (int) $prior['application_je_id'] !== (int) $prior['journal_entry_id']
                || abs((float) $prior['amount_applied'] - $amount) > 0.005) {
                throw new RuntimeException('This deposit application request was already used differently.');
            }
            $pdo->commit();
            return ['application_id' => (int) $prior['id'], 'payment_id' => $paymentId,
                'invoice_id' => $invoiceId, 'journal_entry_id' => (int) $prior['journal_entry_id'],
                'unallocated_remaining' => (float) $payment['unallocated_amount'],
                'idempotent_replay' => true, 'pwp' => []];
        }
        if ((float) $payment['unallocated_amount'] + 0.005 < $amount) {
            throw new RuntimeException('The amount exceeds this customer deposit balance.');
        }
        if ($appliedAt < (string) $payment['received_at']) {
            throw new RuntimeException('Apply the deposit on or after its receipt date.');
        }
        billingRequireCustomerDepositAccount($tenantId);
        $bankStmt = $pdo->prepare(
            'SELECT entity_id, currency FROM accounting_bank_accounts WHERE tenant_id = :t AND id = :id'
        );
        $bankStmt->execute(['t' => $tenantId, 'id' => (int) $payment['bank_account_id']]);
        $bank = $bankStmt->fetch(PDO::FETCH_ASSOC);
        $entityId = (int) $payment['je_entity_id'];
        if (!$bank || $entityId <= 0
            || (!empty($bank['entity_id']) && (int) $bank['entity_id'] !== $entityId)) {
            throw new RuntimeException('The deposit bank and journal legal entities disagree.');
        }
        $invoiceStmt = $pdo->prepare(
            'SELECT i.*, je.status AS je_status, je.entity_id AS je_entity_id
               FROM billing_invoices i
               JOIN accounting_journal_entries je ON je.tenant_id = i.tenant_id AND je.id = i.journal_entry_id
              WHERE i.tenant_id = :t AND i.id = :id FOR UPDATE'
        );
        $invoiceStmt->execute(['t' => $tenantId, 'id' => $invoiceId]);
        $invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice || $invoice['je_status'] !== 'posted'
            || !in_array($invoice['status'], ['approved', 'sent', 'partially_paid'], true)
            || (int) $invoice['je_entity_id'] !== $entityId
            || (!empty($invoice['entity_id']) && (int) $invoice['entity_id'] !== $entityId)
            || strcasecmp((string) $invoice['currency'], (string) $payment['currency']) !== 0
            || strcasecmp(trim((string) $invoice['client_name']), trim((string) $payment['client_name'])) !== 0
            || (string) $invoice['issue_date'] > $appliedAt
            || (float) $invoice['amount_due'] + 0.005 < $amount) {
            throw new RuntimeException('Choose an open, posted invoice for this client, currency and legal entity.');
        }

        $allocation = billingAllocatePayment($paymentId, [
            'allocations' => [['invoice_id' => $invoiceId, 'amount' => $amount]],
            'allocation_date' => $appliedAt,
            'defer_pwp' => true,
        ], $actorUserId);
        $applied = $allocation['applied'][0] ?? null;
        if (!$applied || abs((float) $applied['amount_applied'] - $amount) > 0.005) {
            throw new RuntimeException('Deposit application did not allocate the full requested amount.');
        }
        $clientDimension = !empty($invoice['client_company_id'])
            ? (int) $invoice['client_company_id']
            : 'name:' . strtolower(trim((string) $invoice['client_name']));
        $event = accountingProcessEvent($tenantId, [
            'entity_id' => $entityId,
            'event_type' => 'billing.customer_deposit.applied',
            'source_module' => 'billing',
            'source_record_id' => 'deposit_apply:' . $paymentId . ':' . $requestKey,
            'event_date' => $appliedAt,
            'parent_event_id' => billingReceiptParentEventId($tenantId, $paymentId),
            'lineage_relationship' => 'applies_to',
            'payload' => [
                'payment_id' => $paymentId, 'invoice_id' => $invoiceId,
                'amount' => $amount, 'currency' => (string) $payment['currency'],
                'request_key' => $requestKey,
                'source_ref_type' => 'billing_deposit_application', 'source_ref_id' => $paymentId,
                'memo' => 'Apply customer deposit / ' . $invoice['invoice_number'],
                'lines' => [
                    ['account_code' => '2300', 'debit' => $amount, 'credit' => 0,
                        'dims' => ['legal_entity' => $entityId, 'client' => $clientDimension]],
                    ['account_code' => '1100', 'debit' => 0, 'credit' => $amount,
                        'counterparty_company_id' => $invoice['client_company_id'] ?? null,
                        'dims' => ['legal_entity' => $entityId, 'client' => $clientDimension]],
                ],
            ],
        ], $actorUserId);
        if (($event['status'] ?? '') !== 'posted') {
            throw new RuntimeException('Deposit application could not be posted: ' . ($event['error'] ?? 'no posting rule matched'));
        }
        if (!empty($event['idempotent_replay'])) {
            throw new RuntimeException('Deposit application journal already exists without its source link.');
        }
        $jeId = (int) $event['journal_entry_id'];
        $pdo->prepare(
            'UPDATE billing_payment_allocations SET application_je_id = :je WHERE id = :id AND payment_id = :p'
        )->execute(['je' => $jeId, 'id' => (int) $applied['allocation_id'], 'p' => $paymentId]);
        $pdo->prepare(
            'INSERT INTO billing_deposit_applications
                (tenant_id, payment_id, invoice_id, allocation_id, amount, request_key,
                 journal_entry_id, applied_at, created_by_user_id)
             VALUES (:t, :p, :i, :a, :amount, :k, :je, :date, :actor)'
        )->execute(['t' => $tenantId, 'p' => $paymentId, 'i' => $invoiceId,
            'a' => (int) $applied['allocation_id'], 'amount' => $amount,
            'k' => $requestKey, 'je' => $jeId, 'date' => $appliedAt, 'actor' => $actorUserId]);
        $applicationId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO accounting_subledger_links
                (tenant_id, source_module, source_record_id, journal_entry_id, link_kind)
             VALUES (:t, "billing", :ref, :je, "application")'
        )->execute(['t' => $tenantId, 'ref' => 'payment:' . $paymentId, 'je' => $jeId]);
        $pdo->commit();
        $pwp = billingReleasePayWhenPaidForAllocations($tenantId, $allocation['applied'], $actorUserId);
        return ['application_id' => $applicationId, 'payment_id' => $paymentId,
            'invoice_id' => $invoiceId, 'journal_entry_id' => $jeId,
            'unallocated_remaining' => (float) $allocation['unallocated_remaining'],
            'idempotent_replay' => false, 'pwp' => $pwp];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Record a refund already sent to the customer; this does not initiate a transfer. */
function billingRecordCustomerDepositRefund(int $tenantId, int $paymentId, array $request, ?int $actorUserId): array
{
    $bankAccountId = (int) ($request['bank_account_id'] ?? 0);
    $amount = round((float) ($request['amount'] ?? 0), 2);
    $refundedAt = (string) ($request['refunded_at'] ?? '');
    $requestKey = trim((string) ($request['request_key'] ?? ''));
    $reference = trim((string) ($request['reference'] ?? ''));
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $refundedAt);
    if ($paymentId <= 0 || $bankAccountId <= 0 || !is_finite($amount) || $amount <= 0) {
        throw new InvalidArgumentException('Choose a bank account and a positive refund amount.');
    }
    if (!$date || $date->format('Y-m-d') !== $refundedAt) {
        throw new InvalidArgumentException('Enter a valid refund date.');
    }
    if (!preg_match('/^[A-Za-z0-9_-]{8,80}$/', $requestKey) || strlen($reference) > 255) {
        throw new InvalidArgumentException('A unique refund request key and a short reference are required.');
    }

    $pdo = getDB();
    if ($pdo->inTransaction()) throw new RuntimeException('A refund requires its own transaction.');
    cf_begin_transaction();
    try {
        $paymentStmt = $pdo->prepare(
            'SELECT p.*, je.status AS je_status, je.entity_id AS je_entity_id,
                    je.source_module, je.source_ref_type, je.source_ref_id
               FROM billing_payments p
               JOIN accounting_journal_entries je ON je.tenant_id = p.tenant_id AND je.id = p.journal_entry_id
              WHERE p.tenant_id = :t AND p.id = :p FOR UPDATE'
        );
        $paymentStmt->execute(['t' => $tenantId, 'p' => $paymentId]);
        $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);
        if (!$payment || $payment['voided_at'] !== null || $payment['je_status'] !== 'posted'
            || str_starts_with((string) ($payment['external_id'] ?? ''), 'bank-line:')
            || $payment['source_module'] !== 'billing'
            || $payment['source_ref_type'] !== 'billing_payment'
            || (int) $payment['source_ref_id'] !== $paymentId) {
            throw new RuntimeException('Choose an active customer deposit posted from Billing.');
        }
        $priorStmt = $pdo->prepare(
            'SELECT r.*, je.status AS je_status FROM billing_deposit_refunds r
               JOIN accounting_journal_entries je ON je.tenant_id = r.tenant_id AND je.id = r.journal_entry_id
              WHERE r.tenant_id = :t AND r.payment_id = :p AND r.request_key = :k LIMIT 1'
        );
        $priorStmt->execute(['t' => $tenantId, 'p' => $paymentId, 'k' => $requestKey]);
        $prior = $priorStmt->fetch(PDO::FETCH_ASSOC);
        if ($prior) {
            if ((int) $prior['bank_account_id'] !== $bankAccountId
                || abs((float) $prior['amount'] - $amount) > 0.005
                || $prior['refunded_at'] !== $refundedAt
                || (string) ($prior['reference'] ?? '') !== $reference
                || $prior['je_status'] !== 'posted' || $prior['reversed_at'] !== null) {
                throw new RuntimeException('This refund request was already used differently.');
            }
            $pdo->commit();
            return ['refund_id' => (int) $prior['id'], 'payment_id' => $paymentId,
                'journal_entry_id' => (int) $prior['journal_entry_id'],
                'unallocated_remaining' => (float) $payment['unallocated_amount'],
                'idempotent_replay' => true];
        }
        if ($refundedAt < (string) $payment['received_at']) {
            throw new RuntimeException('Refund date cannot precede the original receipt.');
        }
        if ((float) $payment['unallocated_amount'] + 0.005 < $amount) {
            throw new RuntimeException('The refund exceeds the unapplied customer deposit.');
        }
        billingRequireCustomerDepositAccount($tenantId);
        $bankStmt = $pdo->prepare(
            'SELECT id, entity_id, gl_account_code, currency FROM accounting_bank_accounts
              WHERE tenant_id = :t AND id = :id AND status = "active" FOR UPDATE'
        );
        $bankStmt->execute(['t' => $tenantId, 'id' => $bankAccountId]);
        $bank = $bankStmt->fetch(PDO::FETCH_ASSOC);
        $entityId = (int) $payment['je_entity_id'];
        if (!$bank || $entityId <= 0 || (int) $bank['entity_id'] !== $entityId
            || strcasecmp((string) $bank['currency'], (string) $payment['currency']) !== 0) {
            throw new RuntimeException('Choose an active bank account in the deposit currency and legal entity.');
        }
        $closedStmt = $pdo->prepare(
            'SELECT id FROM accounting_reconciliations WHERE tenant_id = :t AND bank_account_id = :b
                AND status = "closed" AND period_end >= :date LIMIT 1'
        );
        $closedStmt->execute(['t' => $tenantId, 'b' => $bankAccountId, 'date' => $refundedAt]);
        if ($closedStmt->fetchColumn()) {
            throw new RuntimeException('This bank account has a closed reconciliation on or after the refund date.');
        }

        $liabilityStmt = $pdo->prepare(
            'SELECT ROUND(COALESCE(SUM(l.credit - l.debit), 0), 2)
               FROM accounting_journal_entry_lines l
               JOIN accounting_accounts a ON a.id = l.account_id AND a.tenant_id = l.tenant_id
              WHERE l.tenant_id = :t AND l.je_id = :je AND a.code = "2300"'
        );
        $liabilityStmt->execute(['t' => $tenantId, 'je' => (int) $payment['journal_entry_id']]);
        $originalDeposit = (float) $liabilityStmt->fetchColumn();
        $usedStmt = $pdo->prepare(
            'SELECT
                (SELECT COALESCE(SUM(amount), 0) FROM billing_deposit_applications
                  WHERE tenant_id = :ta AND payment_id = :pa AND reversed_at IS NULL) AS applied,
                (SELECT COALESCE(SUM(amount), 0) FROM billing_deposit_refunds
                  WHERE tenant_id = :tr AND payment_id = :pr AND reversed_at IS NULL) AS refunded'
        );
        $usedStmt->execute(['ta' => $tenantId, 'pa' => $paymentId,
            'tr' => $tenantId, 'pr' => $paymentId]);
        $used = $usedStmt->fetch(PDO::FETCH_ASSOC);
        if (abs($originalDeposit - (float) $used['applied'] - (float) $used['refunded']
            - (float) $payment['unallocated_amount']) > 0.005) {
            throw new RuntimeException('The customer-deposit liability and available balance disagree. Review before refunding.');
        }

        $clientDimension = 'name:' . strtolower(trim((string) $payment['client_name']));
        $event = accountingProcessEvent($tenantId, [
            'entity_id' => $entityId,
            'event_type' => 'billing.customer_deposit.refunded',
            'source_module' => 'billing',
            'source_record_id' => 'deposit_refund:' . $paymentId . ':' . $requestKey,
            'event_date' => $refundedAt,
            'parent_event_id' => billingReceiptParentEventId($tenantId, $paymentId),
            'lineage_relationship' => 'spawned_by',
            'payload' => [
                'payment_id' => $paymentId, 'bank_account_id' => $bankAccountId,
                'amount' => $amount, 'currency' => (string) $payment['currency'],
                'request_key' => $requestKey, 'reference' => $reference,
                'source_ref_type' => 'billing_deposit_refund', 'source_ref_id' => $paymentId,
                'memo' => 'Customer deposit refund / ' . $payment['client_name'],
                'lines' => [
                    ['account_code' => '2300', 'debit' => $amount, 'credit' => 0,
                        'dims' => ['legal_entity' => $entityId, 'client' => $clientDimension]],
                    ['account_code' => (string) $bank['gl_account_code'], 'debit' => 0, 'credit' => $amount,
                        'memo' => $reference ?: 'Customer deposit refund',
                        'dims' => ['legal_entity' => $entityId]],
                ],
            ],
        ], $actorUserId);
        if (($event['status'] ?? '') !== 'posted') {
            throw new RuntimeException('Deposit refund could not be posted: ' . ($event['error'] ?? 'no posting rule matched'));
        }
        if (!empty($event['idempotent_replay'])) {
            throw new RuntimeException('Refund journal already exists without its source link.');
        }
        $journalId = (int) $event['journal_entry_id'];
        $save = $pdo->prepare(
            'UPDATE billing_payments SET unallocated_amount = ROUND(unallocated_amount - :amount, 2)
              WHERE tenant_id = :t AND id = :p AND voided_at IS NULL AND unallocated_amount >= :minimum'
        );
        $save->execute(['amount' => $amount, 't' => $tenantId, 'p' => $paymentId, 'minimum' => $amount]);
        if ($save->rowCount() !== 1) throw new RuntimeException('Deposit balance changed while recording the refund.');
        $pdo->prepare(
            'INSERT INTO billing_deposit_refunds
                (tenant_id, payment_id, bank_account_id, amount, currency, refunded_at,
                 reference, request_key, journal_entry_id, created_by_user_id)
             VALUES (:t, :p, :b, :amount, :currency, :date, :reference, :key, :je, :actor)'
        )->execute(['t' => $tenantId, 'p' => $paymentId, 'b' => $bankAccountId,
            'amount' => $amount, 'currency' => (string) $payment['currency'],
            'date' => $refundedAt, 'reference' => $reference ?: null,
            'key' => $requestKey, 'je' => $journalId, 'actor' => $actorUserId]);
        $refundId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO accounting_subledger_links
                (tenant_id, source_module, source_record_id, journal_entry_id, link_kind)
             VALUES (:t, "billing", :ref, :je, "refund")'
        )->execute(['t' => $tenantId, 'ref' => 'payment:' . $paymentId,
            'je' => $journalId]);
        $pdo->commit();
        return ['refund_id' => $refundId, 'payment_id' => $paymentId,
            'journal_entry_id' => $journalId,
            'unallocated_remaining' => round((float) $payment['unallocated_amount'] - $amount, 2),
            'idempotent_replay' => false];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Undo an erroneous deposit application without changing the original cash receipt. */
function billingCorrectCustomerDepositApplication(int $tenantId, int $applicationId, string $reason, ?int $actorUserId): array
{
    $reason = trim($reason);
    if ($applicationId <= 0 || $reason === '' || strlen($reason) > 500) {
        throw new InvalidArgumentException('Choose an application and enter a correction reason (up to 500 characters).');
    }
    $pdo = getDB();
    if ($pdo->inTransaction()) throw new RuntimeException('A deposit correction requires its own transaction.');
    cf_begin_transaction();
    try {
        $identityStmt = $pdo->prepare(
            'SELECT payment_id FROM billing_deposit_applications WHERE tenant_id = :t AND id = :id'
        );
        $identityStmt->execute(['t' => $tenantId, 'id' => $applicationId]);
        $paymentId = (int) $identityStmt->fetchColumn();
        if ($paymentId <= 0) throw new RuntimeException('Deposit application not found.');
        $paymentStmt = $pdo->prepare(
            'SELECT id, amount, unallocated_amount, voided_at FROM billing_payments
              WHERE tenant_id = :t AND id = :p FOR UPDATE'
        );
        $paymentStmt->execute(['t' => $tenantId, 'p' => $paymentId]);
        $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);
        if (!$payment || $payment['voided_at'] !== null) throw new RuntimeException('The original receipt is no longer active.');
        $applicationStmt = $pdo->prepare(
            'SELECT a.*, je.status AS je_status, je.source_module, je.source_ref_type, je.source_ref_id
               FROM billing_deposit_applications a
               JOIN accounting_journal_entries je ON je.tenant_id = a.tenant_id AND je.id = a.journal_entry_id
              WHERE a.tenant_id = :t AND a.id = :id AND a.payment_id = :p FOR UPDATE'
        );
        $applicationStmt->execute(['t' => $tenantId, 'id' => $applicationId, 'p' => $paymentId]);
        $application = $applicationStmt->fetch(PDO::FETCH_ASSOC);
        if (!$application || $application['reversed_at'] !== null || $application['je_status'] !== 'posted'
            || $application['source_module'] !== 'billing'
            || $application['source_ref_type'] !== 'billing_deposit_application'
            || (int) $application['source_ref_id'] !== $paymentId) {
            throw new RuntimeException('This deposit application is not active.');
        }
        $invoiceStmt = $pdo->prepare(
            'SELECT id, total, amount_paid, amount_due, status, sent_at FROM billing_invoices
              WHERE tenant_id = :t AND id = :id FOR UPDATE'
        );
        $invoiceStmt->execute(['t' => $tenantId, 'id' => (int) $application['invoice_id']]);
        $invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);
        $amount = (float) $application['amount'];
        if (!$invoice || in_array($invoice['status'], ['draft', 'void'], true)
            || (float) $invoice['amount_paid'] + 0.005 < $amount
            || abs((float) $invoice['total'] - (float) $invoice['amount_paid']
                - (float) $invoice['amount_due']) > 0.005
            || (float) $payment['unallocated_amount'] + $amount > (float) $payment['amount'] + 0.005) {
            throw new RuntimeException('Invoice and deposit balances changed. Review before correcting the application.');
        }
        $allocationStmt = $pdo->prepare(
            'SELECT * FROM billing_payment_allocations WHERE id = :id AND payment_id = :p FOR UPDATE'
        );
        $allocationStmt->execute(['id' => (int) $application['allocation_id'], 'p' => $paymentId]);
        $allocation = $allocationStmt->fetch(PDO::FETCH_ASSOC);
        if (!$allocation || $allocation['reversed_at'] !== null
            || (int) $allocation['invoice_id'] !== (int) $invoice['id']
            || (int) $allocation['application_je_id'] !== (int) $application['journal_entry_id']
            || abs((float) $allocation['amount_applied'] - $amount) > 0.005) {
            throw new RuntimeException('The invoice allocation and application journal disagree.');
        }
        $pwpStmt = $pdo->prepare(
            'SELECT id FROM ap_bills WHERE tenant_id = :t AND linked_ar_invoice_id = :i
                AND pwp_status IN ("triggered", "partial_triggered") LIMIT 1'
        );
        $pwpStmt->execute(['t' => $tenantId, 'i' => (int) $invoice['id']]);
        if ($pwpStmt->fetchColumn()) {
            throw new RuntimeException('Pay-when-paid bills were released by this invoice. Correct those bills first.');
        }
        $eventId = billingReceiptEventForCorrection($tenantId, 'billing.customer_deposit.applied',
            'deposit_apply:' . $paymentId . ':' . (string) $application['request_key'],
            (int) $application['journal_entry_id']);
        $reversal = accountingReverseJe($tenantId, (int) $application['journal_entry_id'], $reason, $actorUserId);
        if (!empty($reversal['idempotent_replay'])) throw new RuntimeException('Application journal was already reversed.');
        billingReverseReceiptEvent($tenantId, $eventId);
        $reversalId = (int) $reversal['je_id'];
        $reverseAllocation = $pdo->prepare(
            'UPDATE billing_payment_allocations
                SET reversed_at = NOW(), reversal_je_id = :je, reversed_by_user_id = :actor
              WHERE id = :id AND payment_id = :p AND reversed_at IS NULL'
        );
        $reverseAllocation->execute(['je' => $reversalId, 'actor' => $actorUserId,
            'id' => (int) $allocation['id'], 'p' => $paymentId]);
        if ($reverseAllocation->rowCount() !== 1) throw new RuntimeException('Allocation changed while correcting.');
        $newPaid = max(0, round((float) $invoice['amount_paid'] - $amount, 2));
        $newDue = max(0, round((float) $invoice['total'] - $newPaid, 2));
        $newStatus = $newDue < 0.005 ? 'paid'
            : ($newPaid > 0 ? 'partially_paid' : ($invoice['sent_at'] ? 'sent' : 'approved'));
        $pdo->prepare(
            'UPDATE billing_invoices SET amount_paid = :paid, amount_due = :due, status = :status
              WHERE tenant_id = :t AND id = :id'
        )->execute(['paid' => $newPaid, 'due' => $newDue, 'status' => $newStatus,
            't' => $tenantId, 'id' => (int) $invoice['id']]);
        $pdo->prepare(
            'UPDATE billing_payments SET unallocated_amount = ROUND(unallocated_amount + :amount, 2)
              WHERE tenant_id = :t AND id = :p'
        )->execute(['amount' => $amount, 't' => $tenantId, 'p' => $paymentId]);
        $pdo->prepare(
            'UPDATE billing_deposit_applications
                SET reversed_at = NOW(), reversal_je_id = :je, reversal_reason = :reason,
                    reversed_by_user_id = :actor
              WHERE tenant_id = :t AND id = :id AND reversed_at IS NULL'
        )->execute(['je' => $reversalId, 'reason' => $reason, 'actor' => $actorUserId,
            't' => $tenantId, 'id' => $applicationId]);
        $pdo->prepare(
            'INSERT INTO accounting_subledger_links
                (tenant_id, source_module, source_record_id, journal_entry_id, link_kind)
             VALUES (:t, "billing", :ref, :je, "reversal")'
        )->execute(['t' => $tenantId, 'ref' => 'payment:' . $paymentId, 'je' => $reversalId]);
        $pdo->commit();
        return ['application_id' => $applicationId, 'payment_id' => $paymentId,
            'invoice_id' => (int) $invoice['id'], 'reversal_je_id' => $reversalId,
            'invoice_amount_due' => $newDue,
            'unallocated_remaining' => round((float) $payment['unallocated_amount'] + $amount, 2)];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Undo an erroneous refund record; a real returned payment needs a new receipt instead. */
function billingCorrectCustomerDepositRefund(int $tenantId, int $refundId, string $reason, ?int $actorUserId): array
{
    $reason = trim($reason);
    if ($refundId <= 0 || $reason === '' || strlen($reason) > 500) {
        throw new InvalidArgumentException('Choose a refund and enter a correction reason (up to 500 characters).');
    }
    $pdo = getDB();
    if ($pdo->inTransaction()) throw new RuntimeException('A refund correction requires its own transaction.');
    cf_begin_transaction();
    try {
        $identityStmt = $pdo->prepare('SELECT payment_id FROM billing_deposit_refunds WHERE tenant_id = :t AND id = :id');
        $identityStmt->execute(['t' => $tenantId, 'id' => $refundId]);
        $paymentId = (int) $identityStmt->fetchColumn();
        if ($paymentId <= 0) throw new RuntimeException('Deposit refund not found.');
        $paymentStmt = $pdo->prepare(
            'SELECT id, amount, unallocated_amount, voided_at FROM billing_payments
              WHERE tenant_id = :t AND id = :p FOR UPDATE'
        );
        $paymentStmt->execute(['t' => $tenantId, 'p' => $paymentId]);
        $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);
        if (!$payment || $payment['voided_at'] !== null) throw new RuntimeException('The original receipt is no longer active.');
        $refundStmt = $pdo->prepare(
            'SELECT r.*, je.status AS je_status, je.source_module, je.source_ref_type, je.source_ref_id
               FROM billing_deposit_refunds r
               JOIN accounting_journal_entries je ON je.tenant_id = r.tenant_id AND je.id = r.journal_entry_id
              WHERE r.tenant_id = :t AND r.id = :id AND r.payment_id = :p FOR UPDATE'
        );
        $refundStmt->execute(['t' => $tenantId, 'id' => $refundId, 'p' => $paymentId]);
        $refund = $refundStmt->fetch(PDO::FETCH_ASSOC);
        if (!$refund || $refund['reversed_at'] !== null || $refund['je_status'] !== 'posted'
            || $refund['source_module'] !== 'billing'
            || $refund['source_ref_type'] !== 'billing_deposit_refund'
            || (int) $refund['source_ref_id'] !== $paymentId
            || (float) $payment['unallocated_amount'] + (float) $refund['amount']
                > (float) $payment['amount'] + 0.005) {
            throw new RuntimeException('This refund is not an active, consistent deposit outflow.');
        }
        $closedStmt = $pdo->prepare(
            'SELECT id FROM accounting_reconciliations WHERE tenant_id = :t AND bank_account_id = :b
                AND status = "closed" AND period_end >= :date LIMIT 1'
        );
        $closedStmt->execute(['t' => $tenantId, 'b' => (int) $refund['bank_account_id'],
            'date' => (string) $refund['refunded_at']]);
        if ($closedStmt->fetchColumn()) {
            throw new RuntimeException('Refund is in a closed bank reconciliation. Reopen it before correcting.');
        }
        $matchedStmt = $pdo->prepare(
            'SELECT id, bank_account_id FROM accounting_bank_statement_lines
              WHERE tenant_id = :t AND matched_je_id = :je AND match_status = "matched" FOR UPDATE'
        );
        $matchedStmt->execute(['t' => $tenantId, 'je' => (int) $refund['journal_entry_id']]);
        $matched = $matchedStmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($matched) > 1
            || ($matched && (int) $matched[0]['bank_account_id'] !== (int) $refund['bank_account_id'])) {
            throw new RuntimeException('Refund has inconsistent bank matches. Review before correcting.');
        }
        $eventId = billingReceiptEventForCorrection($tenantId, 'billing.customer_deposit.refunded',
            'deposit_refund:' . $paymentId . ':' . (string) $refund['request_key'],
            (int) $refund['journal_entry_id']);
        $reversal = accountingReverseJe($tenantId, (int) $refund['journal_entry_id'], $reason, $actorUserId);
        if (!empty($reversal['idempotent_replay'])) throw new RuntimeException('Refund journal was already reversed.');
        billingReverseReceiptEvent($tenantId, $eventId);
        $reversalId = (int) $reversal['je_id'];
        $pdo->prepare(
            'UPDATE billing_payments SET unallocated_amount = ROUND(unallocated_amount + :amount, 2)
              WHERE tenant_id = :t AND id = :p'
        )->execute(['amount' => (float) $refund['amount'], 't' => $tenantId, 'p' => $paymentId]);
        $save = $pdo->prepare(
            'UPDATE billing_deposit_refunds
                SET reversed_at = NOW(), reversal_je_id = :je, reversal_reason = :reason,
                    reversed_by_user_id = :actor
              WHERE tenant_id = :t AND id = :id AND reversed_at IS NULL'
        );
        $save->execute(['je' => $reversalId, 'reason' => $reason, 'actor' => $actorUserId,
            't' => $tenantId, 'id' => $refundId]);
        if ($save->rowCount() !== 1) throw new RuntimeException('Refund changed while correcting.');
        if ($matched) {
            $reopen = $pdo->prepare(
                'UPDATE accounting_bank_statement_lines
                    SET match_status = "unmatched", matched_je_id = NULL, matched_at = NULL,
                        matched_by_user_id = NULL, updated_at = NOW()
                  WHERE tenant_id = :t AND id = :id AND matched_je_id = :je AND match_status = "matched"'
            );
            $reopen->execute(['t' => $tenantId, 'id' => (int) $matched[0]['id'],
                'je' => (int) $refund['journal_entry_id']]);
            if ($reopen->rowCount() !== 1) throw new RuntimeException('Bank match changed while correcting.');
        }
        $pdo->prepare(
            'INSERT INTO accounting_subledger_links
                (tenant_id, source_module, source_record_id, journal_entry_id, link_kind)
             VALUES (:t, "billing", :ref, :je, "reversal")'
        )->execute(['t' => $tenantId, 'ref' => 'payment:' . $paymentId, 'je' => $reversalId]);
        $pdo->commit();
        return ['refund_id' => $refundId, 'payment_id' => $paymentId,
            'reversal_je_id' => $reversalId,
            'reopened_bank_line_id' => $matched ? (int) $matched[0]['id'] : null,
            'unallocated_remaining' => round((float) $payment['unallocated_amount'] + (float) $refund['amount'], 2)];
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
        $applicationStmt = $pdo->prepare(
            'SELECT id FROM billing_deposit_applications
              WHERE tenant_id = :t AND payment_id = :p AND reversed_at IS NULL LIMIT 1'
        );
        $applicationStmt->execute(['t' => $tenantId, 'p' => $paymentId]);
        if ($applicationStmt->fetchColumn()) {
            throw new RuntimeException('This deposit has later invoice applications. Reverse those applications before correcting the original receipt.');
        }
        $refundStmt = $pdo->prepare(
            'SELECT id FROM billing_deposit_refunds
              WHERE tenant_id = :t AND payment_id = :p AND reversed_at IS NULL LIMIT 1'
        );
        $refundStmt->execute(['t' => $tenantId, 'p' => $paymentId]);
        if ($refundStmt->fetchColumn()) {
            throw new RuntimeException('This deposit has a recorded cash refund and cannot be corrected as one receipt.');
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
        $unapplied = round((float) $payment['unallocated_amount'], 2);
        if ($unapplied < -0.005 || abs($total + $unapplied - (float) $payment['amount']) > 0.005) {
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
        $depositStmt = $pdo->prepare(
            'SELECT ROUND(COALESCE(SUM(l.credit - l.debit), 0), 2)
               FROM accounting_journal_entry_lines l
               JOIN accounting_accounts a ON a.id = l.account_id AND a.tenant_id = l.tenant_id
              WHERE l.tenant_id = :tenant_id AND l.je_id = :je_id AND a.code = "2300"'
        );
        $depositStmt->execute(['tenant_id' => $tenantId, 'je_id' => (int) $payment['journal_entry_id']]);
        if (abs((float) $depositStmt->fetchColumn() - $unapplied) > 0.005) {
            throw new RuntimeException('The customer-deposit liability and unapplied balance disagree.');
        }

        ksort($amountsByInvoice, SORT_NUMERIC);
        $invoiceIds = array_keys($amountsByInvoice);
        $invoices = [];
        if ($invoiceIds) {
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
        }
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

        $eventId = billingReceiptEventForCorrection($tenantId, 'billing.manual_receipt.posted',
            'payment:' . $paymentId, (int) $payment['journal_entry_id']);
        $reversal = accountingReverseJe(
            $tenantId, (int) $payment['journal_entry_id'], $reason, $actorUserId
        );
        if (!empty($reversal['idempotent_replay'])) throw new RuntimeException('The receipt journal was already reversed.');
        billingReverseReceiptEvent($tenantId, $eventId);
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
