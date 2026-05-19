<?php
/**
 * Sprint 7f.2a smoke — AI auto-map for tax mappings.
 *
 * Asserts:
 *   - api/tax_mapping_ai_suggest.php (POST-only, RBAC, form whitelist,
 *     hard-coded line catalogue, line validation rejects hallucinations,
 *     uses aiAsk() chokepoint, AIDisabled returns 503, deduping).
 *   - TaxMappings.jsx exposes the AI strip with full controls,
 *     bulk-accept ≥ threshold, per-row confidence pill, model footer.
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

echo "Backend — api/tax_mapping_ai_suggest.php\n";
$ep = (string) file_get_contents("{$ROOT}/api/tax_mapping_ai_suggest.php");
$assert('endpoint exists',                       strlen($ep) > 0);
$assert('parses',                                $lint("{$ROOT}/api/tax_mapping_ai_suggest.php"));
$assert('POST-only',                             strpos($ep, "if (api_method() !== 'POST')") !== false);
$assert('RBAC accounting.je.create',             strpos($ep, "rbac_legacy_require(\$user, 'accounting.je.create')") !== false);
$assert('rejects unknown tax_form_code',         strpos($ep, "Unknown tax_form_code") !== false);
$assert('TAX_FORM_LINES catalogue (5 forms)',
    strpos($ep, "'US-1040-SCH-C' => [") !== false
    && strpos($ep, "'US-1120' => [")    !== false
    && strpos($ep, "'US-1120-S' => [")  !== false
    && strpos($ep, "'US-1065' => [")    !== false
    && strpos($ep, "'US-990' => [")     !== false);
$assert('Schedule C line 22 = Supplies',
    strpos($ep, "'line' => '22',  'label' => 'Supplies'") !== false);
$assert('defaults to all unmapped when account_ids absent',
    strpos($ep, "AND a.id NOT IN (") !== false
    && strpos($ep, 'FROM accounting_tax_mappings') !== false);
$assert('explicit account_ids path',
    strpos($ep, "id IN ({\$place})") !== false);
$assert('candidate filter to revenue/expense families',
    strpos($ep, "('revenue','expense','cost_of_goods_sold','other_income','other_expense','contra_revenue')") !== false);
$assert('uses aiAsk() chokepoint',
    strpos($ep, "\$res = aiAsk([") !== false
    && strpos($ep, "'feature_class'     => 'classification'") !== false);
$assert('feature_key=accounting.tax.auto_map',
    strpos($ep, "'feature_key'       => 'accounting.tax.auto_map'") !== false);
$assert('rejects hallucinated lines',
    strpos($ep, "in_array(\$line, \$validLineSet, true)") !== false
    && strpos($ep, "AI returned invalid line") !== false);
$assert('rejects unknown account_id from AI',    strpos($ep, "Unknown account_id from AI") !== false);
$assert('dedupes duplicate suggestions',         strpos($ep, "isset(\$seen[\$aid])") !== false);
$assert('clamps confidence 0..1',                strpos($ep, "max(0.0, min(1.0, \$conf))") !== false);
$assert('falls back label from line catalogue',
    strpos($ep, "(string) (\$labelByLine[\$line] ?? '')") !== false);
$assert('AIDisabled returns 503',
    strpos($ep, "catch (AIDisabledException \$e)") !== false
    && strpos($ep, "api_error('AI disabled for this tenant', 503)") !== false);
$assert('Throwable returns 502',
    strpos($ep, "catch (\\Throwable \$e)") !== false
    && strpos($ep, "AI suggestion failed:") !== false
    && strpos($ep, "502") !== false);
$assert('returns suggestions + skipped + lines envelope',
    strpos($ep, "'suggestions'      => \$out") !== false
    && strpos($ep, "'skipped'          => \$skipped") !== false
    && strpos($ep, "'tax_form_lines'   => \$lines") !== false);
$assert('returns interaction_id + model for audit',
    strpos($ep, "'interaction_id'   => \$res['interaction_id'] ?? null") !== false);
$assert('handles unparseable AI output gracefully',
    strpos($ep, "AI returned no parseable suggestions") !== false);

echo "\nFrontend — TaxMappings.jsx (AI auto-map UI)\n";
$jsx = (string) file_get_contents("{$ROOT}/modules/accounting/ui/TaxMappings.jsx");
$assert('imports Sparkles icon',                 strpos($jsx, "import { Sparkles } from 'lucide-react'") !== false);
$assert('hits suggest endpoint',                 strpos($jsx, "api.post('/api/tax_mapping_ai_suggest.php',") !== false);
$assert('threshold default 0.85',                strpos($jsx, 'useState(0.85)') !== false);
$assert('bulk accept iterates eligible',
    strpos($jsx, '(s.confidence ?? 0) >= aiThreshold') !== false
    && strpos($jsx, "for (const s of eligible)") !== false);
$assert('bulk accept POSTs each suggestion',
    strpos($jsx, "api.post('/api/tax_mappings.php', {") !== false
    && strpos($jsx, 'tax_form_code:  form') !== false);
$assert('pre-populates draft from suggestions',
    strpos($jsx, 'd[s.account_id] = { line: s.line, label: s.label, notes:') !== false);
$assert('removes accepted from suggestions list',
    strpos($jsx, '!acceptedIds.has(s.account_id)') !== false);

foreach ([
    'ai-strip','ai-threshold','ai-run','ai-accept-all','ai-model',
] as $id) {
    $assert("testid: accounting-tax-mappings-{$id}",
        strpos($jsx, "data-testid=\"accounting-tax-mappings-{$id}\"") !== false);
}
$assert('per-row AI confidence pill testid template',
    strpos($jsx, 'data-testid={`accounting-tax-mappings-ai-pill-${a.id}`}') !== false);
$assert('confidence pill color thresholds',
    strpos($jsx, 'ai.confidence >= 0.9') !== false
    && strpos($jsx, 'ai.confidence >= 0.75') !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
