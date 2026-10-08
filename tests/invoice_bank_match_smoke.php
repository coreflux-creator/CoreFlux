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
$invoiceDrafts = $read('modules/billing/lib/invoice_drafts.php');
$bankLib = $read('modules/accounting/lib/bank_rec.php');
$bankApi = $read('modules/accounting/api/bank_statements.php');
$bankAi = $read('modules/accounting/api/bank_ai.php');
$bankUi = $read('modules/accounting/ui/BankReconciliation.jsx');
$accountingNav = $read('modules/accounting/ui/AccountingModule.jsx');
$treasuryUi = $read('modules/treasury/ui/AccountTransactions.jsx');
$billingLib = $read('modules/billing/lib/billing.php');
$rbac = $read('core/rbac/legacy_map.php');
$lightWorkspaceDeployPath = $root . '/.github/workflows/deploy-light-workspace.yml';

echo "Invoice visibility and issuing entity\n";
$check('accounting opens the native billing invoice workflow',
    str_contains($accountingNav, "to: '/modules/billing/invoices', label: 'Invoices'"));
$check('invoice list defaults to all entities and allows filtering',
    str_contains($list, 'useAccountingEntityScope({ defaultAll: true })')
    && str_contains($list, "qs.set('entity_id'"));
$check('new invoice defaults from the selected or active entity',
    str_contains($create, 'requestedEntityId ?? activeEntityId'));
$check('issuing entity is required in UI', str_contains($create, 'allowNone={false}') && str_contains($create, 'required'));
$check('invoice create and detail actions refresh cached invoice worklists',
    str_contains($create, "bustApiCachePrefix('billing-invoices-list:')")
    && str_contains($detail, "bustApiCachePrefix('billing-invoices-list:')"));
$check('draft service resolves a valid issuing entity',
    str_contains($invoiceApi, 'billingCreateDirectInvoiceDraft(')
    && str_contains($invoiceDrafts, 'activeEntityResolveForTenant(')
    && str_contains($invoiceDrafts, "'entity_id' => (int) \$issuingEntity['id']"));

echo "\nBank matching rules\n";
$check('journal candidates use bank GL account', str_contains($bankLib, 'ba.gl_account_code = a.code'));
$check('journal candidates use signed cash side', str_contains($bankLib, 'l.debit = :abs_amt') && str_contains($bankLib, 'l.credit = :abs_amt'));
$check('invoice candidate helper exists', str_contains($bankLib, 'function bankRecInvoiceMatchCandidates('));
$check('drafts may be surfaced but not applied', str_contains($bankLib, '"draft", "approved", "sent", "partially_paid"') && str_contains($bankLib, "'can_apply_payment' => \$canApply"));
$check('only a posted invoice can be applied', str_contains($bankLib, "\$invoice['journal_status'] ?? null") && str_contains($bankLib, "=== 'posted'"));
$check('bank list preloads invoice suggestions', str_contains($bankApi, 'bankRecAttachInvoiceSuggestions('));
$check('matched bank lines show applied invoices',
    str_contains($bankApi, "\$lineRow['applied_invoices']")
    && str_contains($bankUi, 'line.applied_invoices.map(invoice =>'));
$check('matched bank lines find both imported and bank-created receipts by journal',
    str_contains($bankApi, 'p.journal_entry_id = bl.matched_je_id')
    && str_contains($bankApi, 'p.voided_at IS NULL AND a.reversed_at IS NULL'));
$check('bank lines can be reviewed by status',
    str_contains($bankUi, "['matched', 'Matched']")
    && str_contains($bankUi, "params.set('match_status', lineStatus)"));
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
$check('direct receipt validates bank currency and invoice date',
    str_contains($bankApi, 'The invoice and bank account use different currencies')
    && str_contains($bankApi, 'The invoice was issued after this bank receipt'));
