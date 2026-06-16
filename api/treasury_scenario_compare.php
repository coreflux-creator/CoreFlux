<?php
/**
 * Compare Two Treasury Scenarios.
 *
 *   POST /api/treasury_scenario_compare.php
 *     {
 *       "days": 90,
 *       "scenario_a": { "label": "Hire 3 contractors", "events": [...] },
 *       "scenario_b": { "label": "Hire 5 + delay AP 30d", "events": [...] }
 *     }
 *
 * Runs THREE projections off the same baseline datasets:
 *   - baseline    (no overlay)
 *   - scenario_a  (events_a applied)
 *   - scenario_b  (events_b applied)
 *
 * Returns the full daily series for each (the UI overlays them as three
 * lines on the same chart) plus pairwise deltas between every pair so
 * the operator can A/B reason about which scenario costs more runway.
 *
 * Tenant-scoped. RBAC: `treasury.payment.view`. Reuses the shared
 * projection engine — zero new SQL.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/treasury/liquidity_projection.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];

if (api_method() !== 'POST') api_error('Method not allowed', 405);
rbac_legacy_require($user, 'treasury.payment.view');

$body = api_json_body();
$days = max(1, min(365, (int) ($body['days'] ?? 90)));
$today    = date('Y-m-d');
$endDate  = date('Y-m-d', strtotime("+{$days} days"));

/**
 * Validate one scenario block. Same shape contract as the single-scenario
 * endpoint. Returns ['label','events_in','events_out','inflows_by_date',
 * 'outflows_by_date'].
 */
$prepScenario = function (array $raw, string $slot) use ($today, $endDate): array {
    $label = trim((string) ($raw['label'] ?? ($slot === 'a' ? 'Scenario A' : 'Scenario B')));
    if (strlen($label) > 120) api_error("scenario_{$slot}.label max 120 chars", 422);

    $rawEvents = $raw['events'] ?? [];
    if (!is_array($rawEvents)) api_error("scenario_{$slot}.events must be an array", 422);
    if (count($rawEvents) > 50) api_error("scenario_{$slot}.events max 50", 422);

    $events = []; $inflows = []; $outflows = [];
    $inflowTotal = 0.0; $outflowTotal = 0.0;
    foreach ($rawEvents as $idx => $e) {
        if (!is_array($e)) api_error("scenario_{$slot}.events[{$idx}] malformed", 422);
        $kind   = (string) ($e['kind'] ?? '');
        $amount = round((float) ($e['amount'] ?? 0), 2);
        $date   = (string) ($e['date'] ?? '');
        $lbl    = trim((string) ($e['label'] ?? ''));
        if (!in_array($kind, ['inflow','outflow'], true)) api_error("scenario_{$slot}.events[{$idx}]: kind", 422);
        if ($amount <= 0) api_error("scenario_{$slot}.events[{$idx}]: amount", 422);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) api_error("scenario_{$slot}.events[{$idx}]: date", 422);
        if ($date < $today)   $date = $today;
        if ($date > $endDate) $date = $endDate;
        $events[] = ['kind' => $kind, 'amount' => $amount, 'date' => $date, 'label' => $lbl !== '' ? $lbl : ucfirst($kind)];
        if ($kind === 'inflow') {
            $inflows[$date] = ($inflows[$date] ?? 0.0) + $amount;
            $inflowTotal += $amount;
        } else {
            $outflows[$date] = ($outflows[$date] ?? 0.0) + $amount;
            $outflowTotal += $amount;
        }
    }
    return [
        'label'             => $label,
        'events'            => $events,
        'inflows_by_date'   => $inflows,
        'outflows_by_date'  => $outflows,
        'inflow_total'      => $inflowTotal,
        'outflow_total'     => $outflowTotal,
    ];
};

$a = $prepScenario(is_array($body['scenario_a'] ?? null) ? $body['scenario_a'] : [], 'a');
$b = $prepScenario(is_array($body['scenario_b'] ?? null) ? $body['scenario_b'] : [], 'b');

$datasets = liquidityBaselineDatasets($tid, $today, $endDate);
$buckets  = liquidityBucketDatasets($datasets);
$baselineSourceDetail = liquidityProjectionSourceDetail($datasets);
$sourceDetailA = liquidityProjectionSourceDetail($datasets, [
    'extra_inflows_by_date' => $a['inflows_by_date'],
    'extra_outflows_by_date' => $a['outflows_by_date'],
]);
$sourceDetailB = liquidityProjectionSourceDetail($datasets, [
    'extra_inflows_by_date' => $b['inflows_by_date'],
    'extra_outflows_by_date' => $b['outflows_by_date'],
]);

