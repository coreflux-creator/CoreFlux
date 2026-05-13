<?php
/**
 * /api/exec_dashboard.php — CEO/CFO snapshot of the business.
 *
 * Aggregates across Billing, AP, Time, Payroll, Placements, People into a
 * single payload the SPA can render as KPI cards + trendline sparklines +
 * drill-down tables. Modular: every metric is computed independently so a
 * future industry vertical (e.g. consulting, construction) can subscribe to
 * a different KPI set without touching the rest.
 *
 *   GET /api/exec_dashboard.php?weeks=12&client_id=&recruiter_id=&placement_type=
 *
 * Filters (all optional, additive):
 *   weeks            — trendline window in weeks (default 12, max 104)
 *   client_id        — filter by placements.end_client_name OR companies.id
 *   recruiter_id     — filter by placement_commissions.user_id (role='recruiter')
 *   placement_type   — w2 | 1099 | c2c | direct_hire | temp_to_perm
 *   worksite_state   — placement worksite filter
 *
 * Output shape:
 *   {
 *     range: { from, to, weeks },
 *     filters: {...echoed...},
 *     finance: {
 *       revenue: { mtd, qtd, ytd, run_rate_90d, trend: [{week,amount}] },
 *       margin:  { mtd, qtd, ytd, gross_pct, trend: [...] },
 *       ar_aging:{ current, d30, d60, d90, d90_plus, total },
 *       ap_aging:{ current, d30, d60, d90, d90_plus, total },
 *       payroll: { mtd, qtd, ytd, last_run_total }
 *     },
 *     staffing: {
 *       headcount:    { active, contractors_w2, contractors_c2c, contractors_1099, perm },
 *       new_starts:   { period, trend: [...] },
 *       terminations: { period, trend: [...] },
 *       net_change:   { period, trend: [...] },
 *       active_placements: int,
 *       new_placements:    { period, trend: [...] },
 *       ending_soon:  int,
 *       billable_hours: { period, trend: [...] }
 *     }
 *   }
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';

$ctx       = api_require_auth();
$user      = $ctx['user'];
$role      = $ctx['role'] ?? 'employee';
$tenantId  = (int) (currentTenantId() ?? 0);
if (!$tenantId) api_error('No active tenant', 400);

if (!in_array($role, ['master_admin', 'tenant_admin', 'admin', 'manager'], true)) {
    api_error('Forbidden — exec dashboard requires manager+', 403);
}

$pdo = getDB();
if (!$pdo) api_error('No database connection', 500);

$weeks  = max(1, min(104, (int) api_query('weeks', 12)));
$today  = new DateTimeImmutable('today');

// Custom date range support — overrides the weeks preset when provided.
// `from` (YYYY-MM-DD) anchors the start of the trendline window;
// `to` (YYYY-MM-DD) anchors the end. Both must validate or we silently
// fall back to the weeks preset.
$rawFrom = (string) api_query('from', '');
$rawTo   = (string) api_query('to',   '');
$customRange = false;
$from = null; $to = null;
if ($rawFrom !== '' && $rawTo !== '') {
    try {
        $from = (new DateTimeImmutable($rawFrom))->modify('monday this week');
        $to   = new DateTimeImmutable($rawTo);
        if ($from <= $to) {
            $customRange = true;
            $weeks = max(1, min(208, (int) ceil(($to->getTimestamp() - $from->getTimestamp()) / 604800) + 1));
        }
    } catch (Throwable $_) { /* ignore — fall through */ }
}
if (!$customRange) {
    $from = $today->modify('-' . ($weeks - 1) . ' weeks')->modify('monday this week');
    $to   = $today;
}

// Prior-year comparison: when ?compare=prior_year is set, every trended
// metric also returns a `prev_period` series shifted exactly 52 weeks earlier.
// When ?compare=prior_period is set, the prev window is the same length
// window immediately preceding the current one (CFO use case).
$compare = (string) api_query('compare', '');
$compareEnabled    = in_array($compare, ['prior_year', 'prior_period'], true);
$comparePriorYear  = $compare === 'prior_year';
$windowDays = max(1, (int) ceil(($to->getTimestamp() - $from->getTimestamp()) / 86400) + 1);
if ($comparePriorYear) {
    $prevFrom = $from->modify('-52 weeks');
    $prevTo   = $to->modify('-52 weeks');
} else {
    // prior_period (or default) — immediately preceding same-length window.
    $prevTo   = $from->modify('-1 day');
    $prevFrom = $prevTo->modify('-' . ($windowDays - 1) . ' days');
}

