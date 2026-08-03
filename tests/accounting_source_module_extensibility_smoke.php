<?php
/**
 * Source-contract regression for module-owned journal-entry provenance.
 */

$root = dirname(__DIR__);
$failures = 0;

$assert = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failures++;
};

$migration = (string) file_get_contents($root . '/modules/accounting/migrations/025_journal_entry_source_module_varchar.sql');
$treasury = (string) file_get_contents($root . '/modules/treasury/api/account_transactions.php');

$assert(
    'journal-entry source_module migrates from enum to VARCHAR',
    preg_match('/ALTER\s+TABLE\s+accounting_journal_entries.*?MODIFY\s+COLUMN\s+source_module\s+VARCHAR\(64\)/is', $migration) === 1
);
$assert(
    'Treasury retains explicit treasury_feed provenance',
    str_contains($treasury, "'source_module'  => 'treasury_feed'")
        || str_contains($treasury, "'source_module'    => 'treasury_feed'")
);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} assertion(s) failed\n");
    exit(1);
}

echo "Source-module extensibility smoke passed.\n";
