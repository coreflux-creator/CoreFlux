<?php
/** Read-only check that invoice allocations have an active posted ledger source. */
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';

$tenantId = 0;
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (preg_match('/^--tenant=([1-9][0-9]*)$/', $arg, $match)) $tenantId = (int) $match[1];
}
if ($tenantId <= 0) {
    fwrite(STDERR, "Usage: php sim/audit_billing_receipts.php --tenant=TENANT_ID\n");
    exit(2);
}

$pdo = getDB();
$payments = $pdo->prepare(
    'SELECT p.id, p.source_system, p.external_id, p.amount,
            p.unallocated_amount, p.journal_entry_id, je.status AS je_status,
            ROUND(SUM(a.amount_applied), 2) AS allocated
       FROM billing_payments p
       JOIN billing_payment_allocations a ON a.payment_id = p.id AND a.reversed_at IS NULL
  LEFT JOIN accounting_journal_entries je
         ON je.tenant_id = p.tenant_id AND je.id = p.journal_entry_id
      WHERE p.tenant_id = :tenant_id AND p.voided_at IS NULL
      GROUP BY p.id, p.source_system, p.external_id, p.amount,
               p.unallocated_amount, p.journal_entry_id, je.status
      ORDER BY p.id'
);
$payments->execute(['tenant_id' => $tenantId]);
$bankLine = $pdo->prepare(
    'SELECT bl.match_status, je.status AS je_status
       FROM accounting_bank_statement_lines bl
  LEFT JOIN accounting_journal_entries je
         ON je.tenant_id = bl.tenant_id AND je.id = bl.matched_je_id
      WHERE bl.tenant_id = :tenant_id AND bl.id = :id'
);

$count = 0;
$issues = [];
foreach ($payments->fetchAll(PDO::FETCH_ASSOC) as $payment) {
    $count++;
    $flags = [];
    $bankLineId = null;
    if (preg_match('/^bank-line:([1-9][0-9]*)(?::|$)/', (string) $payment['external_id'], $match)) {
        $bankLineId = (int) $match[1];
    }
    if ($payment['je_status'] !== 'posted') {
        if ($bankLineId === null || $payment['source_system'] !== 'manual') {
            $flags[] = 'invoice_allocated_without_posted_receipt_journal';
        } else {
            $bankLine->execute(['tenant_id' => $tenantId, 'id' => $bankLineId]);
            $line = $bankLine->fetch(PDO::FETCH_ASSOC);
            if (!$line || $line['match_status'] !== 'matched' || $line['je_status'] !== 'posted') {
                $flags[] = 'bank_receipt_without_active_match';
            }
        }
    }
    if ((float) $payment['allocated'] - (float) $payment['amount'] > 0.005
        || (float) $payment['unallocated_amount'] < -0.005) {
        $flags[] = 'allocation_exceeds_receipt';
    }
    if ($flags) {
        $issues[] = ['payment_id' => (int) $payment['id'],
            'source_system' => $payment['source_system'], 'issues' => $flags];
    }
}

echo json_encode(['tenant_id' => $tenantId, 'allocated_payments_checked' => $count,
    'issues_found' => count($issues), 'issues' => $issues], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit($issues ? 1 : 0);
