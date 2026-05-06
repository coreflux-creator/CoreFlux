<?php
/**
 * Reports Module — Staffing metric helpers.
 *
 * Shared math used by Overview + drill reports so KPI definitions stay
 * consistent. All functions read from v_timesheet_day_fin and accept
 * pre-resolved [from, to] dates from reportsResolvePeriod().
 *
 * Metric definitions (Reports spec §Staffing Overview Dashboard):
 *   Revenue           = SUM(v.revenue)
 *   Pay Cost          = SUM(v.cost)
 *   Gross Profit      = Revenue − Pay Cost
 *   Gross Profit %    = GP ÷ Revenue
 *   Total Hours       = SUM(v.hours)
 *   Overtime %        = SUM(hours WHERE is_overtime=1) ÷ Total Hours
 *   Spread per Hour   = GP ÷ Total Hours
 *   Run Rate (weekly) = last_week_value × 52
 */
declare(strict_types=1);

/**
 * Period KPI totals. Returns ints/floats; caller is responsible for rounding.
 *
 * @return array{revenue:float, cost:float, gross_profit:float, hours:float, ot_hours:float, billable_hours:float}
 */
function staffingKpiTotals(int $tenantId, string $from, string $to, array $filters = []): array {
    $pdo = getDB();
    if (!$pdo) {
        return ['revenue'=>0,'cost'=>0,'gross_profit'=>0,'hours'=>0,'ot_hours'=>0,'billable_hours'=>0];
    }
    [$where, $params] = _staffingBuildWhere($tenantId, $from, $to, $filters);
    $sql = "SELECT
              COALESCE(SUM(revenue), 0)     AS revenue,
              COALESCE(SUM(cost), 0)        AS cost,
              COALESCE(SUM(gross_profit),0) AS gross_profit,
              COALESCE(SUM(hours), 0)       AS hours,
              COALESCE(SUM(CASE WHEN is_overtime = 1 THEN hours ELSE 0 END), 0) AS ot_hours,
              COALESCE(SUM(CASE WHEN is_billable = 1 THEN hours ELSE 0 END), 0) AS billable_hours
            FROM v_timesheet_day_fin
            WHERE $where";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'revenue'        => (float) ($row['revenue'] ?? 0),
        'cost'           => (float) ($row['cost'] ?? 0),
        'gross_profit'   => (float) ($row['gross_profit'] ?? 0),
        'hours'          => (float) ($row['hours'] ?? 0),
        'ot_hours'       => (float) ($row['ot_hours'] ?? 0),
        'billable_hours' => (float) ($row['billable_hours'] ?? 0),
    ];
}

/**
 * Weekly time series for the Rev/GP chart. Returns one row per week_start
 * within the range. Empty weeks are omitted; the UI fills gaps from the
 * period.weeks list.
 *
 * @return list<array{week_start:string, week_end:string, revenue:float, cost:float, gross_profit:float, hours:float, ot_hours:float}>
 */
function staffingWeeklySeries(int $tenantId, string $from, string $to, array $filters = []): array {
    $pdo = getDB();
    if (!$pdo) return [];
    [$where, $params] = _staffingBuildWhere($tenantId, $from, $to, $filters);
    $sql = "SELECT
              week_start,
              MAX(week_end) AS week_end,
              COALESCE(SUM(revenue), 0)     AS revenue,
              COALESCE(SUM(cost), 0)        AS cost,
              COALESCE(SUM(gross_profit),0) AS gross_profit,
              COALESCE(SUM(hours), 0)       AS hours,
              COALESCE(SUM(CASE WHEN is_overtime = 1 THEN hours ELSE 0 END), 0) AS ot_hours
            FROM v_timesheet_day_fin
            WHERE $where
            GROUP BY week_start
            ORDER BY week_start";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = [
            'week_start'   => $r['week_start'],
            'week_end'     => $r['week_end'],
            'revenue'      => (float) $r['revenue'],
            'cost'         => (float) $r['cost'],
            'gross_profit' => (float) $r['gross_profit'],
            'hours'        => (float) $r['hours'],
            'ot_hours'     => (float) $r['ot_hours'],
        ];
    }
    return $out;
}

