<?php
/** Prove entity-scoped aging against two rollback-only subledgers on staging. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'staging') {
    fwrite(STDERR, "This check is restricted to the staging CLI.\n");
    exit(2);
}
require_once __DIR__ . '/../modules/accounting/lib/accounting.php';
require_once __DIR__ . '/../modules/billing/lib/billing.php';
require_once __DIR__ . '/../modules/ap/lib/ap.php';

$tenantId = 0;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--tenant=')) $tenantId = (int) substr($arg, 9);
}
if ($tenantId <= 0) {
    fwrite(STDERR, "Use --tenant=ID with a CoreFlux CI Simulation tenant.\n");
    exit(2);
}
$GLOBALS['__cf_request_tenant_id'] = $tenantId;
$pdo = getDB();
$tenantStmt = $pdo->prepare('SELECT name FROM tenants WHERE id = :id');
$tenantStmt->execute(['id' => $tenantId]);
if (!in_array($tenantStmt->fetchColumn(), ['CoreFlux CI Simulation', "CoreFlux CI Simulation {$tenantId}"], true)) {
    fwrite(STDERR, "This check can only run against a disposable CI Simulation tenant.\n");
    exit(2);
}
$entityStmt = $pdo->prepare(
    'SELECT id, base_currency FROM accounting_entities
      WHERE tenant_id = :t AND active = 1 ORDER BY id LIMIT 1'
);
$entityStmt->execute(['t' => $tenantId]);
$firstEntity = $entityStmt->fetch(PDO::FETCH_ASSOC);
if (!$firstEntity) {
    fwrite(STDERR, "Seed an active synthetic accounting entity first.\n");
    exit(2);
}

$checks = [];
$error = null;
$secondEntityId = 0;
$secondInvoiceId = 0;
$tag = bin2hex(random_bytes(6));
$asOf = date('Y-m-d');
$documentDate = date('Y-m-d', strtotime('-40 days'));
$dueDate = date('Y-m-d', strtotime('-15 days'));
$client = ['CoreOne AR A ' . $tag, 'CoreOne AR B ' . $tag];
$voidClient = 'CoreOne AR void ' . $tag;
$vendor = ['CoreOne AP A ' . $tag, 'CoreOne AP B ' . $tag];
$totalFor = static function (array $rows, string $name, string $key): ?float {
    foreach ($rows as $row) {
        if ((string) ($row[$key] ?? '') === $name) return (float) $row['total_due'];
    }
    return null;
};

try {
    $pdo->beginTransaction();
    $pdo->prepare(
        'INSERT INTO accounting_entities (tenant_id, code, legal_name, base_currency, active)
         VALUES (:t, :code, :name, :currency, 1)'
    )->execute(['t' => $tenantId, 'code' => 'SIM-AGING-' . $tag,
        'name' => 'Rollback-only aging entity', 'currency' => $firstEntity['base_currency']]);
    $secondEntityId = (int) $pdo->lastInsertId();
    $entityIds = [(int) $firstEntity['id'], $secondEntityId];

    foreach ($entityIds as $index => $entityId) {
        $ar = $index === 0 ? '100.00' : '200.00';
        $ap = $index === 0 ? '30.00' : '40.00';
        $invoiceNumber = 'SIM-AGING-INV-' . $tag . '-' . $index;
        $pdo->prepare(
            'INSERT INTO billing_invoices
                (tenant_id, entity_id, invoice_number, client_name, currency,
                 issue_date, due_date, subtotal, total, amount_due, status)
             VALUES (:t, :e, :number, :name, :currency, :issued, :due, :amount, :total, :open, "sent")'
        )->execute(['t' => $tenantId, 'e' => $entityId, 'number' => $invoiceNumber,
            'name' => $client[$index], 'currency' => $firstEntity['base_currency'],
            'issued' => $documentDate, 'due' => $dueDate,
            'amount' => $ar, 'total' => $ar, 'open' => $ar]);
        $invoiceId = (int) $pdo->lastInsertId();
        if ($index === 1) $secondInvoiceId = $invoiceId;
        $invoiceJe = accountingPostJe($tenantId, [
            'entity_id' => $entityId, 'posting_date' => $documentDate,
            'currency' => $firstEntity['base_currency'], 'source_module' => 'billing',
            'source_ref_type' => 'invoice', 'source_ref_id' => $invoiceId,
            'idempotency_key' => 'sim.aging.invoice.' . $tag . '.' . $index,
            'lines' => [
                ['account_code' => '1100', 'debit' => $ar, 'credit' => 0],
                ['account_code' => '4000', 'debit' => 0, 'credit' => $ar],
            ],
        ]);
        $pdo->prepare('UPDATE billing_invoices SET journal_entry_id = :je WHERE tenant_id = :t AND id = :id')
            ->execute(['je' => $invoiceJe['je_id'], 't' => $tenantId, 'id' => $invoiceId]);

        $billNumber = 'SIM-AGING-BILL-' . $tag . '-' . $index;
        $pdo->prepare(
            'INSERT INTO ap_bills
                (tenant_id, entity_id, bill_number, internal_ref, vendor_name,
                 received_at, bill_date, due_date, currency, subtotal, total, amount_due, status)
             VALUES (:t, :e, :number, :reference, :name, :received, :bill_date, :due,
                     :currency, :amount, :total, :open, "approved")'
        )->execute(['t' => $tenantId, 'e' => $entityId, 'number' => $billNumber,
            'reference' => $billNumber, 'name' => $vendor[$index],
            'received' => $documentDate, 'bill_date' => $documentDate, 'due' => $dueDate,
            'currency' => $firstEntity['base_currency'],
            'amount' => $ap, 'total' => $ap, 'open' => $ap]);
        $billId = (int) $pdo->lastInsertId();
        $billJe = accountingPostJe($tenantId, [
            'entity_id' => $entityId, 'posting_date' => $documentDate,
            'currency' => $firstEntity['base_currency'], 'source_module' => 'ap',
            'source_ref_type' => 'bill', 'source_ref_id' => $billId,
            'idempotency_key' => 'sim.aging.bill.' . $tag . '.' . $index,
            'lines' => [
                ['account_code' => '6990', 'debit' => $ap, 'credit' => 0],
                ['account_code' => '2000', 'debit' => 0, 'credit' => $ap],
            ],
        ]);
        $pdo->prepare('UPDATE ap_bills SET journal_entry_id = :je WHERE tenant_id = :t AND id = :id')
            ->execute(['je' => $billJe['je_id'], 't' => $tenantId, 'id' => $billId]);
    }

    $allAr = billingComputeAging($tenantId, $asOf);
    $firstAr = billingComputeAging($tenantId, $asOf, $entityIds[0]);
    $secondAr = billingComputeAging($tenantId, $asOf, $entityIds[1]);
    $allAp = apComputeAging($tenantId, $asOf);
    $firstAp = apComputeAging($tenantId, $asOf, $entityIds[0]);
    $secondAp = apComputeAging($tenantId, $asOf, $entityIds[1]);

    $checks['tenant_wide_ar_still_sees_both'] =
        $totalFor($allAr, $client[0], 'client_name') === 100.0
        && $totalFor($allAr, $client[1], 'client_name') === 200.0;
    $checks['entity_a_ar_excludes_entity_b'] =
        $totalFor($firstAr, $client[0], 'client_name') === 100.0
        && $totalFor($firstAr, $client[1], 'client_name') === null;
    $checks['entity_b_ar_excludes_entity_a'] =
        $totalFor($secondAr, $client[1], 'client_name') === 200.0
        && $totalFor($secondAr, $client[0], 'client_name') === null;
    $checks['tenant_wide_ap_still_sees_both'] =
        $totalFor($allAp, $vendor[0], 'vendor_name') === 30.0
        && $totalFor($allAp, $vendor[1], 'vendor_name') === 40.0;
    $checks['entity_a_ap_excludes_entity_b'] =
        $totalFor($firstAp, $vendor[0], 'vendor_name') === 30.0
        && $totalFor($firstAp, $vendor[1], 'vendor_name') === null;
    $checks['entity_b_ap_excludes_entity_a'] =
        $totalFor($secondAp, $vendor[1], 'vendor_name') === 40.0
        && $totalFor($secondAp, $vendor[0], 'vendor_name') === null;
    $trial = accountingTrialBalance($tenantId, $asOf, $entityIds[1]);
    $accountBalance = static function (array $rows, string $code): ?float {
        foreach ($rows as $row) {
            if ((string) ($row['code'] ?? '') === $code) return (float) $row['balance_signed'];
        }
        return null;
    };
    $checks['entity_b_aging_agrees_with_its_gl_controls'] =
        $totalFor($secondAr, $client[1], 'client_name') === $accountBalance($trial, '1100')
        && $totalFor($secondAp, $vendor[1], 'vendor_name') === $accountBalance($trial, '2000');
    $checks['entity_b_aging_uses_due_date_bucket'] =
        (float) ($secondAr[0]['bucket_1_30'] ?? 0) === 200.0
        && (float) ($secondAp[0]['bucket_1_30'] ?? 0) === 40.0;
    $beforeDocuments = date('Y-m-d', strtotime($documentDate . ' -1 day'));
    $checks['documents_do_not_age_before_issue_date'] =
        $totalFor(billingComputeAging($tenantId, $beforeDocuments, $entityIds[1]),
            $client[1], 'client_name') === null
        && $totalFor(apComputeAging($tenantId, $beforeDocuments, $entityIds[1]),
            $vendor[1], 'vendor_name') === null;

    $depositDate = date('Y-m-d', strtotime('-20 days'));
    $applicationDate = date('Y-m-d', strtotime('-5 days'));
    $beforeApplication = date('Y-m-d', strtotime('-6 days'));
    $depositJe = accountingPostJe($tenantId, [
        'entity_id' => $entityIds[1], 'posting_date' => $depositDate,
        'currency' => $firstEntity['base_currency'], 'source_module' => 'billing',
        'source_ref_type' => 'customer_deposit',
        'idempotency_key' => 'sim.aging.deposit.' . $tag,
        'lines' => [
            ['account_code' => '1000', 'debit' => '50.00', 'credit' => 0],
            ['account_code' => '2300', 'debit' => 0, 'credit' => '50.00'],
        ],
    ]);
    $applicationJe = accountingPostJe($tenantId, [
        'entity_id' => $entityIds[1], 'posting_date' => $applicationDate,
        'currency' => $firstEntity['base_currency'], 'source_module' => 'billing',
        'source_ref_type' => 'customer_deposit_application',
        'idempotency_key' => 'sim.aging.deposit.application.' . $tag,
        'lines' => [
            ['account_code' => '2300', 'debit' => '50.00', 'credit' => 0],
            ['account_code' => '1100', 'debit' => 0, 'credit' => '50.00'],
        ],
    ]);
    $pdo->prepare(
        'INSERT INTO billing_payments
            (tenant_id, client_name, received_at, amount, currency, unallocated_amount, journal_entry_id)
         VALUES (:t, :name, :received, 50.00, :currency, 0.00, :je)'
    )->execute(['t' => $tenantId, 'name' => $client[1], 'received' => $depositDate,
        'currency' => $firstEntity['base_currency'], 'je' => $depositJe['je_id']]);
    $paymentId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO billing_payment_allocations
            (payment_id, invoice_id, amount_applied, applied_at, application_je_id)
         VALUES (:payment, :invoice, 50.00, :applied, :je)'
    )->execute(['payment' => $paymentId, 'invoice' => $secondInvoiceId,
        'applied' => $applicationDate . ' 00:00:00', 'je' => $applicationJe['je_id']]);
    $allocationId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'UPDATE billing_invoices SET amount_paid = 50.00, amount_due = 150.00, status = "partially_paid"
          WHERE tenant_id = :t AND id = :id'
    )->execute(['t' => $tenantId, 'id' => $secondInvoiceId]);
    $beforeAging = billingComputeAging($tenantId, $beforeApplication, $entityIds[1]);
    $afterAging = billingComputeAging($tenantId, $asOf, $entityIds[1]);
    $beforeTrial = accountingTrialBalance($tenantId, $beforeApplication, $entityIds[1]);
    $afterTrial = accountingTrialBalance($tenantId, $asOf, $entityIds[1]);
    $checks['deposit_application_reduces_ar_only_on_its_posting_date'] =
        $totalFor($beforeAging, $client[1], 'client_name') === 200.0
        && $accountBalance($beforeTrial, '1100') === 200.0
        && $totalFor($afterAging, $client[1], 'client_name') === 150.0
        && $accountBalance($afterTrial, '1100') === 150.0;

    $applicationReversal = accountingReverseJe($tenantId,
        (int) $applicationJe['je_id'], 'Rollback-only deposit application correction');
    $pdo->prepare(
        'UPDATE billing_payment_allocations SET reversed_at = NOW(), reversal_je_id = :je
          WHERE id = :id AND payment_id = :payment'
    )->execute(['je' => $applicationReversal['je_id'],
        'id' => $allocationId, 'payment' => $paymentId]);
    $pdo->prepare(
        'UPDATE billing_invoices SET amount_paid = 0.00, amount_due = 200.00, status = "sent"
          WHERE tenant_id = :t AND id = :id'
    )->execute(['t' => $tenantId, 'id' => $secondInvoiceId]);
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $checks['deposit_application_reversal_restores_ar_from_reversal_date'] =
        $totalFor(billingComputeAging($tenantId, $yesterday, $entityIds[1]),
            $client[1], 'client_name') === 150.0
        && $accountBalance(accountingTrialBalance($tenantId, $yesterday, $entityIds[1]), '1100') === 150.0
        && $totalFor(billingComputeAging($tenantId, $asOf, $entityIds[1]),
            $client[1], 'client_name') === 200.0
        && $accountBalance(accountingTrialBalance($tenantId, $asOf, $entityIds[1]), '1100') === 200.0;

    $pdo->prepare(
        'INSERT INTO billing_invoices
            (tenant_id, entity_id, invoice_number, client_name, currency,
             issue_date, due_date, subtotal, total, amount_due, status)
         VALUES (:t, :e, :number, :name, :currency, :issued, :due, 25.00, 25.00, 25.00, "sent")'
    )->execute(['t' => $tenantId, 'e' => $entityIds[1],
        'number' => 'SIM-AGING-VOID-' . $tag, 'name' => $voidClient,
        'currency' => $firstEntity['base_currency'], 'issued' => $documentDate, 'due' => $dueDate]);
    $voidInvoiceId = (int) $pdo->lastInsertId();
    $voidInvoiceJe = accountingPostJe($tenantId, [
        'entity_id' => $entityIds[1], 'posting_date' => $documentDate,
        'currency' => $firstEntity['base_currency'], 'source_module' => 'billing',
        'source_ref_type' => 'invoice', 'source_ref_id' => $voidInvoiceId,
        'idempotency_key' => 'sim.aging.void.invoice.' . $tag,
        'lines' => [
            ['account_code' => '1100', 'debit' => '25.00', 'credit' => 0],
            ['account_code' => '4000', 'debit' => 0, 'credit' => '25.00'],
        ],
    ]);
    $pdo->prepare('UPDATE billing_invoices SET journal_entry_id = :je WHERE tenant_id = :t AND id = :id')
        ->execute(['je' => $voidInvoiceJe['je_id'], 't' => $tenantId, 'id' => $voidInvoiceId]);
    accountingReverseJe($tenantId, (int) $voidInvoiceJe['je_id'], 'Rollback-only historical aging');
    $pdo->prepare(
        'UPDATE billing_invoices SET status = "void", voided_at = NOW()
          WHERE tenant_id = :t AND id = :id'
    )->execute(['t' => $tenantId, 'id' => $voidInvoiceId]);
    $checks['later_invoice_void_preserves_earlier_aging'] =
        $totalFor(billingComputeAging($tenantId, $yesterday, $entityIds[1]),
            $voidClient, 'client_name') === 25.0
        && $totalFor(billingComputeAging($tenantId, $asOf, $entityIds[1]),
            $voidClient, 'client_name') === null;
} catch (Throwable $e) {
    $error = $e->getMessage();
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}

$entityCount = $pdo->prepare('SELECT COUNT(*) FROM accounting_entities WHERE tenant_id = :t AND id = :id');
$entityCount->execute(['t' => $tenantId, 'id' => $secondEntityId]);
$checks['fixture_left_no_second_entity'] = (int) $entityCount->fetchColumn() === 0;
$invoiceCount = $pdo->prepare('SELECT COUNT(*) FROM billing_invoices WHERE tenant_id = :t AND client_name IN (:a, :b, :c)');
$invoiceCount->execute(['t' => $tenantId, 'a' => $client[0], 'b' => $client[1], 'c' => $voidClient]);
$checks['fixture_left_no_invoices'] = (int) $invoiceCount->fetchColumn() === 0;
$billCount = $pdo->prepare('SELECT COUNT(*) FROM ap_bills WHERE tenant_id = :t AND vendor_name IN (:a, :b)');
$billCount->execute(['t' => $tenantId, 'a' => $vendor[0], 'b' => $vendor[1]]);
$checks['fixture_left_no_bills'] = (int) $billCount->fetchColumn() === 0;

echo json_encode(['tenant_id' => $tenantId, 'checks' => $checks, 'error' => $error],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit($error === null && $checks && !in_array(false, $checks, true) ? 0 : 1);
