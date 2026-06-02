<?php
/**
 * /api/admin/cpa_firm_dashboard.php — multi-tenant firm rollup.
 *
 *   GET /api/admin/cpa_firm_dashboard.php
 *       ?firm_tenant_id=N    (optional — restrict to one firm; default = all
 *                             firms the user belongs to)
 *
 * Aggregates three KPIs per client tenant the user can see via any
 * firm they're a member of:
 *
 *   - open_exceptions:  count of accounting_exceptions where
 *                       status IN ('open','assigned')
 *   - draft_outbox:     count of accounting_outbox_events where
 *                       status IN ('queued','retrying','dead_letter')
 *   - late_close_periods: count of accounting_periods where
 *                       end_date < CURDATE() AND status IN ('open','soft_closed')
 *
 * Plus a portfolio-wide totals block + a per-firm grouping for the UI.
 *
 * Designed to never throw on missing tables — modules that haven't shipped
 * the underlying migration return 0 for that KPI rather than failing the
 * whole request.
 *
 * tenant-leak-allow: cross-tenant by design; the portfolio resolver scopes
 * to the caller's firm memberships above.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/rbac/cpa_firms.php';

$ctx    = api_require_auth(false);
$userId = (int) ($ctx['user']['id'] ?? 0);
if (!$userId) api_error('Authentication required', 401);

$pdo = getDB();
if (!$pdo) api_error('No database connection', 500);

try {
    $pdo->query('SELECT 1 FROM cpa_firm_client_links LIMIT 0');
} catch (\Throwable $_) {
    api_ok(['firms' => [], 'totals' => _emptyTotals(), 'clients' => []]);
}

$portfolio = CpaFirmService::portfolioForUser($userId);
$firmFilter = (int) (api_query('firm_tenant_id') ?? 0);
if ($firmFilter > 0) {
    $portfolio = array_values(array_filter(
        $portfolio,
        fn($r) => (int) $r['firm_tenant_id'] === $firmFilter
    ));
}
if (!$portfolio) {
    api_ok(['firms' => [], 'totals' => _emptyTotals(), 'clients' => []]);
}

$clientIds = array_values(array_unique(array_map(
    fn($r) => (int) $r['client_tenant_id'], $portfolio
)));
if (!$clientIds) {
    api_ok(['firms' => [], 'totals' => _emptyTotals(), 'clients' => []]);
}

$idsCsv = implode(',', array_map('intval', $clientIds));

// ── KPI 1: open accounting exceptions ──────────────────────────────────────
$exceptionsByTenant = [];
try {
    $st = $pdo->query(
        "SELECT tenant_id, COUNT(*) AS n
           FROM accounting_exceptions
          WHERE status IN ('open','assigned')
            AND tenant_id IN ($idsCsv)
       GROUP BY tenant_id"
    );
    foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
        $exceptionsByTenant[(int) $r['tenant_id']] = (int) $r['n'];
    }
} catch (\Throwable $_) { /* migration 093 absent */ }

// ── KPI 2: outbox drafts not yet posted ────────────────────────────────────
$outboxByTenant = [];
try {
    $st = $pdo->query(
        "SELECT tenant_id, COUNT(*) AS n
           FROM accounting_outbox_events
          WHERE status IN ('queued','retrying','dead_letter')
            AND tenant_id IN ($idsCsv)
       GROUP BY tenant_id"
    );
    foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
        $outboxByTenant[(int) $r['tenant_id']] = (int) $r['n'];
    }
} catch (\Throwable $_) { /* migration 088 absent */ }

// ── KPI 3: periods past end_date but still open / soft_closed ──────────────
$lateCloseByTenant = [];
try {
    $st = $pdo->query(
        "SELECT tenant_id, COUNT(*) AS n
           FROM accounting_periods
          WHERE end_date < CURDATE()
            AND status IN ('open','soft_closed')
            AND tenant_id IN ($idsCsv)
       GROUP BY tenant_id"
    );
    foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
        $lateCloseByTenant[(int) $r['tenant_id']] = (int) $r['n'];
    }
} catch (\Throwable $_) { /* periods module absent */ }

// ── Hydrate per-client + group by firm ─────────────────────────────────────
$clientsOut = [];
$firmsMap   = [];
$totals     = _emptyTotals();
foreach ($portfolio as $row) {
    $cid = (int) $row['client_tenant_id'];
    $fid = (int) $row['firm_tenant_id'];
    $kpi = [
        'open_exceptions'     => (int) ($exceptionsByTenant[$cid] ?? 0),
        'draft_outbox'        => (int) ($outboxByTenant[$cid]     ?? 0),
        'late_close_periods'  => (int) ($lateCloseByTenant[$cid]  ?? 0),
    ];
    $kpi['needs_attention'] = $kpi['open_exceptions'] + $kpi['draft_outbox'] + $kpi['late_close_periods'];

    $client = [
        'link_id'                 => (int) $row['link_id'],
        'firm_tenant_id'          => $fid,
        'firm_name'               => $row['firm_name'],
        'client_tenant_id'        => $cid,
        'client_name'             => $row['client_name'],
        'status'                  => $row['status'],
        'relationship_type'       => $row['relationship_type'],
        'client_persona'          => $row['client_persona'],
        'has_client_membership'   => (bool) $row['has_client_membership'],
        'kpis'                    => $kpi,
    ];
    $clientsOut[] = $client;
    if (!isset($firmsMap[$fid])) {
        $firmsMap[$fid] = [
            'firm_tenant_id' => $fid,
            'firm_name'      => $row['firm_name'],
            'client_count'   => 0,
            'kpis'           => _emptyTotals(),
            'clients'        => [],
        ];
    }
    $firmsMap[$fid]['clients'][]                    = $client;
    $firmsMap[$fid]['client_count']                  += 1;
    $firmsMap[$fid]['kpis']['open_exceptions']       += $kpi['open_exceptions'];
    $firmsMap[$fid]['kpis']['draft_outbox']          += $kpi['draft_outbox'];
    $firmsMap[$fid]['kpis']['late_close_periods']    += $kpi['late_close_periods'];
    $firmsMap[$fid]['kpis']['needs_attention']       += $kpi['needs_attention'];

    $totals['open_exceptions']    += $kpi['open_exceptions'];
    $totals['draft_outbox']       += $kpi['draft_outbox'];
    $totals['late_close_periods'] += $kpi['late_close_periods'];
    $totals['needs_attention']    += $kpi['needs_attention'];
}

api_ok([
    'firms'   => array_values($firmsMap),
    'totals'  => $totals,
    'clients' => $clientsOut,
]);

function _emptyTotals(): array
{
    return [
        'open_exceptions'    => 0,
        'draft_outbox'       => 0,
        'late_close_periods' => 0,
        'needs_attention'    => 0,
    ];
}
