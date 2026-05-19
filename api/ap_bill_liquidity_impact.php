<?php
/**
 * AP Bill Liquidity Impact (P0).
 *
 * Inline read for the AP BillDetail UI: "If you pay this bill on date X,
 * here's how the projected lowest balance and runway shift compared to
 * the baseline forecast."
 *
 *   GET /api/ap_bill_liquidity_impact.php?bill_id=N&pay_date=YYYY-MM-DD
 *
 * Response envelope:
 *   {
 *     bill_id, bill_amount, pay_date, days_horizon,
 *     baseline:  { lowest_balance, lowest_balance_date, runway_days_to_zero },
 *     simulated: { lowest_balance, lowest_balance_date, runway_days_to_zero },
 *     delta:     { lowest_balance_shift, lowest_date_shift_days, runway_days_lost,
 *                  crosses_zero }
 *   }
 *
 * Tenant-scoped. RBAC: `treasury.payment.view`. pay_date defaults to today
 * and is clamped to the 90-day forecast window.
 *
 * Internals — uses the shared `core/treasury/liquidity_projection.php`
 * engine so the main forecast + scenario builder share the exact same
 * SQL + walker. The bill being simulated is excluded from baseline
 * outflows (otherwise it would double-count on its own due_date AND
 * on the simulated $payDate).
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/treasury/liquidity_projection.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];

if (api_method() !== 'GET') api_error('Method not allowed', 405);
rbac_legacy_require($user, 'treasury.payment.view');

$billId   = (int) (api_query('bill_id') ?? 0);
if ($billId <= 0) api_error('bill_id required', 422);

$days     = max(1, min(365, (int) (api_query('days') ?? 90)));
$today    = date('Y-m-d');
$endDate  = date('Y-m-d', strtotime("+{$days} days"));

$pdo = getDB();

$billStmt = $pdo->prepare(
    "SELECT id, amount_due, status, vendor_name
       FROM ap_bills
      WHERE tenant_id = :t AND id = :b LIMIT 1"
);
$billStmt->execute(['t' => $tid, 'b' => $billId]);
$bill = $billStmt->fetch(\PDO::FETCH_ASSOC);
if (!$bill) api_error('Bill not found', 404);

$billAmount = round((float) $bill['amount_due'], 2);
if ($billAmount <= 0) {
    api_ok([
        'bill_id'      => $billId,
        'bill_amount'  => 0.0,
        'pay_date'     => $today,
        'days_horizon' => $days,
        'note'         => 'Bill has zero balance due — no liquidity impact.',
        'baseline'     => null,
        'simulated'    => null,
        'delta'        => null,
    ]);
}

// Pay date — defaults to today, clamped inside the forecast window.
$payDate = (string) (api_query('pay_date') ?? $today);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $payDate)) $payDate = $today;
if ($payDate < $today)   $payDate = $today;
if ($payDate > $endDate) $payDate = $endDate;

// Skip the bill we're simulating from baseline outflows. Otherwise a bill
// due in window would be double-counted on its due_date AND on $payDate.
$datasets = liquidityBaselineDatasets($tid, $today, $endDate, null, $billId);
$buckets  = liquidityBucketDatasets($datasets);

$baseline  = liquidityWalkProjection(
    $datasets['starting_cash'], $days, $today,
    $buckets['inflows_by_date'], $buckets['outflows_by_date']
);
$simulated = liquidityWalkProjection(
    $datasets['starting_cash'], $days, $today,
    $buckets['inflows_by_date'], $buckets['outflows_by_date'],
    [], [$payDate => $billAmount]
);

$lowestShift     = round($simulated['lowest_balance'] - $baseline['lowest_balance'], 2);
$baselineRunway  = $baseline['runway_days_to_zero'];
$simulatedRunway = $simulated['runway_days_to_zero'];

if ($baselineRunway === null && $simulatedRunway !== null) {
    $runwayLost   = $days - $simulatedRunway;
    $crossesZero  = true;
} elseif ($baselineRunway !== null && $simulatedRunway !== null) {
    $runwayLost   = max(0, $baselineRunway - $simulatedRunway);
    $crossesZero  = true;
} elseif ($baselineRunway !== null && $simulatedRunway === null) {
    $runwayLost   = 0;
    $crossesZero  = true;
} else {
    $runwayLost   = 0;
    $crossesZero  = false;
}

$lowestDateShiftDays = (int) round((strtotime($simulated['lowest_balance_date']) - strtotime($baseline['lowest_balance_date'])) / 86400);

api_ok([
    'bill_id'       => $billId,
    'bill_amount'   => $billAmount,
    'pay_date'      => $payDate,
    'days_horizon'  => $days,
    'baseline'      => [
        'lowest_balance'      => $baseline['lowest_balance'],
        'lowest_balance_date' => $baseline['lowest_balance_date'],
        'runway_days_to_zero' => $baseline['runway_days_to_zero'],
    ],
    'simulated'     => [
        'lowest_balance'      => $simulated['lowest_balance'],
        'lowest_balance_date' => $simulated['lowest_balance_date'],
        'runway_days_to_zero' => $simulated['runway_days_to_zero'],
    ],
    'delta'         => [
        'lowest_balance_shift'    => $lowestShift,
        'lowest_date_shift_days'  => $lowestDateShiftDays,
        'runway_days_lost'        => $runwayLost,
        'crosses_zero'            => $crossesZero,
    ],
]);
