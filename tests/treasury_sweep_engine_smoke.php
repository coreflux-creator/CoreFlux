<?php
/**
 * Smoke — Treasury Sweep Engine (P1.b — execution-time worker).
 *
 * Pure-logic coverage for the three engine layers:
 *   1. Schedule decoder — every frequency variant on every weekday/day.
 *   2. Amount computer  — floor models + edge cases (zero balance,
 *                          negative floor, both floors set, no floor).
 *   3. Live-mode toggle — env-driven, default OFF.
 *
 * Plus source-level wiring assertions for the orchestrator (run rule
 * + cron driver) so the worker boots correctly without invoking a
 * live Mercury connection.
 */
declare(strict_types=1);

require_once '/app/core/treasury_sweep_engine.php';

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

echo "\n1. Schedule decoder: daily\n";
foreach (['2026-02-02', '2026-02-05', '2026-02-15'] as $d) {
    $a("daily fires on {$d}",
       treasurySweepFrequencyDueOn('daily', new DateTimeImmutable($d)));
}

echo "\n2. Schedule decoder: weekly_<dow>\n";
// 2026-02-02 = Monday, 2026-02-06 = Friday, 2026-02-08 = Sunday.
$a('weekly_mon fires on Mon',
   treasurySweepFrequencyDueOn('weekly_mon', new DateTimeImmutable('2026-02-02')));
$a('weekly_mon does NOT fire on Fri',
   !treasurySweepFrequencyDueOn('weekly_mon', new DateTimeImmutable('2026-02-06')));
$a('weekly_fri fires on Fri',
   treasurySweepFrequencyDueOn('weekly_fri', new DateTimeImmutable('2026-02-06')));
$a('weekly_fri does NOT fire on Sat',
   !treasurySweepFrequencyDueOn('weekly_fri', new DateTimeImmutable('2026-02-07')));
$a('weekly_sun fires on Sun',
   treasurySweepFrequencyDueOn('weekly_sun', new DateTimeImmutable('2026-02-08')));
$a('case-insensitive: WEEKLY_FRI on Fri',
   treasurySweepFrequencyDueOn('WEEKLY_FRI', new DateTimeImmutable('2026-02-06')));

echo "\n3. Schedule decoder: monthly_<dom>\n";
$a('monthly_1 fires on 1st',
   treasurySweepFrequencyDueOn('monthly_1', new DateTimeImmutable('2026-02-01')));
$a('monthly_1 does NOT fire on 2nd',
   !treasurySweepFrequencyDueOn('monthly_1', new DateTimeImmutable('2026-02-02')));
$a('monthly_15 fires on 15th',
   treasurySweepFrequencyDueOn('monthly_15', new DateTimeImmutable('2026-02-15')));
$a('monthly_28 fires on 28th (last safe day)',
   treasurySweepFrequencyDueOn('monthly_28', new DateTimeImmutable('2026-02-28')));
$a('monthly_29 REJECTED (Feb ambiguity)',
   !treasurySweepFrequencyDueOn('monthly_29', new DateTimeImmutable('2026-02-28')));
$a('monthly_31 REJECTED',
   !treasurySweepFrequencyDueOn('monthly_31', new DateTimeImmutable('2026-01-31')));

echo "\n4. Schedule decoder: unknown frequency safe-fails to false\n";
$a("'never' returns false",
   !treasurySweepFrequencyDueOn('never', new DateTimeImmutable('2026-02-15')));
$a("'' returns false",
   !treasurySweepFrequencyDueOn('', new DateTimeImmutable('2026-02-15')));
$a("'biweekly_fri' (unsupported) returns false",
   !treasurySweepFrequencyDueOn('biweekly_fri', new DateTimeImmutable('2026-02-06')));

echo "\n5. Amount computer\n";
// Healthy case: balance 100k, floor 50k → sweep 50k.
$a('balance 100_00 with floor 50_00 sweeps 50_00',
   treasurySweepComputeAmount(10000, 5000, null) === 5000);
$a('balance at floor sweeps 0 (skipped_under_floor)',
   treasurySweepComputeAmount(5000, 5000, null) === 0);
$a('balance below floor sweeps 0',
   treasurySweepComputeAmount(3000, 5000, null) === 0);
$a('sweep_above_cents floor model produces same math',
   treasurySweepComputeAmount(10000, null, 5000) === 5000);
$a('both floors set → target_min wins (more conservative)',
   treasurySweepComputeAmount(10000, 6000, 5000) === 4000);
$a('no floor configured → 0 (safety default)',
   treasurySweepComputeAmount(10000, null, null) === 0);
$a('zero balance → 0',
   treasurySweepComputeAmount(0, 5000, null) === 0);
$a('negative balance → 0',
   treasurySweepComputeAmount(-100, 5000, null) === 0);
$a('negative floor coerced to 0 (so balance becomes the sweep)',
   treasurySweepComputeAmount(10000, -50, null) === 10000);

echo "\n6. Live-mode toggle\n";
unset($_ENV['TREASURY_SWEEP_LIVE']);
putenv('TREASURY_SWEEP_LIVE');
$a('live mode OFF by default',
   treasurySweepLiveModeEnabled() === false);
