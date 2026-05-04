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
$assert("products = ['transactions'] only (auth filters out credit cards)",
                                             strpos($src, "['transactions']") !== false
                                             && strpos($src, "['auth', 'transactions']") === false);
$assert("auth attached via required_if_supported_products",
                                             strpos($src, "'required_if_supported_products' => ['auth']") !== false);
$assert("liabilities attached via optional_products",
                                             strpos($src, "'optional_products'              => ['liabilities']") !== false);
$assert('encrypts token before storage',     strpos($src, 'plaidEncryptAccessToken(') !== false);
$assert("inserts plaid_items purpose='bank_feed'",
                                             strpos($src, "'bank_feed'") !== false);
$assert('hydrates accounts via /accounts/get',
                                             strpos($src, 'plaidGetAccounts(') !== false);
$assert('mirrors depository acct in accounting_bank_accounts',
                                             strpos($src, 'INSERT INTO accounting_bank_accounts') !== false);
$assert("feed_provider='plaid_transactions' on insert (matches schema comment)",
                                             strpos($src, '"plaid_transactions"') !== false);
$assert('mirrors credit/loan in treasury_liability_accounts',
                                             strpos($src, 'INSERT INTO treasury_liability_accounts') !== false);
$assert('per-account try/catch (silent failures fixed)',
                                             substr_count($src, "try {") >= 4
                                             && substr_count($src, "} catch (\\Throwable \$e)") >= 3);
$assert('returns errors[] to caller',        strpos($src, "'errors'") !== false);
$assert('GL code allocator avoids unique conflict',
                                             strpos($src, 'plaidAllocateBankGlCode(') !== false);
$assert('GL allocator suffixes with last4',  strpos(file_get_contents(__DIR__ . '/../core/plaid_service.php'), "\$base . '-' . \$mask") !== false);
$assert('runtime ALTER TABLE adds plaid_account_id if migration not run',
                                             strpos($src, "ADD COLUMN plaid_account_id") !== false);
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

echo "/api/plaid_diagnostics.php\n";
$diag = file_get_contents(__DIR__ . '/../api/plaid_diagnostics.php');
$assert('diagnostics endpoint exists',       is_string($diag) && strlen($diag) > 200);
$assert('returns plaid_items list',          strpos($diag, "'plaid_items'") !== false);
$assert('returns plaid_accounts list',       strpos($diag, "'plaid_accounts'") !== false);
$assert('returns mirrored bank rows',        strpos($diag, "'accounting_bank_accounts_for_plaid'") !== false);
$assert('returns mirrored liability rows',   strpos($diag, "'treasury_liability_accounts_for_plaid'") !== false);
$assert('computes orphans',                  strpos($diag, "'orphaned_plaid_accounts'") !== false);
$assert('guards against missing column',     strpos($diag, "AND column_name  = 'plaid_account_id'") !== false);
$assert('PHP parses cleanly',                $lint(__DIR__ . '/../api/plaid_diagnostics.php'));

// ─── Backfill action (orphan rescue) ───
echo "/api/plaid_diagnostics.php?action=backfill\n";
$assert('backfill action handler',           strpos($diag, "action'] ?? '') === 'backfill'") !== false);
$assert('backfill is POST-only',             strpos($diag, "api_method() === 'POST'") !== false);
$assert('backfill requires accounting.bank.manage perm',
                                             strpos($diag, "'accounting.bank.manage'") !== false);
$assert('backfill loads plaid_service for shared helpers',
                                             strpos($diag, "require_once __DIR__ . '/../core/plaid_service.php'") !== false);
$assert('backfill uses shared plaidAllocateBankGlCode helper',
                                             substr_count($diag, 'plaidAllocateBankGlCode(') >= 2);
$assert('backfill mirrors deposit orphans',  strpos($diag, 'INSERT INTO accounting_bank_accounts') !== false);
$assert('backfill mirrors liability orphans',strpos($diag, 'INSERT INTO treasury_liability_accounts') !== false);
$assert('backfill audits payment_rails.plaid.backfill',
                                             strpos($diag, "'payment_rails.plaid.backfill'") !== false);
$assert('backfill returns orphans_processed',strpos($diag, "'orphans_processed'") !== false);
$assert('backfill returns errors[]',         strpos($diag, "'errors'") !== false);
$assert('shared helper lives in plaid_service',
                                             strpos(file_get_contents(__DIR__ . '/../core/plaid_service.php'),
                                                    'function plaidAllocateBankGlCode(') !== false);

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
$assert('diagnostics button rendered',       strpos($ui, 'data-testid="plaid-bank-diagnostics-btn"') !== false);
$assert('error stack pre-line for multi-line errors',
                                             strpos($ui, "whiteSpace: 'pre-line'") !== false);
$assert('PlaidTransferFundingCard preserved',strpos($ui, 'PlaidTransferFundingCard') !== false);
$assert('UI surfaces orphan banner',         strpos($ui, 'data-testid="plaid-orphan-banner"') !== false);
$assert('UI has Backfill orphans button',    strpos($ui, 'data-testid="plaid-backfill-orphans-btn"') !== false);
$assert('UI calls backfill action',          strpos($ui, "/api/plaid_diagnostics.php?action=backfill") !== false);
$assert('UI shows DiagStat panel',           strpos($ui, 'data-testid="plaid-diagnostics-panel"') !== false);

// ─── Link token endpoint /api/plaid_link_token.php (multi-purpose) ───
echo "/api/plaid_link_token.php\n";
$lt = file_get_contents(__DIR__ . '/../api/plaid_link_token.php');
$assert('bank_feed default is transactions only',
                                             strpos($lt, "'bank_feed'        => ['transactions']") !== false);
$assert("bank_feed adds required_if_supported auth", strpos($lt, "'required_if_supported_products'") !== false);
$assert("bank_feed adds optional liabilities", strpos($lt, "'optional_products'") !== false && strpos($lt, "['liabilities']") !== false);
$assert("'liabilities' added to allowed product set",
                                             strpos($lt, "'auth','transactions','identity','liabilities'") !== false);

echo "\n";
echo "Pass: {$pass}\n";
echo "Fail: {$fail}\n";
exit($fail === 0 ? 0 : 1);
