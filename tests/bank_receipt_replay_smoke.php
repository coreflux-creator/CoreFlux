<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$api = (string) file_get_contents($root . '/modules/accounting/api/bank_statements.php');
$lib = (string) file_get_contents($root . '/modules/billing/lib/bank_receipt_replay.php');
$migration = (string) file_get_contents($root . '/modules/billing/migrations/018_bank_receipt_requests.sql');
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    $checks++;
};
$splitStart = strpos($api, "\$action === 'split_match_invoices'");
$directStart = strpos($api, "\$action === 'match_invoice'");
$end = strpos($api, "\$action === 'reverse_receipt'");
$check($splitStart !== false && $directStart !== false && $end !== false,
    'both invoice receipt routes are present');
foreach (['split' => substr($api, $splitStart, $directStart - $splitStart),
          'direct' => substr($api, $directStart, $end - $directStart)] as $name => $route) {
    $check(str_contains($route, 'billingBankReceiptRequestHash(')
        && str_contains($route, 'billingBankReceiptReplay('),
        $name . ' route compares the original posting intent before validation');
    $check(str_contains($route, 'billingRecordBankReceiptRequest(')
        && strpos($route, 'billingRecordBankReceiptRequest(') < strrpos($route, '$pdo->commit()'),
        $name . ' route saves result in the posting transaction');
}
$check(str_contains($migration, 'UNIQUE KEY uq_bank_receipt_request_attempt'),
    'one durable result is allowed per line and correction attempt');
$check(str_contains($lib, "je.status AS journal_status")
    && str_contains($lib, 'voided_at IS NULL')
    && str_contains($lib, "'idempotent_replay'"),
    'replay requires a posted journal and active source payments');
$check(str_contains($lib, 'hash_equals('), 'changed request hashes are compared in constant time');
echo "Passed: {$checks}\n";
