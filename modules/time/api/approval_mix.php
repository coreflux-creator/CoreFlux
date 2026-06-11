<?php
/**
 * Time API — approval-channel mix (CFO dashboard sparkline).
 *
 *   GET /modules/time/api/approval_mix.php?weeks=12
 *
 * Reads the `audit_log` rows our `time.entry.approved` emitter writes
 * and rolls them up per ISO-week + per `approved_via` channel
 * (manual / tokenized_client_email / bulk_pre_approved / external_email). The CFO
 * dashboard renders this as a small "Approval Mix" sparkline so CFOs
 * can spot tenants leaning heavily on `bulk_pre_approved` — which
 * skips client validation entirely and is an early-warning signal
 * for collection risk.
 *
 * Response shape:
 *   {
 *     "weeks": ["2025-W50","2025-W51", ...],
 *     "channels": {
 *       "manual":                  [12, 18, 22, ...],
 *       "tokenized_client_email":  [ 4,  5,  6, ...],
 *       "bulk_pre_approved":       [ 0,  3, 14, ...],
 *       "external_email":          [ 2,  1,  3, ...]
 *     },
 *     "totals_by_week": [16, 26, 42, ...],
 *     "totals_by_channel": {"manual": 130, ...},
 *     "grand_total": 264,
 *     "window_weeks": 12,
 *     "last_week_pct": {"manual": 0.524, ...}
 *   }
 *
 * SPEC: §P1.a follow-up — Approval Mix sparkline.
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/time.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
if (api_method() !== 'GET') api_error('Method not allowed', 405);
// time.view is the broadest read permission tenants already hold;
// CFOs always have it. (Same gate the `reports.php` siblings use.)
rbac_legacy_require($user, 'time.view');

// Bounded so a bored operator setting weeks=9999 doesn't pin the
// audit_log scanner. 4 ≤ weeks ≤ 26 covers "last month" through "last
// two quarters" — anything longer is a query the BI layer should run.
$weeks = (int) ($_GET['weeks'] ?? 12);
if ($weeks < 4)  $weeks = 4;
if ($weeks > 26) $weeks = 26;

$tid = currentTenantId();
$cutoff = (new DateTimeImmutable("-{$weeks} weeks"))->format('Y-m-d 00:00:00');

// We bucket on ISO year+week so dashboards render cleanly across
// year boundaries. The `approved_via` value lives inside meta_json
// (the emitter writes it explicitly) — JSON_UNQUOTE for clean
// channel names.
$pdo = getDB();
$stmt = $pdo->prepare(
    "SELECT
        DATE_FORMAT(created_at, '%x-W%v') AS iso_week,
        JSON_UNQUOTE(JSON_EXTRACT(meta_json, '$.approved_via')) AS channel,
        COUNT(*) AS n
       FROM audit_log
      WHERE tenant_id = :t
        AND event     = 'time.entry.approved'
        AND created_at >= :since
      GROUP BY iso_week, channel
      ORDER BY iso_week ASC"
);
$stmt->execute(['t' => (int) $tid, 'since' => $cutoff]);
$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

// Pre-seed the week labels so empty channels still render zero-bars
// (avoids a misleading sparkline where channels visually disappear).
$weekLabels = [];
$cursor = new DateTimeImmutable("-{$weeks} weeks");
for ($i = 0; $i <= $weeks; $i++) {
    $weekLabels[] = $cursor->format('o-\WW');
    $cursor = $cursor->modify('+1 week');
}
$weekLabels = array_values(array_unique($weekLabels));

$knownChannels = ['manual', 'tokenized_client_email', 'bulk_pre_approved', 'external_email'];
$channels = [];
foreach ($knownChannels as $ch) $channels[$ch] = array_fill(0, count($weekLabels), 0);
// Anything new (e.g. a future approval path) lands under '_other' so
// we don't silently drop it.
$channels['_other'] = array_fill(0, count($weekLabels), 0);

$weekIndex = array_flip($weekLabels);
foreach ($rows as $r) {
    $w = (string) $r['iso_week'];
    if (!isset($weekIndex[$w])) continue; // out-of-window safety
    $idx = $weekIndex[$w];
    $ch  = (string) ($r['channel'] ?? '');
    if (!array_key_exists($ch, $channels)) $ch = '_other';
    $channels[$ch][$idx] += (int) $r['n'];
}

// Derived rollups for the tile copy.
$totalsByWeek    = array_fill(0, count($weekLabels), 0);
$totalsByChannel = [];
foreach ($channels as $ch => $series) {
    $totalsByChannel[$ch] = (int) array_sum($series);
    foreach ($series as $i => $v) $totalsByWeek[$i] += (int) $v;
}
$grandTotal = (int) array_sum($totalsByChannel);

// Last-week percentage mix — the headline KPI for collection-risk
// concentration (if last week was 95% bulk_pre_approved, ring the bell).
$lastIdx = max(0, count($weekLabels) - 1);
$lastWeekTotal = max(1, $totalsByWeek[$lastIdx] ?? 0); // avoid div-by-zero
$lastWeekPct   = [];
foreach ($channels as $ch => $series) {
    $lastWeekPct[$ch] = round(((int) $series[$lastIdx]) / $lastWeekTotal, 4);
}

api_ok([
    'weeks'             => $weekLabels,
    'channels'          => $channels,
    'totals_by_week'    => $totalsByWeek,
    'totals_by_channel' => $totalsByChannel,
    'grand_total'       => $grandTotal,
    'window_weeks'      => $weeks,
    'last_week_pct'     => $lastWeekPct,
]);
