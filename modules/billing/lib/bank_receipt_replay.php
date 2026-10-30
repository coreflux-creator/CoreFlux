<?php
declare(strict_types=1);

require_once __DIR__ . '/bank_receipt_correction.php';

function billingBankReceiptRequestHash(string $action, array $line, array $body): string
{
    if (!in_array($action, ['match_invoice', 'split_match_invoices'], true)) {
        throw new InvalidArgumentException('Unsupported bank receipt action.');
    }
    $intent = [
        'action' => $action,
        'bank_line_id' => (int) $line['id'],
        'bank_account_id' => (int) $line['bank_account_id'],
        'bank_entity_id' => (int) ($line['bank_entity_id'] ?? 0),
        'gl_account_code' => (string) ($line['gl_account_code'] ?? ''),
        'bank_currency' => strtoupper((string) ($line['bank_currency'] ?: 'USD')),
        'posted_date' => (string) $line['posted_date'],
        'amount' => number_format((float) $line['amount'], 2, '.', ''),
        'description' => (string) ($line['description'] ?? ''),
    ];
    if ($action === 'match_invoice') {
        $intent['invoice_id'] = (int) ($body['invoice_id'] ?? 0);
    } else {
        $allocations = [];
        foreach ((array) ($body['allocations'] ?? []) as $allocation) {
            if (!is_array($allocation)) throw new InvalidArgumentException('Invalid invoice allocation.');
            if (!is_numeric($allocation['amount'] ?? null)
                || !is_finite((float) $allocation['amount'])) {
                throw new InvalidArgumentException('Every invoice allocation needs a finite amount.');
            }
            $invoiceId = (int) ($allocation['invoice_id'] ?? 0);
            $allocations[$invoiceId] = ($allocations[$invoiceId] ?? 0)
                + (int) round((float) ($allocation['amount'] ?? 0) * 100);
        }
        ksort($allocations, SORT_NUMERIC);
        $intent['allocations'] = $allocations;
        $splits = [];
        foreach ((array) ($body['account_splits'] ?? []) as $split) {
            if (!is_array($split)) throw new InvalidArgumentException('Invalid GL split.');
            if (!is_numeric($split['amount'] ?? null)
                || !is_finite((float) $split['amount'])) {
                throw new InvalidArgumentException('Every GL split needs a finite amount.');
            }
            $splits[] = [
                'account_id' => (int) ($split['account_id'] ?? 0),
                'amount_cents' => (int) round((float) ($split['amount'] ?? 0) * 100),
                'memo' => trim((string) ($split['memo'] ?? '')),
                'entity_id' => (int) ($split['entity_id'] ?? 0),
            ];
        }
        usort($splits, static fn(array $a, array $b): int =>
            [$a['account_id'], $a['amount_cents'], $a['memo'], $a['entity_id']]
            <=> [$b['account_id'], $b['amount_cents'], $b['memo'], $b['entity_id']]);
        $intent['account_splits'] = $splits;
    }
    return hash('sha256', json_encode($intent, JSON_THROW_ON_ERROR));
}

/** Only a still-posted, still-matched receipt with its active payments is replayable. */
function billingBankReceiptReplay(PDO $pdo, int $tenantId, array $line,
    string $action, string $requestHash): ?array
{
    if (($line['match_status'] ?? '') !== 'matched' || (int) ($line['matched_je_id'] ?? 0) <= 0) return null;
    $attempt = billingBankReceiptAttempt($tenantId, (int) $line['id']);
    $stmt = $pdo->prepare(
        'SELECT r.action, r.request_hash, r.journal_entry_id, r.response_json,
                je.status AS journal_status, je.source_module, je.source_ref_type, je.source_ref_id
           FROM billing_bank_receipt_requests r
           JOIN accounting_journal_entries je ON je.tenant_id = r.tenant_id
                AND je.id = r.journal_entry_id
          WHERE r.tenant_id = :tenant_id AND r.bank_line_id = :line_id
            AND r.attempt_no = :attempt_no LIMIT 1'
    );
    $stmt->execute(['tenant_id' => $tenantId, 'line_id' => (int) $line['id'], 'attempt_no' => $attempt]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$record) return null;
    if ($record['action'] !== $action || !hash_equals((string) $record['request_hash'], $requestHash)) {
        throw new RuntimeException('This bank line was posted with a different allocation. Correct that receipt before changing it.');
    }
    if ((int) $record['journal_entry_id'] !== (int) $line['matched_je_id']
        || $record['journal_status'] !== 'posted'
        || $record['source_module'] !== 'billing'
        || $record['source_ref_type'] !== 'bank_statement_line'
        || (int) $record['source_ref_id'] !== (int) $line['id']) {
        throw new RuntimeException('The bank receipt no longer matches its posted journal. Review it before retrying.');
    }
    $response = json_decode((string) $record['response_json'], true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($response) || (int) ($response['matched_je_id'] ?? 0) !== (int) $record['journal_entry_id']) {
        throw new RuntimeException('The saved bank receipt result is incomplete. Review before retrying.');
    }
    $paymentIds = isset($response['payment_ids'])
        ? array_map('intval', (array) $response['payment_ids'])
        : [(int) ($response['payment_id'] ?? 0)];
    if (!$paymentIds || in_array(0, $paymentIds, true)) {
        throw new RuntimeException('The bank receipt payment lineage is incomplete. Review before retrying.');
    }
    $placeholders = implode(',', array_fill(0, count($paymentIds), '?'));
    $payments = $pdo->prepare(
        'SELECT COUNT(*) FROM billing_payments
          WHERE tenant_id = ? AND id IN (' . $placeholders . ')
            AND journal_entry_id = ? AND voided_at IS NULL'
    );
    $payments->execute(array_merge([$tenantId], $paymentIds, [(int) $record['journal_entry_id']]));
    if ((int) $payments->fetchColumn() !== count(array_unique($paymentIds))) {
        throw new RuntimeException('The bank receipt payments changed. Review before retrying.');
    }
    $response['idempotent_replay'] = true;
    return $response;
}

/** Call inside the same transaction that posts the journal, payment, and bank match. */
function billingRecordBankReceiptRequest(PDO $pdo, int $tenantId, int $bankLineId,
    int $attempt, string $action, string $requestHash, array $response): void
{
    if (!$pdo->inTransaction()) throw new RuntimeException('Receipt request history must be saved with its posting.');
    $stmt = $pdo->prepare(
        'INSERT INTO billing_bank_receipt_requests
            (tenant_id, bank_line_id, attempt_no, action, request_hash, journal_entry_id, response_json)
         VALUES (:tenant_id, :bank_line_id, :attempt_no, :action, :request_hash,
                 :journal_entry_id, :response_json)'
    );
    $stmt->execute([
        'tenant_id' => $tenantId, 'bank_line_id' => $bankLineId, 'attempt_no' => $attempt,
        'action' => $action, 'request_hash' => $requestHash,
        'journal_entry_id' => (int) $response['matched_je_id'],
        'response_json' => json_encode($response, JSON_THROW_ON_ERROR),
    ]);
}
