<?php
/** Real-MySQL processor payout and correction exercise for the synthetic staging tenant. */
declare(strict_types=1);

if (getenv('COREFLUX_ENV') !== 'staging' || PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This check is restricted to the staging CLI.\n");
    exit(2);
}
require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../modules/billing/lib/processor_payouts.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
});

$tenantId = 999;
$pdo = getDB();
if ($pdo->query('SELECT name FROM tenants WHERE id = 999')->fetchColumn() !== 'CoreFlux CI Simulation') {
    throw new RuntimeException('Synthetic staging tenant is missing.');
}
$_SESSION['tenant_id'] = $tenantId;
$chargeStmt = $pdo->prepare(
    'SELECT p.id AS payment_id, p.amount, p.journal_entry_id
       FROM qbo_payment_charges q
       JOIN billing_payments p ON p.tenant_id = q.tenant_id AND p.id = q.coreflux_payment_id
      WHERE q.tenant_id = :tenant_id AND q.qbo_charge_id = :charge_id LIMIT 1'
);
$chargeStmt->execute(['tenant_id' => $tenantId, 'charge_id' => 'STG-COREACCT-QBO-001']);
$capture = $chargeStmt->fetch(PDO::FETCH_ASSOC);
if (!$capture || round((float) $capture['amount'], 2) !== 1.0) {
    throw new RuntimeException('The synthetic $1 processor capture is missing.');
}
$paymentId = (int) $capture['payment_id'];
$bankStmt = $pdo->prepare(
    'SELECT id, gl_account_code FROM accounting_bank_accounts
      WHERE tenant_id = :tenant_id AND name = :name AND status = "active" LIMIT 1'
);
$bankStmt->execute(['tenant_id' => $tenantId, 'name' => 'Staging Test Operating']);
$bank = $bankStmt->fetch(PDO::FETCH_ASSOC);
if (!$bank) throw new RuntimeException('Synthetic staging bank account is missing.');
$feeAccountStmt = $pdo->prepare(
    'SELECT id, code FROM accounting_accounts
      WHERE tenant_id = :tenant_id AND account_type = "expense" AND active = 1 AND is_postable = 1
      ORDER BY id LIMIT 1'
);
$feeAccountStmt->execute(['tenant_id' => $tenantId]);
$feeAccount = $feeAccountStmt->fetch(PDO::FETCH_ASSOC);
if (!$feeAccount) throw new RuntimeException('Synthetic staging fee expense account is missing.');
$feeAccountId = (int) $feeAccount['id'];

$fitid = 'stg-processor-payout-qbo-1';
bankRecImportCsv($tenantId, (int) $bank['id'],
    "Date,Description,Amount,Transaction ID\n2026-10-02,Synthetic QBO net payout,0.97,$fitid\n", null, null);
$lineStmt = $pdo->prepare(
    'SELECT id, match_status, matched_je_id FROM accounting_bank_statement_lines
      WHERE tenant_id = :tenant_id AND bank_account_id = :bank_account_id AND fitid = :fitid LIMIT 1'
);
$lineStmt->execute(['tenant_id' => $tenantId, 'bank_account_id' => (int) $bank['id'], 'fitid' => $fitid]);
$line = $lineStmt->fetch(PDO::FETCH_ASSOC);
if (!$line) throw new RuntimeException('Synthetic payout bank line was not imported.');
$lineId = (int) $line['id'];
if ($line['match_status'] === 'matched') {
    billingCorrectProcessorPayout($tenantId, $lineId, 'Reset previous synthetic payout check', null);
}
$checks = [];
$check = static function (string $label, bool $passed) use (&$checks): void {
    $checks[$label] = $passed;
};
$rejects = static function (callable $operation): bool {
    try { $operation(); return false; }
    catch (InvalidArgumentException|RuntimeException $e) { return true; }
};
$invoicePaidStmt = $pdo->prepare(
    'SELECT i.amount_paid FROM billing_payment_allocations a
      JOIN billing_invoices i ON i.tenant_id = :tenant_id AND i.id = a.invoice_id
     WHERE a.payment_id = :payment_id AND a.reversed_at IS NULL LIMIT 1'
);
$invoicePaidStmt->execute(['tenant_id' => $tenantId, 'payment_id' => $paymentId]);
$invoicePaidBefore = (float) $invoicePaidStmt->fetchColumn();

