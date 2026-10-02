<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$api = file_get_contents($root . '/modules/accounting/api/bank_statements.php');
$lib = file_get_contents($root . '/modules/billing/lib/bank_receipt_correction.php');
$migration = file_get_contents($root . '/modules/billing/migrations/015_bank_receipt_corrections.sql');
$billing = file_get_contents($root . '/modules/billing/lib/billing.php');
$ui = file_get_contents($root . '/modules/accounting/ui/BankReconciliation.jsx');
$failures = 0;
$check = static function (string $name, bool $passed) use (&$failures): void {
    echo ($passed ? 'OK  ' : 'FAIL  ') . $name . PHP_EOL;
    if (!$passed) $failures++;
};

$check('receipt correction has a dedicated endpoint', str_contains($api, "\$action === 'reverse_receipt'"));
$check('correction requires bank, JE reversal and payment permissions',
    str_contains($api, "rbac_legacy_require(\$user, 'accounting.je.reverse')")
    && str_contains($api, "rbac_legacy_require(\$user, 'billing.payments.record')"));
$check('correction is one transaction', str_contains($lib, 'cf_begin_transaction()')
    && str_contains($lib, '$pdo->commit()') && str_contains($lib, '$pdo->rollBack()'));
$check('correction refuses closed reconciliation and released PWP bills',
    str_contains($lib, 'status = "closed"') && str_contains($lib, 'partial_triggered'));
$check('correction reverses JE and restores invoice, payment and bank state',
    str_contains($lib, 'accountingReverseJe(') && str_contains($lib, 'reversed_at = NOW()')
    && str_contains($lib, 'voided_at = NOW()') && str_contains($lib, 'match_status = "unmatched"'));
$check('correction records durable lineage', str_contains($migration, 'billing_receipt_corrections')
    && str_contains($migration, 'uq_brc_original') && str_contains($lib, '"reversal"'));
$check('new matches use a distinct attempt', substr_count($api, 'billingBankReceiptAttempt(') === 2);
$check('current invoice aging excludes reversed allocations',
    str_contains($billing, 'alloc.reversed_at IS NULL OR DATE(alloc.reversed_at) > :reversal_as_of'));
$check('corrected payments cannot be reallocated',
    str_contains($billing, "if (\$pay['voided_at'] !== null)"));
$check('bank screen offers correction with an explicit reason',
    str_contains($ui, 'accounting-bank-correction-reason-')
    && str_contains($ui, 'Reverse and reopen'));

echo ($failures ? "Failed: {$failures}" : 'Passed: 10') . PHP_EOL;
exit($failures ? 1 : 0);
