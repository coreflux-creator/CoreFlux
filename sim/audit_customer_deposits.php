<?php
/** Read-only reconciliation of held customer money, applications, refunds and GL links. */
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';

$tenantId = 0;
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (preg_match('/^--tenant=([1-9][0-9]*)$/', $arg, $match)) $tenantId = (int) $match[1];
}
if ($tenantId <= 0) {
    fwrite(STDERR, "Usage: php sim/audit_customer_deposits.php --tenant=TENANT_ID\n");
    exit(2);
}

$pdo = getDB();
$payments = $pdo->prepare(
    'SELECT p.id, p.unallocated_amount, p.journal_entry_id, je.status AS je_status,
            ROUND(COALESCE(SUM(CASE WHEN a.code = "2300" THEN l.credit - l.debit ELSE 0 END), 0), 2) AS held
       FROM billing_payments p
       JOIN accounting_journal_entries je ON je.tenant_id = p.tenant_id AND je.id = p.journal_entry_id
       JOIN accounting_journal_entry_lines l ON l.tenant_id = je.tenant_id AND l.je_id = je.id
       JOIN accounting_accounts a ON a.tenant_id = l.tenant_id AND a.id = l.account_id
      WHERE p.tenant_id = :t AND p.source_system = "manual" AND p.voided_at IS NULL
      GROUP BY p.id, p.unallocated_amount, p.journal_entry_id, je.status
     HAVING held > 0
      ORDER BY p.id'
);
$payments->execute(['t' => $tenantId]);
$applicationStmt = $pdo->prepare(
    'SELECT a.amount, a.journal_entry_id, je.status AS je_status,
            a.reversed_at, a.reversal_je_id, reversal.status AS reversal_status,
            alloc.amount_applied, alloc.application_je_id,
            alloc.reversed_at AS allocation_reversed_at,
            alloc.reversal_je_id AS allocation_reversal_je_id
       FROM billing_deposit_applications a
       LEFT JOIN accounting_journal_entries je ON je.tenant_id = a.tenant_id AND je.id = a.journal_entry_id
       LEFT JOIN accounting_journal_entries reversal ON reversal.tenant_id = a.tenant_id AND reversal.id = a.reversal_je_id
       LEFT JOIN billing_payment_allocations alloc ON alloc.id = a.allocation_id AND alloc.payment_id = a.payment_id
      WHERE a.tenant_id = :t AND a.payment_id = :p'
);
$refundStmt = $pdo->prepare(
    'SELECT r.amount, r.journal_entry_id, je.status AS je_status,
            r.reversed_at, r.reversal_je_id, reversal.status AS reversal_status,
            r.bank_account_id, b.gl_account_code
       FROM billing_deposit_refunds r
       LEFT JOIN accounting_journal_entries je ON je.tenant_id = r.tenant_id AND je.id = r.journal_entry_id
       LEFT JOIN accounting_journal_entries reversal ON reversal.tenant_id = r.tenant_id AND reversal.id = r.reversal_je_id
       LEFT JOIN accounting_bank_accounts b ON b.tenant_id = r.tenant_id AND b.id = r.bank_account_id
      WHERE r.tenant_id = :t AND r.payment_id = :p'
);
$journalAmounts = $pdo->prepare(
    'SELECT a.code, ROUND(SUM(l.debit), 2) AS debit, ROUND(SUM(l.credit), 2) AS credit
       FROM accounting_journal_entry_lines l
       JOIN accounting_accounts a ON a.tenant_id = l.tenant_id AND a.id = l.account_id
      WHERE l.tenant_id = :t AND l.je_id = :je GROUP BY a.code'
);
$linesFor = static function (int $jeId) use ($journalAmounts, $tenantId): array {
    $journalAmounts->execute(['t' => $tenantId, 'je' => $jeId]);
    $lines = [];
    foreach ($journalAmounts->fetchAll(PDO::FETCH_ASSOC) as $line) $lines[$line['code']] = $line;
    return $lines;
};

$count = 0;
$issues = [];
foreach ($payments->fetchAll(PDO::FETCH_ASSOC) as $payment) {
    $count++;
    $flags = [];
    if ($payment['je_status'] !== 'posted') $flags[] = 'original_receipt_not_posted';
    $applicationStmt->execute(['t' => $tenantId, 'p' => (int) $payment['id']]);
    $applied = 0.0;
    foreach ($applicationStmt->fetchAll(PDO::FETCH_ASSOC) as $application) {
        if ($application['reversed_at'] !== null) {
            if ($application['je_status'] !== 'reversed'
                || $application['reversal_status'] !== 'posted'
                || $application['allocation_reversed_at'] === null
                || (int) $application['allocation_reversal_je_id'] !== (int) $application['reversal_je_id']) {
                $flags[] = 'application_reversal_mismatch';
            }
            continue;
        }
        $applied += (float) $application['amount'];
        if ($application['je_status'] !== 'posted'
            || $application['allocation_reversed_at'] !== null
            || (int) $application['application_je_id'] !== (int) $application['journal_entry_id']
            || abs((float) $application['amount_applied'] - (float) $application['amount']) > 0.005) {
            $flags[] = 'application_link_mismatch';
            continue;
        }
        $lines = $linesFor((int) $application['journal_entry_id']);
        if (abs((float) ($lines['2300']['debit'] ?? 0) - (float) $application['amount']) > 0.005
            || abs((float) ($lines['1100']['credit'] ?? 0) - (float) $application['amount']) > 0.005) {
            $flags[] = 'application_journal_mismatch';
        }
    }
    $refundStmt->execute(['t' => $tenantId, 'p' => (int) $payment['id']]);
    $refunded = 0.0;
    foreach ($refundStmt->fetchAll(PDO::FETCH_ASSOC) as $refund) {
        if ($refund['reversed_at'] !== null) {
            if ($refund['je_status'] !== 'reversed' || $refund['reversal_status'] !== 'posted') {
                $flags[] = 'refund_reversal_mismatch';
            }
            continue;
        }
        $refunded += (float) $refund['amount'];
        if ($refund['je_status'] !== 'posted' || !$refund['gl_account_code']) {
            $flags[] = 'refund_link_mismatch';
            continue;
        }
        $lines = $linesFor((int) $refund['journal_entry_id']);
        if (abs((float) ($lines['2300']['debit'] ?? 0) - (float) $refund['amount']) > 0.005
            || abs((float) ($lines[$refund['gl_account_code']]['credit'] ?? 0) - (float) $refund['amount']) > 0.005) {
            $flags[] = 'refund_journal_mismatch';
        }
    }
    if (abs((float) $payment['held'] - $applied - $refunded
        - (float) $payment['unallocated_amount']) > 0.005) {
        $flags[] = 'deposit_balance_mismatch';
    }
    if ($flags) $issues[] = ['payment_id' => (int) $payment['id'], 'issues' => array_values(array_unique($flags))];
}

echo json_encode(['tenant_id' => $tenantId, 'deposits_checked' => $count,
    'issues_found' => count($issues), 'issues' => $issues], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit($issues ? 1 : 0);
