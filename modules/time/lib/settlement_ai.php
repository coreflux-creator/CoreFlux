<?php
/**
 * AI assistance for the Time Settlement screen.
 *
 * Single entry: timeSettlementAiSuggest($target, $blocks).
 *
 * Sends a tightly-scoped prompt to GPT-4o-mini with the current ready
 * blocks (placement_id, work_date, hours, cycle_default) and asks it to
 * group them into optimal extraction batches considering:
 *   - cycle alignment (blocks within same cycle window cluster together)
 *   - placement boundary (each suggestion is single-placement)
 *   - outliers (anomalous hours, weekends, late-approved → flagged)
 *
 * Output schema (validated):
 *   { suggestions: [{ name, reasoning, placement_id, entry_ids[], total_hours, flags[] }],
 *     advisories: [string] }
 *
 * Falls back to a rules-based grouping if AI is unavailable so the UI
 * always returns *something* useful.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../core/ai_service.php';

function timeSettlementAiSuggest(string $target, array $blocks): array
{
    if (!$blocks) return ['suggestions' => [], 'advisories' => ['No approved blocks to analyse.']];

    // Defensive trim: don't send absurd payloads to the LLM.
    if (count($blocks) > 200) {
        $blocks    = array_slice($blocks, 0, 200);
        $truncated = true;
    } else {
        $truncated = false;
    }

    // Compress blocks → minimal struct for prompt economy.
    $compact = array_map(fn ($b) => [
        'placement_id' => (int) $b['placement_id'],
        'work_date'    => $b['work_date'],
        'cycle_default'=> $b['cycle_default'] ?? 'monthly',
        'cycle_window' => $b['cycle_window']['label'] ?? null,
        'total_hours'  => (float) $b['total_hours'],
        'entry_ids'    => array_map(fn ($e) => (int) $e['id'], $b['entries']),
        'categories'   => array_values(array_unique(array_map(fn ($e) => $e['category'], $b['entries']))),
    ], $blocks);

    $sys  = 'You are an AP/AR settlement assistant. Group approved time blocks into optimal extraction batches. '
          . 'Each batch must come from ONE placement. Respect the cycle_default per placement. '
          . 'Flag anomalies: weekend work, hours > 12/day, or blocks outside their typical cycle window. '
          . 'Return STRICT JSON only — no prose.';
    $userMsg =
        "TARGET: $target\n" .
        "BLOCKS: " . json_encode($compact, JSON_UNESCAPED_SLASHES) . "\n\n" .
        "Return JSON: { \"suggestions\": [ { \"name\": \"…\", \"reasoning\": \"…\", \"placement_id\": <int>, " .
        "\"entry_ids\": [<int>...], \"total_hours\": <float>, \"flags\": [\"weekend\"|\"long_day\"|\"cycle_drift\"|...] } ], " .
        "\"advisories\": [\"short tip\"] }";

    [$resp, $latencyMs, $model, $http, $err] = aiCallOpenAI([
        'model'           => 'gpt-4o-mini',
        'messages'        => [
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user',   'content' => $userMsg],
        ],
        'temperature'     => 0.2,
        'max_tokens'      => 2000,
        'response_format' => ['type' => 'json_object'],
    ]);
    aiAuditWrite([
        'tenant_id' => function_exists('currentTenantId') ? currentTenantId() : null,
        'feature'   => 'time.settlement.ai_suggest',
        'model'     => $model,
        'latency_ms'=> $latencyMs,
        'http_code' => $http,
        'success'   => is_string($resp) && empty($err),
        'error'     => !is_string($resp) ? (is_string($err) ? substr((string) $err, 0, 500) : null) : null,
        'meta_json' => json_encode(['target' => $target, 'block_count' => count($compact), 'truncated' => $truncated]),
    ]);

    // Fallback: rules-based grouping (per placement, by cycle window).
    if (!is_string($resp)) {
        $fallback = _settlementFallbackGroup($compact, $target);
        $fallback['ai_used'] = false;
        $fallback['advisories'][] = 'AI unavailable — using rule-based grouping.';
        return $fallback;
    }
    $parsed = json_decode($resp, true);
    if (!is_array($parsed) || !isset($parsed['suggestions'])) {
        $fallback = _settlementFallbackGroup($compact, $target);
        $fallback['ai_used'] = false;
        $fallback['advisories'][] = 'AI returned an unparseable response — using rule-based grouping.';
        return $fallback;
    }
    $parsed['ai_used'] = true;
    $parsed['model']   = $model;
    if ($truncated) $parsed['advisories'][] = 'Showing first 200 blocks only — refine the date filter to see more.';
    return $parsed;
}

/** Simple deterministic fallback: one batch per (placement_id, cycle_window). */
function _settlementFallbackGroup(array $blocks, string $target): array
{
    $groups = [];
    foreach ($blocks as $b) {
        $key = $b['placement_id'] . '|' . ($b['cycle_window'] ?? 'adhoc');
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'name'         => "Placement #{$b['placement_id']} — " . ($b['cycle_window'] ?? 'Ad-hoc'),
                'reasoning'    => 'Grouped by placement + cycle window (rules-based fallback).',
                'placement_id' => $b['placement_id'],
                'entry_ids'    => [],
                'total_hours'  => 0.0,
                'flags'        => [],
            ];
        }
        foreach ($b['entry_ids'] as $eid) $groups[$key]['entry_ids'][] = $eid;
        $groups[$key]['total_hours'] += $b['total_hours'];
        // Lightweight anomaly flags.
        $dow = (int) date('N', strtotime($b['work_date']));
        if ($dow >= 6) $groups[$key]['flags'][] = 'weekend';
        if ($b['total_hours'] > 12) $groups[$key]['flags'][] = 'long_day';
    }
    foreach ($groups as &$g) $g['flags'] = array_values(array_unique($g['flags']));
    return ['suggestions' => array_values($groups), 'advisories' => []];
}
