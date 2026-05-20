<?php
/**
 * /api/admin/ai_usage.php — last-N-day AI usage panel.
 *
 *   GET /api/admin/ai_usage.php?days=7
 *
 * Aggregates `ai_interactions` rows for the active tenant (master_admin can
 * pass tenant_id=NN to switch). Returns:
 *
 *   {
 *     ok: true,
 *     tenant_id: int,
 *     window_days: int,
 *     window_from: 'YYYY-MM-DD HH:MM:SS',
 *     totals: {
 *       calls:           int,
 *       ok_count:        int,
 *       error_count:     int,
 *       disabled_count:  int,
 *       success_rate:    float (0..1, null if calls=0),
 *       p50_latency_ms:  int|null,
 *       p95_latency_ms:  int|null,
 *       cost_cents:      int|null,   // sum of cost_cents across the window (null when no row carried a cost)
 *       prompt_tokens:   int|null,   // sum of token_count_prompt
 *       response_tokens: int|null,   // sum of token_count_response
 *     },
 *     by_feature_class: [
 *       { feature_class, calls, ok_count, error_count, success_rate, p50_latency_ms, p95_latency_ms, distinct_features, cost_cents },
 *       …
 *     ],
 *     top_feature_keys: [
 *       { feature_key, feature_class, calls, last_call_at }, … (top 10 by calls)
 *     ],
 *   }
 *
 * Latency percentiles use the `latency_ms` column on `ai_interactions`. We
 * compute them in PHP (single round-trip, fewer-than-50k rows per window in
 * realistic use) rather than relying on MySQL window functions which aren't
 * uniformly available.
 *
 * Cost is summed from `cost_cents` (populated by `aiAuditWrite()` from the
 * per-model rate card in `aiComputeCostCents()`). Rows that pre-date migration
 * `060_ai_interactions_cost_tracking.sql` — or were written without token
 * counts — contribute null and therefore don't double-count or zero-out the
 * window. If every row in the window has null cost, totals.cost_cents is null.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';

$ctx           = api_require_auth();
$role          = (string) ($ctx['role'] ?? 'employee');
$isGlobalAdmin = (bool) ($ctx['is_global_admin'] ?? false);
$activeTenant  = (int) (currentTenantId() ?? 0);

if (!$isGlobalAdmin && !in_array($role, ['master_admin', 'tenant_admin'], true)) {
    api_error('Forbidden — admin only', 403);
}
if (api_method() !== 'GET') api_error('Method not allowed', 405);

$days = max(1, min(90, (int) ($_GET['days'] ?? 7)));
$tid  = (int) ($_GET['tenant_id'] ?? $activeTenant);
if ($tid <= 0) api_error('tenant_id required', 400);
if (!$isGlobalAdmin && $role === 'tenant_admin' && $tid !== $activeTenant) {
    api_error('Forbidden — tenant_admin may only view own tenant', 403);
}

$pdo = getDB();
if (!$pdo) api_error('Database unavailable', 500);

$cutoff = (new DateTimeImmutable("-{$days} days"))->format('Y-m-d H:i:s');

/** Percentiles in PHP — fetch latencies once and bucket. */
function _aiUsagePercentile(array $sortedAsc, float $pct): ?int {
    $n = count($sortedAsc);
    if ($n === 0) return null;
    if ($n === 1) return (int) $sortedAsc[0];
    $rank = (int) ceil($pct * $n);
    $rank = max(1, min($n, $rank));
    return (int) $sortedAsc[$rank - 1];
}

