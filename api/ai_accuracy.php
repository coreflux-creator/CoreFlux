<?php
/**
 * AI Accuracy Dashboard API.
 *
 *   GET /api/ai/accuracy?days=30
 *     → { features: [...], top_overrides: [...], totals: {...}, daily: [...] }
 *
 *   POST /api/ai/accuracy?action=rollup
 *     → recomputes ai_accuracy_daily for the last 30 days (idempotent)
 *
 * Visibility: any user with `accounting.coa.view` (the same CoA-aware
 * bookkeepers who care about training the categorizer) can see this. Master
 * admins still get the global rollup via the "across_tenants" query param.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/ai_categorization.php';

$ctx      = api_require_auth();
$tenantId = (int) $ctx['tenant_id'];
rbac_legacy_require($ctx['user'], 'accounting.coa.view');
$pdo = getDB();

if (api_method() === 'POST' && (string) ($_GET['action'] ?? '') === 'rollup') {
    $days = max(1, min(365, (int) ($_GET['days'] ?? 30)));
    $rows = aiRollupAccuracyDaily(
        $tenantId,
        date('Y-m-d', strtotime("-{$days} days")),
        date('Y-m-d')
    );
    api_ok(['rolled_up' => $rows, 'days' => $days]);
}

if (api_method() !== 'GET') api_error('Method not allowed', 405);

$days = max(7, min(365, (int) ($_GET['days'] ?? 30)));
$fromDate = date('Y-m-d', strtotime("-{$days} days"));

// Auto-rollup current day so the dashboard always reflects today.
aiRollupAccuracyDaily($tenantId, $fromDate, date('Y-m-d'));

// 1. Per-feature totals over the window.
$feat = $pdo->prepare(
    "SELECT
        feature_key,
        SUM(suggestions_count)  AS suggestions_count,
        SUM(accepted_count)     AS accepted_count,
        SUM(overridden_count)   AS overridden_count,
        SUM(rejected_count)     AS rejected_count,
        AVG(avg_confidence)     AS avg_confidence,
        AVG(avg_accepted_conf)  AS avg_accepted_conf,
        AVG(avg_overridden_conf) AS avg_overridden_conf
       FROM ai_accuracy_daily
      WHERE tenant_id = :t AND snapshot_date >= :a
      GROUP BY feature_key
      ORDER BY suggestions_count DESC"
);
$feat->execute(['t' => $tenantId, 'a' => $fromDate]);
$features = array_map(function ($r) {
    $total    = (int) ($r['accepted_count'] + $r['overridden_count'] + $r['rejected_count']);
    $accepted = (int) $r['accepted_count'];
    $rate     = $total > 0 ? round($accepted / $total, 4) : null;
    return $r + ['accept_rate' => $rate, 'review_count' => $total];
}, $feat->fetchAll(PDO::FETCH_ASSOC));

// 2. Top overrides — when AI suggested account A but human picked account B.
//    Groups by (feature_key, suggested_value, final_value), counts hits.
$over = $pdo->prepare(
    "SELECT s.feature_key,
            s.suggested_value,
            s.final_value,
            COUNT(*) AS override_count,
            AVG(s.confidence_score) AS avg_confidence_when_overridden,
            sa.code AS suggested_code, sa.name AS suggested_name,
            fa.code AS final_code,     fa.name AS final_name
       FROM ai_suggestions s
       LEFT JOIN accounting_accounts sa
         ON sa.tenant_id = s.tenant_id AND sa.id = CAST(s.suggested_value AS UNSIGNED)
       LEFT JOIN accounting_accounts fa
         ON fa.tenant_id = s.tenant_id AND fa.id = CAST(s.final_value     AS UNSIGNED)
      WHERE s.tenant_id      = :t
        AND s.status         = 'approved'
        AND s.accepted_as_is = 0
        AND s.suggested_value IS NOT NULL
        AND s.final_value     IS NOT NULL
        AND s.reviewed_at >= :a
      GROUP BY s.feature_key, s.suggested_value, s.final_value, sa.code, sa.name, fa.code, fa.name
      ORDER BY override_count DESC LIMIT 25"
);
$over->execute(['t' => $tenantId, 'a' => $fromDate]);
$topOverrides = $over->fetchAll(PDO::FETCH_ASSOC);

// 3. Daily series for the chart.
$daily = $pdo->prepare(
    "SELECT snapshot_date,
            SUM(suggestions_count) AS suggestions_count,
            SUM(accepted_count)    AS accepted_count,
            SUM(overridden_count)  AS overridden_count,
            SUM(rejected_count)    AS rejected_count,
            AVG(avg_confidence)    AS avg_confidence
       FROM ai_accuracy_daily
      WHERE tenant_id = :t AND snapshot_date >= :a
      GROUP BY snapshot_date
      ORDER BY snapshot_date ASC"
);
$daily->execute(['t' => $tenantId, 'a' => $fromDate]);
$dailySeries = $daily->fetchAll(PDO::FETCH_ASSOC);

// 4. Window totals.
$totals = ['suggestions_count' => 0, 'accepted_count' => 0, 'overridden_count' => 0, 'rejected_count' => 0];
foreach ($features as $f) {
    $totals['suggestions_count'] += (int) $f['suggestions_count'];
    $totals['accepted_count']    += (int) $f['accepted_count'];
    $totals['overridden_count']  += (int) $f['overridden_count'];
    $totals['rejected_count']    += (int) $f['rejected_count'];
}
$reviewed = $totals['accepted_count'] + $totals['overridden_count'] + $totals['rejected_count'];
$totals['overall_accept_rate'] = $reviewed > 0 ? round($totals['accepted_count'] / $reviewed, 4) : null;
$totals['days']                = $days;
$totals['from_date']           = $fromDate;

// 5. Training-history snapshot — how much has the moat compounded?
$histStmt = $pdo->prepare(
    'SELECT signal_kind, COUNT(*) AS rows, SUM(accept_count) AS total_accepts
       FROM ai_categorization_history
      WHERE tenant_id = :t
      GROUP BY signal_kind'
);
$histStmt->execute(['t' => $tenantId]);
$historySnapshot = $histStmt->fetchAll(PDO::FETCH_ASSOC);

api_ok([
    'features'         => $features,
    'top_overrides'    => $topOverrides,
    'daily'            => $dailySeries,
    'totals'           => $totals,
    'history_snapshot' => $historySnapshot,
    'tenant_id'        => $tenantId,
    'as_of'            => date('c'),
]);
