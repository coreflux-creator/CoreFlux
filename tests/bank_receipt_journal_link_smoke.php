<?php
/** Keep both bank-receipt routes linked to their canonical payment journal. */
declare(strict_types=1);

$bank = (string) file_get_contents(__DIR__ . '/../modules/accounting/api/bank_statements.php');
$billing = (string) file_get_contents(__DIR__ . '/../modules/billing/lib/billing.php');
$backfill = (string) file_get_contents(__DIR__ . '/../modules/billing/migrations/024_bank_receipt_payment_metadata.sql');
$checks = [
    'split and direct bank receipts both link the payment' =>
        substr_count($bank, 'billingLinkBankReceiptJournal(') === 2,
    'linking happens after the bank line is matched' =>
        str_contains($bank,
            "bankRecMatchLine((int) \$ctx['tenant_id'], \$lid, (int) \$receiptJe['je_id'], \$user['id'] ?? null);\n        foreach (\$paymentIds as \$paymentId) {\n            billingLinkBankReceiptJournal(")
        && str_contains($bank,
            "bankRecMatchLine((int) \$ctx['tenant_id'], \$lid, (int) \$receiptJe['je_id'], \$user['id'] ?? null);\n        billingLinkBankReceiptJournal("),
    'helper requires transaction, verified match and posted billing journal' =>
        str_contains($billing, 'function billingLinkBankReceiptJournal(')
        && str_contains($billing, 'if (!$pdo->inTransaction())')
        && str_contains($billing, '$bankLine[\'match_status\'] !== \'matched\'')
        && str_contains($billing, '$journal[\'status\'] !== \'posted\'')
        && str_contains($billing, '$journal[\'source_ref_type\'] !== \'bank_statement_line\''),
    'linked payment records the matched bank and posting time' =>
        str_contains($billing, 'bank_account_id = :bank_account_id')
        && str_contains($billing, 'posted_at = COALESCE(posted_at, :posted_at)')
        && str_contains($billing, "'posted_at' => \$journal['posted_at'] ?: \$journal['created_at']"),
    'backfill is scoped to an active, matching posted bank receipt' =>
        str_contains($backfill, "je.status = 'posted'")
        && str_contains($backfill, "je.source_ref_type = 'bank_statement_line'")
        && str_contains($backfill, "bl.match_status = 'matched'")
        && str_contains($backfill, 'p.voided_at IS NULL')
        && str_contains($backfill, 'p.bank_account_id = bl.bank_account_id'),
];
$failed = 0;
foreach ($checks as $name => $passed) {
    echo ($passed ? 'OK  ' : 'FAIL  ') . $name . PHP_EOL;
    if (!$passed) $failed++;
}
echo $failed ? "Failed: {$failed}\n" : 'Passed: ' . count($checks) . "\n";
exit($failed ? 1 : 0);
