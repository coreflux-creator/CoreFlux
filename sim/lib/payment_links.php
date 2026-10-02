<?php
declare(strict_types=1);

function simLinkClearedPayment(int $tenantId, int $billId, int $paymentId, int $jeId, string $payDate): void {
    if ($billId <= 0 || $paymentId <= 0 || $jeId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $payDate)) {
        throw new \InvalidArgumentException('A bill, payment, posted journal entry, and payment date are required');
    }
    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        $event = $pdo->prepare(
            'SELECT e.payload, j.total_debit, j.entity_id
               FROM accounting_events e
               JOIN accounting_journal_entries j ON j.id = e.journal_entry_id AND j.tenant_id = e.tenant_id
              WHERE e.tenant_id = :tenant_id AND e.journal_entry_id = :je_id
                AND e.event_type = "ap.payment.cleared" AND e.status = "posted"
                AND j.status = "posted" AND j.source_module = "ap"
              LIMIT 1'
        );
        $event->execute(['tenant_id' => $tenantId, 'je_id' => $jeId]);
        $posted = $event->fetch(\PDO::FETCH_ASSOC);
        $payload = $posted ? json_decode((string) $posted['payload'], true) : null;
        if (!is_array($payload)
            || (int) ($payload['payment_id'] ?? 0) !== $paymentId
            || (int) ($payload['bill_id'] ?? 0) !== $billId) {
            throw new \RuntimeException('Payment event does not match the bill and payment');
        }

        $entityId = (int) $posted['entity_id'];
        $bill = $pdo->prepare('SELECT vendor_name, total, journal_entry_id, entity_id FROM ap_bills WHERE tenant_id = :t AND id = :id FOR UPDATE');
        $bill->execute(['t' => $tenantId, 'id' => $billId]);
        $b = $bill->fetch(\PDO::FETCH_ASSOC);
        if (!$b || (int) ($b['journal_entry_id'] ?? 0) <= 0) {
            throw new \RuntimeException('The approved source bill is missing its posted journal entry');
        }
        if ((int) ($b['entity_id'] ?? 0) !== $entityId) {
            throw new \RuntimeException('The cleared payment and source bill belong to different entities');
        }
        $amount = round((float) $b['total'], 2);
        if ($amount <= 0 || $amount !== round((float) $posted['total_debit'], 2)) {
            throw new \RuntimeException('Payment and bill amounts do not match');
        }

        $payment = $pdo->prepare('SELECT tenant_id, entity_id, amount, status, journal_entry_id FROM ap_payments WHERE id = :id FOR UPDATE');
        $payment->execute(['id' => $paymentId]);
        $existing = $payment->fetch(\PDO::FETCH_ASSOC);
        if ($existing) {
            if ((int) $existing['tenant_id'] !== $tenantId
                || (int) ($existing['journal_entry_id'] ?? 0) !== $jeId
                || round((float) $existing['amount'], 2) !== $amount
                || $existing['status'] !== 'cleared'
                || ((int) ($existing['entity_id'] ?? 0) !== 0 && (int) $existing['entity_id'] !== $entityId)) {
                throw new \RuntimeException('The simulation payment conflicts with an existing payment');
            }
            if ((int) ($existing['entity_id'] ?? 0) === 0) {
                $pdo->prepare('UPDATE ap_payments SET entity_id = :entity_id WHERE tenant_id = :tenant_id AND id = :id')
                    ->execute(['entity_id' => $entityId, 'tenant_id' => $tenantId, 'id' => $paymentId]);
            }
        } else {
            $pdo->prepare(
                'INSERT INTO ap_payments
                   (id, tenant_id, entity_id, vendor_name, pay_date, reference, amount, unallocated_amount,
                    status, cleared_at, journal_entry_id)
                 VALUES (:id, :tenant_id, :entity_id, :vendor_name, :pay_date, :reference, :amount, 0,
                         "cleared", :cleared_at, :je_id)'
            )->execute([
                'id' => $paymentId,
                'tenant_id' => $tenantId,
                'entity_id' => $entityId,
                'vendor_name' => (string) $b['vendor_name'],
                'pay_date' => $payDate,
                'reference' => (string) ($payload['payment_number'] ?? "SIM-PAY-{$paymentId}"),
                'amount' => $amount,
                'cleared_at' => $payDate . ' 12:00:00',
                'je_id' => $jeId,
            ]);
        }

        $allocation = $pdo->prepare(
            'SELECT bill_id, amount_applied FROM ap_payment_allocations WHERE payment_id = :payment_id FOR UPDATE'
        );
        $allocation->execute(['payment_id' => $paymentId]);
        $allocations = $allocation->fetchAll(\PDO::FETCH_ASSOC);
        if ($allocations) {
            if (count($allocations) !== 1
                || (int) $allocations[0]['bill_id'] !== $billId
                || round((float) $allocations[0]['amount_applied'], 2) !== $amount) {
                throw new \RuntimeException('The simulation payment has a conflicting allocation');
            }
        } else {
            $pdo->prepare(
                'INSERT INTO ap_payment_allocations (payment_id, bill_id, amount_applied, applied_at)
                 VALUES (:payment_id, :bill_id, :amount, :applied_at)'
            )->execute([
                'payment_id' => $paymentId,
                'bill_id' => $billId,
                'amount' => $amount,
                'applied_at' => $payDate . ' 12:00:00',
            ]);
        }
        $pdo->prepare(
            'UPDATE ap_bills SET amount_paid = total, amount_due = 0, status = "paid", updated_at = NOW()
              WHERE tenant_id = :tenant_id AND id = :id'
        )->execute(['tenant_id' => $tenantId, 'id' => $billId]);
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