$check('invoice page finds tenant-scoped, unbooked receipt candidates',
    str_contains($bankApi, "\$action === 'receipt_candidates'")
    && str_contains($bankApi, 'bankRecRepairPostedMatches(')
    && str_contains($bankApi, 'bl.matched_je_id IS NULL')
    && str_contains($detail, 'billing-invoice-receipt-candidates'));
$check('invoice page separates likely receipts from unrelated bank deposits',
    str_contains($detail, 'const likelyReceipts = receiptRows.filter(')
    && str_contains($detail, 'billing-invoice-other-receipts-toggle')
    && str_contains($detail, 'likelyReceipts.includes(line) && line.can_apply_directly')
    && str_contains($detail, 'Review bank line'));
$check('invoice receipt confirmation names the client and bank description',
    str_contains($detail, 'for ${inv.client_name}')
    && str_contains($detail, 'Bank description: ${line.description'));
$check('invoice bank feed handoff keeps the issuing entity',
    str_contains($detail, "addEntityScope('/modules/accounting/transactions-to-review', inv.entity_id)")
    && str_contains($bankUi, 'data-testid="accounting-bank-accounts-entity"')
    && str_contains($bankUi, 'const displayedAccounts = (data?.rows || []).filter(')
    && str_contains($bankUi, 'const bankListPath ='));
$check('invoice review link opens the exact scoped bank line',
    str_contains($detail, '?line_id=${line.id}')
    && str_contains($bankUi, 'data-testid="accounting-bank-focused-line"')
    && str_contains($bankUi, "params.set('line_id', focusedLineId)")
    && str_contains($bankApi, "\$where[] = 'id = :line_id'"));

echo "\nPartial invoice matching from Treasury\n";
$check('manual invoice candidates include client and open balance',
    str_contains($bankApi, "action === 'invoice_candidates'")
    && str_contains($bankApi, 'bi.client_name')
    && str_contains($bankApi, 'bi.amount_due'));
$check('invoice candidate response exits before the generic bank-account listing',
    preg_match('/action === \'invoice_candidates\'[\s\S]*?api_ok\(\[\'rows\' => \$rows[\s\S]*?exit;[\s\S]*?if \(\$method === \'GET\'\)/', $bankApi) === 1);
$check('invoice candidates respect bank currency and receipt date',
    str_contains($bankApi, 'bi.currency = :bank_currency')
    && str_contains($bankApi, 'bi.issue_date <= :posted_date'));
$check('split receipt endpoint accepts invoice and GL portions',
    str_contains($bankApi, "action === 'split_match_invoices'")
    && str_contains($bankApi, "\$body['allocations']")
    && str_contains($bankApi, "\$body['account_splits']"));
$check('partial invoice allocations cannot exceed open balance',
    str_contains($bankApi, 'Allocation exceeds the open balance on invoice'));
$check('split receipt posts AR by invoice and allocates the subledger payment',
    str_contains($bankApi, "'account_code' => '1100'")
    && str_contains($bankApi, 'billingAllocatePayment(')
    && str_contains($bankApi, "'billing:bank-receipt-split:'"));
$splitAction = substr($bankApi, strpos($bankApi, "\$action === 'split_match_invoices'"),
    strpos($bankApi, "\$action === 'match_invoice'") - strpos($bankApi, "\$action === 'split_match_invoices'"));
$directAction = substr($bankApi, strpos($bankApi, "\$action === 'match_invoice'"),
    strpos($bankApi, "\$action === 'unmatch'") - strpos($bankApi, "\$action === 'match_invoice'"));
foreach (['split' => $splitAction, 'direct' => $directAction] as $name => $actionSource) {
    $check("{$name} receipt journal, allocation and match share one transaction",
        preg_match('/cf_begin_transaction\(\);[\s\S]*accountingPostJe\([\s\S]*billingAllocatePayment\([\s\S]*bankRecMarkLineMatched\([\s\S]*\$pdo->commit\(\)/', $actionSource) === 1
        && str_contains($actionSource, '$pdo->rollBack()'));
}
$check('pay-when-paid release is deferred until the bank transaction commits',
    str_contains($billingLib, "\$request['defer_pwp']")
    && str_contains($bankApi, 'billingReleasePayWhenPaidForAllocations('));
