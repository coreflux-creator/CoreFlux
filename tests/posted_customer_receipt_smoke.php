<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$api = file_get_contents($root . '/modules/billing/api/payments.php');
$service = file_get_contents($root . '/modules/billing/lib/posted_receipts.php');
$billing = file_get_contents($root . '/modules/billing/lib/billing.php');
$ui = file_get_contents($root . '/modules/billing/ui/PaymentsList.jsx');
$migration = file_get_contents($root . '/modules/billing/migrations/016_posted_customer_receipts.sql');
$depositMigration = file_get_contents($root . '/modules/accounting/migrations/032_customer_deposits.sql');
$refundMigration = file_get_contents($root . '/modules/accounting/migrations/033_customer_deposit_refunds.sql');
$correctionMigration = file_get_contents($root . '/modules/accounting/migrations/034_customer_deposit_corrections.sql');
$fingerprintMigration = file_get_contents($root . '/modules/accounting/migrations/035_receipt_request_fingerprint.sql');
$movement = file_get_contents($root . '/modules/billing/lib/money_movement.php');
$registry = file_get_contents($root . '/core/seeds/event_registry_seed.php');
$rules = file_get_contents($root . '/core/posting_engine/seed_defaults.php');
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
    && str_contains($service, "'event_type' => 'billing.manual_receipt.posted'")
    && substr_count($service, 'accountingProcessEvent(') === 3
    && !str_contains($service, 'accountingPostJe('));
$check('receipt, application and refund events have registered payload-line rules',
    (static function () use ($registry, $rules): bool {
        foreach (['billing.manual_receipt.posted', 'billing.customer_deposit.applied',
                  'billing.customer_deposit.refunded'] as $type) {
            if (!str_contains($registry, "'{$type}'")
                || !preg_match('/\x27event_type\x27\s*=>\s*\x27' . preg_quote($type, '/')
                    . '\x27.*?\x27line_source\x27\s*=>\s*\x27payload\x27/s', $rules)) return false;
        }
        return true;
    })());
$check('failed posting rolls back receipt and invoice allocation',
    str_contains($service, 'cf_begin_transaction()')
    && str_contains($service, '$pdo->commit()')
    && str_contains($service, '$pdo->rollBack()'));
$check('exact receipt replay requires the original posting intent',
    str_contains($fingerprintMigration, 'receipt_request_hash')
    && str_contains($service, 'billingReceiptRequestHash(')
    && str_contains($service, 'hash_equals((string) $existing[')
    && str_contains($service, "'receipt_request_hash' => \$requestHash"));
$check('posting requires full allocation or an explicit customer deposit',
    str_contains($service, 'hold_unapplied')
    && str_contains($service, "'account_code' => '2300'")
    && str_contains($service, "\$invoice['je_status'] !== 'posted'")
    && str_contains($service, "\$invoice['client_name']"));
$check('deposit applications move liability to AR with one journal link',
    str_contains($service, 'billingApplyCustomerDeposit(')
    && str_contains($service, "'account_code' => '2300'")
    && str_contains($service, 'application_je_id = :je')
    && str_contains($depositMigration, 'billing_deposit_applications'));
$check('manual and deposit allocations carry their historical business date',
    str_contains($service, "'allocation_date' => (string) \$payment['received_at']")
    && str_contains($service, "'allocation_date' => \$appliedAt")
    && str_contains($billing, 'COALESCE(:applied_at, CURRENT_TIMESTAMP)'));
$check('deposit refunds post a separate bank outflow with a durable source record',
    str_contains($service, 'billingRecordCustomerDepositRefund(')
    && str_contains($service, "'source_ref_type' => 'billing_deposit_refund'")
    && str_contains($refundMigration, 'billing_deposit_refunds'));
$check('refund action is permission-gated and clearly records rather than sends money',
    str_contains($api, "\$action === 'refund_deposit'")
    && str_contains($api, "'billing.payments.record'")
    && str_contains($ui, 'it does not send money'));
$check('source-level deposit corrections reverse application or refund with provenance',
    str_contains($service, 'billingCorrectCustomerDepositApplication(')
    && str_contains($service, 'billingCorrectCustomerDepositRefund(')
    && str_contains($correctionMigration, 'billing_deposit_refunds ADD COLUMN reversal_je_id')
    && str_contains($api, "\$action === 'deposit_activity'")
    && str_contains($ui, 'billing-deposit-activity-modal'));
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
    && substr_count($service, 'billingReverseReceiptEvent($tenantId, $eventId)') === 3
    && str_contains($service, 'reversed_at = NOW()')
    && str_contains($service, 'match_status = "unmatched"'));
$check('correction refuses closed reconciliation and released PWP',
    str_contains($service, 'status = "closed"') && str_contains($service, 'partial_triggered'));
$check('UI selects a bank account and shows deposit, posted and corrected states',
    str_contains($ui, 'billing-rp-bank')
    && str_contains($ui, 'Pending ledger')
    && str_contains($ui, 'billing-deposit-apply-')
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
$check('CSV receipts require positive cents and protect posted deposits from edits',
    str_contains($import, 'enter a positive amount in cents')
    && str_contains($import, "\$existing['journal_entry_id'] !== null")
    && str_contains($import, 'Posted or bank-linked receipts cannot be updated by CSV'));
$check('external-source pending receipts use Billing posting and deposit controls',
    str_contains($service, 'Imported receipts are still operator-posted')
    && str_contains($service, "\$payment['source_module'] !== 'billing'")
    && str_contains($service, "\$payment['source_ref_type'] !== 'billing_payment'"));
$check('payment list exposes actions from posted Billing journal ownership, not source label',
    str_contains($api, "\$row['journal_source_module'] === 'billing'")
    && str_contains($api, "\$row['journal_source_ref_type'] === 'billing_payment'")
    && str_contains($api, "\$row['can_apply_deposit'] = \$billingPosted")
    && str_contains($api, "\$row['can_correct'] = \$billingPosted"));

echo ($failures ? "Failed: {$failures}" : 'Passed: 23') . PHP_EOL;
exit($failures ? 1 : 0);
