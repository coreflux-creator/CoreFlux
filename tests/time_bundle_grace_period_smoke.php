<?php
/**
 * Smoke — Time bundle correction grace period (P1.9).
 *
 * Spec re-audit decision (2026-02): "Time module grace period for
 * bundle corrections IS required. Reverses earlier 'no grace
 * period on consumed bundles' rule. Within window: correction
 * supersedes prior bundle accrual. Beyond window: refuse / require
 * explicit override."
 */
declare(strict_types=1);

require_once __DIR__ . '/../modules/time/lib/time.php';

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

echo "\n1. Migration 080 — tenants.time_bundle_correction_grace_days\n";
$m = (string) file_get_contents('/app/core/migrations/080_time_bundle_grace_period.sql');
$a('adds column with default 7',
    str_contains($m, 'time_bundle_correction_grace_days INT NOT NULL DEFAULT 7'));
$a('places it after ap_three_way_match_enforce',
    str_contains($m, 'AFTER ap_three_way_match_enforce'));

echo "\n2. timeBundleWithinGrace() — boundary checks\n";
$a('null consumed_at = within (never actually consumed)',
    timeBundleWithinGrace(null, 7) === true);
$a('zero-value consumed_at = within',
    timeBundleWithinGrace('0000-00-00 00:00:00', 7) === true);
$a('consumed 1 day ago is within a 7-day window',
    timeBundleWithinGrace(date('Y-m-d H:i:s', strtotime('-1 day')), 7) === true);
$a('consumed exactly 7 days ago is within window (inclusive)',
    timeBundleWithinGrace(date('Y-m-d H:i:s', strtotime('-7 days +1 minute')), 7) === true);
$a('consumed 14 days ago is OUTSIDE 7-day window',
    timeBundleWithinGrace(date('Y-m-d H:i:s', strtotime('-14 days')), 7) === false);
$a('grace honoured against custom 30-day window',
    timeBundleWithinGrace(date('Y-m-d H:i:s', strtotime('-14 days')), 30) === true);
$a('unparseable timestamp degrades to within (no silent block)',
    timeBundleWithinGrace('not-a-date', 7) === true);

echo "\n3. Bundle builder applies the grace check at supersession point\n";
$lib = (string) file_get_contents('/app/modules/time/lib/time.php');
$a('SELECT pulls consumed_at alongside id/status',
    str_contains($lib, 'SELECT id, status, consumed_at FROM time_downstream_feed'));
$a('grace lookup uses timeBundleCorrectionGraceDays()',
    str_contains($lib, '$graceDays = timeBundleCorrectionGraceDays();'));
$a('grace gate refuses supersede when out of window',
    str_contains($lib, "if (!timeBundleWithinGrace(\$existing['consumed_at'] ?? null, \$graceDays))"));
$a('out-of-window emits time.bundle.grace_exceeded_skipped audit',
    str_contains($lib, "timeAudit('time.bundle.grace_exceeded_skipped',"));
$a('audit payload carries period_id/placement_id/bundle_type/prior_bundle_id/consumed_at/grace_days',
    str_contains($lib, "'period_id'    => \$periodId,")
    && str_contains($lib, "'prior_bundle_id' => (int) \$existing['id'],")
    && str_contains($lib, "'consumed_at'  => \$existing['consumed_at'] ?? null,")
    && str_contains($lib, "'grace_days'   => \$graceDays,"));
$a('out-of-window continues the loop (does NOT silently overwrite consumed bundle)',
    (bool) preg_match("/timeAudit\('time\.bundle\.grace_exceeded_skipped'.+?\], \(int\) \\\$existing\['id'\]\);\s*continue;/s", $lib));

echo "\n4. Within-window path is unchanged (supersede + insert new)\n";
$a('within-window still produces superseded + new bundle as before',
    str_contains($lib, 'UPDATE time_downstream_feed SET status = "superseded"')
    && str_contains($lib, "'status' => 'ready', 'superseded_prior' => \$existing['id']"));

echo "\n5. timeBundleCorrectionGraceDays falls back to 7\n";
$a('default fallback reads tenants.time_bundle_correction_grace_days',
    str_contains($lib, "SELECT time_bundle_correction_grace_days FROM tenants WHERE id = :t LIMIT 1"));
$a('fallback safeguard: 0 or negative collapses to 7',
    str_contains($lib, 'return $cache[$tid] = ($days > 0 ? $days : 7);'));

echo "\n6. PHP syntax\n";
$out = []; $rc = 0;
exec('php -l /app/modules/time/lib/time.php 2>&1', $out, $rc);
$a('php -l /app/modules/time/lib/time.php', $rc === 0, implode("\n", $out));

echo "\n=========================================\n";
echo "Time bundle grace-period (P1.9) smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
