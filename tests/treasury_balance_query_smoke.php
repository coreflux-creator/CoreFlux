<?php
/**
 * Treasury balance query smoke — guards against the SQL schema drift that
 * caused both deposit_accounts.php and liability_accounts.php to silently
 * return HTTP 500 once Plaid mirrored real rows. Schema realities (per
 * /app/modules/accounting/migrations/001_init.sql):
 *
 *   accounting_journal_entry_lines = (id, je_id, line_no, account_id,
 *                                     debit, credit, memo, ...)
 *
 *   ▸ NO `side` column
 *   ▸ NO `amount` column
 *   ▸ NO `tenant_id` column (lines inherit scope from parent JE)
 *   ▸ Foreign-key column is `je_id`, NOT `journal_entry_id`
 *
 * The original treasury queries assumed a side/amount + journal_entry_id +
 * tenant_id-on-lines schema and 500'd the moment GL balance was computed.
 * This smoke locks in the corrected joins.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $name, $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ {$name}\n"; $pass++; } else { echo "  ✗ {$name}\n"; $fail++; }
};

echo "modules/treasury/api/deposit_accounts.php\n";
$dep = file_get_contents(__DIR__ . '/../modules/treasury/api/deposit_accounts.php');
$assert('uses jel.debit - jel.credit (no side/amount columns)',
        strpos($dep, '(jel.debit - jel.credit)') !== false);
$assert('does NOT reference jel.side',          strpos($dep, 'jel.side') === false);
$assert('does NOT reference jel.amount',        strpos($dep, 'jel.amount') === false);
$assert('joins on jel.je_id (not journal_entry_id)',
        strpos($dep, 'jel.je_id = je.id') !== false
        && strpos($dep, 'jel.journal_entry_id') === false);
$assert('does NOT use jel.tenant_id (no such column)',
        strpos($dep, 'jel.tenant_id') === false);
$assert('parent JE filtered to status=posted', strpos($dep, "je.status = 'posted'") !== false);
$assert('PHP parses cleanly', shell_exec('php -l ' . escapeshellarg(__DIR__ . '/../modules/treasury/api/deposit_accounts.php') . ' 2>&1') !== null);

echo "modules/treasury/api/liability_accounts.php\n";
$lia = file_get_contents(__DIR__ . '/../modules/treasury/api/liability_accounts.php');
$assert('uses jel.credit - jel.debit (sign-flipped for credit-normal)',
        strpos($lia, '(jel.credit - jel.debit)') !== false);
$assert('does NOT reference jel.side',          strpos($lia, 'jel.side') === false);
$assert('does NOT reference jel.amount',        strpos($lia, 'jel.amount') === false);
$assert('joins on jel.je_id (not journal_entry_id)',
        strpos($lia, 'jel.je_id = je.id') !== false
        && strpos($lia, 'jel.journal_entry_id') === false);
$assert('does NOT use jel.tenant_id (no such column)',
        strpos($lia, 'jel.tenant_id') === false);
$assert('parent JE filtered to status=posted', strpos($lia, "je.status = 'posted'") !== false);

echo "\nPass: {$pass}\nFail: {$fail}\n";
exit($fail === 0 ? 0 : 1);