$_ENV['TREASURY_SWEEP_LIVE'] = '1';
$a("'1' → live mode ON",
   treasurySweepLiveModeEnabled() === true);
$_ENV['TREASURY_SWEEP_LIVE'] = 'true';
$a("'true' → ON",
   treasurySweepLiveModeEnabled() === true);
$_ENV['TREASURY_SWEEP_LIVE'] = 'off';
$a("'off' → OFF",
   treasurySweepLiveModeEnabled() === false);
$_ENV['TREASURY_SWEEP_LIVE'] = '0';
$a("'0' → OFF",
   treasurySweepLiveModeEnabled() === false);
unset($_ENV['TREASURY_SWEEP_LIVE']);

echo "\n7. Orchestrator + cron driver wiring (source-level)\n";
$eng = (string) file_get_contents('/app/core/treasury_sweep_engine.php');
$cron = (string) file_get_contents('/app/cron/treasury_sweep_worker.php');

$a('engine declares treasurySweepRunRule',
   function_exists('treasurySweepRunRule'));
$a('engine declares treasurySweepRecordRun',
   function_exists('treasurySweepRecordRun'));
$a('engine declares treasurySweepRunAllTenants',
   function_exists('treasurySweepRunAllTenants'));

$a('engine short-circuits disabled rules with skipped_disabled',
   str_contains($eng, "'skipped_disabled'")
   && str_contains($eng, '(int) ($rule[\'enabled\'] ?? 0) !== 1'));
$a('engine short-circuits frequency mismatch with skipped_not_due',
   str_contains($eng, "'skipped_not_due'")
   && str_contains($eng, 'treasurySweepFrequencyDueOn((string) $rule[\'frequency\']'));
$a('engine short-circuits missing connection with failed_no_connection',
   str_contains($eng, "'failed_no_connection'"));
$a('engine prefers availableBalance over currentBalance',
   str_contains($eng, "\$acct['availableBalance'] ?? \$acct['currentBalance']"));
$a('engine catches Mercury fetch failures with failed_balance_fetch',
   str_contains($eng, "'failed_balance_fetch'"));
$a('engine records skipped_under_floor when sweep amount is 0',
   str_contains($eng, "'skipped_under_floor'"));
$a('dry-run path records outcome=swept with dry_run=true flag',
   str_contains($eng, "treasurySweepRecordRun(\$tenantId, \$ruleId, \$balanceCents, \$sweepCents, 'swept', true);"));
$a('live path stub records failed_execute with explanatory message',
   str_contains($eng, "'failed_execute'")
   && str_contains($eng, 'internal-transfer recipient model not yet wired'));
$a('UPDATE tenant_sweep_rules carries tenant_leak-allow comment',
   (bool) preg_match('/tenant-leak-allow.*\n\s+\$pdo->prepare\(\s*\n\s+\'UPDATE tenant_sweep_rules/s', $eng));
$a('orchestrator caches Mercury tokens per tenant (no re-decrypt)',
   str_contains($eng, '$tokenCache = []')
   && str_contains($eng, '$tokenCache[$tid] = '));

$a('cron driver requires treasury_sweep_engine',
   str_contains($cron, "require_once __DIR__ . '/../core/treasury_sweep_engine.php';"));
$a('cron driver requires mercury_payments for mercuryGetConnection',
   str_contains($cron, "require_once __DIR__ . '/../core/mercury_payments.php';"));
$a('cron driver delegates to treasurySweepRunAllTenants',
   str_contains($cron, 'treasurySweepRunAllTenants($now)'));
$a('cron driver logs live/dry-run mode on startup',
   str_contains($cron, 'live mode: ')
   && str_contains($cron, 'treasurySweepLiveModeEnabled()'));
$a('cron driver swallows fatal exceptions cleanly',
   str_contains($cron, '} catch (\Throwable $e) {')
   && str_contains($cron, '[treasury_sweep_worker] FATAL:'));

echo "\n8. Migration 074_treasury_sweep_runs.sql shape\n";
$mig = (string) file_get_contents('/app/core/migrations/074_treasury_sweep_runs.sql');
$a('table has rule_id + ran_at index',
   str_contains($mig, 'KEY idx_sweep_runs_rule   (tenant_id, rule_id, ran_at)'));
$a('outcome column is VARCHAR(48)',
   str_contains($mig, 'outcome                  VARCHAR(48) NOT NULL'));
$a('dry_run defaults to 1 (safe-by-default)',
   str_contains($mig, "dry_run                  TINYINT(1) NOT NULL DEFAULT 1"));
$a('payment_instruction_id is nullable',
   str_contains($mig, 'payment_instruction_id   INT UNSIGNED NULL'));

echo "\n9. PHP syntax\n";
foreach ([
    '/app/core/treasury_sweep_engine.php',
    '/app/cron/treasury_sweep_worker.php',
] as $f) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
    $a("php -l {$f}", $rc === 0, implode("\n", $out));
}

echo "\n=========================================\n";
echo "Treasury sweep engine smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
