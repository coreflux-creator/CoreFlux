<?php
/**
 * Accounting Phase 2 Sprint A.1 smoke test.
 *
 *   - Migration 002_phase2.sql declares cash_flow_tag + recurring + bank rec tables
 *   - reports.php exposes type=cash_flow_indirect handler
 *   - reportCashFlowIndirect() function declared with the right signature
 *   - JE list endpoint accepts account_code filter (drill-through)
 *   - AccountingModule.jsx routes /journal-entries/new + /:id and a Cash Flow tab
 *   - CashFlowStatement.jsx renders operating / investing / financing / untagged sections
 *   - JournalEntryCreate.jsx + JournalEntryDetail.jsx files exist with the expected
 *     test-ids and call the right APIs
 *   - IS / BS rows wrap account codes in drill-through Links
 *   - JournalEntries.jsx reads URL filters (account_code / from / to) and passes
 *     them to the API
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $label, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

echo "Migration 002_phase2.sql\n";
$mig = (string) file_get_contents(__DIR__ . '/../modules/accounting/migrations/002_phase2.sql');
$a('adds cash_flow_tag to accounting_accounts',        strpos($mig, 'ADD COLUMN cash_flow_tag') !== false);
$a('creates accounting_recurring_journal_entries',     strpos($mig, 'CREATE TABLE IF NOT EXISTS accounting_recurring_journal_entries') !== false);
$a('recurring cadence enum',                           strpos($mig, "ENUM('weekly','biweekly','monthly','quarterly','yearly')") !== false);
$a('recurring auto_post + status columns',
    strpos($mig, 'auto_post') !== false &&
    strpos($mig, "ENUM('active','paused','ended')") !== false);
$a('creates accounting_recurring_je_lines',            strpos($mig, 'CREATE TABLE IF NOT EXISTS accounting_recurring_je_lines') !== false);
$a('creates accounting_bank_accounts',                 strpos($mig, 'CREATE TABLE IF NOT EXISTS accounting_bank_accounts') !== false);
$a('bank account has feed_provider + plaid fields',
    strpos($mig, 'feed_provider') !== false &&
    strpos($mig, 'plaid_access_token_ct') !== false &&
    strpos($mig, 'plaid_account_id') !== false);
$a('creates accounting_bank_statement_imports',        strpos($mig, 'CREATE TABLE IF NOT EXISTS accounting_bank_statement_imports') !== false);
$a('creates accounting_bank_statement_lines',          strpos($mig, 'CREATE TABLE IF NOT EXISTS accounting_bank_statement_lines') !== false);
$a('bank line de-dup by FITID',
    strpos($mig, 'fitid')       !== false &&
    strpos($mig, 'uq_absl_fitid') !== false);
$a('match_status enum',                                strpos($mig, "ENUM('unmatched','matched','ignored')") !== false);
$a('creates accounting_reconciliations',               strpos($mig, 'CREATE TABLE IF NOT EXISTS accounting_reconciliations') !== false);
$a('reconciliation status enum',                       strpos($mig, "ENUM('open','closed','reopened')") !== false);
$a('utf8mb4_unicode_ci collation only',
    strpos($mig, 'utf8mb4_unicode_ci') !== false &&
    stripos($mig, 'utf8mb4_0900_ai_ci') === false);

echo "\nReports — Cash Flow Indirect\n";
$rdis = (string) file_get_contents(__DIR__ . '/../modules/accounting/api/reports.php');
$rep  = (string) file_get_contents(__DIR__ . '/../modules/accounting/lib/standard_reports.php');
$a('type=cash_flow_indirect dispatch',                 strpos($rdis, "type === 'cash_flow_indirect'") !== false);
$a('type=cash_flow alias',                             strpos($rdis, "type === 'cash_flow'") !== false);
$a('reportCashFlowIndirect declared',                  strpos($rep, 'function reportCashFlowIndirect') !== false);
$a('uses reportIncomeStatement for net income',        strpos($rep, "reportIncomeStatement(\$tenantId, \$from, \$to") !== false);
$a('walks balance sheets at start vs end',
    strpos($rep, 'startBs') !== false &&
    strpos($rep, 'endBs')   !== false);
$a('cash_flow_tag pulled from accounting_accounts',    strpos($rep, 'cash_flow_tag') !== false);
$a('asset increase = use of cash (sign flip)',         strpos($rep, "(\$type === 'asset') ? -\$delta : \$delta") !== false);
$a('three section buckets + untagged',
    strpos($rep, "'operating'") !== false &&
    strpos($rep, "'investing'") !== false &&
    strpos($rep, "'financing'") !== false &&
    strpos($rep, "'untagged'") !== false);
$a('cash_and_equivalents skipped from sections',       strpos($rep, "'cash_and_equivalents'") !== false);
$a('reconciliation diff returned',                     strpos($rep, "'reconciliation_diff'") !== false);
$a('balanced flag returned',                           strpos($rep, "'balanced'") !== false);
$a('net income prepended to operating',                strpos($rep, "array_unshift(\$sections['operating']['lines']") !== false);
$a('untagged_warning flag',                            strpos($rep, 'untagged_warning') !== false);

echo "\nJE list — drill-through filter\n";
$je = (string) file_get_contents(__DIR__ . '/../modules/accounting/api/journal_entries.php');
$a('account_code filter joins JE lines',
    strpos($je, 'INNER JOIN accounting_journal_entry_lines') !== false &&
    strpos($je, 'INNER JOIN accounting_accounts') !== false);
$a('account_code filter binds :acode',                 strpos($je, "\$params['acode'] = (string) \$_GET['account_code']") !== false);
$a('list query uses DISTINCT je.id',                   strpos($je, 'SELECT DISTINCT je.id') !== false);
$a('count uses COUNT(DISTINCT je.id)',                 strpos($je, 'COUNT(DISTINCT je.id)') !== false);

echo "\nAccountingModule routing\n";
$mod = (string) file_get_contents(__DIR__ . '/../modules/accounting/ui/AccountingModule.jsx');
$a('imports JournalEntryCreate',                       strpos($mod, "from './JournalEntryCreate'") !== false);
$a('imports JournalEntryDetail',                       strpos($mod, "from './JournalEntryDetail'") !== false);
$a('imports CashFlowStatement',                        strpos($mod, "from './CashFlowStatement'") !== false);
$a('Cash Flow tab',                                    strpos($mod, 'to="cash-flow"') !== false);
$a('Cash Flow route',                                  strpos($mod, 'path="cash-flow"') !== false);
$a('JE detail route /:id',                             strpos($mod, 'path="journal-entries/:id"') !== false);
$a('JE create route /new',                             strpos($mod, 'path="journal-entries/new"') !== false);

echo "\nCashFlowStatement.jsx\n";
$cf = (string) file_get_contents(__DIR__ . '/../modules/accounting/ui/CashFlowStatement.jsx');
$a('section testid root (ReportShell prefix)',         strpos($cf, 'testIdPrefix="rpt-cf"') !== false);
$a('uses cash_flow_indirect API',                      strpos($cf, 'type=cash_flow_indirect') !== false);
$a('renders operating section',                        strpos($cf, '"rpt-cf-operating"') !== false);
$a('renders investing section',                        strpos($cf, '"rpt-cf-investing"') !== false);
$a('renders financing section',                        strpos($cf, '"rpt-cf-financing"') !== false);
$a('renders untagged warning',                         strpos($cf, 'rpt-cf-untagged-warning') !== false);
$a('renders net change + beginning + ending',
    strpos($cf, 'rpt-cf-net-change') !== false &&
    strpos($cf, 'rpt-cf-beginning')  !== false &&
    strpos($cf, 'rpt-cf-ending')     !== false);
$a('renders reconciliation row (tie-out kpi)',         strpos($cf, 'rpt-cf-kpi-balanced') !== false);
$a('renders balanced flag',                            strpos($cf, 'rpt-cf-balanced') !== false);

echo "\nJournalEntryCreate.jsx\n";
$jc = (string) file_get_contents(__DIR__ . '/../modules/accounting/ui/JournalEntryCreate.jsx');
$a('section testid',                                   strpos($jc, 'data-testid="accounting-je-create"') !== false);
$a('posting-date input',                               strpos($jc, 'accounting-je-date') !== false);
$a('memo input',                                       strpos($jc, 'accounting-je-memo') !== false);
$a('balance status indicator',                         strpos($jc, 'accounting-je-balance-status') !== false);
$a('save-as-draft button',                             strpos($jc, 'accounting-je-save-draft') !== false);
$a('save-and-post button',                             strpos($jc, 'accounting-je-post') !== false);
$a('balance check sum(debit) === sum(credit)',
    strpos($jc, 'totals.debit - totals.credit') !== false);
$a('only valid lines posted',
    strpos($jc, "l.account_code && (parseFloat(l.debit) > 0 || parseFloat(l.credit) > 0)") !== false);
$a('uses v1 journal entries API constant',
    strpos($jc, "const JOURNAL_ENTRIES_API = '/api/v1/accounting/journal-entries'") !== false);
$a('uses v1 accounts API constant',
    strpos($jc, "const ACCOUNTS_API = '/api/v1/accounting/accounts'") !== false);

echo "\nJournalEntryDetail.jsx\n";
$jd = (string) file_get_contents(__DIR__ . '/../modules/accounting/ui/JournalEntryDetail.jsx');
$a('detail section testid',                            strpos($jd, 'data-testid="accounting-je-detail"') !== false);
$a('reverse button',                                   strpos($jd, 'accounting-je-reverse') !== false);
$a('source-link drill-through',                        strpos($jd, 'accounting-je-source-link') !== false);
$a('maps ap_bills source',                             strpos($jd, 'ap_bills:') !== false || strpos($jd, "'ap_bills'") !== false);
$a('maps payroll_runs source',                         strpos($jd, "'payroll_runs'") !== false);
$a('status pill renders',                              strpos($jd, 'accounting-je-status-') !== false);
$a('uses v1 journal detail and reverse routes',
    strpos($jd, "const JOURNAL_ENTRIES_API = '/api/v1/accounting/journal-entries'") !== false &&
    strpos($jd, '${JOURNAL_ENTRIES_API}/${id}/reverse') !== false);

echo "\nIS / BS drill-through (Reports Overhaul Pass 2 — slide-over not route)\n";
$is = (string) file_get_contents(__DIR__ . '/../modules/accounting/ui/IncomeStatement.jsx');
$a('IS imports GlDetailDrilldown',                     strpos($is, 'GlDetailDrilldown') !== false);
$a('IS row onDrill wires setDrill (slide-over modal)', strpos($is, 'setDrill({') !== false &&
                                                       strpos($is, 'accountCode: code') !== false);
$a('IS row drill chevron testid via ComparisonTable',  strpos($is, 'rpt-pnl-revenue') !== false &&
                                                       strpos($is, 'rpt-pnl-expense')   !== false);
$bs = (string) file_get_contents(__DIR__ . '/../modules/accounting/ui/BalanceSheet.jsx');
$a('BS imports GlDetailDrilldown',                     strpos($bs, 'GlDetailDrilldown') !== false);
$a('BS row onDrill wires setDrill',                    strpos($bs, 'setDrill({') !== false);
$a('BS drill skips synthetic rows',                    strpos($bs, 'synthetic ? null') !== false);

echo "\nJournalEntries.jsx — URL filter pass-through\n";
$jl = (string) file_get_contents(__DIR__ . '/../modules/accounting/ui/JournalEntries.jsx');
$a('imports useSearchParams',                          strpos($jl, 'useSearchParams') !== false);
$a('reads account_code from URL',                      strpos($jl, "searchParams.get('account_code')") !== false);
$a('reads from / to from URL',
    strpos($jl, "searchParams.get('from')") !== false &&
    strpos($jl, "searchParams.get('to')")   !== false);
$a('forwards filters to API call',                     strpos($jl, "qs.set('account_code', accountCode)") !== false);
$a('renders filter pill when active',                  strpos($jl, 'accounting-journal-filter-pill') !== false);
$a('clear-filter button',                              strpos($jl, 'accounting-journal-filter-clear') !== false);
$a('uses v1 journal list API',                         strpos($jl, 'const apiUrl = JOURNAL_ENTRIES_API') !== false);

echo PHP_EOL . "Total: $pass passed, $fail failed" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
