<?php
/**
 * OpenAI mock — deterministic AI responses for the harness.
 *
 * Covers the production `aiAsk()` + `aiExtract()` surface in
 * /app/core/ai_service.php. Returns canned narratives + structured
 * payloads keyed on (feature_class, model, payload_hash) so the same
 * prompt + seed yields the same output forever.
 *
 * The mock honours the AI integration rules (advisory only, never
 * outputs values consumed by the app) — every response carries a
 * disclaimer string in `meta`.
 */
declare(strict_types=1);

require_once __DIR__ . '/manager.php';

/** Drop-in replacement for aiAsk(). */
function simMockAiAsk(array $args): array {
    if (!simShouldMock('openai')) throw new \RuntimeException('openai mock not enabled');
    if (($f = simMockConsumeFault('openai')) !== null) simMockApplyFault('openai', $f);

    $feature = (string) ($args['feature_class'] ?? 'general');
    $model   = (string) ($args['model']         ?? 'gpt-4o-mini');
    $prompt  = (string) ($args['prompt']        ?? '');
    $payload = $args['payload'] ?? [];
    $key     = simHash([$feature, $model, $prompt, $payload]);

    // Library of canned narratives keyed by feature_class so the same
    // sim run produces a thematically appropriate response.
    $library = [
        'csv_mapping'      => 'Mapped 3 of 4 source columns. Suggested: First name → first_name, Last name → last_name, Email → email_primary. The Phone column likely maps to phone_primary; please confirm.',
        'cfo_digest'       => 'AR aging shifted right: Net30+ bucket grew 12% week-over-week. Two clients exceeded their credit term by >5 days. Liquidity runway holds at 4.2 months under base case.',
        'rate_explain'     => 'This rate change reflects updated overhead + benefits load (FY26 plan) and a 3% market adjustment.',
        'anomaly'          => 'No anomalies detected for this period.',
        'tax_automap'      => 'Suggested mapping: 6000 → Office Expense (Schedule C line 18). Confidence: medium. Please review.',
        'bookkeeping'      => 'Bank account 4582 has 3 uncategorized transactions older than 7 days. Run /bookkeeping/transactions-to-review to clear them.',
        'general'          => 'Reviewed the provided context; nothing actionable to surface this run.',
    ];
    $narrative = $library[$feature] ?? $library['general'];

    $resp = [
        'narrative'   => $narrative,
        'model'       => $model,
        'feature'     => $feature,
        'usage'       => ['prompt_tokens' => 120 + (hexdec(substr($key, 0, 4)) % 80),
                          'completion_tokens' => 60 + (hexdec(substr($key, 4, 4)) % 40)],
        'meta'        => [
            'sim'        => true,
            'disclaimer' => 'AI output is advisory; verify before acting.',
            'cache_key'  => $key,
        ],
        'finish_reason' => 'stop',
    ];
    simMockRecordCall('openai', 'ai_ask', ['feature' => $feature, 'model' => $model, 'prompt_hash' => simHash($prompt)], $resp);
    return $resp;
}

/** Drop-in for aiExtract() — structured extraction (receipts, bills). */
function simMockAiExtract(array $args): array {
    if (!simShouldMock('openai')) throw new \RuntimeException('openai mock not enabled');
    if (($f = simMockConsumeFault('openai')) !== null) simMockApplyFault('openai', $f);

    $kind = (string) ($args['kind'] ?? 'receipt');
    $resp = match ($kind) {
        'bill', 'invoice' => [
            'vendor'        => 'Acme Industrial',
            'bill_number'   => 'INV-' . simRandInt(10000, 99999),
            'bill_date'     => simNow('Y-m-d'),
            'due_date'      => simNow('Y-m-d'),
            'currency'      => 'USD',
            'subtotal'      => simRandFloat(500, 5000),
            'tax'           => simRandFloat(0, 400),
            'total'         => simRandFloat(500, 5500),
            'lines'         => [
                ['description' => 'Service fee — Q1', 'qty' => 1, 'rate' => 1200.00, 'amount' => 1200.00],
            ],
        ],
        default => [
            'merchant'      => 'Office Depot',
            'date'          => simNow('Y-m-d'),
            'currency'      => 'USD',
            'total'         => simRandFloat(20, 500),
            'category_hint' => 'Office Supplies',
        ],
    };
    $out = ['extracted' => $resp, 'confidence' => simRandFloat(0.6, 0.98, 2), 'meta' => ['sim' => true]];
    simMockRecordCall('openai', 'ai_extract', ['kind' => $kind], $out);
    return $out;
}
