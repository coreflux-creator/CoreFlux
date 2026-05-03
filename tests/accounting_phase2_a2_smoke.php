<?php
/**
 * Accounting Phase 2 Sprint A.2 smoke — Bank rec + AI assists.
 *
 *  - Migration 003: bank_rules + ai_suggested_* columns + applied_rule_id
 *  - bank_accounts.php CRUD
 *  - bank_statements.php (import_csv, match, unmatch, ignore, apply_rules)
 *  - bank_rules.php (CRUD + approve / pause / archive)
 *  - bank_ai.php (suggest_match, suggest_categorize, suggest_rule via aiAsk)
 *  - lib/bank_rec.php helpers
 *  - BankReconciliation.jsx — accounts list, lines grid, AI buttons,
 *    rules list with Approve/Pause/Archive, "Apply rules now" action,
 *    Suggested vs Auto-apply pills
 *  - AccountingModule wires Bank Rec route
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $label, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

echo "Migration 003_bank_rules_ai.sql\n";
$mig = (string) file_get_contents(__DIR__ . '/../modules/accounting/migrations/003_bank_rules_ai.sql');
$a('creates accounting_bank_rules',                strpos($mig, 'CREATE TABLE IF NOT EXISTS accounting_bank_rules') !== false);
$a('rule pattern_kind enum',                       strpos($mig, "ENUM('contains','starts_with','equals','regex')") !== false);
$a('rule direction enum',                          strpos($mig, "ENUM('any','credit','debit')") !== false);
$a('rule is_approved flag',                        strpos($mig, 'is_approved              TINYINT(1) NOT NULL DEFAULT 0') !== false);
$a('rule created_via enum',                        strpos($mig, "ENUM('manual','ai_suggested')") !== false);
$a('rule status enum',                             strpos($mig, "ENUM('active','paused','archived')") !== false);
$a('adds ai_suggested_account_code col',           strpos($mig, 'ai_suggested_account_code') !== false);
$a('adds ai_suggested_je_id col',                  strpos($mig, 'ai_suggested_je_id') !== false);
$a('adds ai_suggested_rule_id col',                strpos($mig, 'ai_suggested_rule_id') !== false);
$a('adds ai_suggested_confidence',                 strpos($mig, 'ai_suggested_confidence') !== false);
$a('adds applied_rule_id (auto-applied marker)',   strpos($mig, 'applied_rule_id') !== false);
$a('utf8mb4_unicode_ci collation',
    strpos($mig, 'utf8mb4_unicode_ci') !== false &&
    stripos($mig, 'utf8mb4_0900_ai_ci') === false);

echo "\nBank accounts API\n";
$ba = (string) file_get_contents(__DIR__ . '/../modules/accounting/api/bank_accounts.php');
$a('GET list',                                     strpos($ba, "method === 'GET'") !== false);
$a('GET detail (with id)',                         strpos($ba, "method === 'GET' && !empty(\$_GET['id'])") !== false);
$a('detail returns unmatched_line_count',          strpos($ba, "'unmatched_line_count'") !== false);
$a('POST create',                                  strpos($ba, "api_require_fields(\$body, ['name', 'gl_account_code'])") !== false);
$a('PUT update',                                   strpos($ba, "method === 'PUT'") !== false);
$a('close action',                                 strpos($ba, "action === 'close'") !== false);
$a('never exposes plaid_access_token_ct',          strpos($ba, "unset(\$row['plaid_access_token_ct'])") !== false);
$a('audit on create',                              strpos($ba, "'accounting.bank_account.created'") !== false);

echo "\nBank statements API\n";
$bs = (string) file_get_contents(__DIR__ . '/../modules/accounting/api/bank_statements.php');
$a('import_csv action',                            strpos($bs, "action === 'import_csv'") !== false);
$a('import_csv calls bankRecImportCsv',            strpos($bs, 'bankRecImportCsv(') !== false);
$a('match action',                                 strpos($bs, "action === 'match'") !== false);
$a('unmatch action',                               strpos($bs, "action === 'unmatch'") !== false);
$a('ignore action',                                strpos($bs, "action === 'ignore'") !== false);
$a('apply_rules action',                           strpos($bs, "action === 'apply_rules'") !== false);
$a('audits statement_imported',                    strpos($bs, "'accounting.bank.statement_imported'") !== false);
$a('audits line_matched',                          strpos($bs, "'accounting.bank.line_matched'") !== false);
$a('audits rules_applied',                         strpos($bs, "'accounting.bank.rules_applied'") !== false);

echo "\nBank rules API\n";
$br = (string) file_get_contents(__DIR__ . '/../modules/accounting/api/bank_rules.php');
$a('GET list rules',                               strpos($br, "method === 'GET'") !== false);
$a('POST create rule',                             strpos($br, "api_require_fields(\$body, ['name','pattern','target_account_code'])") !== false);
$a('approve action flips is_approved',             strpos($br, "'is_approved' => 1") !== false);
$a('pause action sets status=paused',              strpos($br, "['status' => 'paused']") !== false);
$a('archive action sets status=archived',          strpos($br, "['status' => 'archived']") !== false);
$a('regex pattern compile-validated',              strpos($br, '@preg_match(') !== false);
$a('PUT update',                                   strpos($br, "method === 'PUT'") !== false);
$a('audits rule_created',                          strpos($br, "'accounting.bank.rule_created'") !== false);
$a('audits rule_approve / pause / archive',        strpos($br, "'accounting.bank.rule_' . \$action") !== false);

echo "\nBank AI assist API\n";
$bai = (string) file_get_contents(__DIR__ . '/../modules/accounting/api/bank_ai.php');
$a('suggest_match action',                         strpos($bai, "action === 'suggest_match'") !== false);
$a('suggest_match calls bankRecAutoSuggestMatches',strpos($bai, 'bankRecAutoSuggestMatches(') !== false);
$a('suggest_match feature_key',                    strpos($bai, "'accounting.bank.suggest_match'") !== false);
$a('suggest_categorize action',                    strpos($bai, "action === 'suggest_categorize'") !== false);
$a('suggest_categorize feature_key',               strpos($bai, "'accounting.bank.suggest_categorize'") !== false);
$a('suggest_categorize loads COA',                 strpos($bai, "FROM accounting_accounts") !== false);
$a('suggest_rule action',                          strpos($bai, "action === 'suggest_rule'") !== false);
$a('suggest_rule feature_key',                     strpos($bai, "'accounting.bank.suggest_rule'") !== false);
$a('suggest_rule passes existing rules to AI',     strpos($bai, "'existing_rules'") !== false);
$a('suggest_rule mentions is_approved default 0',  stripos($bai, 'is_approved=0') !== false);
$a('all suggestions return review_required true',  substr_count($bai, "'review_required' => true") >= 3);
$a('uses aiAsk advisory feature_class',            substr_count($bai, "'feature_class'   => 'advisory'") >= 3);

echo "\nlib/bank_rec.php helpers\n";
$lib = (string) file_get_contents(__DIR__ . '/../modules/accounting/lib/bank_rec.php');
$a('bankRecImportCsv declared',                    strpos($lib, 'function bankRecImportCsv') !== false);
$a('bankRecMatchLine declared',                    strpos($lib, 'function bankRecMatchLine') !== false);
$a('bankRecUnmatchLine declared',                  strpos($lib, 'function bankRecUnmatchLine') !== false);
$a('bankRecApplyRules declared',                   strpos($lib, 'function bankRecApplyRules') !== false);
$a('bankRecLineMatchesRule declared',              strpos($lib, 'function bankRecLineMatchesRule') !== false);
$a('bankRecAutoSuggestMatches declared',           strpos($lib, 'function bankRecAutoSuggestMatches') !== false);
$a('CSV import dedups via INSERT IGNORE',          strpos($lib, 'INSERT IGNORE INTO accounting_bank_statement_lines') !== false);
$a('CSV synthesizes FITID for de-dup when missing',strpos($lib, 'sha1(') !== false);
$a('rule auto-apply stamps applied_rule_id',
    strpos($lib, "'applied_rule_id'           => \$r['id']") !== false);
$a('rule suggested writes ai_suggested_rule_id only',
    strpos($lib, "'ai_suggested_confidence'   => 0.800") !== false);
$a('rule applies to amount_min / amount_max guards',
    strpos($lib, "amount_min_cents")  !== false &&
    strpos($lib, "amount_max_cents")  !== false);
$a('regex rule kind supported',
    strpos($lib, "'regex'       => @preg_match") !== false);
$a('first matching rule wins per line (loop break)',
    strpos($lib, '            break;  // first matching rule wins per line') !== false);

// Pure-function unit test for bankRecLineMatchesRule (no DB touch)
require_once __DIR__ . '/../modules/accounting/lib/bank_rec.php';

$ruleContains = ['pattern_kind' => 'contains', 'pattern' => 'AWS', 'direction' => 'any',
                 'amount_min_cents' => null, 'amount_max_cents' => null];
$ruleEquals   = ['pattern_kind' => 'equals',   'pattern' => 'STRIPE FEE', 'direction' => 'debit',
                 'amount_min_cents' => null, 'amount_max_cents' => null];
$ruleRegex    = ['pattern_kind' => 'regex',    'pattern' => '^GUSTO.*PAYROLL', 'direction' => 'any',
                 'amount_min_cents' => null, 'amount_max_cents' => null];
$ruleAmtCap   = ['pattern_kind' => 'contains', 'pattern' => 'amzn',  'direction' => 'any',
                 'amount_min_cents' => 1000,   'amount_max_cents' => 50000];

$lineAws    = ['description' => 'AWS Cloud Charge 12345', 'amount' => -340.12];
$lineMisc   = ['description' => 'Office Depot',           'amount' => -19.99];
$lineStripe = ['description' => 'STRIPE FEE',             'amount' => -2.50];
$lineGusto  = ['description' => 'GUSTO INC PAYROLL ABC',  'amount' => -3500.00];
$lineAmzBig = ['description' => 'AMZN Mktp US',           'amount' => -800.00];   // 80,000 cents — over cap
$lineAmzOk  = ['description' => 'AMZN Mktp US',           'amount' => -120.00];   // 12,000 cents — within cap

$a('contains-rule matches AWS line',               bankRecLineMatchesRule($lineAws, $ruleContains) === true);
$a('contains-rule rejects unrelated line',         bankRecLineMatchesRule($lineMisc, $ruleContains) === false);
$a('equals-rule + debit direction matches',        bankRecLineMatchesRule($lineStripe, $ruleEquals) === true);
$a('regex-rule matches GUSTO payroll line',        bankRecLineMatchesRule($lineGusto, $ruleRegex) === true);
$a('amount cap rejects $800 line',                 bankRecLineMatchesRule($lineAmzBig, $ruleAmtCap) === false);
$a('amount cap accepts $120 line',                 bankRecLineMatchesRule($lineAmzOk,  $ruleAmtCap) === true);
$a('direction=debit rejects credit line',
    bankRecLineMatchesRule(['description' => 'STRIPE FEE', 'amount' => 5.00], $ruleEquals) === false);

echo "\nBankReconciliation.jsx UI\n";
$ui = (string) file_get_contents(__DIR__ . '/../modules/accounting/ui/BankReconciliation.jsx');
$a('accounts list',                                strpos($ui, 'accounting-bank-accounts') !== false);
$a('add-account button',                           strpos($ui, 'accounting-bank-account-new') !== false);
$a('account detail page',                          strpos($ui, 'accounting-bank-account-detail') !== false);
$a('CSV import textarea + button',
    strpos($ui, 'accounting-bank-csv-input') !== false &&
    strpos($ui, 'accounting-bank-csv-import') !== false);
$a('apply-rules button',                           strpos($ui, 'accounting-bank-apply-rules') !== false);
$a('lines table',                                  strpos($ui, 'accounting-bank-lines-table') !== false);
$a('AI match button per line',                     strpos($ui, 'accounting-bank-ai-match-') !== false);
$a('AI categorize button per line',                strpos($ui, 'accounting-bank-ai-cat-') !== false);
$a('AI suggest-rule button per line',              strpos($ui, 'accounting-bank-ai-rule-') !== false);
$a('rule-applied pill',                            strpos($ui, 'accounting-bank-line-applied-') !== false);
$a('rule-suggested pill',                          strpos($ui, 'accounting-bank-line-rule-suggested-') !== false);
$a('cat-suggested pill',                           strpos($ui, 'accounting-bank-line-cat-suggested-') !== false);
$a('rules list page',                              strpos($ui, 'accounting-bank-rules') !== false);
$a('rule mode pills (suggested vs auto)',
    strpos($ui, 'accounting-bank-rule-mode-suggested-')  !== false &&
    strpos($ui, 'accounting-bank-rule-mode-approved-')   !== false);
$a('rule approve button',                          strpos($ui, 'accounting-bank-rule-approve-') !== false);
$a('rule pause / archive buttons',
    strpos($ui, 'accounting-bank-rule-pause-')   !== false &&
    strpos($ui, 'accounting-bank-rule-archive-') !== false);
$a('explanatory copy: suggested vs auto-apply',    stripos($ui, 'auto-apply') !== false);

echo "\nAccountingModule routes Bank Rec\n";
$mod = (string) file_get_contents(__DIR__ . '/../modules/accounting/ui/AccountingModule.jsx');
$a('imports BankReconciliation',                   strpos($mod, "from './BankReconciliation'") !== false);
$a('Bank Rec tab',                                 strpos($mod, 'to="bank-rec"') !== false);
$a('Bank Rec route',                               strpos($mod, 'path="bank-rec/*"') !== false);

echo PHP_EOL . "Total: $pass passed, $fail failed" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
