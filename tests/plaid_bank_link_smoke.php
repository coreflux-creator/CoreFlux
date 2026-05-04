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
$assert('mirrors credit/loan in treasury_liability_accounts',
                                             strpos($src, 'INSERT INTO treasury_liability_accounts') !== false);
$assert('routes credit cards to subtype=credit_card',
                                             strpos($src, "=> 'credit_card'") !== false);
$assert('routes loans to subtype=loan',      strpos($src, "=> 'loan'") !== false);
$assert('finds-or-creates accounting_accounts row for liability',
                                             strpos($src, "INSERT INTO accounting_accounts") !== false
                                             && strpos($src, "'liability', 'credit'") !== false);
$assert('skips investment / other types',    strpos($src, 'leave on plaid_accounts only') !== false);
$assert('idempotent on existing item',       strpos($src, 'ON DUPLICATE KEY UPDATE') !== false);
$assert('audits payment_rails.plaid.bank_linked',
                                             strpos($src, 'payment_rails.plaid.bank_linked') !== false);
$assert('returns liability_accounts_created',strpos($src, "'liability_accounts_created'") !== false);
$assert('PHP parses cleanly',                $lint(__DIR__ . '/../api/plaid_bank_link.php'));

// ─── Treasury liability migration ───
echo "Treasury liability migration\n";
$mig = file_get_contents(__DIR__ . '/../modules/treasury/migrations/002_plaid_liability_link.sql');
$assert('migration exists',                  is_string($mig) && strlen($mig) > 100);
$assert('adds plaid_account_id column',      strpos($mig, 'plaid_account_id VARCHAR(80)') !== false);
$assert('idempotent guard',                  strpos($mig, 'information_schema.columns') !== false);
$assert('uniq index on (tenant, plaid_acc)', strpos($mig, 'uq_tla_tenant_plaid') !== false);

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
$assert('UI mentions liabilities + deposits',strpos($ui, 'liabilities tab') !== false);
$assert('summarises both deposits + liabs',  strpos($ui, 'data.liability_accounts_created') !== false);
$assert('data-testid: plaid-bank-connect-btn',
                                             strpos($ui, 'data-testid="plaid-bank-connect-btn"') !== false);
$assert('PlaidTransferFundingCard preserved',strpos($ui, 'PlaidTransferFundingCard') !== false);

echo "\n";
echo "Pass: {$pass}\n";
echo "Fail: {$fail}\n";
exit($fail === 0 ? 0 : 1);