$clientId       = (int) api_query('client_id', 0);
$recruiterId    = (int) api_query('recruiter_id', 0);
$placementType  = (string) api_query('placement_type', '');
$worksiteState  = (string) api_query('worksite_state', '');

$placementWhere = ['p.tenant_id = :t'];
$pwParams       = ['t' => $tenantId];
if ($placementType !== '')  { $placementWhere[] = 'p.engagement_type = :pt'; $pwParams['pt'] = $placementType; }
if ($worksiteState !== '')  { $placementWhere[] = 'p.worksite_state  = :ws'; $pwParams['ws'] = $worksiteState; }
if ($clientId)              { $placementWhere[] = 'p.end_client_name IN (SELECT client_name FROM tenant_end_clients WHERE id = :cid AND tenant_id = :t)'; $pwParams['cid'] = $clientId; }
if ($recruiterId) {
    $placementWhere[] = "p.id IN (SELECT placement_id FROM placement_commissions
                                   WHERE tenant_id = :t AND user_id = :rid AND role = 'recruiter')";
    $pwParams['rid'] = $recruiterId;
}
$placementWhereSql = implode(' AND ', $placementWhere);

/* ---------- helpers ---------- */
function _execRowsExist(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM information_schema.tables
                                WHERE table_schema = DATABASE() AND table_name = :n LIMIT 1");
        $stmt->execute(['n' => $table]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) { return false; }
}
function _execSafeFetch(PDO $pdo, string $sql, array $params): array {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('exec_dashboard query failed: ' . $e->getMessage());
        return [];
    }
}
function _execWeekBuckets(DateTimeImmutable $from, DateTimeImmutable $to): array {
    $weeks = []; $cursor = $from;
    while ($cursor <= $to) {
        $weeks[$cursor->format('Y-m-d')] = 0.0;
        $cursor = $cursor->modify('+1 week');
    }
    return $weeks;
}
function _execBucketByWeek(DateTimeImmutable $from, array $rows, string $dateKey, string $valueKey): array {
    $buckets = [];
    foreach ($rows as $r) {
        $d = $r[$dateKey] ?? null;
        if (!$d) continue;
        $monday = (new DateTimeImmutable($d))->modify('monday this week');
        if ($monday < $from) continue;
        $key = $monday->format('Y-m-d');
        $buckets[$key] = ($buckets[$key] ?? 0) + (float) $r[$valueKey];
    }
    return $buckets;
}
function _execTrendlineFromRows(DateTimeImmutable $from, DateTimeImmutable $to, array $rows, string $dateKey, string $valueKey): array {
    $shell   = _execWeekBuckets($from, $to);
    $buckets = _execBucketByWeek($from, $rows, $dateKey, $valueKey);
    $out = [];
    foreach ($shell as $weekStart => $_) {
        $out[] = ['week' => $weekStart, 'amount' => round($buckets[$weekStart] ?? 0, 2)];
    }
    return $out;
}

/* ---------- FINANCE ---------- */
$finance = [
    'revenue'  => ['mtd' => 0, 'qtd' => 0, 'ytd' => 0, 'run_rate_90d' => 0, 'trend' => []],
    'margin'   => ['mtd' => 0, 'qtd' => 0, 'ytd' => 0, 'gross_pct' => 0, 'trend' => []],
    'ar_aging' => ['current' => 0, 'd30' => 0, 'd60' => 0, 'd90' => 0, 'd90_plus' => 0, 'total' => 0],
    'ap_aging' => ['current' => 0, 'd30' => 0, 'd60' => 0, 'd90' => 0, 'd90_plus' => 0, 'total' => 0],
    'payroll'  => ['mtd' => 0, 'qtd' => 0, 'ytd' => 0, 'last_run_total' => 0],
    // CFO-specific working-capital metrics (2026-02).
    // dso  = days sales outstanding   ≈ AR / (revenue_last_90 / 90)
    // dpo  = days payable outstanding ≈ AP / (cogs_last_90    / 90)
    // unapplied_cash = customer payments received but not yet applied to any invoice.
    'dso'            => null,
    'dpo'            => null,
    'unapplied_cash' => 0,
];

