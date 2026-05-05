<?php
/**
 * /api/reports_staffing.php — staffing drill page payload.
 *
 * Returns:
 *   1. placement_margin   — one row per active placement: client, candidate,
 *                           recruiter, bill rate, pay rate, weekly hours,
 *                           weekly margin, lifetime margin.
 *   2. recruiter_board    — recruiter leaderboard: # active placements,
 *                           gross margin contribution, # new placements,
 *                           avg margin / hour.
 *   3. headcount_breakdown — slice by classification + worksite_state.
 *
 *   GET /api/reports_staffing.php?from=&to=&client_id=&recruiter_id=&placement_type=&worksite_state=
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';

$ctx       = api_require_auth();
$user      = $ctx['user'];
$role      = $ctx['role'] ?? 'employee';
$tenantId  = (int) (currentTenantId() ?? 0);
if (!$tenantId) api_error('No active tenant', 400);

if (!in_array($role, ['master_admin', 'tenant_admin', 'admin', 'manager'], true)) {
    api_error('Forbidden — reports require manager+', 403);
}

$pdo = getDB();
if (!$pdo) api_error('No database connection', 500);

$today = new DateTimeImmutable('today');
$rawFrom = (string) api_query('from', '');
$rawTo   = (string) api_query('to',   '');
$weeks   = max(1, min(208, (int) api_query('weeks', 12)));
if ($rawFrom !== '' && $rawTo !== '') {
    try {
        $from = new DateTimeImmutable($rawFrom);
        $to   = new DateTimeImmutable($rawTo);
        if ($from > $to) [$from, $to] = [$to, $from];
    } catch (Throwable $_) {
        $from = $today->modify("-{$weeks} weeks"); $to = $today;
    }
} else {
    $from = $today->modify("-{$weeks} weeks"); $to = $today;
}

$clientId      = (int) api_query('client_id', 0);
$recruiterId   = (int) api_query('recruiter_id', 0);
$placementType = (string) api_query('placement_type', '');
$worksiteState = (string) api_query('worksite_state', '');

$where  = ['p.tenant_id = :t'];
$params = ['t' => $tenantId];
if ($placementType !== '') { $where[] = 'p.engagement_type = :pt'; $params['pt'] = $placementType; }
if ($worksiteState !== '') { $where[] = 'p.worksite_state  = :ws'; $params['ws'] = $worksiteState; }
if ($recruiterId) {
    $where[] = "p.id IN (SELECT placement_id FROM placement_commissions
                          WHERE tenant_id = :t AND user_id = :rid AND role = 'recruiter')";
    $params['rid'] = $recruiterId;
}
$whereSql = implode(' AND ', $where);

function _rsFetch(PDO $pdo, string $sql, array $p): array {
    try { $stmt = $pdo->prepare($sql); $stmt->execute($p); return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []; }
    catch (Throwable $e) { error_log('reports_staffing: ' . $e->getMessage()); return []; }
}

/* =========================================================================
 *  1. Placement margin table
 * ========================================================================= */
$placementsTable = _rsFetch($pdo,
    "SELECT p.id,
            CONCAT(COALESCE(pe.preferred_name, pe.first_name, ''), ' ',
                   COALESCE(pe.last_name, '')) AS candidate_name,
            p.end_client_name, p.engagement_type,
            p.start_date, p.end_date, p.worksite_state, p.status,
            (SELECT pr.bill_rate FROM placement_rates pr
               WHERE pr.placement_id = p.id AND pr.approved_at IS NOT NULL
                 AND pr.effective_to IS NULL
            ORDER BY pr.effective_from DESC LIMIT 1) AS bill_rate,
            (SELECT pr.pay_rate  FROM placement_rates pr
               WHERE pr.placement_id = p.id AND pr.approved_at IS NOT NULL
                 AND pr.effective_to IS NULL
            ORDER BY pr.effective_from DESC LIMIT 1) AS pay_rate,
            (SELECT u.name FROM placement_commissions pc
               JOIN users u ON u.id = pc.user_id
              WHERE pc.placement_id = p.id AND pc.role = 'recruiter'
            ORDER BY pc.id LIMIT 1) AS recruiter_name,
            (SELECT u.id FROM placement_commissions pc
               JOIN users u ON u.id = pc.user_id
              WHERE pc.placement_id = p.id AND pc.role = 'recruiter'
            ORDER BY pc.id LIMIT 1) AS recruiter_id,
            COALESCE((SELECT SUM(te.hours) FROM time_entries te
              WHERE te.placement_id = p.id
                AND te.status = 'approved'
                AND te.category IN ('regular_billable','OT_billable')
                AND te.work_date BETWEEN :a AND :b),0) AS billable_hours_period,
            COALESCE((SELECT SUM(te.hours) FROM time_entries te
              WHERE te.placement_id = p.id
                AND te.status = 'approved'
                AND te.category IN ('regular_billable','OT_billable')),0) AS billable_hours_lifetime
       FROM placements p
       LEFT JOIN people pe ON pe.id = p.person_id
      WHERE $whereSql
   ORDER BY p.start_date DESC
      LIMIT 1000",
    array_merge($params, [
        'a' => $from->format('Y-m-d'),
        'b' => $to->format('Y-m-d'),
    ])
);

