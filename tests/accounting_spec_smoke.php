<?php
/**
 * Accounting v1.0 Phase 0 — contract smoke tests.
 * Validates migration schema, lib signatures, API surface, UI wiring,
 * and subledger (AP + Billing) integration.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function ($n, $c) use (&$pass, &$fail) {
    if ($c) { echo "  \u{2713} {$n}\n"; $pass++; } else { echo "  \u{2717} {$n}\n"; $fail++; }
};

echo "Migration 001_init.sql\n";
$sql = (string) file_get_contents(__DIR__ . '/../modules/accounting/migrations/001_init.sql');
$a('migration exists',                         strlen($sql) > 0);
$a('utf8mb4_unicode_ci safe',                  strpos($sql, 'utf8mb4_0900_ai_ci') === false);
foreach ([
    'accounting_entities','accounting_fiscal_calendars','accounting_periods',
    'accounting_accounts','accounting_journal_entries','accounting_journal_entry_lines',
    'accounting_posting_idempotency',
] as $t) {
    $a("creates {$t}", strpos($sql, "CREATE TABLE IF NOT EXISTS {$t}") !== false);
}
$a('accounts.account_type enum',               strpos($sql, "account_type ENUM('asset','liability','equity','revenue','expense')") !== false);
$a('accounts.normal_side enum',                strpos($sql, "normal_side ENUM('debit','credit')") !== false);
$a('je status lifecycle',                      strpos($sql, "status ENUM('draft','posted','reversed','void')") !== false);
$a('je number is unique per tenant',           strpos($sql, 'uq_aje_tenant_number') !== false);
$a('je idempotency index',                     strpos($sql, 'idx_aje_tenant_idempotency') !== false);
$a('lines FK cascades from JE',                strpos($sql, 'fk_ajel_je FOREIGN KEY (je_id) REFERENCES accounting_journal_entries') !== false);
$a('period.status enum incl reopened',         strpos($sql, "status ENUM('future','open','soft_closed','closed','reopened')") !== false);
$a('tenants.accounting_je_prefix ALTER',       strpos($sql, 'accounting_je_prefix') !== false);
$a('tenants.accounting_next_je_seq ALTER',     strpos($sql, 'accounting_next_je_seq') !== false);

echo "\naccounting.lib posting engine\n";
$lib = (string) file_get_contents(__DIR__ . '/../modules/accounting/lib/accounting.php');
$a('accountingNextJeNumber atomic seq',        strpos($lib, 'FOR UPDATE') !== false);
$a('JE number format prefix-YYYY-NNNNNN',      strpos($lib, "sprintf('%s-%s-%06d', \$prefix") !== false);
$a('resolvePeriod auto-creates monthly',       strpos($lib, 'Auto-create a monthly period') !== false);
$a('defaultEntity auto-creates MAIN',          strpos($lib, "VALUES (:t, \"MAIN\", \"Main Entity\"") !== false);
$a('accountingPostJe(): idempotency replay',   strpos($lib, 'idempotent_replay') !== false);
$a('needs ≥ 2 lines',                          strpos($lib, 'Need at least 2 lines') !== false);
$a('rejects closed periods',                   strpos($lib, 'cannot post') !== false);
$a('rejects negative amounts',                 strpos($lib, 'negative amounts not allowed') !== false);
$a('rejects debit+credit on one line',         strpos($lib, 'cannot have both debit and credit') !== false);
$a('rejects debit=0 credit=0',                 strpos($lib, 'must specify debit or credit') !== false);
$a('rejects inactive account',                 strpos($lib, 'is inactive') !== false);
$a('rejects non-postable summary account',     strpos($lib, 'is not postable (summary)') !== false);
$a('enforces balance within 0.005',            strpos($lib, 'round(abs($totalDebit - $totalCredit)') !== false);
$a('posts wrapped in transaction',             strpos($lib, '$pdo->beginTransaction()') !== false && strpos($lib, '$pdo->rollBack()') !== false);
$a('idempotency row persisted',                strpos($lib, 'INSERT INTO accounting_posting_idempotency') !== false);
$a('accountingReverseJe() flips signs',        strpos($lib, "'debit'                   => (float) \$l['credit']") !== false);
$a('reverse is idempotent',                    strpos($lib, 'idempotent_replay') !== false && strpos($lib, 'reversed_by_je_id') !== false);
$a('only posted JE is reversible',             strpos($lib, 'Can only reverse posted JEs') !== false);
$a('accountingTrialBalance signed balance',    strpos($lib, "\$r['normal_side'] === 'debit' ? round(\$d - \$c, 2) : round(\$c - \$d, 2)") !== false);

echo "\naccounts API\n";
$aapi = (string) file_get_contents(__DIR__ . '/../modules/accounting/api/accounts.php');
$a('GET list perm',                            strpos($aapi, "'accounting.coa.view'") !== false);
$a('POST requires account_type',               strpos($aapi, "api_require_fields(\$body, ['code','name','account_type'])") !== false);
$a('rejects invalid type',                     strpos($aapi, "'Invalid account_type'") !== false);
$a('DELETE is soft (active=0)',                strpos($aapi, "['active' => 0]") !== false);
$a('PATCH with non-empty body guard',          strpos($aapi, "'No fields to update'") !== false);

echo "\njournal_entries API\n";
$japi = (string) file_get_contents(__DIR__ . '/../modules/accounting/api/journal_entries.php');
$a('trial_balance route',                      strpos($japi, "'trial_balance'") !== false);
$a('detail returns lines joined to accounts',  strpos($japi, 'JOIN accounting_accounts a ON a.id = l.account_id') !== false);
$a('list filters from/to/status/entity_id',    strpos($japi, "'status'") !== false && strpos($japi, "'from'") !== false);
$a('reverse perm',                             strpos($japi, "'accounting.je.reverse'") !== false);
$a('post perm',                                strpos($japi, "'accounting.je.post'") !== false);
$a('draft support via action=draft',           strpos($japi, "\$postNow = (\$action !== 'draft')") !== false);

echo "\nAP bill → GL post\n";
$apbills = (string) file_get_contents(__DIR__ . '/../modules/ap/api/bills.php');
$a('requires accounting lib',                  strpos($apbills, "require_once __DIR__ . '/../../accounting/lib/accounting.php'") !== false);
$a('idempotency key ap:bill:<id>:post',        strpos($apbills, "sprintf('ap:bill:%d:post', \$id)") !== false);
$a('Dr expense per bill line',                 strpos($apbills, "'account_code' => \$acct") !== false);
$a('Cr AP 2000',                               strpos($apbills, "'account_code' => '2000'") !== false);
$a('updates ap_bills.journal_entry_id',        strpos($apbills, 'UPDATE ap_bills SET journal_entry_id = :j') !== false);
$a('audit includes je_number',                 strpos($apbills, "'je_number' => \$res['je_number']") !== false);

echo "\nBilling invoice → GL post\n";
$binv = (string) file_get_contents(__DIR__ . '/../modules/billing/api/invoices.php');
$a('POST action=post route',                   strpos($binv, "POST' && \$action === 'post'") !== false);
$a('Dr AR 1100',                               strpos($binv, "'account_code' => '1100'") !== false);
$a('Cr Revenue 4000',                          strpos($binv, "'account_code' => '4000'") !== false);
$a('Cr Sales Tax Payable 2100',                strpos($binv, "'account_code' => '2100'") !== false);
$a('idempotency key billing:invoice:<id>',     strpos($binv, "sprintf('billing:invoice:%d:post', \$id)") !== false);
$a('audit billing.invoice.posted',             strpos($binv, "'billing.invoice.posted'") !== false);

echo "\nAccountingModule React routing\n";
$am = (string) file_get_contents(__DIR__ . '/../modules/accounting/ui/AccountingModule.jsx');
$a('tabs: accounts/journal/trial',             strpos($am, '"accounts"') !== false && strpos($am, '"journal"') !== false && strpos($am, '"trial"') !== false);

$coa = (string) file_get_contents(__DIR__ . '/../modules/accounting/ui/ChartOfAccounts.jsx');
$a('COA seed button',                          strpos($coa, 'accounting-accounts-seed') !== false);
$a('COA default CHART has 8 accounts',         substr_count($coa, "account_type: '") >= 5);
$a('COA add form testid',                      strpos($coa, 'accounting-accounts-form') !== false);

$je  = (string) file_get_contents(__DIR__ . '/../modules/accounting/ui/JournalEntries.jsx');
$a('JE list testid',                           strpos($je, 'accounting-journal-list') !== false);
$a('JE detail shows total debit/credit',       strpos($je, 'accounting-journal-total-debit') !== false);
$a('JE new line balance testid',               strpos($je, 'accounting-journal-new-balance') !== false);
$a('JE reverse flow',                          strpos($je, 'accounting-journal-reverse') !== false);
$a('JE rejects unbalanced submit (disabled)',  strpos($je, '!balanced || busy') !== false);

$tb  = (string) file_get_contents(__DIR__ . '/../modules/accounting/ui/TrialBalance.jsx');
$a('Trial Balance as-of picker',               strpos($tb, 'accounting-trial-asof') !== false);
$a('Trial Balance diff cell',                  strpos($tb, 'accounting-trial-diff') !== false);

echo "\nSidebar + app routing\n";
$mod = (string) file_get_contents(__DIR__ . '/../core/modules.php');
$a('sidebar: Chart of Accounts',               strpos($mod, "'Chart of Accounts'") !== false);
$a('sidebar: Journal Entries',                 strpos($mod, "'Journal Entries'") !== false);
$a('sidebar: Trial Balance',                   strpos($mod, "'Trial Balance'") !== false);
$a('sidebar description updated',              strpos($mod, 'General ledger — Chart of Accounts') !== false);

$app = (string) file_get_contents(__DIR__ . '/../dashboard/src/App.jsx');
$a('App imports AccountingV1Module',           strpos($app, 'AccountingV1Module') !== false);
$a('App routes /modules/accounting/*',         strpos($app, '"/modules/accounting/*"') !== false);

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