$bucketDates = [
    'mtd' => $today->modify('first day of this month')->format('Y-m-d'),
    'qtd' => (function (DateTimeImmutable $t) {
        $m = (int) $t->format('n');
        $qStart = (int) (floor(($m - 1) / 3) * 3) + 1;
        return $t->setDate((int) $t->format('Y'), $qStart, 1)->format('Y-m-d');
    })($today),
    'ytd' => $today->setDate((int) $today->format('Y'), 1, 1)->format('Y-m-d'),
];

if (_execRowsExist($pdo, 'billing_invoices')) {
    // Revenue: invoices in 'sent' / 'partially_paid' / 'paid' state.
    foreach (['mtd','qtd','ytd'] as $bucket) {
        $sql = "SELECT COALESCE(SUM(total),0) AS v FROM billing_invoices
                 WHERE tenant_id = :t AND status IN ('sent','partially_paid','paid')
                   AND issue_date >= :start";
        $rows = _execSafeFetch($pdo, $sql, ['t' => $tenantId, 'start' => $bucketDates[$bucket]]);
        $finance['revenue'][$bucket] = (float) ($rows[0]['v'] ?? 0);
    }
    // Run rate: trailing 90 days revenue (annualised x4).
    $rrFrom = $today->modify('-90 days')->format('Y-m-d');
    $rrRows = _execSafeFetch($pdo,
        "SELECT COALESCE(SUM(total),0) AS v FROM billing_invoices
          WHERE tenant_id = :t AND status IN ('sent','partially_paid','paid')
            AND issue_date >= :s",
        ['t' => $tenantId, 's' => $rrFrom]
    );
    $finance['revenue']['run_rate_90d'] = round(((float) ($rrRows[0]['v'] ?? 0)) * 4, 2);

    // AR aging — outstanding (sent or partially_paid) bucketed by days past due.
    $arRows = _execSafeFetch($pdo,
        "SELECT id, total, amount_paid, due_date FROM billing_invoices
          WHERE tenant_id = :t AND status IN ('sent','partially_paid')",
        ['t' => $tenantId]
    );
    foreach ($arRows as $r) {
        $outstanding = max(0, (float) $r['total'] - (float) $r['amount_paid']);
        if ($outstanding <= 0) continue;
        $due = (new DateTimeImmutable((string) $r['due_date']));
        $age = (int) $today->diff($due)->format('%r%a') * -1;  // positive = overdue days
        $finance['ar_aging']['total'] += $outstanding;
        if      ($age <= 0)  $finance['ar_aging']['current']  += $outstanding;
        elseif  ($age <= 30) $finance['ar_aging']['d30']      += $outstanding;
        elseif  ($age <= 60) $finance['ar_aging']['d60']      += $outstanding;
        elseif  ($age <= 90) $finance['ar_aging']['d90']      += $outstanding;
        else                 $finance['ar_aging']['d90_plus'] += $outstanding;
    }
    foreach ($finance['ar_aging'] as $k => $v) $finance['ar_aging'][$k] = round($v, 2);

    // Revenue trendline by week.
    $trendRows = _execSafeFetch($pdo,
        "SELECT issue_date AS d, total AS v FROM billing_invoices
          WHERE tenant_id = :t AND status IN ('sent','partially_paid','paid')
            AND issue_date >= :s",
        ['t' => $tenantId, 's' => $from->format('Y-m-d')]
    );
    $finance['revenue']['trend'] = _execTrendlineFromRows($from, $to, $trendRows, 'd', 'v');

    if ($compareEnabled) {
        $prevRows = _execSafeFetch($pdo,
            "SELECT issue_date AS d, total AS v FROM billing_invoices
              WHERE tenant_id = :t AND status IN ('sent','partially_paid','paid')
                AND issue_date BETWEEN :a AND :b",
            ['t' => $tenantId, 'a' => $prevFrom->format('Y-m-d'), 'b' => $prevTo->format('Y-m-d')]
        );
        $finance['revenue']['prev_period'] = _execTrendlineFromRows($prevFrom, $prevTo, $prevRows, 'd', 'v');
    }
}

