<?php
/**
 * Plaid Bank Link smoke test.
 * Validates the read-only bank-feed endpoint, encrypted token storage,
 * accounting_bank_accounts mirroring, and Treasury UI wire-in.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $name, $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ {$name}\n"; $pass++; } else { echo "  ✗ {$name}\n"; $fail++; }
};
$lint = function (string $path): bool {
    $rc = 0; @exec('php -l ' . escapeshellarg($path) . ' 2>&1', $_, $rc); return $rc === 0;
};

// ─── Backend endpoint ───
echo "/api/plaid_bank_link.php\n";
$src = file_get_contents(__DIR__ . '/../api/plaid_bank_link.php');
$assert('endpoint exists',                   is_string($src) && strlen($src) > 200);
$assert('action=link_token branch',          strpos($src, "action === 'link_token'") !== false);
$assert('action=exchange branch',            strpos($src, "action === 'exchange'") !== false);
$assert("products = ['auth','transactions']", strpos($src, "['auth', 'transactions']") !== false);
$assert('encrypts token before storage',     strpos($src, 'plaidEncryptAccessToken(') !== false);
$assert("inserts plaid_items purpose='bank_feed'",
                                             strpos($src, "'bank_feed'") !== false);
$assert('hydrates accounts via /accounts/get',
                                             strpos($src, 'plaidGetAccounts(') !== false);
$assert('mirrors depository acct in accounting_bank_accounts',
                                             strpos($src, 'INSERT INTO accounting_bank_accounts') !== false);
$assert("feed_provider='plaid' on insert",   strpos($src, '"plaid"') !== false);
$assert('skips non-depository (cards/loans)',strpos($src, "if (\$type !== 'depository') continue;") !== false);
$assert('idempotent on existing item',       strpos($src, 'ON DUPLICATE KEY UPDATE') !== false);
$assert('audits payment_rails.plaid.bank_linked',
                                             strpos($src, 'payment_rails.plaid.bank_linked') !== false);
$assert('PHP parses cleanly',                $lint(__DIR__ . '/../api/plaid_bank_link.php'));

// ─── Treasury UI wire-in ───
echo "Treasury UI\n";
$ui = file_get_contents(__DIR__ . '/../modules/treasury/ui/TreasuryOverview.jsx');
$assert('BankConnectCard component',         strpos($ui, 'function BankConnectCard') !== false);
$assert("BankConnectCard rendered before Transfer card",
        (strpos($ui, '<BankConnectCard') !== false)
        && (strpos($ui, '<BankConnectCard') < strpos($ui, '<PlaidTransferFundingCard'))) ;
$assert('hits /api/plaid_bank_link.php',     strpos($ui, '/api/plaid_bank_link.php') !== false);
$assert('exchange POST wired',               strpos($ui, '/api/plaid_bank_link.php?action=exchange') !== false);
$assert('passes accounts metadata',          strpos($ui, "accounts:    meta?.accounts || []") !== false);
$assert('passes institution metadata',       strpos($ui, "institution: {") !== false);
$assert('clarifies "no money moves"',        strpos($ui, 'No money moves') !== false);
$assert('data-testid: plaid-bank-connect-btn',
                                             strpos($ui, 'data-testid="plaid-bank-connect-btn"') !== false);
$assert('PlaidTransferFundingCard preserved',strpos($ui, 'PlaidTransferFundingCard') !== false);

echo "\n";
echo "Pass: {$pass}\n";
echo "Fail: {$fail}\n";
exit($fail === 0 ? 0 : 1);
