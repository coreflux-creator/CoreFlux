<?php
/**
 * Source-contract regression for inline Treasury categorization and JE hover.
 */

$root = dirname(__DIR__);
$failures = 0;
$assert = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failures++;
};

$api = (string) file_get_contents($root . '/modules/treasury/api/account_transactions.php');
$ui = (string) file_get_contents($root . '/modules/treasury/ui/AccountTransactions.jsx');

$assert('matched JEs are fetched in one batch', str_contains($api, 'AND je.id IN ('));
$assert('JE payload includes account details', str_contains($api, "'journal_entry'") && str_contains($api, "'account_code'"));
$assert('bank/card offset is excluded from categorization', str_contains($api, "'categorization'") && str_contains($api, "!== \$sideAccountId"));
$assert('category renders inline', str_contains($ui, 'treasury-txn-category-${r.id}'));
$assert('JE reference has a hover preview', str_contains($ui, 'function JournalEntryHover(') && str_contains($ui, 'role="tooltip"'));
$assert('JE detail link uses the routed URL', str_contains($ui, 'href={`/modules/accounting/journal-entries/${jeId}`}'));

if ($failures > 0) {
    fwrite(STDERR, "{$failures} assertion(s) failed\n");
    exit(1);
}

echo "Treasury posting detail smoke passed.\n";