if (_execRowsExist($pdo, 'ap_bills')) {
    $apRows = _execSafeFetch($pdo,
        "SELECT id, total, amount_paid, due_date FROM ap_bills
          WHERE tenant_id = :t AND status IN ('approved','partially_paid')",
        ['t' => $tenantId]
    );
    foreach ($apRows as $r) {
        $outstanding = max(0, (float) $r['total'] - (float) $r['amount_paid']);
        if ($outstanding <= 0) continue;
        $due = new DateTimeImmutable((string) ($r['due_date'] ?: $today->format('Y-m-d')));
        $age = (int) $today->diff($due)->format('%r%a') * -1;
        $finance['ap_aging']['total'] += $outstanding;
        if      ($age <= 0)  $finance['ap_aging']['current']  += $outstanding;
        elseif  ($age <= 30) $finance['ap_aging']['d30']      += $outstanding;
        elseif  ($age <= 60) $finance['ap_aging']['d60']      += $outstanding;
        elseif  ($age <= 90) $finance['ap_aging']['d90']      += $outstanding;
        else                 $finance['ap_aging']['d90_plus'] += $outstanding;
    }
    foreach ($finance['ap_aging'] as $k => $v) $finance['ap_aging'][$k] = round($v, 2);
}

// Margin from placement_rates (effective): aggregate (bill - pay) * billable hours.
if (_execRowsExist($pdo, 'time_entries') && _execRowsExist($pdo, 'placement_rates')) {
    $marginSql = "
        SELECT te.work_date AS d,
               te.hours AS hrs,
               te.category,
               (SELECT pr.bill_rate - pr.pay_rate
                  FROM placement_rates pr
                 WHERE pr.placement_id = te.placement_id
                   AND pr.approved_at IS NOT NULL
                   AND pr.effective_from <= te.work_date
                   AND (pr.effective_to IS NULL OR pr.effective_to >= te.work_date)
              ORDER BY pr.effective_from DESC LIMIT 1) AS spread
          FROM time_entries te
         WHERE te.tenant_id = :t
           AND te.status = 'approved'
           AND te.category IN ('regular_billable','OT_billable')
           AND te.work_date >= :s
           AND te.placement_id IN (SELECT p.id FROM placements p WHERE $placementWhereSql)";
    $rows = _execSafeFetch($pdo, $marginSql, array_merge(
        $pwParams,
        ['s' => $from->format('Y-m-d')]
    ));
    $marginRows = [];
    foreach ($rows as $r) {
        if ($r['spread'] === null) continue;
        $marginRows[] = ['d' => $r['d'], 'v' => (float) $r['hrs'] * (float) $r['spread']];
    }
    $finance['margin']['trend'] = _execTrendlineFromRows($from, $to, $marginRows, 'd', 'v');
    foreach (['mtd','qtd','ytd'] as $bucket) {
        $start = $bucketDates[$bucket];
        $finance['margin'][$bucket] = round(array_sum(array_map(
            fn($r) => $r['d'] >= $start ? $r['v'] : 0,
            $marginRows
        )), 2);
    }
    if ($finance['revenue']['ytd'] > 0) {
        $finance['margin']['gross_pct'] = round($finance['margin']['ytd'] / $finance['revenue']['ytd'] * 100, 1);
    }
}

if (_execRowsExist($pdo, 'payroll_runs')) {
    foreach (['mtd','qtd','ytd'] as $bucket) {
        $rows = _execSafeFetch($pdo,
            "SELECT COALESCE(SUM(total_gross_cents),0) AS v FROM payroll_runs
              WHERE tenant_id = :t AND status IN ('approved','paid')
                AND created_at >= :s",
            ['t' => $tenantId, 's' => $bucketDates[$bucket]]
        );
        $finance['payroll'][$bucket] = round(((float) ($rows[0]['v'] ?? 0)) / 100, 2);
    }
    $last = _execSafeFetch($pdo,
        "SELECT total_gross_cents FROM payroll_runs
          WHERE tenant_id = :t AND status IN ('approved','paid')
       ORDER BY created_at DESC LIMIT 1",
        ['t' => $tenantId]
    );
    $finance['payroll']['last_run_total'] = round(((float) ($last[0]['total_gross_cents'] ?? 0)) / 100, 2);
}