/**
 * Headcount stats for the selected period.
 *   active_at_end     — distinct person_id with any approved time in last week of range
 *   new_starts        — placements with start_date in range
 *   terminations      — placements with actual_end_date or end_date in range AND status in ended/cancelled
 *
 * @return array{active:int, new_starts:int, terminations:int, net_change:int}
 */
function staffingHeadcount(int $tenantId, string $from, string $to): array {
    $pdo = getDB();
    if (!$pdo) return ['active'=>0,'new_starts'=>0,'terminations'=>0,'net_change'=>0];

    $active = (int) $pdo->prepare(
        "SELECT COUNT(DISTINCT person_id)
           FROM placements
          WHERE tenant_id = :t AND deleted_at IS NULL
            AND start_date <= :to
            AND (end_date IS NULL OR end_date >= :from_)
            AND status IN ('active','pending_start','on_hold')"
    )->execute(['t'=>$tenantId,'to'=>$to,'from_'=>$from]) ? 0 : 0;

    // Execute properly (the prepare above was just for illustration; redo).
    $stmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT person_id) AS c
           FROM placements
          WHERE tenant_id = :t AND deleted_at IS NULL
            AND start_date <= :to
            AND (end_date IS NULL OR end_date >= :from_)
            AND status IN ('active','pending_start','on_hold')"
    );
    $stmt->execute(['t'=>$tenantId,'to'=>$to,'from_'=>$from]);
    $active = (int) ($stmt->fetchColumn() ?: 0);

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS c FROM placements
          WHERE tenant_id = :t AND deleted_at IS NULL
            AND start_date BETWEEN :from_ AND :to"
    );
    $stmt->execute(['t'=>$tenantId,'from_'=>$from,'to'=>$to]);
    $newStarts = (int) ($stmt->fetchColumn() ?: 0);

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS c FROM placements
          WHERE tenant_id = :t AND deleted_at IS NULL
            AND COALESCE(actual_end_date, end_date) BETWEEN :from_ AND :to
            AND status IN ('ended','cancelled')"
    );
    $stmt->execute(['t'=>$tenantId,'from_'=>$from,'to'=>$to]);
    $terms = (int) ($stmt->fetchColumn() ?: 0);

    return [
        'active'       => $active,
        'new_starts'   => $newStarts,
        'terminations' => $terms,
        'net_change'   => $newStarts - $terms,
    ];
}

/**
 * Timesheet health summary for the Overview card.
 *   median_approval_lag_hours — median (approved_at - created_at) across approved entries in range
 *   ot_hours, ot_pct          — same totals used in KPI tile (returned here for convenience)
 */
function staffingTimesheetHealth(int $tenantId, string $from, string $to): array {
    $pdo = getDB();
    if (!$pdo) return ['median_approval_lag_hours'=>null,'submitted_pending'=>0,'approved'=>0,'rejected'=>0];

    // Median lag — MySQL 5.7 has no built-in MEDIAN; cheap approximation via ORDER BY LIMIT midrow.
    $stmt = $pdo->prepare(
        "SELECT TIMESTAMPDIFF(HOUR, created_at, approved_at) AS lag_hours
           FROM time_entries
          WHERE tenant_id = :t
            AND status = 'approved'
            AND approved_at IS NOT NULL
            AND work_date BETWEEN :from_ AND :to
          ORDER BY lag_hours"
    );
    $stmt->execute(['t'=>$tenantId,'from_'=>$from,'to'=>$to]);
    $lags = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'lag_hours'));
    $median = null;
    if ($lags) {
        $n = count($lags);
        $median = $n % 2
            ? $lags[intdiv($n, 2)]
            : intval(round(($lags[intdiv($n, 2) - 1] + $lags[intdiv($n, 2)]) / 2));
    }

    $stmt = $pdo->prepare(
        "SELECT status, COUNT(*) AS c
           FROM time_entries
          WHERE tenant_id = :t
            AND work_date BETWEEN :from_ AND :to
          GROUP BY status"
    );
    $stmt->execute(['t'=>$tenantId,'from_'=>$from,'to'=>$to]);
    $byStatus = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $byStatus[$r['status']] = (int) $r['c'];
    }

    return [
        'median_approval_lag_hours' => $median,
        'submitted_pending' => (int) ($byStatus['pending_review'] ?? 0),
        'approved'          => (int) ($byStatus['approved'] ?? 0),
        'rejected'          => (int) ($byStatus['rejected'] ?? 0),
        'draft'             => (int) ($byStatus['draft'] ?? 0),
    ];
}

