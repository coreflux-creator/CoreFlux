<?php
/** Read-only reconciliation of processor payout sources, bank matches and journals. */
declare(strict_types=1);

if (getenv('COREFLUX_ENV') !== 'staging' || PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This audit is restricted to the staging CLI.\n");
    exit(2);
}
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';
$tenantArgs = array_values(array_filter($argv, static fn(string $arg): bool => str_starts_with($arg, '--tenant=')));
$tenantId = count($tenantArgs) === 1 ? (int) substr($tenantArgs[0], 9) : 0;
if ($tenantId <= 0) {
    fwrite(STDERR, "Use --tenant=ID.\n");
    exit(2);
}
$pdo = getDB();
$stmt = $pdo->prepare(
    'SELECT payout.*, bl.bank_account_id AS current_bank_account_id,
            bl.match_status, bl.matched_je_id, bl.amount AS bank_amount,
            ba.gl_account_code, je.status AS journal_status, je.source_module,
            je.source_ref_type, je.source_ref_id, reversal.status AS reversal_status
       FROM billing_processor_payouts payout
       JOIN accounting_bank_statement_lines bl
         ON bl.tenant_id = payout.tenant_id AND bl.id = payout.bank_line_id
       JOIN accounting_bank_accounts ba
         ON ba.tenant_id = payout.tenant_id AND ba.id = payout.bank_account_id
       LEFT JOIN accounting_journal_entries je
         ON je.tenant_id = payout.tenant_id AND je.id = payout.journal_entry_id
       LEFT JOIN accounting_journal_entries reversal
         ON reversal.tenant_id = payout.tenant_id AND reversal.id = payout.reversal_je_id
      WHERE payout.tenant_id = :tenant_id ORDER BY payout.id'
);
$stmt->execute(['tenant_id' => $tenantId]);
$payouts = $stmt->fetchAll(PDO::FETCH_ASSOC);
$linkStmt = $pdo->prepare(
    'SELECT link.payment_id, link.gross_amount, payment.amount, payment.journal_entry_id,
            payment.voided_at, payment.source_system
       FROM billing_processor_payout_payments link
       LEFT JOIN billing_payments payment
         ON payment.tenant_id = link.tenant_id AND payment.id = link.payment_id
      WHERE link.tenant_id = :tenant_id AND link.payout_id = :payout_id'
);
$journalStmt = $pdo->prepare(
    'SELECT account.code, account.account_type,
            ROUND(SUM(line.debit), 2) AS debit, ROUND(SUM(line.credit), 2) AS credit
       FROM accounting_journal_entry_lines line
       JOIN accounting_accounts account
         ON account.tenant_id = line.tenant_id AND account.id = line.account_id
      WHERE line.tenant_id = :tenant_id AND line.je_id = :journal_id
      GROUP BY account.code, account.account_type'
);
$sourceLinkStmt = $pdo->prepare(
    'SELECT id FROM accounting_subledger_links
      WHERE tenant_id = :tenant_id AND source_module = "billing"
        AND source_record_id = :source_record_id
        AND journal_entry_id = :journal_entry_id AND link_kind = :link_kind LIMIT 1'
);
$issues = [];
$activePaymentCounts = [];
$activeLineCounts = [];
foreach ($payouts as $payout) {
    $id = (int) $payout['id'];
    $gross = (int) round((float) $payout['gross_amount'] * 100);
    $fee = (int) round((float) $payout['fee_amount'] * 100);
    $net = (int) round((float) $payout['net_amount'] * 100);
    $bank = (int) round((float) $payout['bank_amount'] * 100);
    if ($gross <= 0 || $fee < 0 || $net <= 0 || $gross !== $fee + $net || $net !== $bank
        || (int) $payout['bank_account_id'] !== (int) $payout['current_bank_account_id']) {
        $issues[] = "Payout $id amounts disagree with the bank line.";
    }
    if ($payout['source_module'] !== 'billing' || $payout['source_ref_type'] !== 'processor_payout'
        || (int) $payout['source_ref_id'] !== $id) {
        $issues[] = "Payout $id has invalid journal source lineage.";
    }
    $sourceLinkStmt->execute(['tenant_id' => $tenantId, 'source_record_id' => 'processor_payout:' . $id,
        'journal_entry_id' => (int) $payout['journal_entry_id'], 'link_kind' => 'primary']);
    if (!$sourceLinkStmt->fetchColumn()) $issues[] = "Payout $id is missing its primary source link.";
    $linkStmt->execute(['tenant_id' => $tenantId, 'payout_id' => $id]);
    $links = $linkStmt->fetchAll(PDO::FETCH_ASSOC);
    $linkedGross = 0;
    if (!$links) $issues[] = "Payout $id has no captured payments.";
    foreach ($links as $link) {
        $paymentId = (int) $link['payment_id'];
        $linkedGross += (int) round((float) $link['gross_amount'] * 100);
        if ($link['source_system'] !== 'qbo' || $link['voided_at'] !== null
            || (int) $link['journal_entry_id'] <= 0
            || (int) round((float) $link['amount'] * 100) !== (int) round((float) $link['gross_amount'] * 100)) {
            $issues[] = "Payout $id payment $paymentId is missing, void or changed.";
        }
        if ($payout['status'] === 'posted') {
            $activePaymentCounts[$paymentId] = ($activePaymentCounts[$paymentId] ?? 0) + 1;
        }
    }
    if ($linkedGross !== $gross) $issues[] = "Payout $id linked captures do not equal gross.";

    $journalStmt->execute(['tenant_id' => $tenantId, 'journal_id' => (int) $payout['journal_entry_id']]);
    $lines = [];
    foreach ($journalStmt->fetchAll(PDO::FETCH_ASSOC) as $line) $lines[$line['code']] = $line;
    $cashDebit = (int) round((float) ($lines[$payout['gl_account_code']]['debit'] ?? 0) * 100);
    $clearingCredit = (int) round((float) ($lines['1010']['credit'] ?? 0) * 100);
    $feeDebit = 0;
    foreach ($lines as $code => $line) {
        if ($line['account_type'] === 'expense') $feeDebit += (int) round((float) $line['debit'] * 100);
    }
    if ($cashDebit !== $net || $clearingCredit !== $gross || $feeDebit !== $fee) {
        $issues[] = "Payout $id journal cash, clearing or fee lines disagree.";
    }
    if ($payout['status'] === 'posted') {
        $activeLineCounts[(int) $payout['bank_line_id']] = ($activeLineCounts[(int) $payout['bank_line_id']] ?? 0) + 1;
        if ($payout['journal_status'] !== 'posted' || $payout['match_status'] !== 'matched'
            || (int) $payout['matched_je_id'] !== (int) $payout['journal_entry_id']
            || $payout['reversal_je_id'] !== null) {
            $issues[] = "Active payout $id is not matched to its posted journal.";
        }
    } elseif ($payout['status'] === 'corrected') {
        $sourceLinkStmt->execute(['tenant_id' => $tenantId, 'source_record_id' => 'processor_payout:' . $id,
            'journal_entry_id' => (int) $payout['reversal_je_id'], 'link_kind' => 'reversal']);
        if ($payout['journal_status'] !== 'reversed' || $payout['reversal_status'] !== 'posted'
            || !$payout['correction_reason'] || (int) $payout['matched_je_id'] === (int) $payout['journal_entry_id']
            || !$sourceLinkStmt->fetchColumn()) {
            $issues[] = "Corrected payout $id has incomplete reversal lineage.";
        }
    } else {
        $issues[] = "Payout $id has an unexpected committed status.";
    }
}
foreach ($activePaymentCounts as $paymentId => $count) {
    if ($count !== 1) $issues[] = "Payment $paymentId appears in $count active payouts.";
}
foreach ($activeLineCounts as $lineId => $count) {
    if ($count !== 1) $issues[] = "Bank line $lineId has $count active payouts.";
}
echo json_encode(['tenant_id' => $tenantId, 'payouts' => count($payouts),
    'active_payouts' => array_sum($activeLineCounts), 'issues' => $issues], JSON_PRETTY_PRINT) . "\n";
exit($issues ? 1 : 0);
