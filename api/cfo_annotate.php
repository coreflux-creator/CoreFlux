<?php
/**
 * /api/cfo_annotate — AI-generated commentary for a single CFO dashboard widget.
 *
 *   POST { widget_key, snapshot, comparison? }
 *     → returns one short paragraph (2-4 sentences) explaining what the
 *       numbers mean, flagging anything notable, and suggesting one next
 *       action. NOT a numeric restatement — the AI gate explicitly forbids
 *       echoing raw figures.
 *
 * Auth: any authenticated user (CFO/master_admin gating happens on the
 * front-end route; the API is read-only). Failures are non-fatal — the
 * UI shows "AI offline" without breaking the dashboard.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/ai_service.php';
require_once __DIR__ . '/../core/event_registry.php';

$ctx    = api_require_cfo();
$method = api_method();
if ($method !== 'POST') api_error('Method not allowed', 405);

$body       = api_json_body();
$widgetKey  = trim((string) ($body['widget_key'] ?? ''));
$snapshot   = $body['snapshot']   ?? null;
$comparison = $body['comparison'] ?? null;
if ($widgetKey === '') api_error('widget_key required', 422);
if (!is_array($snapshot) && !is_scalar($snapshot)) api_error('snapshot required', 422);

// Widget → relevant canonical event_types. Used to ground the AI in the
// actual Dr/Cr hints the registry knows about for each metric.
$widgetEventMap = [
    'finance.revenue'        => ['ar.invoice.issued', 'ar.credit_memo.issued'],
    'finance.margin'         => ['ar.invoice.issued', 'staffing.worker_hours.approved'],
    'finance.ar_aging'       => ['ar.invoice.issued', 'ar.payment.received', 'ar.cash.applied', 'ar.writeoff.recorded'],
    'finance.ap_aging'       => ['ap.bill.approved', 'ap.payment.executed', 'ap.payment.cleared'],
    'finance.dso'            => ['ar.invoice.issued', 'ar.payment.received'],
    'finance.dpo'            => ['ap.bill.approved', 'ap.payment.executed'],
    'finance.unapplied_cash' => ['ar.payment.received', 'ar.cash.applied'],
    'finance.payroll'        => ['payroll.run.approved', 'payroll.cash.disbursed', 'payroll.tax_liability.paid'],
    'staffing.headcount'     => ['staffing.worker.placed', 'staffing.placement.ended'],
    'staffing.new_starts'    => ['staffing.worker.placed'],
    'staffing.terminations'  => ['staffing.placement.ended'],
    'staffing.upcoming'      => ['staffing.worker.placed', 'staffing.placement.ended'],
    'staffing.placements'    => ['staffing.worker.placed', 'staffing.placement.ended', 'staffing.worker_hours.approved'],
];
$registryHints = [];
foreach ($widgetEventMap[$widgetKey] ?? [] as $et) {
    $row = eventRegistryGet($et);
    if ($row && !empty($row['typical_accounting'])) {
        $registryHints[] = [
            'event_type'         => $row['event_type'],
            'typical_accounting' => $row['typical_accounting'],
        ];
    }
}

// Per-widget system prompt — keeps the AI grounded on what each tile means.
$systemHints = [
    'finance.revenue'        => 'You are commenting on revenue trend and YTD totals for a B2B services business.',
    'finance.margin'         => 'You are commenting on gross margin (revenue minus direct labor cost).',
    'finance.ar_aging'       => 'You are commenting on AR aging buckets. Flag concentration in d60+ as a collections risk.',
    'finance.ap_aging'       => 'You are commenting on AP aging. Flag d60+ as a vendor-relationship risk.',
    'finance.dso'            => 'You are commenting on Days Sales Outstanding. Benchmark: services SMBs usually run 35-50.',
    'finance.dpo'            => 'You are commenting on Days Payable Outstanding. Higher = cash conserved but vendor risk; lower = vendor goodwill but cash tied up.',
    'finance.unapplied_cash' => 'You are commenting on unapplied customer cash — money received but not yet matched to an invoice. High balances usually mean billing ops attention is needed.',
    'finance.payroll'        => 'You are commenting on payroll cost trends.',
    'staffing.headcount'     => 'You are commenting on workforce composition (W2 vs 1099 vs C2C vs perm).',
    'staffing.new_starts'    => 'You are commenting on new hire pace.',
    'staffing.terminations'  => 'You are commenting on attrition pace.',
    'staffing.upcoming'      => 'You are commenting on upcoming starts and terminations in the next 30 days.',
    'staffing.placements'    => 'You are commenting on active placements and those ending soon.',
];
$systemHint = $systemHints[$widgetKey] ?? 'You are commenting on a financial KPI tile.';

try {
    $res = aiAsk([
        'feature_class' => 'narrative',
        'kind'          => 'narrative',
        'feature_key'   => 'cfo_dashboard.annotation',
        'system'        => $systemHint
                          . "\nOutput format: ONE paragraph, 2-4 sentences, plain English."
                          . "\nDo NOT restate raw numbers verbatim — interpret them."
                          . (count($registryHints) > 0
                              ? "\nGround any accounting language in the typical Dr/Cr hints supplied in 'registry_hints' — these come from the canonical event registry and you can reference them naturally if relevant."
                              : "")
                          . "\nEnd with one suggested action the CFO should consider this week.",
        'prompt'        => "Analyze the snapshot below for widget '{$widgetKey}'. "
                          . ($comparison ? "Compare against the comparison block to call out movement direction and magnitude. " : "")
                          . "Be concise.",
        'context'       => array_filter([
            'widget_key'     => $widgetKey,
            'current'        => $snapshot,
            'comparison'     => $comparison,
            'registry_hints' => $registryHints ?: null,
        ], fn ($v) => $v !== null),
        'max_output_tokens' => 320,
    ]);
    api_ok([
        'annotation' => (string) ($res['content'] ?? ''),
        'confidence' => (float)  ($res['confidence'] ?? 0),
        'model'      => $res['model'] ?? null,
        'requires_review' => (bool) ($res['requires_human_review'] ?? false),
        'registry_hints'  => $registryHints,
    ]);
} catch (\AIDisabledException $e) {
    api_ok([
        'annotation'      => null,
        'disabled'        => true,
        'disabled_reason' => $e->getMessage(),
    ]);
} catch (\Throwable $e) {
    error_log('[cfo_annotate] ' . $e->getMessage());
    api_ok([
        'annotation' => null,
        'error'      => 'AI temporarily unavailable',
    ]);
}
