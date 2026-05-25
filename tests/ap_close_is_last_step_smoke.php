<?php
/**
 * Smoke — AP cycle inversion fix (mirrors the billing-side fix).
 *
 * Same architectural bug: BillFromTimeBundleModal hard-filtered the
 * period dropdown to `status=closed`, creating a deadlock — you
 * couldn't book a contractor payable until the period was closed, but
 * closing was supposed to come AFTER AR + AP + payroll were booked.
 *
 * Plus a cross-tenant JOIN-drift bug inside apBuildDraftFromBundle()
 * for the same reason as placement detail / staffing readiness /
 * time entries: the `placements` + `people` JOINs bound to
 * `tdf.tenant_id` (the bundle's tenant). Sub-tenants in `'shared'`
 * mode store those rows under the parent → JOIN missed → bills built
 * with NULL placement title + worker name.
 *
 * Payroll was investigated and confirmed clean: `payroll/api/runs.php`
 * and `payroll/api/preflight.php` source hours from their own
 * `payroll_pay_periods`, not from `time_periods`. No deadlock.
 *
 * Fixes locked in:
 *   - ap/lib/ap.php: require_once core/sub_tenants.php; bind
 *     placements_tid + people_tid via effectiveTenantIdForModule().
 *   - ap/ui/BillFromTimeBundleModal.jsx: drop status=closed URL
 *     filter, default-select most recent OPEN period, surface a
 *     "Build bundles for this period" CTA, disable Create when
 *     closed period selected.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

$apLib   = (string) file_get_contents('/app/modules/ap/lib/ap.php');
$apModal = (string) file_get_contents('/app/modules/ap/ui/BillFromTimeBundleModal.jsx');

echo "\n1. ap/lib/ap.php — cross-tenant JOIN drift fixed\n";
$a('lib loads core/sub_tenants.php',
   str_contains($apLib, "require_once __DIR__ . '/../../../core/sub_tenants.php';"));
$a('placements JOIN binds :placements_tid',
   str_contains($apLib, 'LEFT JOIN placements p ON p.id = tdf.placement_id AND p.tenant_id = :placements_tid'));
$a('people JOIN binds :people_tid',
   str_contains($apLib, 'LEFT JOIN people pe ON pe.id = p.person_id AND pe.tenant_id = :people_tid'));
$a('placements tenant resolved via effectiveTenantIdForModule',
   str_contains($apLib, "'placements_tid' => effectiveTenantIdForModule('placements') ?? currentTenantId()"));
$a('people tenant resolved via effectiveTenantIdForModule',
   str_contains($apLib, "'people_tid'     => effectiveTenantIdForModule('people')     ?? currentTenantId()"));
$a('old t.tenant_id-style binds removed',
   !preg_match('/p\.tenant_id = tdf\.tenant_id/', $apLib)
   && !preg_match('/pe\.tenant_id = p\.tenant_id/', $apLib));

echo "\n2. ap/ui/BillFromTimeBundleModal — no longer forces closed period\n";
$a('useApi path drops status=closed filter',
   str_contains($apModal, "'/modules/time/api/periods.php?per_page=20'")
   && !str_contains($apModal, '?status=closed'));
$a('default-selects most recent OPEN period',
   str_contains($apModal, "rows.find(p => p.status === 'open') || rows[0]"));
$a('header copy frames close as accrual-side lock (not "last step")',
   str_contains($apModal, 'Closing a time period locks the <strong>accrual</strong>'));
$a('Create-bills button NOT disabled merely because period is closed (drafts allowed; GL posting is the real gate)',
   str_contains($apModal, 'disabled={busy || !periodId || selected.size === 0}')
   && !str_contains($apModal, 'selected.size === 0 || isClosed'));
$a('build-bundles handler POSTs to shared backend endpoint',
   str_contains($apModal, '/modules/time/api/periods.php?action=build_bundles&id=${periodId}'));
$a('build-bundles CTA gated to non-closed periods with empty bundles',
   str_contains($apModal, '{periodId && bundles.length === 0 && !isClosed && ('))
   && str_contains($apModal, 'data-testid="ap-from-time-build-bundles"');
$a('Create-bills button NOT disabled on closed period',
   !str_contains($apModal, 'disabled={busy || !periodId || selected.size === 0 || isClosed}'));
$a('closed-empty hint nudges toward reopen / manual bill',
   str_contains($apModal, 'data-testid="ap-from-time-closed-empty"')
   && str_contains($apModal, 'reopen the period to rebuild bundles, or post a manual bill'));

echo "\n3. Payroll — confirmed clean (no time_period gating)\n";
$payrollRuns = (string) file_get_contents('/app/modules/payroll/api/runs.php');
$payrollPref = (string) file_get_contents('/app/modules/payroll/api/preflight.php');
$a('payroll/api/runs.php does NOT gate on time_periods.status',
   !preg_match('/time_periods.*?status.*?(open|locked|closed)/s', $payrollRuns));
$a('payroll/api/preflight.php does NOT gate on time_periods.status',
   !preg_match('/time_periods.*?status.*?(open|locked|closed)/s', $payrollPref));

echo "\n4. PHP syntax\n";
$out = []; $rc = 0;
exec('php -l /app/modules/ap/lib/ap.php 2>&1', $out, $rc);
$a("php -l /app/modules/ap/lib/ap.php", $rc === 0, implode("\n", $out));

echo "\n=========================================\n";
echo "AP close-is-last-step + JOIN drift smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