/* ---------- STAFFING ---------- */
$staffing = [
    'headcount'        => ['active' => 0, 'contractors_w2' => 0, 'contractors_c2c' => 0, 'contractors_1099' => 0, 'perm' => 0],
    'new_starts'       => ['period' => 0, 'trend' => []],
    'terminations'     => ['period' => 0, 'trend' => []],
    'net_change'       => ['period' => 0, 'trend' => []],
    'active_placements'=> 0,
    'new_placements'   => ['period' => 0, 'trend' => []],
    'ending_soon'      => 0,
    'billable_hours'   => ['period' => 0, 'trend' => []],
];

if (_execRowsExist($pdo, 'people')) {
    $hc = _execSafeFetch($pdo,
        "SELECT classification, COUNT(*) AS c FROM people
          WHERE tenant_id = :t AND status = 'active'
       GROUP BY classification",
        ['t' => $tenantId]
    );
    foreach ($hc as $r) {
        $staffing['headcount']['active'] += (int) $r['c'];
        switch ($r['classification']) {
            case 'w2':       $staffing['headcount']['contractors_w2']   = (int) $r['c']; break;
            case 'c2c':      $staffing['headcount']['contractors_c2c']  = (int) $r['c']; break;
            case '1099':     $staffing['headcount']['contractors_1099'] = (int) $r['c']; break;
            case 'perm':     $staffing['headcount']['perm']             = (int) $r['c']; break;
        }
    }

    $startsRows = _execSafeFetch($pdo,
        "SELECT hire_date AS d, 1 AS v FROM people
          WHERE tenant_id = :t AND hire_date >= :s",
        ['t' => $tenantId, 's' => $from->format('Y-m-d')]
    );
    $staffing['new_starts']['trend']  = _execTrendlineFromRows($from, $to, $startsRows, 'd', 'v');
    $staffing['new_starts']['period'] = array_sum(array_column($startsRows, 'v'));

    $termRows = _execSafeFetch($pdo,
        "SELECT termination_date AS d, 1 AS v FROM people
          WHERE tenant_id = :t AND termination_date >= :s",
        ['t' => $tenantId, 's' => $from->format('Y-m-d')]
    );
    $staffing['terminations']['trend']  = _execTrendlineFromRows($from, $to, $termRows, 'd', 'v');
    $staffing['terminations']['period'] = array_sum(array_column($termRows, 'v'));

    $netTrend = [];
    foreach ($staffing['new_starts']['trend'] as $i => $s) {
        $term = $staffing['terminations']['trend'][$i]['amount'] ?? 0;
        $netTrend[] = ['week' => $s['week'], 'amount' => $s['amount'] - $term];
    }
    $staffing['net_change']['trend']  = $netTrend;
    $staffing['net_change']['period'] = $staffing['new_starts']['period'] - $staffing['terminations']['period'];
}

if (_execRowsExist($pdo, 'placements')) {
    $plRows = _execSafeFetch($pdo,
        "SELECT COUNT(*) AS c FROM placements p WHERE $placementWhereSql AND p.status = 'active'",
        $pwParams
    );
    $staffing['active_placements'] = (int) ($plRows[0]['c'] ?? 0);

    $endingRows = _execSafeFetch($pdo,
        "SELECT COUNT(*) AS c FROM placements p
          WHERE $placementWhereSql AND p.status = 'active'
            AND p.end_date IS NOT NULL
            AND p.end_date BETWEEN :today AND :soon",
        array_merge($pwParams, [
            'today' => $today->format('Y-m-d'),
            'soon'  => $today->modify('+30 days')->format('Y-m-d'),
        ])
    );
    $staffing['ending_soon'] = (int) ($endingRows[0]['c'] ?? 0);

    $newPlacements = _execSafeFetch($pdo,
        "SELECT p.start_date AS d, 1 AS v FROM placements p
          WHERE $placementWhereSql AND p.start_date >= :s",
        array_merge($pwParams, ['s' => $from->format('Y-m-d')])
    );
    $staffing['new_placements']['trend']  = _execTrendlineFromRows($from, $to, $newPlacements, 'd', 'v');
    $staffing['new_placements']['period'] = array_sum(array_column($newPlacements, 'v'));
}

