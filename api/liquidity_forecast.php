<?php
/**
 * Liquidity Forecast (Sprint 7g+ / P2).
 *
 *   GET /api/liquidity_forecast.php
 *     ?days=90              (1..365, default 90)
 *     &entity_id=N          (optional)
 *
 *   Response: {
 *     window_days,
 *     starting_cash,
 *     daily: [{ date, opening, inflows, outflows, closing }],
 *     totals: { total_inflows, total_outflows, ending_cash, lowest_balance,
 *               lowest_balance_date, runway_days_to_zero | null },
 *     sources: {
 *       inflows: [{ source: 'invoice', count, amount }, ...],
 *       outflows: [{ source: 'treasury_payment'|'ap_bill', count, amount }, ...]
 *     },
 *     guards: { has_bank_accounts, has_open_ar, has_open_ap, has_scheduled_payments }
 *   }
 *
 * Tenant-scoped. RBAC: `treasury.payment.view`.
 *
 * Inflows model: open billing_invoices (status NOT IN paid/void/draft) due
 * within window — uses `amount_due` (= total - amount_paid).
 *
 * Outflows model: open ap_bills (status NOT IN paid/void) due within window
 * UNION treasury_payments scheduled in window with status in
 * (approved, scheduled, pending_approval, draft) — DEDUPED by vendor name
 * + amount so the same obligation isn't counted twice.
 *
 * Internals — uses the shared `core/treasury/liquidity_projection.php`
 * engine so the per-bill what-if and the scenario builder share the
 * exact same SQL + walker.
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

$days     = max(1, min(365, (int) (api_query('days') ?? 90)));
$entityId = (int) (api_query('entity_id') ?? 0) ?: null;
$today    = date('Y-m-d');
$endDate  = date('Y-m-d', strtotime("+{$days} days"));

$datasets = liquidityBaselineDatasets($tid, $today, $endDate, $entityId);
$buckets  = liquidityBucketDatasets($datasets);
$result   = liquidityWalkProjection(
    $datasets['starting_cash'], $days, $today,
    $buckets['inflows_by_date'], $buckets['outflows_by_date']
);
$projection = liquidityProjectionEvidence($tid, $today, $endDate, $days, $datasets);

$daily         = $result['daily'];
$totalInflows  = array_sum(array_column($daily, 'inflows'));
$totalOutflows = array_sum(array_column($daily, 'outflows'));
$tpAmount      = array_sum(array_column($datasets['tp'], 'amount'));
$apAmount      = array_sum(array_column($datasets['ap'], 'amount_due'));

api_ok([
    'window_days'    => $days,
    'projection'     => $projection,
    'starting_cash'  => round($datasets['starting_cash'], 2),
    'daily'          => $daily,
    'totals'         => [
        'total_inflows'        => round($totalInflows, 2),
        'total_outflows'       => round($totalOutflows, 2),
        'ending_cash'          => $daily ? end($daily)['closing'] : round($datasets['starting_cash'], 2),
        'lowest_balance'       => $result['lowest_balance'],
        'lowest_balance_date'  => $result['lowest_balance_date'],
        'runway_days_to_zero'  => $result['runway_days_to_zero'],
    ],
    'sources'        => [
        'inflows'  => [['source' => 'invoice',          'count' => count($datasets['ar']), 'amount' => round($totalInflows, 2)]],
        'outflows' => [
            ['source' => 'treasury_payment', 'count' => count($datasets['tp']), 'amount' => round($tpAmount, 2)],
            ['source' => 'ap_bill',          'count' => count($datasets['ap']), 'amount' => round($apAmount, 2)],
        ],
    ],
    'guards'         => [
        'has_bank_accounts'        => $datasets['bank_count'] > 0,
        'has_open_ar'              => count($datasets['ar']) > 0,
        'has_open_ap'              => count($datasets['ap']) > 0,
        'has_scheduled_payments'   => count($datasets['tp']) > 0,
    ],
]);