// ── Totals + per-class summary in one round trip ──
$stmt = $pdo->prepare(
    "SELECT feature_class,
            status,
            latency_ms,
            feature_key,
            created_at,
            cost_cents,
            token_count_prompt,
            token_count_response
       FROM ai_interactions
      WHERE tenant_id = :t AND created_at >= :since
      ORDER BY created_at DESC"
);
$stmt->execute(['t' => $tid, 'since' => $cutoff]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$totals = ['calls' => 0, 'ok_count' => 0, 'error_count' => 0, 'disabled_count' => 0];
$allLatencies = [];
$perClass = [];            // class => row aggregator
$perFeatureKey = [];       // feature_key => row aggregator
$costRows = 0; $costSum = 0;
$promptTokRows = 0; $promptTokSum = 0;
$respTokRows = 0;  $respTokSum  = 0;
foreach ($rows as $r) {
    $cls = (string) ($r['feature_class'] ?? '');
    $status = (string) ($r['status'] ?? '');
    $lat = $r['latency_ms'] === null ? null : (int) $r['latency_ms'];
    $cost = $r['cost_cents'] === null ? null : (int) $r['cost_cents'];
    $ptok = $r['token_count_prompt'] === null ? null : (int) $r['token_count_prompt'];
    $rtok = $r['token_count_response'] === null ? null : (int) $r['token_count_response'];
    $totals['calls']++;
    if ($status === 'ok')        $totals['ok_count']++;
    elseif ($status === 'error') $totals['error_count']++;
    elseif ($status === 'disabled') $totals['disabled_count']++;

    if ($lat !== null) $allLatencies[] = $lat;
    if ($cost !== null) { $costRows++; $costSum += $cost; }
    if ($ptok !== null) { $promptTokRows++; $promptTokSum += $ptok; }
    if ($rtok !== null) { $respTokRows++;   $respTokSum  += $rtok; }

    if (!isset($perClass[$cls])) {
        $perClass[$cls] = ['feature_class' => $cls, 'calls' => 0, 'ok_count' => 0, 'error_count' => 0,
                           'latencies' => [], 'features' => [], 'cost_rows' => 0, 'cost_sum' => 0];
    }
    $perClass[$cls]['calls']++;
    if ($status === 'ok')        $perClass[$cls]['ok_count']++;
    if ($status === 'error')     $perClass[$cls]['error_count']++;
    if ($lat !== null) $perClass[$cls]['latencies'][] = $lat;
    if ($cost !== null) { $perClass[$cls]['cost_rows']++; $perClass[$cls]['cost_sum'] += $cost; }
    $perClass[$cls]['features'][(string) $r['feature_key']] = true;

    $fk = (string) ($r['feature_key'] ?? '');
    if ($fk !== '') {
        if (!isset($perFeatureKey[$fk])) {
            $perFeatureKey[$fk] = ['feature_key' => $fk, 'feature_class' => $cls, 'calls' => 0, 'last_call_at' => $r['created_at']];
        }
        $perFeatureKey[$fk]['calls']++;
    }
}

sort($allLatencies);
$totals['success_rate']    = $totals['calls'] ? round($totals['ok_count'] / $totals['calls'], 4) : null;
$totals['p50_latency_ms']  = _aiUsagePercentile($allLatencies, 0.50);
$totals['p95_latency_ms']  = _aiUsagePercentile($allLatencies, 0.95);
$totals['cost_cents']      = $costRows > 0 ? $costSum : null;
$totals['prompt_tokens']   = $promptTokRows > 0 ? $promptTokSum : null;
$totals['response_tokens'] = $respTokRows > 0 ? $respTokSum : null;

$byClass = [];
foreach ($perClass as $cls => $agg) {
    sort($agg['latencies']);
    $byClass[] = [
        'feature_class'      => $cls ?: '(unset)',
        'calls'              => $agg['calls'],
        'ok_count'           => $agg['ok_count'],
        'error_count'        => $agg['error_count'],
        'success_rate'       => $agg['calls'] ? round($agg['ok_count'] / $agg['calls'], 4) : null,
        'p50_latency_ms'     => _aiUsagePercentile($agg['latencies'], 0.50),
        'p95_latency_ms'     => _aiUsagePercentile($agg['latencies'], 0.95),
        'distinct_features'  => count($agg['features']),
        'cost_cents'         => $agg['cost_rows'] > 0 ? $agg['cost_sum'] : null,
    ];
}
// Sort by call volume desc so the heaviest classes show first.
usort($byClass, fn($a, $b) => $b['calls'] <=> $a['calls']);

// Top 10 feature_keys by call volume.
$topKeys = array_values($perFeatureKey);
usort($topKeys, fn($a, $b) => $b['calls'] <=> $a['calls']);
$topKeys = array_slice($topKeys, 0, 10);

api_json([
    'ok'                => true,
    'tenant_id'         => $tid,
    'window_days'       => $days,
    'window_from'       => $cutoff,
    'totals'            => $totals,
    'by_feature_class'  => $byClass,
    'top_feature_keys'  => $topKeys,
]);