if (_execRowsExist($pdo, 'time_entries')) {
    $hoursRows = _execSafeFetch($pdo,
        "SELECT te.work_date AS d, te.hours AS v FROM time_entries te
          WHERE te.tenant_id = :t AND te.status = 'approved'
            AND te.category IN ('regular_billable','OT_billable')
            AND te.work_date >= :s
            AND te.placement_id IN (SELECT p.id FROM placements p WHERE $placementWhereSql)",
        array_merge($pwParams, ['s' => $from->format('Y-m-d')])
    );
    $staffing['billable_hours']['trend']  = _execTrendlineFromRows($from, $to, $hoursRows, 'd', 'v');
    $staffing['billable_hours']['period'] = round(array_sum(array_column($hoursRows, 'v')), 2);
}

/* ---------- CFO EXTRAS: DSO / DPO / unapplied cash / upcoming hires + terms ---------- */
$last90 = $today->modify('-90 days')->format('Y-m-d');

// DSO — AR balance ÷ (revenue last 90 / 90). Skip when revenue is 0.
if ($finance['ar_aging']['total'] > 0 && _execRowsExist($pdo, 'billing_invoices')) {
    $rev90 = _execSafeFetch($pdo,
        "SELECT COALESCE(SUM(total),0) AS v FROM billing_invoices
          WHERE tenant_id = :t AND status IN ('sent','partially_paid','paid')
            AND issue_date >= :s",
        ['t' => $tenantId, 's' => $last90]
    );
    $rev90v = (float) ($rev90[0]['v'] ?? 0);
    if ($rev90v > 0) {
        $finance['dso'] = round($finance['ar_aging']['total'] / ($rev90v / 90), 1);
    }
}

// DPO — AP balance ÷ (bill volume last 90 / 90).
if ($finance['ap_aging']['total'] > 0 && _execRowsExist($pdo, 'ap_bills')) {
    $bill90 = _execSafeFetch($pdo,
        "SELECT COALESCE(SUM(total),0) AS v FROM ap_bills
          WHERE tenant_id = :t AND status IN ('approved','partially_paid','paid')
            AND bill_date >= :s",
        ['t' => $tenantId, 's' => $last90]
    );
    $bill90v = (float) ($bill90[0]['v'] ?? 0);
    if ($bill90v > 0) {
        $finance['dpo'] = round($finance['ap_aging']['total'] / ($bill90v / 90), 1);
    }
}

// Unapplied cash — customer payments not yet applied to an invoice.
// billing_payments has an `unallocated_amount` column maintained inline
// by the billing module's allocation flow — fastest source of truth.
if (_execRowsExist($pdo, 'billing_payments')) {
    $rows = _execSafeFetch($pdo,
        "SELECT COALESCE(SUM(unallocated_amount), 0) AS v
           FROM billing_payments
          WHERE tenant_id = :t",
        ['t' => $tenantId]
    );
    $finance['unapplied_cash'] = round(max(0, (float) ($rows[0]['v'] ?? 0)), 2);
}

// Upcoming starts (hire_date in next 30 days) + upcoming terms (termination_date in next 30 days).
if (_execRowsExist($pdo, 'people')) {
    $soon = $today->modify('+30 days')->format('Y-m-d');
    $upStarts = _execSafeFetch($pdo,
        "SELECT COUNT(*) AS c FROM people
          WHERE tenant_id = :t AND status = 'active'
            AND hire_date BETWEEN :a AND :b",
        ['t' => $tenantId, 'a' => $today->format('Y-m-d'), 'b' => $soon]
    );
    $staffing['upcoming_starts'] = (int) ($upStarts[0]['c'] ?? 0);

    $upTerms = _execSafeFetch($pdo,
        "SELECT COUNT(*) AS c FROM people
          WHERE tenant_id = :t
            AND termination_date BETWEEN :a AND :b",
        ['t' => $tenantId, 'a' => $today->format('Y-m-d'), 'b' => $soon]
    );
    $staffing['upcoming_terminations'] = (int) ($upTerms[0]['c'] ?? 0);
} else {
    $staffing['upcoming_starts']       = 0;
    $staffing['upcoming_terminations'] = 0;
}

