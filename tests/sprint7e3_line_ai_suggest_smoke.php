<?php
/**
 * Sprint 7e.3 smoke — Bill + Invoice UX polish: inline AI line-account suggestion.
 *
 * Asserts:
 *   - api/line_ai_suggest.php (POST-only, RBAC by kind, history-first
 *     cascade, LLM fallback restricted to expense vs revenue family,
 *     graceful AI-disabled / unknown-account paths).
 *   - LineItemEditor.jsx exposes per-line AI suggest + accept buttons +
 *     confidence rendering when aiSuggestKind is set.
 *   - BillCreate.jsx + InvoiceCreate.jsx opt into the AI cascade with
 *     correct kind + counterparty name.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok) use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}\n"; $fail++; }
};
$lint = function (string $p): bool {
    $o = []; $rc = 0; @exec('php -l ' . escapeshellarg($p) . ' 2>&1', $o, $rc);
    return $rc === 0;
};
$ROOT = realpath(__DIR__ . '/..');

echo "Backend — api/line_ai_suggest.php\n";
$ep = (string) file_get_contents("{$ROOT}/api/line_ai_suggest.php");
$assert('endpoint exists',                       strlen($ep) > 0);
$assert('parses',                                $lint("{$ROOT}/api/line_ai_suggest.php"));
$assert('POST-only',                             strpos($ep, "if (api_method() !== 'POST')") !== false);
$assert('kind whitelist ap_bill / billing_invoice',
    strpos($ep, "['ap_bill','billing_invoice']") !== false);
$assert('RBAC ap.bill.create for ap_bill',
    strpos($ep, "'ap.bill.create' : 'billing.invoice.create'") !== false);
$assert('history-first cascade reads ai_categorization_history',
    strpos($ep, 'FROM ai_categorization_history') !== false);
$assert('history threshold (>= 2 hits) gives high confidence',
    strpos($ep, "(int) \$historyRow['hits'] >= 2") !== false
    && strpos($ep, "0.92") !== false);
$assert('history source label',                  strpos($ep, "'source'        => 'history'") !== false);
$assert('LLM fallback restricts AP candidates to expense families',
    strpos($ep, "('expense','cost_of_goods_sold','other_expense')") !== false);
$assert('LLM fallback restricts AR candidates to revenue families',
    strpos($ep, "('revenue','other_income','contra_revenue')") !== false);
$assert('LLM goes through aiAsk() chokepoint',   strpos($ep, '$res = aiAsk([') !== false);
$assert('LLM feature_class=classification',      strpos($ep, "'feature_class'     => 'classification'") !== false);
$assert('feature_key per kind',
    strpos($ep, "'ap.bill_line.suggest_account' : 'billing.invoice_line.suggest_account'") !== false);
$assert('rejects unknown account_code from LLM',
    strpos($ep, 'AI suggested unknown account code') !== false);
$assert('AIDisabled graceful',                   strpos($ep, 'catch (AIDisabledException $e)') !== false);
$assert('Throwable graceful',                    strpos($ep, 'AI suggestion unavailable') !== false);
$assert('returns review_required flag',          strpos($ep, "'review_required'") !== false);

echo "\nFrontend — LineItemEditor.jsx\n";
$lie = (string) file_get_contents("{$ROOT}/dashboard/src/components/LineItemEditor.jsx");
$assert('imports api',                           strpos($lie, "import { api } from '../lib/api'") !== false);
$assert('imports Sparkles icon',                 strpos($lie, "import { Sparkles } from 'lucide-react'") !== false);
$assert('aiSuggestKind prop',                    strpos($lie, 'aiSuggestKind = null') !== false);
$assert('counterpartyName prop',                 strpos($lie, "counterpartyName = ''") !== false);
$assert('aiSuggest function POSTs to /api/line_ai_suggest.php',
    strpos($lie, "api.post('/api/line_ai_suggest.php'") !== false);
$assert('vendor_name set when kind=ap_bill',     strpos($lie, "if (aiSuggestKind === 'ap_bill') body.vendor_name") !== false);
$assert('client_name set otherwise',             strpos($lie, "else body.client_name") !== false);
$assert('acceptAi sets gl_account_code from suggestion',
    strpos($lie, "setLine(i, { gl_account_code: code })") !== false);
$assert('AI suggest button testid template',
    strpos($lie, 'data-testid={`${testIdPrefix}-line-${i}-ai-suggest`}') !== false);
$assert('AI accept button testid template',
    strpos($lie, 'data-testid={`${testIdPrefix}-line-${i}-ai-accept`}') !== false);
$assert('AI empty-state testid template',
    strpos($lie, 'data-testid={`${testIdPrefix}-line-${i}-ai-empty`}') !== false);
$assert('AI suggest disabled when description empty',
    strpos($lie, "disabled={aiBusy[i] || !(l.description || '').trim()}") !== false);
$assert('Confidence color thresholds',
    strpos($lie, '>= 0.8') !== false && strpos($lie, '>= 0.5') !== false);

echo "\nWiring — BillCreate.jsx + InvoiceCreate.jsx\n";
$bc = (string) file_get_contents("{$ROOT}/modules/ap/ui/BillCreate.jsx");
$assert('AP BillCreate opts into aiSuggestKind=ap_bill',
    strpos($bc, 'aiSuggestKind="ap_bill"') !== false);
$assert('AP BillCreate passes vendor name as counterparty',
    strpos($bc, 'counterpartyName={vendor?.name') !== false);

$ic = (string) file_get_contents("{$ROOT}/modules/billing/ui/InvoiceCreate.jsx");
$assert('Billing InvoiceCreate opts into aiSuggestKind=billing_invoice',
    strpos($ic, 'aiSuggestKind="billing_invoice"') !== false);
$assert('Billing InvoiceCreate passes client name as counterparty',
    strpos($ic, 'counterpartyName={client?.name') !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
