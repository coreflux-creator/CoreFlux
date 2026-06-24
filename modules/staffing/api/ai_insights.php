<?php
/**
 * /api/staffing/ai_insights — AI-generated weekly ops memo for Staffing.
 *
 *   GET ?action=weekly_memo&period_start=&period_end=
 *       → { memo: "...", citations: [...] }
 *
 * Pulls week stats (hours, revenue, GP, top clients, top placements) and
 * feeds them to aiAsk() for a 5-bullet ops memo a manager can ship to
 * the team channel without editing.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/ai_service.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$method = api_method();
$action = $_GET['action'] ?? '';

if ($method !== 'GET' || $action !== 'weekly_memo') api_error('Unknown action', 404);
rbac_legacy_require($user, 'staffing.reports.view');
rbac_legacy_require($user, 'ai.use');

$ps = (string) ($_GET['period_start'] ?? date('Y-m-d', strtotime('monday -1 week')));
$pe = (string) ($_GET['period_end']   ?? date('Y-m-d', strtotime('sunday')));

// Gather inputs.
$stats = ['hours' => 0, 'revenue' => 0, 'cost' => 0, 'gp' => 0,
          'approved_count' => 0, 'pending_count' => 0, 'rejected_count' => 0];
$topClients    = [];
$topPlacements = [];

try {
    $week = scopedFind(
        "SELECT SUM(v.hours) AS hours, SUM(v.revenue) AS revenue, SUM(v.cost) AS cost, SUM(v.gross_profit) AS gp
           FROM v_timesheet_day_fin v
          WHERE v.tenant_id = :tenant_id AND v.work_date BETWEEN :ps AND :pe AND v.entry_status != 'superseded'",
        ['ps' => $ps, 'pe' => $pe]
    );
    if ($week) $stats = array_merge($stats, array_map('floatval', $week));

    $topClients = scopedQuery(
        "SELECT c.name AS client_name, SUM(v.revenue) AS revenue, SUM(v.gross_profit) AS gp
           FROM v_timesheet_day_fin v
           JOIN placements p ON p.id = v.placement_id AND p.tenant_id = v.tenant_id
           LEFT JOIN staffing_clients c ON c.id = p.client_id AND c.tenant_id = v.tenant_id
          WHERE v.tenant_id = :tenant_id AND v.work_date BETWEEN :ps AND :pe AND v.entry_status != 'superseded'
          GROUP BY c.name HAVING revenue > 0 ORDER BY revenue DESC LIMIT 5",
        ['ps' => $ps, 'pe' => $pe]
    );
} catch (\Throwable $_) { /* view missing — proceed with empty stats */ }

try {
    $counts = scopedQuery(
        "SELECT status, COUNT(*) AS c FROM staffing_timesheets
          WHERE tenant_id = :tenant_id AND period_start BETWEEN :ps AND :pe
          GROUP BY status",
        ['ps' => $ps, 'pe' => $pe]
    );
    foreach ($counts as $r) {
        if ($r['status'] === 'approved')   $stats['approved_count'] = (int) $r['c'];
        if ($r['status'] === 'submitted')  $stats['pending_count']  = (int) $r['c'];
        if ($r['status'] === 'rejected')   $stats['rejected_count'] = (int) $r['c'];
    }
} catch (\Throwable $_) { /* fine */ }

$ctxStr  = sprintf(
    "Week: %s to %s\nTotal hours: %.1f\nRevenue: \$%s\nCost: \$%s\nGross profit: \$%s (%.1f%% GP)\nApproved timesheets: %d, Pending: %d, Rejected: %d",
    $ps, $pe, $stats['hours'],
    number_format($stats['revenue'], 0), number_format($stats['cost'], 0), number_format($stats['gp'], 0),
    $stats['revenue'] > 0 ? ($stats['gp'] / $stats['revenue'] * 100) : 0,
    $stats['approved_count'], $stats['pending_count'], $stats['rejected_count']
);
if ($topClients) {
    $ctxStr .= "\nTop clients (by revenue):";
    foreach ($topClients as $tc) {
        $ctxStr .= sprintf("\n  - %s: \$%s revenue, \$%s GP",
            $tc['client_name'] ?: 'Unassigned', number_format((float) $tc['revenue'], 0), number_format((float) $tc['gp'], 0));
    }
}

try {
    $out = aiAsk([
        'feature_class' => 'narrative',
        'feature_key'   => 'staffing.weekly_memo',
        'kind'          => 'narrative',
        'system'        => "You are an operations analyst writing a concise weekly memo for a staffing-firm management team. Tone: direct, candid, action-oriented. Output exactly 5 bullets, each one sentence: (1) headline number, (2) notable client win or risk, (3) margin call-out, (4) workflow bottleneck (approvals stuck, rejections, etc.), (5) one specific action for next week. No salutation, no signoff. Markdown bullets.",
        'prompt'        => "Generate this week's ops memo from the data below.\n\n" . $ctxStr,
        'context'       => ['stats' => $stats, 'top_clients' => $topClients, 'period' => [$ps, $pe]],
        'max_output_tokens' => 400,
    ]);
    api_ok(['memo' => $out['answer'] ?? $out['content'] ?? '', 'stats' => $stats, 'top_clients' => $topClients, 'period' => ['start' => $ps, 'end' => $pe]]);
} catch (AIDisabledException $e) {
    api_error('AI disabled for this tenant. Enable AI in Settings → AI to use weekly memos.', 503, ['ai_disabled' => true]);
} catch (\Throwable $e) {
    api_error('AI request failed: ' . $e->getMessage(), 502);
}
