<?php
/** Rollback-only check of bank receipt payment-to-journal linkage. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'staging') {
    fwrite(STDERR, "This check is restricted to the staging CLI.\n");
    exit(2);
}
require_once __DIR__ . '/../modules/billing/lib/billing.php';

$tenantId = 0;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--tenant=([1-9][0-9]*)$/', $arg, $match)) $tenantId = (int) $match[1];
}
if ($tenantId <= 0) {
    fwrite(STDERR, "Use --tenant=ID with a CoreFlux CI Simulation tenant.\n");
    exit(2);
}
$GLOBALS['__cf_request_tenant_id'] = $tenantId;
$pdo = getDB();
$tenant = $pdo->prepare('SELECT name FROM tenants WHERE id = :t');
$tenant->execute(['t' => $tenantId]);
if (!in_array($tenant->fetchColumn(), ['CoreFlux CI Simulation', "CoreFlux CI Simulation {$tenantId}"], true)) {
    fwrite(STDERR, "This check only uses a CI Simulation tenant.\n");
    exit(2);
}
$paymentStmt = $pdo->prepare(
    'SELECT p.id, p.external_id, p.journal_entry_id
       FROM billing_payments p
      WHERE p.tenant_id = :t AND p.voided_at IS NULL
        AND p.source_system = "manual" AND p.external_id LIKE "bank-line:%"
      ORDER BY p.id LIMIT 100'
);
$paymentStmt->execute(['t' => $tenantId]);
$bankStmt = $pdo->prepare(
    'SELECT bl.matched_je_id
       FROM accounting_bank_statement_lines bl
       JOIN accounting_journal_entries je
         ON je.tenant_id = bl.tenant_id AND je.id = bl.matched_je_id
      WHERE bl.tenant_id = :t AND bl.id = :id
        AND bl.match_status = "matched" AND je.status = "posted"
        AND je.source_module = "billing"'
);
$candidate = null;
foreach ($paymentStmt->fetchAll(PDO::FETCH_ASSOC) as $payment) {
    if (!preg_match('/^bank-line:([1-9][0-9]*)(?::|$)/',
        (string) $payment['external_id'], $match)) continue;
    $bankStmt->execute(['t' => $tenantId, 'id' => (int) $match[1]]);
    $journalId = (int) $bankStmt->fetchColumn();
    if ($journalId > 0) {
        $candidate = ['payment' => $payment, 'journal_id' => $journalId];
        break;
    }
}
if (!$candidate) {
    fwrite(STDERR, "Seed a matched synthetic bank receipt first.\n");
    exit(2);
}

$checks = [];
$error = null;
$paymentId = (int) $candidate['payment']['id'];
$journalId = $candidate['journal_id'];
$readLink = $pdo->prepare('SELECT journal_entry_id FROM billing_payments WHERE tenant_id = :t AND id = :id');
try {
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE billing_payments SET journal_entry_id = NULL WHERE tenant_id = :t AND id = :id')
        ->execute(['t' => $tenantId, 'id' => $paymentId]);
    billingLinkBankReceiptJournal($tenantId, $paymentId, $journalId);
    $readLink->execute(['t' => $tenantId, 'id' => $paymentId]);
    $checks['links_payment_to_matched_posted_journal'] = (int) $readLink->fetchColumn() === $journalId;
    billingLinkBankReceiptJournal($tenantId, $paymentId, $journalId);
    $readLink->execute(['t' => $tenantId, 'id' => $paymentId]);
    $checks['exact_retry_keeps_same_link'] = (int) $readLink->fetchColumn() === $journalId;
    try {
        billingLinkBankReceiptJournal($tenantId, $paymentId, $journalId + 1000000);
        $checks['different_journal_is_rejected'] = false;
    } catch (RuntimeException $e) {
        $checks['different_journal_is_rejected'] = true;
    }
    $readLink->execute(['t' => $tenantId, 'id' => $paymentId]);
    $checks['rejected_link_does_not_change_payment'] = (int) $readLink->fetchColumn() === $journalId;
} catch (Throwable $e) {
    $error = $e->getMessage();
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
$readLink->execute(['t' => $tenantId, 'id' => $paymentId]);
$afterRollback = $readLink->fetchColumn();
$original = $candidate['payment']['journal_entry_id'];
$checks['fixture_restores_original_link'] = $original === null
    ? $afterRollback === null : (int) $afterRollback === (int) $original;
try {
    billingLinkBankReceiptJournal($tenantId, $paymentId, $journalId);
    $checks['link_requires_caller_transaction'] = false;
} catch (RuntimeException $e) {
    $checks['link_requires_caller_transaction'] = true;
}

echo json_encode(['tenant_id' => $tenantId, 'checks' => $checks, 'error' => $error],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit($error === null && $checks && !in_array(false, $checks, true) ? 0 : 1);
