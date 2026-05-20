<?php
/**
 * Smoke for the AI cost-tracking columns + aiComputeCostCents() helper.
 *
 *   - Migration 060 adds the three columns at the right ordinal.
 *   - aiComputeCostCents() returns null when model unknown OR no tokens.
 *   - Returns sensible cents for known rate cards.
 *   - aiAuditWrite() auto-computes cost_cents when tokens supplied and
 *     doesn't double-write when caller already pinned it.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/ai_service.php';

$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};

// ── Migration ────────────────────────────────────────────────────────
$mig = file_get_contents('/app/core/migrations/060_ai_interactions_cost_tracking.sql');
$a('migration 060 exists',                              $mig !== false);
$a('migration adds token_count_prompt',                 str_contains($mig, 'token_count_prompt'));
$a('migration adds token_count_response',               str_contains($mig, 'token_count_response'));
$a('migration adds cost_cents',                         str_contains($mig, 'cost_cents'));
$a('migration uses IF NOT EXISTS (idempotent)',         substr_count($mig, 'IF NOT EXISTS') >= 3);
$a('migration positions columns after response_hash',   str_contains($mig, 'AFTER response_hash'));
$a('migration stores cost as INT UNSIGNED (no float drift)',
   str_contains($mig, 'cost_cents           INT UNSIGNED'));

// ── Rate card ────────────────────────────────────────────────────────
$a('rate card known: gpt-4o-mini',                      is_array(aiModelRateCardCentsPer1k('gpt-4o-mini')));
$a('rate card known: claude-sonnet-4.5',                is_array(aiModelRateCardCentsPer1k('claude-sonnet-4.5')));
$a('rate card known: gemini-3-pro',                     is_array(aiModelRateCardCentsPer1k('gemini-3-pro')));
$a('rate card matches model prefix (e.g. gpt-4o-mini-2026)',
   is_array(aiModelRateCardCentsPer1k('gpt-4o-mini-2026-02-01')));
$a('rate card returns null for unknown model',          aiModelRateCardCentsPer1k('llama-magic-9000') === null);

// ── aiComputeCostCents() ─────────────────────────────────────────────
$a('null when model is null',                           aiComputeCostCents(null, 1000, 1000) === null);
$a('null when both token counts are null',              aiComputeCostCents('gpt-4o-mini', null, null) === null);
$a('null when model unknown',                           aiComputeCostCents('mystery-model', 1000, 1000) === null);

// gpt-4o-mini @ 0.015c/1k prompt + 0.06c/1k response
// 1000 + 1000 tokens → 0.015 + 0.06 = 0.075c → ceil = 1 cent
$cost = aiComputeCostCents('gpt-4o-mini', 1000, 1000);
$a('gpt-4o-mini @ 1k/1k tokens → cost > 0',             $cost !== null && $cost >= 1);

// claude-sonnet-4.5 @ 0.3/1k prompt + 1.5/1k response
// 10000 + 5000 → 3.0 + 7.5 = 10.5c → 11
$cost2 = aiComputeCostCents('claude-sonnet-4.5', 10000, 5000);
$a('claude-sonnet-4.5 @ 10k/5k tokens → ≈11 cents',     $cost2 === 11);

// gemini-3-flash super-cheap path
$cost3 = aiComputeCostCents('gemini-3-flash', 100000, 100000);
$a('gemini-3-flash @ 100k/100k → reasonable cents',     $cost3 !== null && $cost3 >= 1 && $cost3 <= 200);

// Always rounds UP (we use ceil so we never under-bill)
$cost4 = aiComputeCostCents('gpt-4o-mini', 1, 1);
$a('cost rounds up (ceil, never under-bills)',          $cost4 === 1);

// ── aiAuditWrite() column surface ────────────────────────────────────
$svc = file_get_contents('/app/core/ai_service.php');
$a('aiAuditWrite includes token_count_prompt column',   str_contains($svc, "'token_count_prompt'"));
$a('aiAuditWrite includes token_count_response column', str_contains($svc, "'token_count_response'"));
$a('aiAuditWrite includes cost_cents column',           str_contains($svc, "'cost_cents'"));
$a('aiAuditWrite auto-computes cost when tokens given', str_contains($svc, "aiComputeCostCents("));
$a('aiAuditWrite skips auto-compute if caller pinned cost_cents',
   str_contains($svc, "!isset(\$data['cost_cents'])"));

echo "\n=========================================\n";
echo "AI cost tracking smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
