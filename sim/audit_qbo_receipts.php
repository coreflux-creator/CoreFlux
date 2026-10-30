<?php
/** Read-only audit of captured processor charges against CoreFlux AR and GL. */
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';

$tenantId = 0;
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (preg_match('/^--tenant=([1-9][0-9]*)$/', $arg, $match)) $tenantId = (int) $match[1];
}
if ($tenantId <= 0) {
    fwrite(STDERR, "Usage: php sim/audit_qbo_receipts.php --tenant=TENANT_ID\n");
    exit(2);
}

$pdo = getDB();
$charges = $pdo->prepare(
    'SELECT c.qbo_charge_id, c.status, c.amount_cents, c.currency,
            c.coreflux_invoice_id, c.coreflux_payment_id,
            p.id AS payment_id, p.amount AS payment_amount,
            p.currency AS payment_currency, p.unallocated_amount,
            p.journal_entry_id, p.voided_at, je.status AS journal_status
       FROM qbo_payment_charges c
  LEFT JOIN billing_payments p
         ON p.tenant_id = c.tenant_id AND p.source_system = "qbo"
        AND p.external_id = c.qbo_charge_id
  LEFT JOIN accounting_journal_entries je
         ON je.tenant_id = p.tenant_id AND je.id = p.journal_entry_id
      WHERE c.tenant_id = :tenant_id ORDER BY c.id'
);
$charges->execute(['tenant_id' => $tenantId]);
$allocation = $pdo->prepare(
    'SELECT invoice_id, ROUND(SUM(amount_applied), 2) AS amount
       FROM billing_payment_allocations
      WHERE payment_id = :payment_id AND reversed_at IS NULL GROUP BY invoice_id'
);
$journalLines = $pdo->prepare(
    'SELECT a.code, ROUND(SUM(l.debit), 2) AS debit, ROUND(SUM(l.credit), 2) AS credit
       FROM accounting_journal_entry_lines l
       JOIN accounting_accounts a ON a.tenant_id = l.tenant_id AND a.id = l.account_id
      WHERE l.tenant_id = :tenant_id AND l.je_id = :journal_entry_id
        AND a.code IN ("1010", "1100") GROUP BY a.code'
);

$count = 0;
$issues = [];
foreach ($charges->fetchAll(PDO::FETCH_ASSOC) as $charge) {
    $count++;
    $flags = [];
    $captured = in_array(strtoupper((string) $charge['status']), ['CAPTURED', 'SETTLED'], true);
    $paymentId = (int) ($charge['payment_id'] ?? 0);
    $amount = (int) $charge['amount_cents'] / 100;
    if (!$paymentId) {
        if ($captured) $flags[] = 'captured_without_receipt';
    } else {
        if (!$captured && $charge['voided_at'] === null) {
            $flags[] = 'noncaptured_charge_with_active_receipt_review_refund_or_dispute';
        }
        if ((int) $charge['coreflux_payment_id'] !== $paymentId) $flags[] = 'shadow_payment_link_mismatch';
        if (abs((float) $charge['payment_amount'] - $amount) > 0.005
            || strcasecmp((string) $charge['payment_currency'], (string) $charge['currency']) !== 0) {
            $flags[] = 'payment_amount_or_currency_mismatch';
        }
        if ($captured && $charge['voided_at'] === null) {
            if ((int) $charge['journal_entry_id'] <= 0 || $charge['journal_status'] !== 'posted') {
                $flags[] = 'receipt_without_posted_journal';
            }
            $allocation->execute(['payment_id' => $paymentId]);
            $rows = $allocation->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) !== 1
                || (int) ($rows[0]['invoice_id'] ?? 0) !== (int) $charge['coreflux_invoice_id']
                || abs((float) ($rows[0]['amount'] ?? 0) - $amount) > 0.005
                || abs((float) $charge['unallocated_amount']) > 0.005) {
                $flags[] = 'invoice_allocation_mismatch';
            }
            if ($charge['journal_status'] === 'posted') {
                $journalLines->execute([
                    'tenant_id' => $tenantId, 'journal_entry_id' => (int) $charge['journal_entry_id'],
                ]);
                $lines = [];
                foreach ($journalLines->fetchAll(PDO::FETCH_ASSOC) as $line) $lines[$line['code']] = $line;
                if (abs((float) ($lines['1010']['debit'] ?? 0) - $amount) > 0.005
                    || abs((float) ($lines['1100']['credit'] ?? 0) - $amount) > 0.005) {
                    $flags[] = 'clearing_or_ar_journal_mismatch';
                }
            }
        }
    }
    if ($flags) {
        $issues[] = ['charge_id' => $charge['qbo_charge_id'], 'status' => $charge['status'],
            'payment_id' => $paymentId ?: null, 'issues' => $flags];
    }
}

$orphanStmt = $pdo->prepare(
    'SELECT p.id, p.external_id FROM billing_payments p
  LEFT JOIN qbo_payment_charges c ON c.tenant_id = p.tenant_id AND c.qbo_charge_id = p.external_id
      WHERE p.tenant_id = :tenant_id AND p.source_system = "qbo" AND c.id IS NULL'
);
$orphanStmt->execute(['tenant_id' => $tenantId]);
foreach ($orphanStmt->fetchAll(PDO::FETCH_ASSOC) as $orphan) {
    $issues[] = ['charge_id' => $orphan['external_id'], 'payment_id' => (int) $orphan['id'],
        'issues' => ['receipt_without_charge_shadow']];
}

echo json_encode(['tenant_id' => $tenantId, 'charges_checked' => $count,
    'issues_found' => count($issues), 'issues' => $issues], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit($issues ? 1 : 0);
