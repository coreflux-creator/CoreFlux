<?php
/**
 * /api/admin/treasury/sweep_runs.php — read-only audit feed for the
 * Treasury Sweep worker. Powers the "Last N days" tab in
 * SweepRulesAdmin.jsx so operators can validate the engine math
 * before flipping TREASURY_SWEEP_LIVE=1.
 *
 *   GET /api/admin/treasury/sweep_runs.php?days=30&rule_id=42
 *
 * `rule_id` is optional — when omitted, returns runs across all rules.
 * `days` is bounded 1..90 (90 days covers a full quarter; longer
 * windows belong in BI tooling, not this admin tab).
 *
 * Response:
 *   {
 *     "rows": [ {id, rule_id, rule_name, ran_at, source_balance_cents,
 *                sweep_amount_cents, outcome, dry_run,
 *                payment_instruction_id, error_message}, ... ],
 *     "summary": {
 *       "total_runs":  N,
 *       "by_outcome":  {outcome: count},
 *       "dry_run_count":   X,
 *       "live_count":      Y,
 *       "total_swept_cents_live":     SUM(when outcome=swept && !dry_run),
 *       "total_planned_cents_dryrun": SUM(when outcome=swept && dry_run)
 *     },
 *     "live_mode": bool,
 *     "window_days": N
 *   }
 *
 * RBAC: accounting.bank.manage — same gate as the rules CRUD endpoint.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/treasury_sweep_engine.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];
if (api_method() !== 'GET') api_error('Method not allowed', 405);
rbac_legacy_require($user, 'accounting.bank.manage');

$days   = (int) ($_GET['days'] ?? 30);
if ($days < 1)  $days = 1;
if ($days > 90) $days = 90;
$ruleId = isset($_GET['rule_id']) ? (int) $_GET['rule_id'] : 0;

$cutoff = (new DateTimeImmutable("-{$days} days"))->format('Y-m-d 00:00:00');

$pdo = getDB();
$params = ['t' => $tid, 'since' => $cutoff];
$sql = "SELECT r.id, r.rule_id, sr.name AS rule_name,
               r.ran_at, r.source_balance_cents, r.sweep_amount_cents,
               r.outcome, r.dry_run, r.payment_instruction_id, r.error_message
          FROM treasury_sweep_runs r
     LEFT JOIN tenant_sweep_rules sr ON sr.id = r.rule_id AND sr.tenant_id = r.tenant_id
         WHERE r.tenant_id = :t
           AND r.ran_at >= :since";
if ($ruleId > 0) {
    $sql .= " AND r.rule_id = :rid";
    $params['rid'] = $ruleId;
}
$sql .= " ORDER BY r.ran_at DESC LIMIT 500";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {
    // Migration 074 not applied yet → return empty feed gracefully so
    // the UI tab can still render. Matches the SweepRulesAdmin banner
    // pattern that surfaces "migration not applied" without 500-ing.
    api_ok([
        'rows' => [], 'summary' => [
            'total_runs' => 0, 'by_outcome' => [],
            'dry_run_count' => 0, 'live_count' => 0,
            'total_swept_cents_live' => 0, 'total_planned_cents_dryrun' => 0,
        ],
        'live_mode' => treasurySweepLiveModeEnabled(),
        'window_days' => $days,
        'migration_pending' => true,
        'migration_error'   => $e->getMessage(),
    ]);
}

// Rollups
$summary = [
    'total_runs'                 => count($rows),
    'by_outcome'                 => [],
    'dry_run_count'              => 0,
    'live_count'                 => 0,
    'total_swept_cents_live'     => 0,
    'total_planned_cents_dryrun' => 0,
];
foreach ($rows as $r) {
    $o = (string) $r['outcome'];
    $summary['by_outcome'][$o] = ($summary['by_outcome'][$o] ?? 0) + 1;
    if ((int) $r['dry_run'] === 1) {
        $summary['dry_run_count']++;
        if ($o === 'swept') $summary['total_planned_cents_dryrun'] += (int) $r['sweep_amount_cents'];
    } else {
        $summary['live_count']++;
        if ($o === 'swept') $summary['total_swept_cents_live'] += (int) $r['sweep_amount_cents'];
    }
}

api_ok([
    'rows'        => $rows,
    'summary'     => $summary,
    'live_mode'   => treasurySweepLiveModeEnabled(),
    'window_days' => $days,
]);
