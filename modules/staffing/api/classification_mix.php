<?php
/**
 * /api/staffing/classification_mix — W2-vs-1099-vs-internal mix over time.
 *
 *   GET ?weeks=12          → weekly buckets for the last N weeks
 *   GET ?period_start=&period_end=
 *
 *   Returns:
 *     {
 *       weeks: [
 *         { week_start, w2_hours, w2_cost, c1099_hours, c1099_cost, c2c_hours, c2c_cost, internal_hours, internal_cost, total_hours, total_cost }
 *       ],
 *       classification_changes: [
 *         { person_id, name, prior_type, current_type, changed_at }
 *       ]
 *     }
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';

$ctx    = api_require_auth();
$method = api_method();
if ($method !== 'GET') api_error('Method not allowed', 405);

$weeks = max(1, min(52, (int) ($_GET['weeks'] ?? 12)));
$pe    = isset($_GET['period_end'])   ? (string) $_GET['period_end']
                                       : date('Y-m-d');
$ps    = isset($_GET['period_start']) ? (string) $_GET['period_start']
                                       : date('Y-m-d', strtotime("-{$weeks} weeks", strtotime($pe)));

try {
    $rows = scopedQuery(
        "SELECT
            DATE_SUB(te.work_date, INTERVAL WEEKDAY(te.work_date) DAY) AS week_start,
            COALESCE(pl.engagement_type, 'w2') AS engagement_type,
            SUM(te.hours)                              AS hours,
            SUM(te.hours * COALESCE(pr.pay_rate, 0))   AS cost
           FROM time_entries te
           LEFT JOIN placements pl     ON pl.id = te.placement_id AND pl.tenant_id = te.tenant_id
           LEFT JOIN placement_rates pr ON pr.id = te.rate_snapshot_id
          WHERE te.tenant_id = :tenant_id
            AND te.work_date BETWEEN :ps AND :pe
            AND te.status != 'superseded'
          GROUP BY week_start, engagement_type
          ORDER BY week_start ASC",
        ['ps' => $ps, 'pe' => $pe]
    );
} catch (\Throwable $e) {
    api_ok(['weeks' => [], 'classification_changes' => [], 'note' => 'No data: ' . $e->getMessage()]);
}

// Pivot to per-week buckets with one row per week.
$byWeek = [];
foreach ($rows as $r) {
    $w = $r['week_start'];
    if (!isset($byWeek[$w])) {
        $byWeek[$w] = [
            'week_start'     => $w,
            'w2_hours'       => 0.0, 'w2_cost'       => 0.0,
            'c1099_hours'    => 0.0, 'c1099_cost'    => 0.0,
            'c2c_hours'      => 0.0, 'c2c_cost'      => 0.0,
            'internal_hours' => 0.0, 'internal_cost' => 0.0,
            'other_hours'    => 0.0, 'other_cost'    => 0.0,
            'total_hours'    => 0.0, 'total_cost'    => 0.0,
        ];
    }
    $hours = (float) $r['hours'];
    $cost  = (float) $r['cost'];
    $type  = $r['engagement_type'];
    if ($type === 'w2')          { $byWeek[$w]['w2_hours']       += $hours; $byWeek[$w]['w2_cost']       += $cost; }
    elseif ($type === '1099')    { $byWeek[$w]['c1099_hours']    += $hours; $byWeek[$w]['c1099_cost']    += $cost; }
    elseif ($type === 'c2c')     { $byWeek[$w]['c2c_hours']      += $hours; $byWeek[$w]['c2c_cost']      += $cost; }
    elseif ($type === 'internal'){ $byWeek[$w]['internal_hours'] += $hours; $byWeek[$w]['internal_cost'] += $cost; }
    else                         { $byWeek[$w]['other_hours']    += $hours; $byWeek[$w]['other_cost']    += $cost; }
    $byWeek[$w]['total_hours'] += $hours;
    $byWeek[$w]['total_cost']  += $cost;
}

// Classification-change detection — workers placed on multiple placements
// across the window where engagement_type differs from prior placement.
// Flags potential W2↔1099 reclassification triggers.
$changes = [];
try {
    $changes = scopedQuery(
        "SELECT pl.person_id AS person_id,
                CONCAT(COALESCE(p.first_name,''), ' ', COALESCE(p.last_name,'')) AS name,
                GROUP_CONCAT(DISTINCT pl.engagement_type ORDER BY pl.engagement_type) AS types_seen,
                MIN(pl.start_date) AS first_start,
                MAX(pl.start_date) AS latest_start
           FROM placements pl
           LEFT JOIN people p ON p.id = pl.person_id AND p.tenant_id = pl.tenant_id
          WHERE pl.tenant_id = :tenant_id
            AND pl.start_date BETWEEN :ps AND :pe
            AND pl.person_id IS NOT NULL
          GROUP BY pl.person_id
         HAVING COUNT(DISTINCT pl.engagement_type) > 1",
        ['ps' => $ps, 'pe' => $pe]
    );
} catch (\Throwable $_) { /* placements may be missing — empty */ }

api_ok([
    'weeks'                  => array_values($byWeek),
    'classification_changes' => $changes,
    'period_start'           => $ps,
    'period_end'             => $pe,
]);
