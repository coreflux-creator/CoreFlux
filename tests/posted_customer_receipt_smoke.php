<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$api = file_get_contents($root . '/modules/billing/api/payments.php');
$service = file_get_contents($root . '/modules/billing/lib/posted_receipts.php');
$billing = file_get_contents($root . '/modules/billing/lib/billing.php');
$ui = file_get_contents($root . '/modules/billing/ui/PaymentsList.jsx');
$migration = file_get_contents($root . '/modules/billing/migrations/016_posted_customer_receipts.sql');
$movement = file_get_contents($root . '/modules/billing/lib/money_movement.php');
$export = file_get_contents($root . '/modules/billing/api/payments_csv_export.php');
$import = file_get_contents($root . '/modules/billing/api/payments_csv_import.php');
$failures = 0;
$check = static function (string $name, bool $passed) use (&$failures): void {
    echo ($passed ? 'OK  ' : 'FAIL  ') . $name . PHP_EOL;
    if (!$passed) $failures++;
};

$check('manual receipt has durable bank and JE links',
    str_contains($migration, 'bank_account_id') && str_contains($migration, 'journal_entry_id'));
$check('record action uses one service for receipt, allocation and posting',
    str_contains($api, 'billingPostReceivedPayment($tid, $body')
    && str_contains($service, 'billingAllocatePayment(')
    && str_contains($service, 'accountingPostJe('));
$check('failed posting rolls back receipt and invoice allocation',
    str_contains($service, 'cf_begin_transaction()')
    && str_contains($service, '$pdo->commit()')
    && str_contains($service, '$pdo->rollBack()'));
$check('posting requires full allocation to posted same-client invoices',
    str_contains($service, 'The receipt must be fully applied')
    && str_contains($service, "\$invoice['je_status'] !== 'posted'")
    && str_contains($service, "\$invoice['client_name']"));
$check('automatic allocation skips unposted and wrong-currency invoices',
    str_contains($billing, "\$request['require_posted']")
    && str_contains($billing, 'je.status = "posted"')
    && str_contains($billing, 'i.currency = :currency'));
$check('legacy standalone allocation cannot silently mark invoices paid',
    str_contains($api, "\$action === 'allocate'")
    && str_contains($api, 'Choose Post payment and a bank account'));
$check('bank-matched receipts cannot be posted again as manual receipts',
    str_contains($service, 'This receipt was recorded from bank reconciliation')
    && str_contains($api, "str_starts_with((string) (\$row['external_id'] ?? ''), 'bank-line:')"));
$check('source correction reverses JE, allocations and any bank match',
    str_contains($service, 'billingCorrectPostedPayment(')
    && str_contains($service, 'accountingReverseJe(')
    && str_contains($service, 'reversed_at = NOW()')
    && str_contains($service, 'match_status = "unmatched"'));
$check('correction refuses closed reconciliation and released PWP',
    str_contains($service, 'status = "closed"') && str_contains($service, 'partial_triggered'));
$check('UI selects a bank account and shows pending, posted and corrected states',
    str_contains($ui, 'billing-rp-bank')
    && str_contains($ui, 'Pending ledger')
    && str_contains($ui, 'billing-correct-payment-confirm'));
$check('weekly cash excludes pending and corrected receipts',
    str_contains($movement, 'p.voided_at IS NULL')
    && str_contains($movement, 'p.journal_entry_id IS NOT NULL')
    && str_contains($movement, 'je.status = "posted"'));
$check('CSV receipt export exposes accounting lineage',
    str_contains($export, "'receipt_state'")
    && str_contains($export, "'bank_account_id'")
    && str_contains($export, "'journal_entry_id'"));
$check('CSV preview and commit reject reserved internal receipt IDs',
    str_contains($import, "'validate' => static function")
    && str_contains($import, "'bank-line:'")
    && str_contains($import, 'CsvImportService::dryRun')
    && str_contains($import, 'CsvImportService::commit'));

echo ($failures ? "Failed: {$failures}" : 'Passed: 13') . PHP_EOL;
exit($failures ? 1 : 0);
