<?php
/**
 * AP payment workflow regression contract.
 *
 * Protects the operator path from approved bill through payment run,
 * release, bank clearing, ledger posting, and CSV round-trip controls.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;
$assert = static function (string $label, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

$lib = (string) file_get_contents($root . '/modules/ap/lib/ap.php');
$payments = (string) file_get_contents($root . '/modules/ap/api/payments.php');
$bills = (string) file_get_contents($root . '/modules/ap/api/bills.php');
$paymentsUi = (string) file_get_contents($root . '/modules/ap/ui/PaymentsList.jsx');
$billsUi = (string) file_get_contents($root . '/modules/ap/ui/BillsList.jsx');
$csvImport = (string) file_get_contents($root . '/modules/ap/api/payments_csv_import.php');
$csvExport = (string) file_get_contents($root . '/modules/ap/api/payments_csv_export.php');
$dataset = (string) file_get_contents($root . '/core/export_datasets.php');
$migration = (string) file_get_contents($root . '/modules/ap/migrations/020_payment_entity_scope.sql');

echo "Reservation and payment-run integrity\n";
$assert('payment suggestions subtract draft and queued reservations',
    str_contains($lib, "p.status IN ('draft', 'queued')")
    && str_contains($lib, '$availableDue = max(0'));
$assert('payment-run execution rechecks live reservations',
    str_contains($lib, 'AS reserved_amount')
    && str_contains($lib, "amount_due'] - (float) (\$b['reserved_amount']"));
$assert('payment runs are all-or-nothing transactions',
    str_contains($lib, '$ownsTransaction = !$pdo->inTransaction()')
    && str_contains($lib, 'if ($ownsTransaction) $pdo->commit()')
    && str_contains($lib, 'if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack()'));
$assert('stale vendor and PWP rows fail instead of being skipped',
    str_contains($lib, 'belongs to {$b[\'vendor_name\']}, not {$vendorName}')
    && str_contains($lib, 'is still waiting for the linked client payment'));
$assert('draft allocations do not settle bills',
    str_contains($lib, '$released = in_array($pay[\'status\'], [\'sent\', \'cleared\'], true)')
    && str_contains($lib, "p.status IN (\"sent\", \"cleared\")"));
$assert('release refreshes every allocated bill atomically',
    str_contains($payments, 'apRefreshReleasedPaymentBillsForPayment($pdo, $tid, $id)'));
$assert('release refuses any unallocated remainder',
    str_contains($payments, "'code' => 'payment_not_fully_allocated'")
    && str_contains($payments, 'Allocate the full payment before releasing it.'));

echo "Queue accuracy and bulk usability\n";
$assert('ready-to-pay rows exclude fully reserved bills',
    str_contains($bills, 'ready_payment.status IN ("draft", "queued")')
    && str_contains($bills, ") > 0.005';"));
$assert('ready-to-pay summary subtracts reservations',
    str_contains($bills, 'GREATEST(amount_due - payment_reserved, 0)')
    && str_contains($bills, 'AS reserved_amount'));
$assert('payment list supports server search and pagination',
    str_contains($payments, "reference LIKE :q_reference")
    && str_contains($payments, "'total'                 =>")
    && str_contains($paymentsUi, 'data-testid="ap-payments-search"')
    && str_contains($paymentsUi, 'data-testid="ap-payments-pagination"'));
$assert('new payment-run rows are auto-selected',
    str_contains($paymentsUi, 'autoSelectedRunRef')
    && str_contains($paymentsUi, 'selectMany(visible)')
    && str_contains($paymentsUi, 'Created and selected'));
$assert('bulk release and origination exclude unallocated payments',
    substr_count($paymentsUi, 'Number(p.unallocated_amount) <= 0.005') >= 2);
$assert('bill queue has bulk approval and posting',
    str_contains($billsUi, 'data-testid="ap-bills-approve-selected"')
    && str_contains($billsUi, 'data-testid="ap-bills-post-selected"'));

echo "Clearing and ledger integrity\n";
$clearing = $lib . "\n" . $payments;
$eventPos = strpos($clearing, "'event_type' => 'ap.payment.cleared'");
$eventJournalPos = strpos($clearing, '$journalEntryId = (int) $eventResult[\'journal_entry_id\'];');
$clearStatusPos = strpos($clearing, 'SET status = "cleared"');
$assert('clearing uses one idempotent accounting event with no direct JE fallback',
    $eventPos !== false
    && str_contains($clearing, "'source_record_id' => 'ap_payment:' . \$paymentId")
    && !str_contains($clearing, "'idempotency_key' => sprintf('ap:payment:%d:clear'"));
$assert('payment is marked cleared only after the journal succeeds',
    $eventJournalPos !== false && $clearStatusPos !== false && $eventJournalPos < $clearStatusPos);
$assert('clear failure leaves payment sent and explicitly retryable',
    str_contains($clearing, 'The payment remains sent so you can fix the setup and retry.')
    && str_contains($payments, "['retryable' => true]"));
$assert('bank clearing and the AP screen share one payment clearing helper',
    str_contains($lib, 'function apClearPayment(')
    && str_contains($payments, 'apClearPayment($tid, $id'));

echo "Entity scope and CSV safety\n";
$assert('payment entity migration and backfill exist',
    str_contains($migration, 'ADD COLUMN entity_id')
    && str_contains($migration, 'UPDATE ap_payments'));
$assert('payment list, export, and dataset all accept entity scope',
    str_contains($payments, "entity_id = :eid")
    && str_contains($csvExport, "'entity_id'   =>")
    && str_contains($dataset, "p.entity_id = :entity_id"));
$assert('payment CSV supports stable IDs and Mercury method',
    str_contains($csvImport, "'payment_id'")
    && str_contains($csvImport, "'external_id'")
    && str_contains($csvImport, "'mercury'")
    && str_contains($csvExport, "'payment_id'"));
$assert('CSV cannot bypass payment lifecycle controls',
    str_contains($csvImport, 'CSV import creates payment drafts only.')
    && str_contains($csvImport, "'status'             => 'draft'"));
$assert('only untouched drafts can be bulk updated',
    str_contains($csvImport, 'Only unallocated, unposted, undispatched AP payment drafts can be updated by CSV'));

echo PHP_EOL . "AP end-to-end payment workflow: {$pass} passed, {$fail} failed" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
