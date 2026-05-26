<?php
/**
 * Treasury Sweep Worker (cron driver).
 *
 * Cron: 30 8 * * * php /home/master/applications/<app>/public_html/cron/treasury_sweep_worker.php
 *  (08:30 daily — earliest a cash-management decision is meaningful;
 *   per-rule schedule filtering lives inside treasurySweepRunAllTenants)
 *
 * Walks every enabled tenant_sweep_rules row, fetches the source
 * account's available balance from Mercury, computes the sweep amount,
 * and records the run in treasury_sweep_runs.
 *
 * Default mode is DRY-RUN: every evaluation lands in
 * treasury_sweep_runs with dry_run=1 and no money moves. Flip
 * TREASURY_SWEEP_LIVE=1 in the environment when the internal-transfer
 * recipient model is wired (see Layer 3c comment in
 * /app/core/treasury_sweep_engine.php).
 *
 * Never calls Mercury synchronously from a user-facing endpoint — all
 * adapter calls live here so the UI stays fast.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/encryption.php';
require_once __DIR__ . '/../core/treasury_sweep_engine.php';
// mercury_payments.php carries mercuryGetConnection() — load it for
// the engine's lazy lookup. treasury_sweep_engine.php has a soft
// function_exists() guard so smoke tests can run without it.
require_once __DIR__ . '/../core/mercury_payments.php';

$now = new \DateTimeImmutable('now');
fwrite(STDOUT, "[treasury_sweep_worker] starting at " . $now->format(\DateTimeImmutable::ATOM) . "\n");
fwrite(STDOUT, "[treasury_sweep_worker] live mode: " . (treasurySweepLiveModeEnabled() ? 'ON' : 'OFF (dry-run)') . "\n");

try {
    $summary = treasurySweepRunAllTenants($now);
} catch (\Throwable $e) {
    fwrite(STDERR, "[treasury_sweep_worker] FATAL: " . $e->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, "[treasury_sweep_worker] rules_seen={$summary['rules_seen']}\n");
foreach (($summary['by_outcome'] ?? []) as $outcome => $n) {
    fwrite(STDOUT, "[treasury_sweep_worker]   {$outcome}: {$n}\n");
}
fwrite(STDOUT, "[treasury_sweep_worker] complete.\n");
exit(0);
