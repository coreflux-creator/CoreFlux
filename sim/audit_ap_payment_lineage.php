<?php
/** Read-only AP payment, ledger and bill-settlement audit on isolated staging. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'staging') {
    fwrite(STDERR, "This audit is restricted to the staging CLI.\n");
    exit(2);
}

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../modules/ap/lib/payment_correction.php';

function simAuditApPaymentLineage(PDO $pdo, int $tenantId): array
{
$paymentsStmt = $pdo->prepare('SELECT * FROM ap_payments WHERE tenant_id = :tenant_id ORDER BY id');
$paymentsStmt->execute(['tenant_id' => $tenantId]);
$allocStmt = $pdo->prepare(
    'SELECT a.bill_id, a.amount_applied, b.tenant_id AS bill_tenant_id,
            b.entity_id AS bill_entity_id, b.currency AS bill_currency
       FROM ap_payment_allocations a
       LEFT JOIN ap_bills b ON b.id = a.bill_id
      WHERE a.payment_id = :payment_id'
);
$journalStmt = $pdo->prepare(
    'SELECT id, entity_id, currency, status, source_module, source_ref_type, source_ref_id,
            total_debit, total_credit, reversed_by_je_id
       FROM accounting_journal_entries
      WHERE tenant_id = :tenant_id AND id = :journal_id'
);
$reversalStmt = $pdo->prepare(
    'SELECT status FROM accounting_journal_entries WHERE tenant_id = :tenant_id AND id = :journal_id'
);
$linkStmt = $pdo->prepare(
    'SELECT link_kind, journal_entry_id FROM accounting_subledger_links
      WHERE tenant_id = :tenant_id AND source_module = "ap" AND source_record_id = :source_record_id'
);
$eventStmt = $pdo->prepare(
    'SELECT status, journal_entry_id FROM accounting_events
      WHERE tenant_id = :tenant_id AND source_module = "ap"
        AND source_record_id = :source_record_id AND event_type = "ap.payment.cleared"'
);
$bankLineStmt = $pdo->prepare(
    'SELECT id, bank_account_id, amount, match_status
       FROM accounting_bank_statement_lines
      WHERE tenant_id = :tenant_id AND matched_je_id = :journal_id'
);
$issues = [];
$correctionBlockers = [];
$statusCounts = [];
$manualEligible = 0;
$providerCleared = 0;
$paymentCount = 0;
$money = static fn($value): int => (int) round((float) $value * 100);
$flag = static function (int $paymentId, string $code) use (&$issues): void {
    $issues[] = ['payment_id' => $paymentId, 'code' => $code];
};

while ($payment = $paymentsStmt->fetch(PDO::FETCH_ASSOC)) {
    ++$paymentCount;
    $id = (int) $payment['id'];
    $status = (string) $payment['status'];
    $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
    $amount = $money($payment['amount']);
    $journalId = (int) ($payment['journal_entry_id'] ?? 0);
    $sourceRecordId = 'ap_payment:' . $id;

    $allocStmt->execute(['payment_id' => $id]);
    $allocations = $allocStmt->fetchAll(PDO::FETCH_ASSOC);
    $allocated = 0;
    foreach ($allocations as $allocation) {
        $allocated += $money($allocation['amount_applied']);
        if ((int) ($allocation['bill_tenant_id'] ?? 0) !== $tenantId
            || (int) ($allocation['bill_entity_id'] ?? 0) !== (int) ($payment['entity_id'] ?? 0)
            || strcasecmp((string) ($allocation['bill_currency'] ?? ''), (string) $payment['currency']) !== 0
            || $money($allocation['amount_applied']) <= 0) {
            $flag($id, 'invalid_bill_allocation');
        }
    }
    if ($amount <= 0 || $allocated + $money($payment['unallocated_amount']) !== $amount) {
        $flag($id, 'allocation_balance_mismatch');
    }

    $journal = null;
    if ($journalId > 0) {
        $journalStmt->execute(['tenant_id' => $tenantId, 'journal_id' => $journalId]);
        $journal = $journalStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $linkStmt->execute(['tenant_id' => $tenantId, 'source_record_id' => $sourceRecordId]);
    $links = $linkStmt->fetchAll(PDO::FETCH_ASSOC);
    $eventStmt->execute(['tenant_id' => $tenantId, 'source_record_id' => $sourceRecordId]);
    $events = $eventStmt->fetchAll(PDO::FETCH_ASSOC);

    if ($status === 'cleared' || ($status === 'void' && $journalId > 0)) {
        if (!$journal || $journal['source_module'] !== 'ap'
            || (int) $journal['entity_id'] !== (int) ($payment['entity_id'] ?? 0)
            || strcasecmp((string) $journal['currency'], (string) $payment['currency']) !== 0
            || $money($journal['total_debit']) !== $amount
            || $money($journal['total_credit']) !== $amount) {
            $flag($id, 'payment_journal_mismatch');
        }
        if ($journal && $journal['source_ref_type'] !== null
            && ($journal['source_ref_type'] !== 'ap_payment' || (int) $journal['source_ref_id'] !== $id)) {
            $flag($id, 'payment_journal_wrong_source');
        }
        $primaryLinks = array_values(array_filter($links, static fn(array $link): bool =>
            $link['link_kind'] === 'primary' && (int) $link['journal_entry_id'] === $journalId
        ));
        if (count($primaryLinks) !== 1 || count(array_filter($links, static fn(array $link): bool =>
            $link['link_kind'] === 'primary'
        )) !== 1) {
            $flag($id, 'missing_or_duplicate_primary_link');
        }
        $postedEvents = array_values(array_filter($events, static fn(array $event): bool =>
            $event['status'] === 'posted' && (int) $event['journal_entry_id'] === $journalId
        ));
        if (count($postedEvents) !== 1 || count($events) !== 1) {
            $flag($id, 'missing_or_inconsistent_clearance_event');
        }
        if ($allocated !== $amount || $money($payment['unallocated_amount']) !== 0) {
            $flag($id, 'cleared_payment_not_fully_allocated');
        }
        if (empty($payment['bank_account_id']) || empty($payment['cleared_at'])) {
            $flag($id, 'missing_clearance_bank_or_date');
        }
    } elseif ($journalId > 0 || $links || $events) {
        $flag($id, 'unposted_payment_has_ledger_lineage');
    }

    if ($journalId > 0) {
        $bankLineStmt->execute(['tenant_id' => $tenantId, 'journal_id' => $journalId]);
        $bankLines = $bankLineStmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($bankLines) > 1) $flag($id, 'multiple_bank_lines_on_payment_journal');
        foreach ($bankLines as $bankLine) {
            if ($bankLine['match_status'] !== 'matched'
                || (int) $bankLine['bank_account_id'] !== (int) ($payment['bank_account_id'] ?? 0)
                || $money($bankLine['amount']) !== -$amount) {
                $flag($id, 'bank_match_mismatch');
            }
        }
        if ($status === 'cleared' && (!$journal || $journal['status'] !== 'posted')) {
            $flag($id, 'cleared_journal_not_posted');
        }
        if ($status === 'void') {
            $reversalId = (int) ($journal['reversed_by_je_id'] ?? 0);
            $reversalStmt->execute(['tenant_id' => $tenantId, 'journal_id' => $reversalId]);
            $reversalStatus = $reversalStmt->fetchColumn();
            if (!$journal || $journal['status'] !== 'reversed' || $reversalStatus !== 'posted'
                || count($bankLines) > 0
                || count(array_filter($links, static fn(array $link): bool =>
                    $link['link_kind'] === 'reversal' && (int) $link['journal_entry_id'] === $reversalId
                )) !== 1) {
                $flag($id, 'voided_payment_reversal_incomplete');
            }
        }
    }

    if ($status === 'cleared') {
        if (!empty($payment['disbursement_rail']) || !empty($payment['rail_external_ref'])
            || !empty($payment['plaid_transfer_id'])) {
            ++$providerCleared;
        } else {
            try {
                apInspectClearedManualPayment($pdo, $tenantId, $payment);
                ++$manualEligible;
            } catch (Throwable $e) {
                $correctionBlockers[] = ['payment_id' => $id, 'reason' => $e->getMessage()];
            }
        }
    }
}

$billStmt = $pdo->prepare(
    'SELECT b.id, b.total, b.amount_paid, b.amount_due,
            COALESCE(SUM(CASE WHEN p.status = "cleared" AND je.status = "posted"
                              THEN a.amount_applied ELSE 0 END), 0) AS posted_payment_amount
       FROM ap_bills b
       LEFT JOIN ap_payment_allocations a ON a.bill_id = b.id
       LEFT JOIN ap_payments p ON p.id = a.payment_id AND p.tenant_id = b.tenant_id
       LEFT JOIN accounting_journal_entries je
         ON je.id = p.journal_entry_id AND je.tenant_id = b.tenant_id
      WHERE b.tenant_id = :tenant_id AND b.status IN ("approved", "partially_paid", "paid")
      GROUP BY b.id, b.total, b.amount_paid, b.amount_due'
);
$billStmt->execute(['tenant_id' => $tenantId]);
$billCount = 0;
while ($bill = $billStmt->fetch(PDO::FETCH_ASSOC)) {
    ++$billCount;
    $paid = $money($bill['posted_payment_amount']);
    if ($paid !== $money($bill['amount_paid'])
        || max(0, $money($bill['total']) - $paid) !== $money($bill['amount_due'])) {
        $issues[] = ['bill_id' => (int) $bill['id'], 'code' => 'bill_settlement_mismatch'];
    }
}

return [
    'tenant_id' => $tenantId,
    'read_only' => true,
    'payments' => $paymentCount,
    'status_counts' => $statusCounts,
    'bills_checked' => $billCount,
    'manual_correction_eligible' => $manualEligible,
    'manual_correction_blockers' => $correctionBlockers,
    'provider_cleared_payment_count' => $providerCleared,
    'issues' => $issues,
];
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $tenantArgs = array_values(array_filter(
        array_slice($argv, 1), static fn(string $arg): bool => str_starts_with($arg, '--tenant=')
    ));
    $tenantId = count($tenantArgs) === 1 ? (int) substr($tenantArgs[0], 9) : 0;
    if ($tenantId <= 0) {
        fwrite(STDERR, "Use --tenant=ID.\n");
        exit(2);
    }
    $audit = simAuditApPaymentLineage(getDB(), $tenantId);
    echo json_encode($audit, JSON_PRETTY_PRINT) . "\n";
    exit($audit['issues'] ? 1 : 0);
}
