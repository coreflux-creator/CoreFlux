<?php
/**
 * Smoke — accounting-period flow inversion fix.
 *
 * Operator's call-out (correct accounting-cycle common sense):
 *   "How would an accounting period be closed until we've invoiced,
 *    booked payables, payroll, etc?"
 *
 * The old design chained AR-bundle build to ?action=close which
 * created a deadlock: you couldn't invoice from a period until you
 * closed it, but closing was supposed to be the LAST step (after
 * invoicing, AP, payroll).
 *
 * Fixes locked in:
 *   - New POST /api/time/periods?action=build_bundles&id=N — explicit
 *     bundle build for any open or locked period (closed periods are
 *     immutable: 409). Reuses the existing timeBuildBundlesForPeriod
 *     helper which has always been idempotent.
 *   - InvoiceFromTimeBundleModal drops the `status=closed` URL filter
 *     and now fetches every period. Default-selects the most recent
 *     OPEN period.
 *   - Modal exposes a "Build bundles for this period" CTA whenever an
 *     open/locked period has no ready AR bundles yet.
 *   - Confirm/Create button disabled when the selected period is
 *     CLOSED — closed periods are historical archives, not active
 *     workflow surfaces.
 *   - Header copy explicitly states "Periods don't need to be closed
 *     first — close is the LAST step in the cycle."
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

$periods = (string) file_get_contents('/app/modules/time/api/periods.php');
$modal   = (string) file_get_contents('/app/modules/billing/ui/InvoiceFromTimeBundleModal.jsx');

echo "\n1. Backend — explicit build_bundles endpoint (any open/locked period)\n";
$a('declares action=build_bundles POST handler',
   str_contains($periods, "if (\$method === 'POST' && \$action === 'build_bundles')"));
$a('requires time.period.close permission',
   (bool) preg_match("/action === 'build_bundles'.*?rbac_legacy_require\(\\\$user, 'time\\.period\\.close'\)/s", $periods));
$a('rejects closed periods with 409',
   str_contains($periods, "api_error('Period is closed — bundles are immutable. Reopen first if you need to rebuild.', 409);"));
$a('calls timeBuildBundlesForPeriod (idempotent helper)',
   (bool) preg_match("/action === 'build_bundles'.*?timeBuildBundlesForPeriod\(\\\$id\)/s", $periods));
$a('emits time.period.bundles_rebuilt audit',
   str_contains($periods, "timeAudit('time.period.bundles_rebuilt'"));
$a('preserves ?action=close handler intact (regression guard)',
   str_contains($periods, "if (\$method === 'POST' && \$action === 'close')")
   && str_contains($periods, "'status'           => 'closed',"));
$a('rationale comment names the deadlock',
   str_contains($periods, "deadlock"));

echo "\n2. Frontend — modal no longer forces closed periods\n";
$a('useApi path drops the status=closed filter',
   str_contains($modal, "'/modules/time/api/periods.php?per_page=20'"));
$a('drops the prior `status=closed` URL filter completely',
   !str_contains($modal, '?status=closed'));
$a('default-selects the most recent OPEN period',
   str_contains($modal, "const firstOpen = rows.find(p => p.status === 'open') || rows[0];")
   && str_contains($modal, 'setPeriodId(String(firstOpen.id));'));
$a('header copy explicitly debunks the "must be closed first" assumption',
   str_contains($modal, "close is the LAST step in the cycle"));
$a('period dropdown shows status next to each entry',
   str_contains($modal, '· {p.status}'));
$a('period meta row shows status badge',
   str_contains($modal, 'data-testid="billing-from-time-period-meta"')
   && str_contains($modal, 'periodStatusBadge(selectedPeriod.status)'));

echo "\n3. Frontend — explicit build-bundles affordance when empty\n";
$a('Build bundles CTA renders when periodId set, bundles empty, NOT closed',
   str_contains($modal, '{periodId && bundles.length === 0 && !isClosed && ('))
   && str_contains($modal, 'data-testid="billing-from-time-build-bundles"');
$a('buildBundles handler POSTs to new backend endpoint',
   str_contains($modal, '/modules/time/api/periods.php?action=build_bundles&id=${periodId}'));
$a('handler reloads bundles after build (no full reload)',
   str_contains($modal, 'await loadBundles(periodId);'));
$a('info banner reports built count',
   str_contains($modal, 'Built ${r.bundles_built} bundle'));

echo "\n4. Frontend — closed periods are read-only archives\n";
$a('isClosed flag derived from selected period',
   str_contains($modal, "const isClosed = selectedPeriod?.status === 'closed';"));
$a('Create-drafts button disabled when isClosed',
   str_contains($modal, 'disabled={busy || !periodId || selected.size === 0 || isClosed}'));
$a('closed-empty hint nudges toward reopen / manual invoice',
   str_contains($modal, 'data-testid="billing-from-time-closed-empty"')
   && str_contains($modal, 'Closed periods are immutable'));

echo "\n5. PHP syntax\n";
$out = []; $rc = 0;
exec('php -l /app/modules/time/api/periods.php 2>&1', $out, $rc);
$a("php -l /app/modules/time/api/periods.php", $rc === 0, implode("\n", $out));

echo "\n=========================================\n";
echo "Period close-is-last-step smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
