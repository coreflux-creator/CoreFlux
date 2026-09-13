<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$passed = 0; $failed = 0;
$check = static function (string $label, bool $ok) use (&$passed, &$failed): void {
    echo ($ok ? "  OK  " : "  FAIL ") . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);

$list = $read('modules/billing/ui/InvoicesList.jsx');
$create = $read('modules/billing/ui/InvoiceCreate.jsx');
$detail = $read('modules/billing/ui/InvoiceDetail.jsx');
$invoiceApi = $read('modules/billing/api/invoices.php');
$bankLib = $read('modules/accounting/lib/bank_rec.php');
$bankApi = $read('modules/accounting/api/bank_statements.php');
$bankAi = $read('modules/accounting/api/bank_ai.php');
$bankUi = $read('modules/accounting/ui/BankReconciliation.jsx');
$rbac = $read('core/rbac/legacy_map.php');

echo "Invoice visibility and issuing entity\n";
$check('invoice list is tenant-wide', !str_contains($list, "qs.set('entity_id'"));
$check('new invoice defaults from active entity', str_contains($create, 'activeEntityId') && str_contains($create, 'setEntityId(activeEntityId'));
$check('issuing entity is required in UI', str_contains($create, 'allowNone={false}') && str_contains($create, 'required'));
$check('API resolves a valid default entity', str_contains($invoiceApi, 'activeEntityResolveForTenant(') && str_contains($invoiceApi, "'entity_id'         => (int) \$issuingEntity['id']"));

echo "\nBank matching rules\n";
$check('journal candidates use bank GL account', str_contains($bankLib, 'ba.gl_account_code = a.code'));
$check('journal candidates use signed cash side', str_contains($bankLib, 'l.debit = :abs_amt') && str_contains($bankLib, 'l.credit = :abs_amt'));
$check('invoice candidate helper exists', str_contains($bankLib, 'function bankRecInvoiceMatchCandidates('));
$check('drafts may be surfaced but not applied', str_contains($bankLib, '"draft", "approved", "sent", "partially_paid"') && str_contains($bankLib, "'can_apply_payment' => \$canApply"));
$check('only a posted invoice can be applied', str_contains($bankLib, "\$invoice['journal_status'] ?? null") && str_contains($bankLib, "=== 'posted'"));
$check('bank list preloads invoice suggestions', str_contains($bankApi, 'bankRecAttachInvoiceSuggestions('));
$check('AI match combines invoices and journals', str_contains($bankAi, 'array_merge($invoiceCandidates, $jeCandidates)'));

echo "\nApplying a receipt\n";
$check('invoice match endpoint exists', str_contains($bankApi, "\$action === 'match_invoice'"));
$check('requires bank and payment permissions', str_contains($bankApi, "'accounting.bank.manage'") && str_contains($bankApi, "'billing.payments.record'"));
$check('requires exact open balance', str_contains($bankApi, "\$invoice['amount_due'] - \$amount"));
$check('requires posted invoice ledger entry', str_contains($bankApi, 'Post this invoice to the ledger before applying its bank payment'));
$check('posts cash receipt journal', str_contains($bankApi, "'idempotency_key' => 'billing:bank-receipt:'"));
$check('allocates payment and closes bank line', str_contains($bankApi, 'billingAllocatePayment(') && str_contains($bankApi, 'bankRecMarkLineMatched('));
$check('bank UI reviews and applies invoice match', str_contains($bankUi, 'Review match') && str_contains($bankUi, 'Apply payment'));
$check('bank UI can accept posted journal match', str_contains($bankUi, 'Match line') && str_contains($bankUi, "action=match&line_id="));

echo "\nInvoice finalization\n";
$check('post permission maps to billing admin', str_contains($rbac, "'billing.invoice.post'               => ['billing', 'admin']"));
$check('invoice detail exposes post action', str_contains($detail, 'data-testid="billing-invoice-post"'));
$check('sending also posts to ledger', substr_count($detail, 'action=post&id=${id}') >= 2);

echo "Passed: {$passed}; Failed: {$failed}" . PHP_EOL;
exit($failed > 0 ? 1 : 0);
