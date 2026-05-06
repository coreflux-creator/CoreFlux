<?php
/**
 * Audit-log Anomaly Spotter (Sprint 6i).
 *
 *   POST /api/audit_anomaly.php?action=spot&hours=24
 *     → { window_hours, summary, signals: {...}, anomalies: [...] }
 *
 * Pure narrative advisory per /app/AI_INTEGRATION_RULES.md — never emits
 * decisions or recommended actions on individual events. Reads strictly
 * from the `audit_log` table so the model has grounded inputs.
 *
 * Signals computed:
 *   - spike events     : event types whose count in the window is >= 3x
 *                        the median per-event-type count.
 *   - off-hours count  : events outside 07:00-19:00 UTC (proxy for "not
 *                        a normal working hour"). Tenant-local TZ left
 *                        for a future iteration.
 *   - mass-export users: users with >= 5 events whose name contains
 *                        'export' or 'csv' in the last hour.
 *   - top users        : top 3 users by event volume in the window.
 *
 * Best-effort: any failure (missing key, rate limit, AI disabled)
 * returns 200 + empty summary so the UI degrades silently.
 *
 * Admin-gated (master_admin / tenant_admin / admin).
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/ai_service.php';

$ctx      = api_require_auth();
$user     = $ctx['user'];
$tenantId = (int) $ctx['tenant_id'];
$method   = api_method();
$action   = (string) (api_query('action') ?? '');

$role = $user['role'] ?? '';
if (!in_array($role, ['master_admin', 'tenant_admin', 'admin'], true)) {
    api_error('Forbidden', 403);
}
if ($method !== 'POST' || $action !== 'spot') {
    api_error('Unknown method/action', 405);
}

$hours = (int) (api_query('hours') ?? 24);
if ($hours < 1)   $hours = 1;
if ($hours > 168) $hours = 168;  // cap at 1 week

$pdo = getDB();
if (!$pdo) api_error('No DB', 500);

// ──────────────────────────────────────────────────────────────────
// 1) Window totals + per-event counts
// ──────────────────────────────────────────────────────────────────
$rangeStmt = $pdo->prepare(
    "SELECT event, COUNT(*) AS cnt
       FROM audit_log
      WHERE tenant_id = :t
        AND created_at >= (NOW() - INTERVAL :h HOUR)
      GROUP BY event
      ORDER BY cnt DESC"
);
$rangeStmt->bindValue(':t', $tenantId, PDO::PARAM_INT);
$rangeStmt->bindValue(':h', $hours, PDO::PARAM_INT);
$rangeStmt->execute();
$eventCounts = $rangeStmt->fetchAll(PDO::FETCH_ASSOC);
$totalEvents = 0;
foreach ($eventCounts as $r) $totalEvents += (int) $r['cnt'];

// Median (robust against single noisy event class).
$median = 0.0;
$counts = array_map(fn($r) => (int) $r['cnt'], $eventCounts);
if ($counts) {
    sort($counts);
    $n = count($counts);
    $median = $n % 2 ? (float) $counts[(int) ($n / 2)]
                     : ($counts[$n / 2 - 1] + $counts[$n / 2]) / 2.0;
}
$spikeEvents = [];
foreach ($eventCounts as $r) {
    if ($median > 0 && (int) $r['cnt'] >= max(3 * $median, 10)) {
        $spikeEvents[] = ['event' => $r['event'], 'count' => (int) $r['cnt']];
    }
}

// ──────────────────────────────────────────────────────────────────
// 2) Off-hours events (UTC < 7 OR UTC >= 19)
// ──────────────────────────────────────────────────────────────────
$offStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM audit_log
      WHERE tenant_id = :t
        AND created_at >= (NOW() - INTERVAL :h HOUR)
        AND (HOUR(created_at) < 7 OR HOUR(created_at) >= 19)"
);
$offStmt->bindValue(':t', $tenantId, PDO::PARAM_INT);
$offStmt->bindValue(':h', $hours, PDO::PARAM_INT);
$offStmt->execute();
$offHoursCount = (int) $offStmt->fetchColumn();

// ──────────────────────────────────────────────────────────────────
// 3) Mass-export sessions (last hour, >= 5 export-ish events per user)
// ──────────────────────────────────────────────────────────────────
$mxStmt = $pdo->prepare(
    "SELECT al.user_id, u.name, u.email, COUNT(*) AS cnt
       FROM audit_log al
       LEFT JOIN users u ON u.id = al.user_id
      WHERE al.tenant_id = :t
        AND al.created_at >= (NOW() - INTERVAL 1 HOUR)
        AND (al.event LIKE '%export%' OR al.event LIKE '%csv%' OR al.event LIKE '%download%')
        AND al.user_id IS NOT NULL
      GROUP BY al.user_id, u.name, u.email
      HAVING cnt >= 5
      ORDER BY cnt DESC
      LIMIT 5"
);
$mxStmt->bindValue(':t', $tenantId, PDO::PARAM_INT);
$mxStmt->execute();
$massExports = [];
foreach ($mxStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $massExports[] = [
        'user_id' => (int) $r['user_id'],
        'name'    => (string) ($r['name'] ?? $r['email'] ?? ('#' . $r['user_id'])),
        'count'   => (int) $r['cnt'],
    ];
}

// ──────────────────────────────────────────────────────────────────
// 4) Top users by event volume in the window
// ──────────────────────────────────────────────────────────────────
$topStmt = $pdo->prepare(
    "SELECT al.user_id, u.name, u.email, COUNT(*) AS cnt
       FROM audit_log al
       LEFT JOIN users u ON u.id = al.user_id
      WHERE al.tenant_id = :t
        AND al.created_at >= (NOW() - INTERVAL :h HOUR)
        AND al.user_id IS NOT NULL
      GROUP BY al.user_id, u.name, u.email
      ORDER BY cnt DESC
      LIMIT 3"
);
$topStmt->bindValue(':t', $tenantId, PDO::PARAM_INT);
$topStmt->bindValue(':h', $hours, PDO::PARAM_INT);
$topStmt->execute();
$topUsers = [];
foreach ($topStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $topUsers[] = [
        'user_id' => (int) $r['user_id'],
        'name'    => (string) ($r['name'] ?? $r['email'] ?? ('#' . $r['user_id'])),
        'count'   => (int) $r['cnt'],
    ];
}

// ──────────────────────────────────────────────────────────────────
// 5) AI narrative — grounded context only
// ──────────────────────────────────────────────────────────────────
$signals = [
    'window_hours'      => $hours,
    'total_events'      => $totalEvents,
    'distinct_events'   => count($eventCounts),
    'off_hours_count'   => $offHoursCount,
    'spike_events'      => $spikeEvents,
    'mass_export_users' => $massExports,
    'top_users'         => $topUsers,
];

$prompt = "You are a security analyst reviewing this tenant's audit log "
        . "anomaly signals. In 2-4 sentences, qualitatively summarise what "
        . "looks unusual and worth a closer look. Refer to volumes "
        . "qualitatively (e.g. 'a notable spike in bill approvals') rather "
        . "than restating exact counts. If nothing looks anomalous, say so "
        . "plainly in one sentence. Do not invent anomalies that are not "
        . "present in the signals. Never recommend specific account actions "
        . "(suspensions, password resets, etc.) — surface observations only.";

$summary = '';
try {
    $r = aiAsk([
        'feature_class'     => 'narrative',
        'kind'              => 'narrative',
        'feature_key'       => 'audit.anomaly.spotter',
        'prompt'            => $prompt,
        'context'           => $signals,
        'max_output_tokens' => 220,
    ]);
    $summary = trim((string) ($r['content'] ?? ''));
} catch (\Throwable $_) {
    $summary = '';  // graceful degrade
}

api_ok([
    'window_hours' => $hours,
    'summary'      => $summary,
    'signals'      => [
        'total_events'      => $totalEvents,
        'distinct_events'   => count($eventCounts),
        'off_hours_count'   => $offHoursCount,
        'spike_events'      => $spikeEvents,
        'mass_export_users' => $massExports,
        'top_users'         => $topUsers,
    ],
]);
