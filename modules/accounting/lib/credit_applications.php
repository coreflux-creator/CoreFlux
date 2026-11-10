<?php
declare(strict_types=1);

require_once __DIR__ . '/opening_balances.php';

/** There is no new GL posting: both documents already accrued to the same control.
 * Keep settlement separate from cash, with dated, reversible source evidence. */
function accountingCreditSpec(string $side): array
{
    return match ($side) {
        'ar' => ['billing_invoices', 'client_company_id', 'client_name', 'invoice_number', 'issue_date'],
        'ap' => ['ap_bills', 'vendor_company_id', 'vendor_name', 'bill_number', 'bill_date'],
        default => throw new InvalidArgumentException('Choose customer or vendor credits.'),
    };
}

function accountingCreditDate(int $tenantId, int $entityId, string $date, bool $lock = false): void
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date || $date > date('Y-m-d')) {
        throw new InvalidArgumentException('Enter a valid application date no later than today.');
    }
    $period = accountingResolvePeriod($tenantId, $entityId, $date, $lock);
    if ($period['status'] !== 'open') throw new InvalidArgumentException('Choose a date in an open accounting period.');
}

function accountingCreditList(PDO $pdo, int $tenantId, int $entityId, string $side): array
{
    [$table, , $party, $number, $date] = accountingCreditSpec($side);
    $q = $pdo->prepare("SELECT d.id, d.$party AS party, d.$number AS number, d.$date AS document_date,
            d.currency, d.total, d.amount_paid, d.amount_credited, d.amount_due, d.opening_cutover_id
        FROM $table d JOIN accounting_journal_entries je ON je.id = d.journal_entry_id
            AND je.tenant_id = d.tenant_id AND je.status = 'posted'
        WHERE d.tenant_id = :t AND d.entity_id = :e AND d.status NOT IN ('draft','void','disputed')
            AND d.amount_due <> 0 ORDER BY d.$party, d.$date, d.id");
    $q->execute(['t' => $tenantId, 'e' => $entityId]);
    $h = $pdo->prepare("SELECT a.*, c.legal_name AS entity_name,
        credit.$number AS credit_number, document.$number AS document_number,
        credit.$party AS party, credit.currency
        FROM accounting_credit_applications a
        JOIN accounting_entities c ON c.id = a.entity_id AND c.tenant_id = a.tenant_id
        JOIN $table credit ON credit.tenant_id = a.tenant_id AND credit.id = a.credit_id
        JOIN $table document ON document.tenant_id = a.tenant_id AND document.id = a.document_id
        WHERE a.tenant_id = :t AND a.entity_id = :e AND a.side = :side ORDER BY a.id DESC");
    $h->execute(['t' => $tenantId, 'e' => $entityId, 'side' => $side]);
    return ['documents' => $q->fetchAll(PDO::FETCH_ASSOC), 'applications' => $h->fetchAll(PDO::FETCH_ASSOC)];
}

function accountingCreditReview(PDO $pdo, int $tenantId, array $request, bool $lock = false): array
{
    $side = (string) ($request['side'] ?? '');
    [$table, $partyId, $partyName, $number, $dateColumn] = accountingCreditSpec($side);
    $entityId = (int) ($request['entity_id'] ?? 0);
    $creditId = (int) ($request['credit_id'] ?? 0);
    $documentId = (int) ($request['document_id'] ?? 0);
    if ($creditId <= 0 || $documentId <= 0 || $creditId === $documentId) {
        throw new InvalidArgumentException('Choose a credit and a different invoice or bill.');
    }
    $entity = $pdo->prepare('SELECT id FROM accounting_entities WHERE tenant_id = :t AND id = :e AND active = 1'
        . ($lock ? ' FOR UPDATE' : ''));
    $entity->execute(['t' => $tenantId, 'e' => $entityId]);
    if (!$entity->fetchColumn()) throw new InvalidArgumentException('Choose an active legal entity.');
    $date = (string) ($request['application_date'] ?? '');
    accountingCreditDate($tenantId, $entityId, $date, $lock);
    $amount = accountingOpeningSignedCents((string) ($request['amount'] ?? ''));
    if ($amount <= 0) throw new InvalidArgumentException('Enter a positive amount with at most two decimal places.');
    $query = $pdo->prepare("SELECT d.*, je.posting_date AS ledger_date, je.status AS ledger_status,
            je.entity_id AS ledger_entity, je.currency AS ledger_currency
        FROM $table d JOIN accounting_journal_entries je
          ON je.id = d.journal_entry_id AND je.tenant_id = d.tenant_id
        WHERE d.tenant_id = :t AND d.entity_id = :e AND d.id IN (:c,:d)
        ORDER BY d.id" . ($lock ? ' FOR UPDATE' : ''));
    $query->execute(['t' => $tenantId, 'e' => $entityId, 'c' => $creditId, 'd' => $documentId]);
    $rows = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) $rows[(int) $row['id']] = $row;
    $credit = $rows[$creditId] ?? null;
    $document = $rows[$documentId] ?? null;
    if (!$credit || !$document || empty($credit['opening_cutover_id']) || (float) $credit['total'] >= 0
        || (float) $document['total'] <= 0) {
        throw new InvalidArgumentException('Choose a carried-forward credit and a positive invoice or bill in this entity.');
    }
    if (empty($credit[$partyId]) || (int) $credit[$partyId] !== (int) $document[$partyId]
        || $credit['currency'] !== $document['currency']) {
        throw new InvalidArgumentException('Both documents must belong to the same customer or vendor and currency.');
    }
    foreach ([$credit, $document] as $row) {
        if ($row['ledger_status'] !== 'posted' || (int) $row['ledger_entity'] !== $entityId
            || $row['ledger_currency'] !== $row['currency']
            || !in_array($row['status'], ['approved','sent','partially_paid'], true)
            || $date < $row[$dateColumn] || $date < $row['ledger_date']) {
            throw new InvalidArgumentException('Both documents must be posted, open, and dated on or before the application.');
        }
        if (accountingOpeningSignedCents((string) $row['total'])
            - accountingOpeningSignedCents((string) $row['amount_paid'])
            - accountingOpeningSignedCents((string) $row['amount_credited'])
            !== accountingOpeningSignedCents((string) $row['amount_due'])) {
            throw new InvalidArgumentException('A document balance needs reconciliation before applying credits.');
        }
        $control = $pdo->prepare('SELECT COALESCE(SUM(l.debit - l.credit),0)
            FROM accounting_journal_entry_lines l JOIN accounting_accounts a
              ON a.id = l.account_id AND a.tenant_id = l.tenant_id
            WHERE l.tenant_id = :t AND l.je_id = :je AND a.code = :code');
        $control->execute(['t' => $tenantId, 'je' => $row['journal_entry_id'], 'code' => $side === 'ar' ? '1100' : '2000']);
        $controlCents = accountingOpeningSignedCents((string) $control->fetchColumn()) * ($side === 'ar' ? 1 : -1);
        if ($controlCents !== accountingOpeningSignedCents((string) $row['total'])) {
            throw new InvalidArgumentException('Both source journals must reconcile to the same receivable or payable control account.');
        }
    }
    $cutover = $pdo->prepare('SELECT posting_date FROM accounting_opening_document_cutovers
        WHERE tenant_id = :t AND entity_id = :e AND id = :id');
    $cutover->execute(['t' => $tenantId, 'e' => $entityId, 'id' => $credit['opening_cutover_id']]);
    $openingDate = $cutover->fetchColumn();
    if (!$openingDate || $date <= $openingDate) throw new InvalidArgumentException('Apply opening credits on or after the Core start date.');
    $reserved = 0;
    if ($side === 'ap') {
        $q = $pdo->prepare("SELECT COALESCE(SUM(a.amount_applied),0) FROM ap_payment_allocations a
            JOIN ap_payments p ON p.id = a.payment_id WHERE p.tenant_id = :t AND a.bill_id = :id
            AND p.status IN ('draft','queued','sent')");
        $q->execute(['t' => $tenantId, 'id' => $documentId]);
        $reserved = accountingOpeningSignedCents((string) $q->fetchColumn());
    }
    $creditRemaining = -accountingOpeningSignedCents((string) $credit['amount_due']);
    $documentRemaining = accountingOpeningSignedCents((string) $document['amount_due']);
    if ($amount > $creditRemaining || $amount > $documentRemaining - $reserved) {
        throw new InvalidArgumentException('Amount exceeds the available credit or unpaid balance after reserved payments.');
    }
    $history = $pdo->prepare('SELECT id, application_date, reversal_date FROM accounting_credit_applications
        WHERE tenant_id = :t AND side = :side AND (credit_id = :c OR document_id = :d) ORDER BY id');
    $history->execute(['t' => $tenantId, 'side' => $side, 'c' => $creditId, 'd' => $documentId]);
    $historyRows = $history->fetchAll(PDO::FETCH_ASSOC);
    foreach ($historyRows as $event) {
        if ($date < max($event['application_date'], $event['reversal_date'] ?? '')) {
            throw new InvalidArgumentException('Choose an application date on or after the latest credit application or correction for these documents.');
        }
    }
    $review = [
        'entity_id' => $entityId, 'side' => $side, 'credit_id' => $creditId, 'document_id' => $documentId,
        'credit_je_id' => (int) $credit['journal_entry_id'], 'document_je_id' => (int) $document['journal_entry_id'],
        'application_date' => $date, 'amount' => accountingOpeningAmount($amount),
        'party' => $credit[$partyName], 'currency' => $credit['currency'],
        'credit_number' => $credit[$number], 'document_number' => $document[$number],
        'credit_before' => accountingOpeningAmount($creditRemaining),
        'credit_after' => accountingOpeningAmount($creditRemaining - $amount),
        'document_before' => accountingOpeningAmount($documentRemaining),
        'document_after' => accountingOpeningAmount($documentRemaining - $amount),
        'reserved' => accountingOpeningAmount($reserved),
        'revision' => hash('sha256', json_encode($historyRows, JSON_THROW_ON_ERROR)),
    ];
    $review['preview_token'] = hash('sha256', json_encode([$tenantId, $review], JSON_THROW_ON_ERROR));
    return $review;
}

function accountingCreditUpdateDocument(PDO $pdo, int $tenantId, string $side, int $id, int $delta): void
{
    [$table] = accountingCreditSpec($side);
    $q = $pdo->prepare("SELECT * FROM $table WHERE tenant_id = :t AND id = :id FOR UPDATE");
    $q->execute(['t' => $tenantId, 'id' => $id]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row || in_array($row['status'], ['draft','void','disputed'], true)) throw new InvalidArgumentException('Document is no longer available.');
    $credited = accountingOpeningSignedCents((string) $row['amount_credited']) + $delta;
    $due = accountingOpeningSignedCents((string) $row['total'])
        - accountingOpeningSignedCents((string) $row['amount_paid']) - $credited;
    $status = $due === 0 ? 'paid' : (
        $credited !== 0 || (float) $row['amount_paid'] > 0 ? 'partially_paid'
            : (!empty($row['sent_at']) && $side === 'ar' ? 'sent' : 'approved'));
    $pdo->prepare("UPDATE $table SET amount_credited = :credit, amount_due = :due, status = :status
        WHERE tenant_id = :t AND id = :id")->execute([
        'credit' => accountingOpeningAmount($credited), 'due' => accountingOpeningAmount($due),
        'status' => $status, 't' => $tenantId, 'id' => $id,
    ]);
    if ($side === 'ap' && (float) $row['total'] > 0) {
        $pdo->prepare('UPDATE placement_economic_obligations SET status = :status
            WHERE tenant_id = :t AND ap_bill_id = :id AND status <> "void"')
            ->execute(['status' => $status === 'paid' ? 'paid' : 'billed', 't' => $tenantId, 'id' => $id]);
    }
}

function accountingCreditCommit(PDO $pdo, int $tenantId, array $request, ?int $actorId): array
{
    $owns = !$pdo->inTransaction();
    if ($owns) $pdo->beginTransaction();
    else $pdo->exec('SAVEPOINT credit_application');
    try {
        $token = (string) ($request['preview_token'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) throw new InvalidArgumentException('Review the credit application first.');
        $prior = $pdo->prepare('SELECT * FROM accounting_credit_applications WHERE tenant_id = :t AND request_key = :key');
        $prior->execute(['t' => $tenantId, 'key' => $token]);
        if ($row = $prior->fetch(PDO::FETCH_ASSOC)) {
            foreach (['entity_id','side','credit_id','document_id','application_date'] as $field) {
                if ((string) $row[$field] !== (string) ($request[$field] ?? '')) throw new InvalidArgumentException('The reviewed request changed.');
            }
            if (accountingOpeningSignedCents((string) $row['amount']) !== accountingOpeningSignedCents((string) ($request['amount'] ?? ''))) {
                throw new InvalidArgumentException('The reviewed amount changed.');
            }
            if ($owns) $pdo->commit(); else $pdo->exec('RELEASE SAVEPOINT credit_application');
            return ['id' => (int) $row['id'], 'idempotent_replay' => true, 'reversed' => $row['reversal_date'] !== null];
        }
        $review = accountingCreditReview($pdo, $tenantId, $request, true);
        if (!hash_equals($review['preview_token'], $token)) throw new InvalidArgumentException('Balances changed. Review the application again.');
        $q = $pdo->prepare('INSERT INTO accounting_credit_applications
            (tenant_id, entity_id, side, credit_id, document_id, credit_je_id, document_je_id,
             amount, application_date, request_key, created_by_user_id)
            VALUES (:t,:e,:side,:c,:d,:cj,:dj,:amount,:date,:key,:actor)');
        $q->execute(['t' => $tenantId, 'e' => $review['entity_id'], 'side' => $review['side'],
            'c' => $review['credit_id'], 'd' => $review['document_id'], 'cj' => $review['credit_je_id'],
            'dj' => $review['document_je_id'], 'amount' => $review['amount'], 'date' => $review['application_date'],
            'key' => $token, 'actor' => $actorId]);
        $id = (int) $pdo->lastInsertId();
        $amount = accountingOpeningSignedCents($review['amount']);
        accountingCreditUpdateDocument($pdo, $tenantId, $review['side'], $review['credit_id'], -$amount);
        accountingCreditUpdateDocument($pdo, $tenantId, $review['side'], $review['document_id'], $amount);
        if ($owns) $pdo->commit(); else $pdo->exec('RELEASE SAVEPOINT credit_application');
        return ['id' => $id, 'idempotent_replay' => false, 'review' => $review];
    } catch (Throwable $error) {
        if ($owns && $pdo->inTransaction()) $pdo->rollBack();
        elseif (!$owns && $pdo->inTransaction()) { $pdo->exec('ROLLBACK TO SAVEPOINT credit_application'); $pdo->exec('RELEASE SAVEPOINT credit_application'); }
        throw $error;
    }
}

function accountingCreditReverse(PDO $pdo, int $tenantId, int $id, string $date, string $reason, ?int $actorId): array
{
    if (trim($reason) === '' || mb_strlen($reason) > 500) throw new InvalidArgumentException('Enter a correction reason of at most 500 characters.');
    $owns = !$pdo->inTransaction();
    if ($owns) $pdo->beginTransaction(); else $pdo->exec('SAVEPOINT reverse_credit');
    try {
        $q = $pdo->prepare('SELECT * FROM accounting_credit_applications WHERE tenant_id = :t AND id = :id FOR UPDATE');
        $q->execute(['t' => $tenantId, 'id' => $id]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new InvalidArgumentException('Credit application not found.');
        if ($row['reversal_date'] !== null) {
            if ($row['reversal_date'] !== $date || $row['reversal_reason'] !== trim($reason)) throw new InvalidArgumentException('This application was already reversed with different details.');
            if ($owns) $pdo->commit(); else $pdo->exec('RELEASE SAVEPOINT reverse_credit');
            return ['id' => $id, 'idempotent_replay' => true];
        }
        accountingCreditDate($tenantId, (int) $row['entity_id'], $date, true);
        if ($date < $row['application_date']) throw new InvalidArgumentException('A correction cannot predate its application.');
        $amount = accountingOpeningSignedCents((string) $row['amount']);
        accountingCreditUpdateDocument($pdo, $tenantId, $row['side'], (int) $row['credit_id'], $amount);
        accountingCreditUpdateDocument($pdo, $tenantId, $row['side'], (int) $row['document_id'], -$amount);
        $pdo->prepare('UPDATE accounting_credit_applications SET reversal_date = :date, reversal_reason = :reason,
            reversed_by_user_id = :actor, reversed_at = NOW() WHERE tenant_id = :t AND id = :id')
            ->execute(['date' => $date, 'reason' => trim($reason), 'actor' => $actorId, 't' => $tenantId, 'id' => $id]);
        if ($owns) $pdo->commit(); else $pdo->exec('RELEASE SAVEPOINT reverse_credit');
        return ['id' => $id, 'idempotent_replay' => false];
    } catch (Throwable $error) {
        if ($owns && $pdo->inTransaction()) $pdo->rollBack();
        elseif (!$owns && $pdo->inTransaction()) { $pdo->exec('ROLLBACK TO SAVEPOINT reverse_credit'); $pdo->exec('RELEASE SAVEPOINT reverse_credit'); }
        throw $error;
    }
}
