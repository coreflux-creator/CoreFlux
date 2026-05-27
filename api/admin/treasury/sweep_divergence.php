<?php
/**
 * /api/admin/treasury/sweep_divergence.php — in-app divergence alert
 * surface for the Treasury Sweep engine.
 *
 * Where the cron at `/app/cron/treasury_sweep_divergence_alert.php`
 * emails a daily summary, this endpoint exposes the SAME signal
 * inside the dashboard so operators see divergence without waiting
 * for tomorrow's email.
 *
 *   GET /api/admin/treasury/sweep_divergence.php[?hours=24]
 *     →
 *     {
 *       window_hours: 24,
 *       totals: {
 *         total_runs, swept, under_floor, skipped, failed,
 *         dry_run_count, live_count,
 *         total_swept_cents_live, total_planned_cents_dryrun,
 *         divergence_count
 *       },
 *       alerts: [
 *         { id, rule_id, rule_name, ran_at, outcome, dry_run,
 *           severity ("error" | "warn" | "info"),
 *           message, source_balance_cents, sweep_amount_cents,
 *           error_message, payment_instruction_id }
 *       ],
 *       live_mode: bool
 *     }
 *
 * Divergence definition (matches the email driver):
 *   - outcome="failed"               → severity=error  (always alert)
 *   - outcome="swept" + dry_run=1    → severity=warn   (planned movement
 *                                                       not yet live —
 *                                                       worth surfacing
 *                                                       so ops can flip
 *                                                       TREASURY_SWEEP_LIVE)
 *   - other outcomes                 → severity=info   (NOT an alert; we
 *                                                       still return them
 *                                                       only when there
 *                                                       are zero alerts so
 *                                                       the UI can show a
 *                                                       "last activity"
 *                                                       row instead of a
 *                                                       blank state)
 *
 * Soft-degrades to an empty payload + `migration_pending` when
 * migration 074 hasn't been applied yet — so the UI banner stays calm.
 *
 * RBAC: accounting.bank.manage (same gate as sweep_rules + sweep_runs).
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

$hours = (int) ($_GET['hours'] ?? 24);
if ($hours < 1)   $hours = 1;
if ($hours > 168) $hours = 168;  // cap at a week — anything wider belongs in SweepRunsFeed

$cutoff = (new DateTimeImmutable("-{$hours} hours"))->format('Y-m-d H:i:s');

$pdo = getDB();
try {
    $stmt = $pdo->prepare(
        'SELECT r.id, r.rule_id, sr.name AS rule_name,
                r.ran_at, r.source_balance_cents, r.sweep_amount_cents,
                r.outcome, r.dry_run, r.payment_instruction_id, r.error_message
           FROM treasury_sweep_runs r
      LEFT JOIN tenant_sweep_rules sr ON sr.id = r.rule_id AND sr.tenant_id = r.tenant_id
          WHERE r.tenant_id = :t AND r.ran_at >= :since
          ORDER BY r.ran_at DESC
          LIMIT 200'
    );
    $stmt->execute(['t' => $tid, 'since' => $cutoff]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {
    api_ok([
        'window_hours'      => $hours,
        'totals'            => [
            'total_runs' => 0, 'swept' => 0, 'under_floor' => 0,
            'skipped' => 0, 'failed' => 0,
            'dry_run_count' => 0, 'live_count' => 0,
            'total_swept_cents_live'     => 0,
            'total_planned_cents_dryrun' => 0,
            'divergence_count'           => 0,
        ],
        'alerts'            => [],
        'live_mode'         => function_exists('treasurySweepLiveModeEnabled')
                                ? treasurySweepLiveModeEnabled() : false,
        'migration_pending' => true,
        'migration_error'   => $e->getMessage(),
    ]);
}

$totals = [
    'total_runs'                 => count($rows),
    'swept'                      => 0,
    'under_floor'                => 0,
    'skipped'                    => 0,
    'failed'                     => 0,
    'dry_run_count'              => 0,
    'live_count'                 => 0,
    'total_swept_cents_live'     => 0,
    'total_planned_cents_dryrun' => 0,
    'divergence_count'           => 0,
];

$alerts = [];

foreach ($rows as $r) {
    $outcome = (string) ($r['outcome'] ?? '');
    $dryRun  = (int) ($r['dry_run'] ?? 0) === 1;
    $amount  = (int) ($r['sweep_amount_cents'] ?? 0);

    if (isset($totals[$outcome])) $totals[$outcome]++;
    $totals[$dryRun ? 'dry_run_count' : 'live_count']++;
    if ($outcome === 'swept') {
        if ($dryRun) $totals['total_planned_cents_dryrun'] += $amount;
        else         $totals['total_swept_cents_live']     += $amount;
    }

    $severity = null;
    $message  = null;
    if ($outcome === 'failed') {
        $severity = 'error';
        $message  = 'Sweep failed: ' . (string) ($r['error_message'] ?? 'unknown error');
    } elseif ($outcome === 'swept' && $dryRun) {
        $severity = 'warn';
        $message  = 'Dry-run sweep planned ($' . number_format($amount / 100, 2)
                  . ') — flip TREASURY_SWEEP_LIVE=1 to go live.';
    }

    if ($severity !== null) {
        $totals['divergence_count']++;
        $alerts[] = [
            'id'                     => (int) $r['id'],
            'rule_id'                => (int) $r['rule_id'],
            'rule_name'              => (string) ($r['rule_name'] ?? ('rule #' . (int) $r['rule_id'])),
            'ran_at'                 => (string) $r['ran_at'],
            'outcome'                => $outcome,
            'dry_run'                => $dryRun,
            'severity'               => $severity,
            'message'                => $message,
            'source_balance_cents'   => (int) ($r['source_balance_cents'] ?? 0),
            'sweep_amount_cents'     => $amount,
            'error_message'          => (string) ($r['error_message'] ?? ''),
            'payment_instruction_id' => $r['payment_instruction_id'] !== null
                                         ? (int) $r['payment_instruction_id'] : null,
        ];
    }
}

api_ok([
    'window_hours' => $hours,
    'totals'       => $totals,
    'alerts'       => $alerts,
    'live_mode'    => function_exists('treasurySweepLiveModeEnabled')
                        ? treasurySweepLiveModeEnabled() : false,
]);
