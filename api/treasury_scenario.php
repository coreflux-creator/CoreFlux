<?php
/**
 * Treasury What-If Scenario Builder.
 *
 *   POST /api/treasury_scenario.php
 *     {
 *       "days":   90,                           // optional, 1..365, default 90
 *       "events": [                             // up to 50 events
 *         { "kind": "outflow", "amount": 12500, "date": "2026-03-15", "label": "Office rent" },
 *         { "kind": "inflow",  "amount":  8000, "date": "2026-03-22", "label": "Big invoice paid early" }
 *       ]
 *     }
 *
 * Reuses the shared liquidity projection engine — the SAME baseline data
 * sources the per-bill what-if and the main forecast use — and lets the
 * operator stack any number of hypothetical inflows + outflows on top to
 * see the combined runway impact.
 *
 * Response:
 *   {
 *     window_days,
 *     events: [...],                            // echoed back, validated
 *     baseline:  { lowest_balance, lowest_balance_date,
 *                  runway_days_to_zero, ending_cash, daily },
 *     simulated: { lowest_balance, lowest_balance_date,
 *                  runway_days_to_zero, ending_cash, daily },
 *     delta:     { lowest_balance_shift, lowest_date_shift_days,
 *                  runway_days_lost, crosses_zero,
 *                  net_event_impact (inflow_total - outflow_total) },
 *     guards:    { has_bank_accounts, has_open_ar,
 *                  has_open_ap, has_scheduled_payments }
 *   }
 *
 * Tenant-scoped. RBAC: `treasury.payment.view` (matches the rest of treasury).
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/treasury/liquidity_projection.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];

if (!in_array(api_method(), ['POST', 'GET'], true)) api_error('Method not allowed', 405);
rbac_legacy_require($user, 'treasury.payment.view');

$body = api_method() === 'POST' ? api_json_body() : [];

$days = max(1, min(365, (int) ($body['days'] ?? api_query('days') ?? 90)));
$entityId = (int) ($body['entity_id'] ?? api_query('entity_id') ?? 0) ?: null;
$today    = date('Y-m-d');
$endDate  = date('Y-m-d', strtotime("+{$days} days"));

// ──────────────────────────────────────────────────────────────────
// Validate the events array. Cap at 50 to keep payload sane and
// prevent unbounded loops; the UI never sends more than ~20 anyway.
// ──────────────────────────────────────────────────────────────────
$rawEvents = $body['events'] ?? [];
if (!is_array($rawEvents)) api_error('events must be an array', 422);
if (count($rawEvents) > 50) api_error('Too many events — maximum 50 per scenario', 422);

$events = [];
$inflowTotal  = 0.0;
$outflowTotal = 0.0;
$extraInflowsByDate  = [];
$extraOutflowsByDate = [];

foreach ($rawEvents as $idx => $e) {
    if (!is_array($e)) api_error("event #{$idx} malformed", 422);
    $kind   = (string) ($e['kind']   ?? '');
    $amount = round((float) ($e['amount'] ?? 0), 2);
    $date   = (string) ($e['date']   ?? '');
    $label  = trim((string) ($e['label']  ?? ''));

    if (!in_array($kind, ['inflow', 'outflow'], true)) {
        api_error("event #{$idx}: kind must be 'inflow' or 'outflow'", 422);
    }
    if ($amount <= 0) {
        api_error("event #{$idx}: amount must be positive", 422);
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        api_error("event #{$idx}: date must be YYYY-MM-DD", 422);
    }
    // Clamp inside the forecast window so the projection always reflects
    // the event. Past dates are coerced to today.
    if ($date < $today)   $date = $today;
    if ($date > $endDate) $date = $endDate;

    $events[] = [
        'kind'   => $kind,
        'amount' => $amount,
        'date'   => $date,
        'label'  => $label !== '' ? $label : ucfirst($kind),
    ];

    if ($kind === 'inflow') {
        $extraInflowsByDate[$date] = ($extraInflowsByDate[$date] ?? 0.0) + $amount;
        $inflowTotal += $amount;
    } else {
        $extraOutflowsByDate[$date] = ($extraOutflowsByDate[$date] ?? 0.0) + $amount;
        $outflowTotal += $amount;
    }
}

// ──────────────────────────────────────────────────────────────────
// Pull baseline datasets and walk the projection twice.
// ──────────────────────────────────────────────────────────────────
$datasets = liquidityBaselineDatasets($tid, $today, $endDate, $entityId);
$buckets  = liquidityBucketDatasets($datasets);

$baseline  = liquidityWalkProjection(
    $datasets['starting_cash'], $days, $today,
    $buckets['inflows_by_date'], $buckets['outflows_by_date']
);
$simulated = liquidityWalkProjection(
    $datasets['starting_cash'], $days, $today,
    $buckets['inflows_by_date'], $buckets['outflows_by_date'],
    $extraInflowsByDate, $extraOutflowsByDate
);
$baselineProjection = liquidityProjectionEvidence($tid, $today, $endDate, $days, $datasets);
$simulatedProjection = liquidityProjectionEvidence($tid, $today, $endDate, $days, $datasets, [
    'extra_inflows_by_date' => $extraInflowsByDate,
    'extra_outflows_by_date' => $extraOutflowsByDate,
]);

$baselineEnd  = $baseline['daily']  ? end($baseline['daily'])['closing']  : round($datasets['starting_cash'], 2);
$simulatedEnd = $simulated['daily'] ? end($simulated['daily'])['closing'] : round($datasets['starting_cash'], 2);

$lowestShift = round($simulated['lowest_balance'] - $baseline['lowest_balance'], 2);
$lowestDateShift = (int) round((strtotime($simulated['lowest_balance_date']) - strtotime($baseline['lowest_balance_date'])) / 86400);

$baseRunway = $baseline['runway_days_to_zero'];
$simRunway  = $simulated['runway_days_to_zero'];
if ($baseRunway === null && $simRunway === null) {
    $runwayLost = 0;
    $crossesZero = false;
} elseif ($baseRunway !== null && $simRunway !== null) {
    $runwayLost = max(0, $baseRunway - $simRunway);
    $crossesZero = true;
} elseif ($baseRunway === null && $simRunway !== null) {
    // Scenario pushed runway INTO existence — count days inside the window.
    $runwayLost = $days - $simRunway;
    $crossesZero = true;
} else {
    // Scenario pushed runway OUT of the window — net positive.
    $runwayLost = -($simRunway === null ? $days : ($simRunway - ($baseRunway ?? 0)));
    $crossesZero = $simRunway !== null;
}

api_ok([
    'window_days' => $days,
    'projection'  => $simulatedProjection,
    'events'      => $events,
    'baseline'    => [
        'projection'           => $baselineProjection,
        'lowest_balance'      => $baseline['lowest_balance'],
        'lowest_balance_date' => $baseline['lowest_balance_date'],
        'runway_days_to_zero' => $baseline['runway_days_to_zero'],
        'ending_cash'         => $baselineEnd,
        'starting_cash'       => round($datasets['starting_cash'], 2),
        'daily'               => $baseline['daily'],
    ],
    'simulated'   => [
        'projection'           => $simulatedProjection,
        'lowest_balance'      => $simulated['lowest_balance'],
        'lowest_balance_date' => $simulated['lowest_balance_date'],
        'runway_days_to_zero' => $simulated['runway_days_to_zero'],
        'ending_cash'         => $simulatedEnd,
        'starting_cash'       => round($datasets['starting_cash'], 2),
        'daily'               => $simulated['daily'],
    ],
    'delta'       => [
        'lowest_balance_shift'   => $lowestShift,
        'lowest_date_shift_days' => $lowestDateShift,
        'runway_days_lost'       => $runwayLost,
        'crosses_zero'           => $crossesZero,
        'net_event_impact'       => round($inflowTotal - $outflowTotal, 2),
        'inflow_total'           => round($inflowTotal, 2),
        'outflow_total'          => round($outflowTotal, 2),
    ],
    'guards'      => [
        'has_bank_accounts'      => $datasets['bank_count'] > 0,
        'has_open_ar'            => count($datasets['ar']) > 0,
        'has_open_ap'            => count($datasets['ap']) > 0,
        'has_scheduled_payments' => count($datasets['tp']) > 0,
    ],
]);