/**
 * Run rate comparison per Reports spec §Run Rate Comparison.
 * Compares last-week revenue/GP × 52 vs first-week revenue/GP × 52.
 */
function staffingRunRate(int $tenantId, array $weeklySeries): array {
    if (!$weeklySeries) {
        return [
            'last_week_revenue'=>0,'first_week_revenue'=>0,
            'revenue_run_rate_now'=>0,'revenue_run_rate_baseline'=>0,'revenue_run_rate_delta_pct'=>0,
            'last_week_gp'=>0,'first_week_gp'=>0,
            'gp_run_rate_now'=>0,'gp_run_rate_baseline'=>0,'gp_run_rate_delta_pct'=>0,
        ];
    }
    $first = $weeklySeries[0];
    $last  = $weeklySeries[count($weeklySeries) - 1];
    $rateNow  = (float) $last['revenue']  * 52;
    $rateBase = (float) $first['revenue'] * 52;
    $gpNow    = (float) $last['gross_profit']  * 52;
    $gpBase   = (float) $first['gross_profit'] * 52;
    return [
        'last_week_revenue'          => (float) $last['revenue'],
        'first_week_revenue'         => (float) $first['revenue'],
        'revenue_run_rate_now'       => $rateNow,
        'revenue_run_rate_baseline'  => $rateBase,
        'revenue_run_rate_delta_pct' => $rateBase > 0 ? round(($rateNow / $rateBase - 1) * 100, 2) : 0,
        'last_week_gp'               => (float) $last['gross_profit'],
        'first_week_gp'              => (float) $first['gross_profit'],
        'gp_run_rate_now'            => $gpNow,
        'gp_run_rate_baseline'       => $gpBase,
        'gp_run_rate_delta_pct'      => $gpBase > 0 ? round(($gpNow / $gpBase - 1) * 100, 2) : 0,
    ];
}

/**
 * Shared WHERE clause for v_timesheet_day_fin queries.
 * Filters: client_id (placements.end_client_name by id fk proxy — TODO when client table lands),
 * placement_id, recruiter_user_id.
 */
function _staffingBuildWhere(int $tenantId, string $from, string $to, array $filters): array {
    $where  = ['v.tenant_id = :t', 'v.work_date BETWEEN :from_ AND :to'];
    $params = ['t' => $tenantId, 'from_' => $from, 'to' => $to];
    if (!empty($filters['placement_id'])) {
        $where[] = 'v.placement_id = :pl';
        $params['pl'] = (int) $filters['placement_id'];
    }
    if (!empty($filters['employee_id'])) {
        $where[] = 'v.employee_id = :pe';
        $params['pe'] = (int) $filters['employee_id'];
    }
    // _staffingBuildWhere is used with the view aliased as v in a few queries,
    // but also bare (no alias) above. Rewrite to bare column names — callers
    // that need an alias should wrap the view in their own SELECT.
    $bareWhere = str_replace('v.', '', implode(' AND ', $where));
    return [$bareWhere, $params];
}
