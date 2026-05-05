<?php
/**
 * AI Confidence-Score Moat smoke.
 *
 * Locks in:
 *   • Migration 009 declares the right columns + tables
 *   • core/ai_categorization.php has the cascade (history → rules → llm)
 *     and constants (PROMPT_VERSION, MODEL_VERSION, AUTO_ACCEPT)
 *   • account_transactions.php returns ai_suggestion per unmatched row
 *     and records outcome on categorize_and_post
 *   • bank_ai.php uses the unified service + bank_statements records outcome
 *   • api/ai_accuracy.php exposes the dashboard data + rollup action
 *   • UI surfaces confidence pill + Accept button + auto-accept threshold
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $name, $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ {$name}\n"; $pass++; } else { echo "  ✗ {$name}\n"; $fail++; }
};
$lint = function (string $path): bool {
    $rc = 0; @exec('php -l ' . escapeshellarg($path) . ' 2>&1', $_, $rc); return $rc === 0;
};

echo "core/migrations/009_ai_confidence_moat.sql\n";
$mig = file_get_contents(__DIR__ . '/../core/migrations/009_ai_confidence_moat.sql');
$assert('migration exists',                          is_string($mig) && strlen($mig) > 100);
$assert('adds confidence_score column',              strpos($mig, 'confidence_score DECIMAL(5,4)') !== false);
$assert('adds prompt_version + model_version',       strpos($mig, 'prompt_version') !== false
                                                  && strpos($mig, 'model_version')  !== false);
$assert('adds suggested_value + final_value + accepted_as_is',
                                                     strpos($mig, 'suggested_value') !== false
                                                  && strpos($mig, 'final_value')     !== false
                                                  && strpos($mig, 'accepted_as_is')  !== false);
$assert('adds suggestion_source enum',               strpos($mig, "suggestion_source ENUM(''history'',''rules'',''llm'',''hybrid'')") !== false);
$assert('creates ai_categorization_history',         strpos($mig, 'CREATE TABLE IF NOT EXISTS ai_categorization_history') !== false);
$assert('creates ai_accuracy_daily',                 strpos($mig, 'CREATE TABLE IF NOT EXISTS ai_accuracy_daily') !== false);
$assert('history table has accept_count + last_accepted_at',
                                                     strpos($mig, 'accept_count') !== false
                                                  && strpos($mig, 'last_accepted_at') !== false);
$assert('uq_aichist enforces (tenant,feature,signal,value,final)',
                                                     strpos($mig, 'uq_aichist') !== false);

echo "core/ai_categorization.php\n";
$svc = file_get_contents(__DIR__ . '/../core/ai_categorization.php');
$assert('service exists',                       is_string($svc) && strlen($svc) > 500);
$assert('AI_CATEGORIZATION_PROMPT_VERSION set', strpos($svc, "const AI_CATEGORIZATION_PROMPT_VERSION = 'v1.0'") !== false);
$assert('AI_CATEGORIZATION_AUTO_ACCEPT = 0.90', strpos($svc, "const AI_CATEGORIZATION_AUTO_ACCEPT    = 0.90") !== false);
$assert('aiSuggestCounterpartAccount entry',    strpos($svc, 'function aiSuggestCounterpartAccount(') !== false);
$assert('aiRecordCategorizationOutcome entry',  strpos($svc, 'function aiRecordCategorizationOutcome(') !== false);
$assert('history → 0.97 for ≥3 prior accepts',  strpos($svc, "0.97") !== false);
$assert('PFC rule ladder includes FOOD_AND_DRINK',
                                                strpos($svc, "'FOOD_AND_DRINK'") !== false);
$assert('LLM fallback gated by aiGateForTenant',strpos($svc, 'aiGateForTenant(') !== false);
$assert('LLM uses aiCallOpenAI tuple unpack',   strpos($svc, '[$content, $latencyMs, $modelUsed, $http, $error] = aiCallOpenAI(') !== false);
$assert('rollup helper aiRollupAccuracyDaily',  strpos($svc, 'function aiRollupAccuracyDaily(') !== false);
$assert('rollup uses REPLACE INTO ai_accuracy_daily',
                                                strpos($svc, 'REPLACE INTO ai_accuracy_daily') !== false);
$assert('upsert helper aiUpsertCategorizationHistory',
                                                strpos($svc, 'function aiUpsertCategorizationHistory(') !== false);
$assert('PHP parses cleanly',                   $lint(__DIR__ . '/../core/ai_categorization.php'));

echo "modules/treasury/api/account_transactions.php (AI wiring)\n";
$at = file_get_contents(__DIR__ . '/../modules/treasury/api/account_transactions.php');
$assert('GET runs aiSuggestCounterpartAccount per unmatched row',
                                                strpos($at, 'aiSuggestCounterpartAccount(') !== false);
$assert('GET caches suggestions (status=draft) instead of re-suggesting',
                                                strpos($at, "AND status       = 'draft'") !== false);
$assert('categorize_and_post records outcome',  strpos($at, 'aiRecordCategorizationOutcome(') !== false);
$assert('PHP parses cleanly',                   $lint(__DIR__ . '/../modules/treasury/api/account_transactions.php'));

echo "modules/accounting/api/bank_ai.php + bank_statements.php (deposit AI uses same moat)\n";
$ba = file_get_contents(__DIR__ . '/../modules/accounting/api/bank_ai.php');
$assert('bank_ai.php uses aiSuggestCounterpartAccount',
                                                strpos($ba, 'aiSuggestCounterpartAccount(') !== false);
$assert('bank_ai.php returns auto_accept flag', strpos($ba, "review_required") !== false
                                              && strpos($ba, 'auto_accept')      !== false);
$bs = file_get_contents(__DIR__ . '/../modules/accounting/api/bank_statements.php');
$assert('bank_statements accept records outcome to history',
                                                strpos($bs, 'aiRecordCategorizationOutcome(') !== false);

echo "api/ai_accuracy.php (dashboard endpoint)\n";
$acc = file_get_contents(__DIR__ . '/../api/ai_accuracy.php');
$assert('endpoint exists',                      is_string($acc) && strlen($acc) > 200);
$assert('GET aggregates per-feature totals',    strpos($acc, 'GROUP BY feature_key') !== false);
$assert('GET surfaces top_overrides',           strpos($acc, 'top_overrides') !== false);
$assert('GET surfaces history_snapshot (moat depth)',
                                                strpos($acc, 'history_snapshot') !== false
                                              && strpos($acc, 'ai_categorization_history') !== false);
$assert('?action=rollup recomputes daily',      strpos($acc, "action'] ?? '') === 'rollup'") !== false);
$assert('PHP parses cleanly',                   $lint(__DIR__ . '/../api/ai_accuracy.php'));

echo "Treasury UI surfaces confidence pill + Accept button\n";
$ui = file_get_contents(__DIR__ . '/../modules/treasury/ui/AccountTransactions.jsx');
$assert('AiSuggestionPill component present',   strpos($ui, 'function AiSuggestionPill(') !== false);
$assert('renders AI confidence percentage',     strpos($ui, 'AI: {conf}%') !== false);
$assert('renders Accept button on pill',        strpos($ui, 'treasury-txn-ai-accept-${suggestion.suggestion_id}') !== false);
$assert('CategorizeRow pre-selects AI suggestion',
                                                strpos($ui, "aiSuggestion?.suggested_account_id ? String(aiSuggestion.suggested_account_id) : ''") !== false);
$assert('passes ai_suggestion_id back to backend',
                                                strpos($ui, 'ai_suggestion_id') !== false);

echo "AI Accuracy Dashboard React page\n";
$dash = file_get_contents(__DIR__ . '/../dashboard/src/pages/AiAccuracyDashboard.jsx');
$assert('dashboard renders accept rate stat',   strpos($dash, "Accept rate") !== false);
$assert('dashboard surfaces top overrides',     strpos($dash, 'data-testid="ai-accuracy-overrides-table"') !== false);
$assert('dashboard surfaces moat depth',        strpos($dash, 'data-testid="ai-accuracy-moat-strength"') !== false);
$assert('dashboard window selector 7/30/90/365',strpos($dash, 'data-testid="ai-accuracy-window"') !== false);
$adm = file_get_contents(__DIR__ . '/../dashboard/src/pages/AdminModule.jsx');
$assert('AdminModule routes /admin/ai-accuracy',strpos($adm, "<Route path=\"/ai-accuracy\"") !== false);
$assert('AdminModule sidebar has AI Accuracy link',
                                                strpos($adm, "label: 'AI Accuracy'") !== false);

echo "\nPass: {$pass}\nFail: {$fail}\n";
exit($fail === 0 ? 0 : 1);