$baseline = liquidityWalkProjection(
    $datasets['starting_cash'], $days, $today,
    $buckets['inflows_by_date'], $buckets['outflows_by_date']
);
$projA = liquidityWalkProjection(
    $datasets['starting_cash'], $days, $today,
    $buckets['inflows_by_date'], $buckets['outflows_by_date'],
    $a['inflows_by_date'], $a['outflows_by_date']
);
$projB = liquidityWalkProjection(
    $datasets['starting_cash'], $days, $today,
    $buckets['inflows_by_date'], $buckets['outflows_by_date'],
    $b['inflows_by_date'], $b['outflows_by_date']
);
$baselineProjection = liquidityProjectionEvidence($tid, $today, $endDate, $days, $datasets);
$projectionA = liquidityProjectionEvidence($tid, $today, $endDate, $days, $datasets, [
    'extra_inflows_by_date' => $a['inflows_by_date'],
    'extra_outflows_by_date' => $a['outflows_by_date'],
]);
$projectionB = liquidityProjectionEvidence($tid, $today, $endDate, $days, $datasets, [
    'extra_inflows_by_date' => $b['inflows_by_date'],
    'extra_outflows_by_date' => $b['outflows_by_date'],
]);
$baselineDaily = liquidityAttachDailySourceDetail($baseline['daily'], $baselineSourceDetail);
$dailyA = liquidityAttachDailySourceDetail($projA['daily'], $sourceDetailA);
$dailyB = liquidityAttachDailySourceDetail($projB['daily'], $sourceDetailB);

/**
 * Build a delta envelope between two projections — the same shape every
 * what-if surface uses, so the UI can render it with the same component.
 */
$buildDelta = static function (array $left, array $right, int $days): array {
    $shift = round($right['lowest_balance'] - $left['lowest_balance'], 2);
    $dateShift = (int) round((strtotime($right['lowest_balance_date']) - strtotime($left['lowest_balance_date'])) / 86400);
    $lr = $left['runway_days_to_zero'];
    $rr = $right['runway_days_to_zero'];
    if ($lr === null && $rr === null) {
        $runwayLost = 0; $crossesZero = false;
    } elseif ($lr !== null && $rr !== null) {
        $runwayLost = $lr - $rr; $crossesZero = true;
    } elseif ($lr === null && $rr !== null) {
        $runwayLost = $days - $rr; $crossesZero = true;
    } else {
        $runwayLost = -$days; $crossesZero = false; // right side actually pushed runway out
    }
    return [
        'lowest_balance_shift'    => $shift,
        'lowest_date_shift_days'  => $dateShift,
        'runway_days_lost'        => $runwayLost,
        'crosses_zero'            => $crossesZero,
    ];
};

$endA = $dailyA        ? end($dailyA)['closing']        : round($datasets['starting_cash'], 2);
$endB = $dailyB        ? end($dailyB)['closing']        : round($datasets['starting_cash'], 2);
$endBase = $baselineDaily ? end($baselineDaily)['closing'] : round($datasets['starting_cash'], 2);

api_ok([
    'window_days' => $days,
    'projection'  => $baselineProjection,
    'baseline'    => [
        'projection'           => $baselineProjection,
        'source_detail'        => $baselineSourceDetail,
        'starting_cash'       => round($datasets['starting_cash'], 2),
        'ending_cash'         => $endBase,
        'lowest_balance'      => $baseline['lowest_balance'],
        'lowest_balance_date' => $baseline['lowest_balance_date'],
        'runway_days_to_zero' => $baseline['runway_days_to_zero'],
        'daily'               => $baselineDaily,
    ],
    'scenario_a'  => [
        'projection'           => $projectionA,
        'source_detail'        => $sourceDetailA,
        'label'               => $a['label'],
        'events'              => $a['events'],
        'ending_cash'         => $endA,
        'lowest_balance'      => $projA['lowest_balance'],
        'lowest_balance_date' => $projA['lowest_balance_date'],
        'runway_days_to_zero' => $projA['runway_days_to_zero'],
        'daily'               => $dailyA,
        'inflow_total'        => round($a['inflow_total'], 2),
        'outflow_total'       => round($a['outflow_total'], 2),
        'net_event_impact'    => round($a['inflow_total'] - $a['outflow_total'], 2),
    ],
    'scenario_b'  => [
        'projection'           => $projectionB,
        'source_detail'        => $sourceDetailB,
        'label'               => $b['label'],
        'events'              => $b['events'],
        'ending_cash'         => $endB,
        'lowest_balance'      => $projB['lowest_balance'],
        'lowest_balance_date' => $projB['lowest_balance_date'],
        'runway_days_to_zero' => $projB['runway_days_to_zero'],
        'daily'               => $dailyB,
        'inflow_total'        => round($b['inflow_total'], 2),
        'outflow_total'       => round($b['outflow_total'], 2),
        'net_event_impact'    => round($b['inflow_total'] - $b['outflow_total'], 2),
    ],
    'deltas'      => [
        'a_vs_baseline' => $buildDelta($baseline, $projA, $days),
        'b_vs_baseline' => $buildDelta($baseline, $projB, $days),
        'a_vs_b'        => $buildDelta($projA,    $projB, $days),
    ],
    'guards'      => [
        'has_bank_accounts'      => $datasets['bank_count'] > 0,
        'has_open_ar'            => count($datasets['ar']) > 0,
        'has_open_ap'            => count($datasets['ap']) > 0,
        'has_scheduled_payments' => count($datasets['tp']) > 0,
    ],
]);