$candidates = billingProcessorPayoutCandidates($tenantId, $lineId);
$check('capture offered for same entity and currency', in_array($paymentId,
    array_map('intval', array_column($candidates['payments'], 'payment_id')), true));
$pdo->beginTransaction();
try {
    $pdo->prepare(
        'INSERT INTO accounting_subledger_links
            (tenant_id, source_module, source_record_id, journal_entry_id, link_kind)
         VALUES (:tenant_id, "treasury_feed", :source_record_id, :journal_entry_id, "primary")'
    )->execute(['tenant_id' => $tenantId, 'source_record_id' => 'bank_line:split:' . $lineId,
        'journal_entry_id' => (int) $capture['journal_entry_id']]);
    $bookedLineRejected = $rejects(static fn() => billingProcessorPayoutCandidates($tenantId, $lineId));
} finally {
    $pdo->rollBack();
}
$check('posted source lineage blocks a second cash entry', $bookedLineRejected);
$check('changed fee rejected', $rejects(static fn() => billingSettleProcessorPayout(
    $tenantId, $lineId, [$paymentId], 0.02, $feeAccountId, null)));
$check('fee account required', $rejects(static fn() => billingSettleProcessorPayout(
    $tenantId, $lineId, [$paymentId], 0.03, null, null)));
$check('duplicate capture rejected', $rejects(static fn() => billingSettleProcessorPayout(
    $tenantId, $lineId, [$paymentId, $paymentId], 0.03, $feeAccountId, null)));
$check('other tenant rejected', $rejects(static fn() => billingSettleProcessorPayout(
    998, $lineId, [$paymentId], 0.03, $feeAccountId, null)));

$posted = billingSettleProcessorPayout($tenantId, $lineId, [$paymentId], 0.03, $feeAccountId, null);
$eventStmt = $pdo->prepare(
    'SELECT e.id, e.status, e.journal_entry_id, l.accounting_event_id
       FROM accounting_events e
       LEFT JOIN accounting_subledger_links l ON l.tenant_id = e.tenant_id
        AND l.source_module = e.source_module AND l.source_record_id = e.source_record_id
        AND l.journal_entry_id = e.journal_entry_id AND l.link_kind = "primary"
      WHERE e.tenant_id = :tenant_id AND e.source_module = "billing"
        AND e.source_record_id = :source_record_id
        AND e.event_type = "billing.processor_payout.settled" LIMIT 1'
);
$eventStmt->execute(['tenant_id' => $tenantId,
    'source_record_id' => 'processor_payout:' . (int) $posted['payout_id']]);
$postedEvent = $eventStmt->fetch(PDO::FETCH_ASSOC);
$check('payout has posted event and primary source link', $postedEvent
    && $postedEvent['status'] === 'posted'
    && (int) $postedEvent['journal_entry_id'] === (int) $posted['journal_entry_id']
    && (int) $postedEvent['accounting_event_id'] === (int) $postedEvent['id']);
$replay = billingSettleProcessorPayout($tenantId, $lineId, [$paymentId], 0.03, $feeAccountId, null);
$check('exact retry returns same payout', !empty($replay['idempotent_replay'])
    && (int) $replay['payout_id'] === (int) $posted['payout_id']);
$check('changed retry rejected', $rejects(static fn() => billingSettleProcessorPayout(
    $tenantId, $lineId, [$paymentId], 0.03, $feeAccountId + 100000, null)));