/* ---------- PRIOR-PERIOD COMPARISON SCALARS ---------- */
$prevScalars = null;
if ($compareEnabled) {
    $prevScalars = [
        'window_from'      => $prevFrom->format('Y-m-d'),
        'window_to'        => $prevTo->format('Y-m-d'),
        'revenue'          => 0.0,
        'margin'           => 0.0,
        'payroll'          => 0.0,
        'billable_hours'   => 0.0,
        'new_starts'       => 0,
        'terminations'     => 0,
        'new_placements'   => 0,
    ];
    if (_execRowsExist($pdo, 'billing_invoices')) {
        $r = _execSafeFetch($pdo,
            "SELECT COALESCE(SUM(total),0) AS v FROM billing_invoices
              WHERE tenant_id = :t AND status IN ('sent','partially_paid','paid')
                AND issue_date BETWEEN :a AND :b",
            ['t' => $tenantId, 'a' => $prevFrom->format('Y-m-d'), 'b' => $prevTo->format('Y-m-d')]
        );
        $prevScalars['revenue'] = round((float) ($r[0]['v'] ?? 0), 2);
    }
    if (_execRowsExist($pdo, 'payroll_runs')) {
        $r = _execSafeFetch($pdo,
            "SELECT COALESCE(SUM(total_gross_cents),0)/100 AS v FROM payroll_runs
              WHERE tenant_id = :t AND status IN ('approved','paid')
                AND created_at BETWEEN :a AND :b",
            ['t' => $tenantId, 'a' => $prevFrom->format('Y-m-d'), 'b' => $prevTo->format('Y-m-d')]
        );
        $prevScalars['payroll'] = round((float) ($r[0]['v'] ?? 0), 2);
    }
    if (_execRowsExist($pdo, 'people')) {
        $r = _execSafeFetch($pdo,
            "SELECT COUNT(*) AS c FROM people
              WHERE tenant_id = :t AND hire_date BETWEEN :a AND :b",
            ['t' => $tenantId, 'a' => $prevFrom->format('Y-m-d'), 'b' => $prevTo->format('Y-m-d')]
        );
        $prevScalars['new_starts'] = (int) ($r[0]['c'] ?? 0);
        $r = _execSafeFetch($pdo,
            "SELECT COUNT(*) AS c FROM people
              WHERE tenant_id = :t AND termination_date BETWEEN :a AND :b",
            ['t' => $tenantId, 'a' => $prevFrom->format('Y-m-d'), 'b' => $prevTo->format('Y-m-d')]
        );
        $prevScalars['terminations'] = (int) ($r[0]['c'] ?? 0);
    }
    if (_execRowsExist($pdo, 'placements')) {
        $r = _execSafeFetch($pdo,
            "SELECT COUNT(*) AS c FROM placements p WHERE $placementWhereSql
                AND p.start_date BETWEEN :a AND :b",
            array_merge($pwParams, ['a' => $prevFrom->format('Y-m-d'), 'b' => $prevTo->format('Y-m-d')])
        );
        $prevScalars['new_placements'] = (int) ($r[0]['c'] ?? 0);
    }
    if (_execRowsExist($pdo, 'time_entries')) {
        $r = _execSafeFetch($pdo,
            "SELECT COALESCE(SUM(te.hours),0) AS v FROM time_entries te
              WHERE te.tenant_id = :t AND te.status = 'approved'
                AND te.category IN ('regular_billable','OT_billable')
                AND te.work_date BETWEEN :a AND :b
                AND te.placement_id IN (SELECT p.id FROM placements p WHERE $placementWhereSql)",
            array_merge($pwParams, ['a' => $prevFrom->format('Y-m-d'), 'b' => $prevTo->format('Y-m-d')])
        );
        $prevScalars['billable_hours'] = round((float) ($r[0]['v'] ?? 0), 2);
    }
}

api_ok([
    'range'   => [
        'from'  => $from->format('Y-m-d'),
        'to'    => $to->format('Y-m-d'),
        'weeks' => $weeks,
        'custom' => $customRange,
    ],
    'compare' => $compareEnabled ? [
        'mode'      => $compare,
        'prev_from' => $prevFrom->format('Y-m-d'),
        'prev_to'   => $prevTo->format('Y-m-d'),
        'scalars'   => $prevScalars,
    ] : null,
    'filters' => [
        'client_id'      => $clientId ?: null,
        'recruiter_id'   => $recruiterId ?: null,
        'placement_type' => $placementType ?: null,
        'worksite_state' => $worksiteState ?: null,
    ],
    'finance'  => $finance,
    'staffing' => $staffing,
]);
