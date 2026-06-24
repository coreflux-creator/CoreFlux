<?php
/**
 * Liquidity Forecast Variance.
 *
 *   GET /api/liquidity_forecast_variance.php
 *     ?start_date=YYYY-MM-DD  optional, defaults to today - 30 days
 *     &days=30                1..365, default 30
 *     &entity_id=N            optional
 *
 * Replays the deterministic liquidity projection for a historical window and
 * compares projected daily net movement to actual posted cash movement on
 * bank GL accounts. Read-only and replayable; it does not persist forecast
 * outcomes or mutate source events.
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

$days = max(1, min(365, (int) (api_query('days') ?? 30)));
$today = date('Y-m-d');
$startDate = (string) (api_query('start_date') ?? date('Y-m-d', strtotime("-{$days} days")));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) $startDate = date('Y-m-d', strtotime("-{$days} days"));
if ($startDate > $today) $startDate = $today;
$endDate = date('Y-m-d', strtotime($startDate . " +{$days} days"));
if ($endDate > $today) $endDate = $today;
$days = max(0, (int) round((strtotime($endDate) - strtotime($startDate)) / 86400));
$entityId = (int) (api_query('entity_id') ?? 0) ?: null;

$datasets = liquidityBaselineDatasets($tid, $startDate, $endDate, $entityId);
$buckets = liquidityBucketDatasets($datasets);
$sourceDetail = liquidityProjectionSourceDetail($datasets);
$projection = liquidityProjectionEvidence($tid, $startDate, $endDate, $days, $datasets);
$actualByDate = liquidityForecastVarianceActuals($tid, $startDate, $endDate, $entityId);

$daily = [];
$projectedNetTotal = 0.0;
$actualNetTotal = 0.0;
$absoluteErrorTotal = 0.0;
$absoluteActualTotal = 0.0;
$missedInflows = 0;
$earlyLateOutflows = 0;

for ($i = 0; $i <= $days; $i++) {
    $date = date('Y-m-d', strtotime($startDate . " +{$i} days"));
    $projectedInflows = round((float) ($buckets['inflows_by_date'][$date] ?? 0), 2);
    $projectedOutflows = round((float) ($buckets['outflows_by_date'][$date] ?? 0), 2);
    $projectedNet = round($projectedInflows - $projectedOutflows, 2);
    $actual = $actualByDate[$date] ?? ['cash_in' => 0.0, 'cash_out' => 0.0, 'net' => 0.0];
    $actualNet = round((float) $actual['net'], 2);
    $variance = round($actualNet - $projectedNet, 2);
    $absoluteError = abs($variance);

    if ($projectedInflows > 0 && (float) $actual['cash_in'] <= 0) $missedInflows++;
    if ($projectedOutflows > 0 && (float) $actual['cash_out'] <= 0) $earlyLateOutflows++;

    $projectedNetTotal += $projectedNet;
    $actualNetTotal += $actualNet;
    $absoluteErrorTotal += $absoluteError;
    $absoluteActualTotal += abs($actualNet);

    $daily[] = [
        'date' => $date,
        'projected' => [
            'inflows' => $projectedInflows,
            'outflows' => $projectedOutflows,
            'net' => $projectedNet,
            'source_detail' => $sourceDetail['by_date'][$date] ?? [
                'date' => $date,
                'inflows' => [],
                'outflows' => [],
                'inflow_total' => 0.0,
                'outflow_total' => 0.0,
                'net' => 0.0,
            ],
        ],
        'actual' => [
            'cash_in' => round((float) $actual['cash_in'], 2),
            'cash_out' => round((float) $actual['cash_out'], 2),
            'net' => $actualNet,
        ],
        'variance' => $variance,
        'absolute_error' => round($absoluteError, 2),
    ];
}

$wape = $absoluteActualTotal > 0 ? round($absoluteErrorTotal / $absoluteActualTotal, 4) : null;
$bias = round($projectedNetTotal - $actualNetTotal, 2);
$biasPct = $absoluteActualTotal > 0 ? round($bias / $absoluteActualTotal, 4) : null;

api_ok([
    'window' => [
        'start_date' => $startDate,
        'end_date' => $endDate,
        'days' => $days,
    ],
    'projection' => $projection,
    'source_detail' => $sourceDetail,
    'daily' => $daily,
    'metrics' => [
        'projected_net_total' => round($projectedNetTotal, 2),
        'actual_net_total' => round($actualNetTotal, 2),
        'bias' => $bias,
        'bias_pct' => $biasPct,
        'absolute_error_total' => round($absoluteErrorTotal, 2),
        'wape' => $wape,
        'accuracy_score' => $wape === null ? null : max(0, round(100 - min(100, $wape * 100), 1)),
        'missed_inflow_days' => $missedInflows,
        'early_or_late_outflow_days' => $earlyLateOutflows,
    ],
    'guards' => [
        'has_bank_accounts' => $datasets['bank_count'] > 0,
        'has_open_ar' => count($datasets['ar']) > 0,
        'has_open_ap' => count($datasets['ap']) > 0,
        'has_scheduled_payments' => count($datasets['tp']) > 0,
        'has_actual_cash_activity' => $absoluteActualTotal > 0,
    ],
]);

/**
 * @return array<string,array{cash_in:float,cash_out:float,net:float}>
 */
function liquidityForecastVarianceActuals(int $tenantId, string $startDate, string $endDate, ?int $entityId = null): array
{
    $pdo = getDB();
    $sql = "SELECT je.posting_date AS date,
                   COALESCE(SUM(CASE WHEN jl.debit > 0 THEN jl.debit ELSE 0 END), 0) AS cash_in,
                   COALESCE(SUM(CASE WHEN jl.credit > 0 THEN jl.credit ELSE 0 END), 0) AS cash_out,
                   COALESCE(SUM(jl.debit - jl.credit), 0) AS net
              FROM accounting_bank_accounts ba
              JOIN accounting_accounts aa ON aa.tenant_id = ba.tenant_id AND aa.code = ba.gl_account_code
              JOIN accounting_journal_lines jl ON jl.account_id = aa.id AND jl.tenant_id = aa.tenant_id
              JOIN accounting_journal_entries je ON je.id = jl.journal_entry_id AND je.status = 'posted'
             WHERE ba.tenant_id = :t
               AND ba.status = 'active'
               AND je.posting_date BETWEEN :s AND :e"
        . ($entityId ? ' AND ba.entity_id = :ent' : '')
        . ' GROUP BY je.posting_date ORDER BY je.posting_date';
    $params = ['t' => $tenantId, 's' => $startDate, 'e' => $endDate];
    if ($entityId) $params['ent'] = $entityId;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $out = [];
    while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
        $date = (string) $row['date'];
        $out[$date] = [
            'cash_in' => round((float) $row['cash_in'], 2),
            'cash_out' => round((float) $row['cash_out'], 2),
            'net' => round((float) $row['net'], 2),
        ];
    }
    return $out;
}