$check('matched line no longer offers captures', $rejects(static fn() =>
    billingProcessorPayoutCandidates($tenantId, $lineId)));
$check('generic bank unmatch refused', $rejects(static fn() => bankRecUnmatchLine($tenantId, $lineId)));

$journalStmt = $pdo->prepare(
    'SELECT a.code, ROUND(SUM(l.debit), 2) AS debit, ROUND(SUM(l.credit), 2) AS credit
       FROM accounting_journal_entry_lines l
       JOIN accounting_accounts a ON a.tenant_id = l.tenant_id AND a.id = l.account_id
      WHERE l.tenant_id = :tenant_id AND l.je_id = :journal_id GROUP BY a.code'
);
$journalStmt->execute(['tenant_id' => $tenantId, 'journal_id' => (int) $posted['journal_entry_id']]);
$journalLines = [];
foreach ($journalStmt->fetchAll(PDO::FETCH_ASSOC) as $row) $journalLines[$row['code']] = $row;
$check('net cash debit', abs((float) ($journalLines[$bank['gl_account_code']]['debit'] ?? 0) - 0.97) < 0.005);
$check('fee expense debit', abs((float) ($journalLines[$feeAccount['code']]['debit'] ?? 0) - 0.03) < 0.005);
$check('gross clearing credit', abs((float) ($journalLines['1010']['credit'] ?? 0) - 1.0) < 0.005);

$corrected = billingCorrectProcessorPayout($tenantId, $lineId, 'Correct synthetic payout selection', null);
$check('correction creates reversal', (int) $corrected['reversal_je_id'] > 0);
$eventStmt->execute(['tenant_id' => $tenantId,
    'source_record_id' => 'processor_payout:' . (int) $posted['payout_id']]);
$reversedEvent = $eventStmt->fetch(PDO::FETCH_ASSOC);
$check('corrected payout marks event reversed', $reversedEvent && $reversedEvent['status'] === 'reversed');
$lineStmt->execute(['tenant_id' => $tenantId, 'bank_account_id' => (int) $bank['id'], 'fitid' => $fitid]);
$check('correction reopens bank line', $lineStmt->fetch(PDO::FETCH_ASSOC)['match_status'] === 'unmatched');
$check('second correction refused', $rejects(static fn() => billingCorrectProcessorPayout(
    $tenantId, $lineId, 'Repeat correction', null)));
$check('capture available after correction', in_array($paymentId,
    array_map('intval', array_column(billingProcessorPayoutCandidates($tenantId, $lineId)['payments'], 'payment_id')), true));
$replacement = billingSettleProcessorPayout($tenantId, $lineId, [$paymentId], 0.03, $feeAccountId, null);
$check('corrected payout can be replaced', (int) $replacement['payout_id'] !== (int) $posted['payout_id']);
$eventStmt->execute(['tenant_id' => $tenantId,
    'source_record_id' => 'processor_payout:' . (int) $replacement['payout_id']]);
$replacementEvent = $eventStmt->fetch(PDO::FETCH_ASSOC);
$check('replacement has its own posted event', $replacementEvent && $replacementEvent['status'] === 'posted');
$invoicePaidStmt->execute(['tenant_id' => $tenantId, 'payment_id' => $paymentId]);
$check('invoice application unaffected', abs((float) $invoicePaidStmt->fetchColumn() - $invoicePaidBefore) < 0.005);
$lineStmt->execute(['tenant_id' => $tenantId, 'bank_account_id' => (int) $bank['id'], 'fitid' => $fitid]);
$check('replacement matches bank line', $lineStmt->fetch(PDO::FETCH_ASSOC)['match_status'] === 'matched');

echo json_encode(['checks' => $checks, 'passed' => count(array_filter($checks)),
    'total' => count($checks), 'payout_id' => (int) $replacement['payout_id']], JSON_PRETTY_PRINT) . "\n";
exit(count(array_filter($checks)) === count($checks) ? 0 : 1);
