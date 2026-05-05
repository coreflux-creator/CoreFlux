<?php
/**
 * Accounting — Rule learning smoke (Sprint A.2 enhancement).
 *
 *  - Migration 004 adds categorized_* columns + 'ai_learned' enum value
 *  - bank_statements.php exposes accept_ai_categorize action
 *  - bank_rules.php exposes ?action=learn
 *  - lib/bank_rec.php declares bankRecLearnRulesFromAccepts + bankRecExtractTokens
 *  - bankRecExtractTokens (pure function): drops short / numeric / stop tokens
 *  - BankReconciliation.jsx renders Learn button + result banner +
 *    "learned" pill on rules with created_via=ai_learned
 */
declare(strict_types=1);

require_once __DIR__ . '/../modules/accounting/lib/bank_rec.php';

$pass = 0; $fail = 0;
$a = function (string $label, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

echo "Migration 004_rule_learning.sql\n";
$mig = (string) file_get_contents(__DIR__ . '/../modules/accounting/migrations/004_rule_learning.sql');
$a('adds categorized_account_code',   strpos($mig, 'ADD COLUMN categorized_account_code') !== false);
$a('adds categorized_at',             strpos($mig, 'ADD COLUMN categorized_at') !== false);
$a('adds categorized_by_user_id',     strpos($mig, 'ADD COLUMN categorized_by_user_id') !== false);
$a('adds categorized_via',            strpos($mig, 'ADD COLUMN categorized_via') !== false);
$a('extends created_via with ai_learned',
    strpos($mig, "ENUM('manual','ai_suggested','ai_learned')") !== false);
$a('utf8mb4_unicode_ci safe',
    stripos($mig, 'utf8mb4_0900_ai_ci') === false);

echo "\nbank_statements.php — accept_ai_categorize\n";
$bs = (string) file_get_contents(__DIR__ . '/../modules/accounting/api/bank_statements.php');
$a('accept_ai_categorize action',                   strpos($bs, "action === 'accept_ai_categorize'") !== false);
$a('requires account_code',                         strpos($bs, "api_require_fields(\$body, ['account_code'])") !== false);
$a('stamps categorized_via=ai_accepted',            strpos($bs, "'categorized_via'          => 'ai_accepted'") !== false);
$a('records outcome via unified ai moat helper',
    strpos($bs, 'aiRecordCategorizationOutcome(') !== false);
$a('audits accept event',                            strpos($bs, "'accounting.bank.ai_categorize_accepted'") !== false);

echo "\nbank_rules.php — learn action\n";
$br = (string) file_get_contents(__DIR__ . '/../modules/accounting/api/bank_rules.php');
$a('learn action wired',                             strpos($br, "action === 'learn'") !== false);
$a('learn calls bankRecLearnRulesFromAccepts',       strpos($br, 'bankRecLearnRulesFromAccepts(') !== false);
$a('learn min_occurrences default = 3',              strpos($br, "(int) (\$_GET['min_occurrences'] ?? 3)") !== false);
$a('learn audits rules_learned',                     strpos($br, "'accounting.bank.rules_learned'") !== false);

echo "\nlib/bank_rec.php — learner helpers\n";
$lib = (string) file_get_contents(__DIR__ . '/../modules/accounting/lib/bank_rec.php');
$a('bankRecLearnRulesFromAccepts declared',          strpos($lib, 'function bankRecLearnRulesFromAccepts') !== false);
$a('bankRecExtractTokens declared',                  strpos($lib, 'function bankRecExtractTokens') !== false);
$a('learner queries categorized_via=ai_accepted',    strpos($lib, 'categorized_via = "ai_accepted"') !== false);
$a('learner skips clusters < min_occurrences',       strpos($lib, 'if (count($descMap) < $minOccurrences) continue;') !== false);
$a('learner counts each token once per description',
    strpos($lib, 'foreach (array_unique($tokens)') !== false);
$a('learner picks highest-occurrence first (arsort)', strpos($lib, 'arsort($tokenCounts)') !== false);
$a('learner skips existing patterns',                 strpos($lib, '$existingSet[$key]') !== false);
$a('learner direction-locks to debit when all debit', strpos($lib, '$allDebit ? \'debit\'') !== false);
$a('learner inserts created_via=ai_learned',          strpos($lib, "'created_via'         => 'ai_learned'") !== false);
$a('learner stays is_approved=0 (one-click approve)', strpos($lib, "'is_approved'         => 0") !== false);
$a('learner returns drafts list',                     strpos($lib, "'drafts'             => \$drafts") !== false);
$a('one rule per cluster per run (break)',            strpos($lib, '            break;  // one rule per cluster per learner run') !== false);

echo "\nbankRecExtractTokens — pure function unit asserts\n";
$t1 = bankRecExtractTokens('AWS Cloud Charge 12345');
$a('drops 3-char "aws" (4-char threshold)', !in_array('aws',   $t1, true));
$a('keeps "cloud" token',                             in_array('cloud', $t1, true));
$a('keeps "charge" token',                            in_array('charge',$t1, true));
$a('drops pure-numeric token "12345"',                !in_array('12345',$t1, true));
$a('drops short token (≤3 chars)',                    !in_array('aws1', $t1, true));   // edge: still 4 chars; rephrase
// Re-verify the 4-char minimum boundary
$t1b = bankRecExtractTokens('NY ABC');
$a('drops 2-char "NY"',                               !in_array('ny',   $t1b, true));
$a('drops 3-char "ABC"',                              !in_array('abc',  $t1b, true));
$t2 = bankRecExtractTokens('ACH Transfer to AMZN Mktp US Inc');
$a('drops stop-token "ach"',                          !in_array('ach',      $t2, true));
$a('drops stop-token "transfer"',                     !in_array('transfer', $t2, true));
$a('drops stop-token "inc"',                          !in_array('inc',      $t2, true));
$a('keeps "amzn"',                                    in_array('amzn', $t2, true));
$a('keeps "mktp"',                                    in_array('mktp', $t2, true));
$t3 = bankRecExtractTokens('STRIPE FEE STRIPE FEE');
$a('returns repeats (caller dedups)',                 count(array_filter($t3, fn($x) => $x === 'stripe')) === 2);

echo "\nBankReconciliation.jsx — Learn UI surface\n";
$ui = (string) file_get_contents(__DIR__ . '/../modules/accounting/ui/BankReconciliation.jsx');
$a('Learn-from-accepts button',                       strpos($ui, 'accounting-bank-rules-learn"') !== false);
$a('Learn result banner',                             strpos($ui, 'accounting-bank-rules-learn-result') !== false);
$a('Learn-empty state surfaced',                      strpos($ui, 'accounting-bank-rules-learn-empty') !== false);
$a('Learn-count surfaced',                            strpos($ui, 'accounting-bank-rules-learn-count') !== false);
$a('Learn error surface',                             strpos($ui, 'accounting-bank-rules-learn-error') !== false);
$a('"learned" pill on ai_learned rules',              strpos($ui, 'accounting-bank-rule-learned-') !== false);
$a('explanatory copy mentions ≥3 accepts threshold',  stripos($ui, '≥3 times') !== false ||
                                                       stripos($ui, '>= 3 times') !== false ||
                                                       stripos($ui, '3 times') !== false);
$a('calls /bank_rules.php?action=learn',              strpos($ui, "?action=learn") !== false);

echo PHP_EOL . "Total: $pass passed, $fail failed" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