$placementsRows = [];
foreach ($placementsTable as $p) {
    $bill = (float) ($p['bill_rate'] ?? 0);
    $pay  = (float) ($p['pay_rate']  ?? 0);
    $hrsP = (float) ($p['billable_hours_period']   ?? 0);
    $hrsL = (float) ($p['billable_hours_lifetime'] ?? 0);
    $marginPerHr = $bill - $pay;
    $placementsRows[] = [
        'id'                => (int) $p['id'],
        'candidate'         => $p['candidate_name'],
        'client'            => $p['end_client_name'],
        'recruiter'         => $p['recruiter_name'],
        'recruiter_id'      => $p['recruiter_id'] ? (int) $p['recruiter_id'] : null,
        'engagement_type'   => $p['engagement_type'],
        'state'             => $p['worksite_state'],
        'start_date'        => $p['start_date'],
        'end_date'          => $p['end_date'],
        'status'            => $p['status'],
        'bill_rate'         => round($bill, 2),
        'pay_rate'          => round($pay,  2),
        'margin_per_hour'   => round($marginPerHr, 2),
        'period_hours'      => round($hrsP, 2),
        'period_margin'     => round($marginPerHr * $hrsP, 2),
        'lifetime_hours'    => round($hrsL, 2),
        'lifetime_margin'   => round($marginPerHr * $hrsL, 2),
    ];
}

/* =========================================================================
 *  2. Recruiter leaderboard
 * ========================================================================= */
$leaderboard = [];
foreach ($placementsRows as $row) {
    if (!$row['recruiter_id']) continue;
    $rid = $row['recruiter_id'];
    if (!isset($leaderboard[$rid])) {
        $leaderboard[$rid] = [
            'recruiter_id'      => $rid,
            'name'              => $row['recruiter'],
            'active_placements' => 0,
            'period_margin'     => 0,
            'lifetime_margin'   => 0,
            'period_hours'      => 0,
            'new_placements'    => 0,
        ];
    }
    if ($row['status'] === 'active') $leaderboard[$rid]['active_placements']++;
    $leaderboard[$rid]['period_margin']    += $row['period_margin'];
    $leaderboard[$rid]['lifetime_margin']  += $row['lifetime_margin'];
    $leaderboard[$rid]['period_hours']     += $row['period_hours'];
    if ($row['start_date'] >= $from->format('Y-m-d') && $row['start_date'] <= $to->format('Y-m-d')) {
        $leaderboard[$rid]['new_placements']++;
    }
}
foreach ($leaderboard as &$lb) {
    $lb['period_margin']   = round($lb['period_margin'],   2);
    $lb['lifetime_margin'] = round($lb['lifetime_margin'], 2);
    $lb['period_hours']    = round($lb['period_hours'],    2);
    $lb['avg_margin_per_hour'] = $lb['period_hours'] > 0
        ? round($lb['period_margin'] / $lb['period_hours'], 2)
        : 0;
}
unset($lb);
$leaderRows = array_values($leaderboard);
usort($leaderRows, fn($a, $b) => $b['period_margin'] <=> $a['period_margin']);

/* =========================================================================
 *  3. Headcount breakdown
 * ========================================================================= */
$byClassification = _rsFetch($pdo,
    "SELECT classification, COUNT(*) AS c FROM people
      WHERE tenant_id = :t AND status = 'active'
   GROUP BY classification",
    ['t' => $tenantId]);
$byState = _rsFetch($pdo,
    "SELECT home_state AS state, COUNT(*) AS c FROM people
      WHERE tenant_id = :t AND status = 'active' AND home_state IS NOT NULL AND home_state != ''
   GROUP BY home_state
   ORDER BY c DESC LIMIT 30",
    ['t' => $tenantId]);

api_ok([
    'range' => [
        'from'  => $from->format('Y-m-d'),
        'to'    => $to->format('Y-m-d'),
    ],
    'filters' => [
        'client_id'      => $clientId ?: null,
        'recruiter_id'   => $recruiterId ?: null,
        'placement_type' => $placementType ?: null,
        'worksite_state' => $worksiteState ?: null,
    ],
    'placement_margin'     => $placementsRows,
    'recruiter_board'      => $leaderRows,
    'headcount_breakdown'  => [
        'by_classification' => $byClassification,
        'by_state'          => $byState,
    ],
]);