$check('generic AR is rejected when an invoice target is required',
    str_contains($bankApi, 'Use an Invoice target instead of posting a generic Accounts Receivable split'));
$check('GL remainder validates the selected intercompany entity',
    str_contains($bankApi, 'accountingValidateActiveEntityId(')
    && str_contains($bankApi, "'entity_id' => \$counterpartyEntityId"));
$check('plain receipt categorization directs users to invoice matching',
    str_contains($treasuryUi, "String(a.code) !== '1100'")
    && str_contains($treasuryUi, 'choose Apply receipt and select the invoice'));
$check('Treasury split rows choose invoice or GL account',
    str_contains($treasuryUi, '<option value="invoice">Customer invoice</option>')
    && str_contains($treasuryUi, '<option value="account">GL account</option>'));
$check('Treasury invoice picker shows client, invoice and balance',
    str_contains($treasuryUi, 'treasury-txn-split-invoice-')
    && str_contains($treasuryUi, 'inv.client_name')
    && str_contains($treasuryUi, 'inv.invoice_number')
    && str_contains($treasuryUi, 'inv.amount_due'));
$check('choosing an invoice defaults its amount and preserves a remainder row',
    str_contains($treasuryUi, 'const selectInvoice =')
    && str_contains($treasuryUi, 'Math.min(Number(invoice.amount_due), available)')
    && str_contains($treasuryUi, 'next.push(blankRow(remainder.toFixed(2)))'));
$check('Treasury submits invoice allocations and remainder together',
    str_contains($treasuryUi, 'split_match_invoices&line_id=')
    && str_contains($treasuryUi, 'invoiceAllocations')
    && str_contains($treasuryUi, 'accountSplits'));
if (is_file($lightWorkspaceDeployPath)) {
    $lightWorkspaceDeploy = (string) file_get_contents($lightWorkspaceDeployPath);
    $check('light workspace release packages the Treasury transaction source',
        str_contains($lightWorkspaceDeploy, 'modules/treasury/ui/AccountTransactions.jsx'));
}

echo "\nInvoice finalization\n";
$check('invoice void is restricted to unposted, unpaid drafts',
    str_contains($invoiceApi, "\$row['status'] !== 'draft'")
    && str_contains($invoiceApi, '$sourceJe->fetchColumn()')
    && str_contains($invoiceApi, '$hasPayments || (float) $row[\'amount_paid\'] > 0')
    && str_contains($detail, "const canVoid = inv.status === 'draft'"));
$check('post permission maps to billing admin', str_contains($rbac, "'billing.invoice.post'               => ['billing', 'admin']"));
$check('invoice detail exposes post action', str_contains($detail, 'data-testid="billing-invoice-post"'));
$check('approval, posting and sending remain separate; post retry reuses the journal',
    str_contains($detail, "action=approve&id=")
    && str_contains($detail, "action=post&id=")
    && str_contains($detail, "inv.journal_status === 'posted'")
    && str_contains($invoiceApi, "'idempotent_replay' => true"));
$check('sending requires a posted journal in API and UI',
    str_contains($invoiceApi, 'Post the invoice to the ledger before sending it')
    && str_contains($detail, "inv.journal_status === 'posted'")
    && str_contains($list, "row.journal_status === 'posted'"));
$check('invoice page applies direct and partial deposits through native bank APIs',
    str_contains($detail, 'action=match_invoice&line_id=')
    && str_contains($detail, 'action=split_match_invoices&line_id='));

echo "Passed: {$passed}; Failed: {$failed}" . PHP_EOL;
exit($failed > 0 ? 1 : 0);
