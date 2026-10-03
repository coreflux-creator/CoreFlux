<?php
/** Keep both bank-receipt routes linked to their canonical payment journal. */
declare(strict_types=1);

$bank = (string) file_get_contents(__DIR__ . '/../modules/accounting/api/bank_statements.php');
$billing = (string) file_get_contents(__DIR__ . '/../modules/billing/lib/billing.php');
$checks = [
    'split and direct bank receipts both link the payment' =>
        substr_count($bank, 'billingLinkBankReceiptJournal(') === 2,
    'linking happens after the bank line is matched' =>
        str_contains($bank,
            "bankRecMarkLineMatched((int) \$ctx['tenant_id'], \$lid, (int) \$receiptJe['je_id'], \$user['id'] ?? null);\n        foreach (\$paymentIds as \$paymentId) {\n            billingLinkBankReceiptJournal(")
        && str_contains($bank,
            "bankRecMarkLineMatched((int) \$ctx['tenant_id'], \$lid, (int) \$receiptJe['je_id'], \$user['id'] ?? null);\n        billingLinkBankReceiptJournal("),
    'helper requires transaction, verified match and posted billing journal' =>
        str_contains($billing, 'function billingLinkBankReceiptJournal(')
        && str_contains($billing, 'if (!$pdo->inTransaction())')
        && str_contains($billing, '$bankLine[\'match_status\'] !== \'matched\'')
        && str_contains($billing, '$journalStmt->fetchColumn() !== \'posted\''),
];
$failed = 0;
foreach ($checks as $name => $passed) {
    echo ($passed ? 'OK  ' : 'FAIL  ') . $name . PHP_EOL;
    if (!$passed) $failed++;
}
echo $failed ? "Failed: {$failed}\n" : 'Passed: ' . count($checks) . "\n";
exit($failed ? 1 : 0);
