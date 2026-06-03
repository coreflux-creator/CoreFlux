<?php
/**
 * tests/approved_hours_ready_tile_mounted_smoke.php
 *
 * Locks the 2026-02 fix for "approved hours aren't available to flow
 * through to AP / Payroll / or billing".
 *
 * The tile component (`ApprovedHoursReadyTile.jsx`) was already built
 * and the aggregator endpoint (`?action=approved_hours_ready`) was
 * already returning billing / ap / payroll buckets — but the tile was
 * NOT mounted anywhere, so the operator could not see those numbers.
 *
 * This smoke locks:
 *   1) The tile is imported + mounted on `BillsList` (AP)            → variant="ap"
 *   2) The tile is imported + mounted on `InvoicesList` (Billing)    → variant="billing"
 *   3) The tile is imported + mounted on `PayrollOverview` (Payroll) → variant="payroll"
 *   4) The aggregator API still exposes `approved_hours_ready` with
 *      the three buckets (billing / ap / payroll).
 *
 * Run:
 *   php -d zend.assertions=1 tests/approved_hours_ready_tile_mounted_smoke.php
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
function ok(string $label, bool $cond): void {
    global $pass, $fail;
    if ($cond) { echo "  \u{2713} {$label}\n"; $pass++; }
    else       { echo "  \u{2717} {$label}\n"; $fail++; }
}
function read(string $rel): string {
    $p = realpath(__DIR__ . '/../' . $rel);
    if (!$p || !is_readable($p)) { fwrite(STDERR, "missing: {$rel}\n"); exit(2); }
    return file_get_contents($p) ?: '';
}

echo "── AP BillsList — approved hours tile ──\n";
$bills = read('modules/ap/ui/BillsList.jsx');
ok('Imports ApprovedHoursReadyTile from staffing/ui',
   str_contains($bills, "from '../../staffing/ui/ApprovedHoursReadyTile'"));
ok('Mounts <ApprovedHoursReadyTile variant="ap" />',
   (bool) preg_match('/<ApprovedHoursReadyTile\s+variant="ap"/', $bills));
ok('Tile pick handler opens BillFromTimeEntriesModal',
   (bool) preg_match('/<ApprovedHoursReadyTile[^>]*onPick=\{\s*\(\)\s*=>\s*setShowFromEntries\(true\)/', $bills));

echo "── Billing InvoicesList — approved hours tile ──\n";
$inv = read('modules/billing/ui/InvoicesList.jsx');
ok('Imports ApprovedHoursReadyTile from staffing/ui',
   str_contains($inv, "from '../../staffing/ui/ApprovedHoursReadyTile'"));
ok('Mounts <ApprovedHoursReadyTile variant="billing" />',
   (bool) preg_match('/<ApprovedHoursReadyTile\s+variant="billing"/', $inv));
ok('Tile pick handler opens InvoiceFromTimeEntriesModal',
   (bool) preg_match('/<ApprovedHoursReadyTile[^>]*onPick=\{\s*\(\)\s*=>\s*setShowEntries\(true\)/', $inv));

echo "── Payroll PayrollOverview — approved hours tile ──\n";
$pr = read('modules/payroll/ui/PayrollOverview.jsx');
ok('Imports ApprovedHoursReadyTile from staffing/ui',
   str_contains($pr, "from '../../staffing/ui/ApprovedHoursReadyTile'"));
ok('Mounts <ApprovedHoursReadyTile variant="payroll" />',
   (bool) preg_match('/<ApprovedHoursReadyTile\s+variant="payroll"/', $pr));
ok('Tile points to pay_periods so operators land on the start-a-run page',
   (bool) preg_match('/<ApprovedHoursReadyTile[^>]*to="\.\.\/pay_periods"/', $pr));

echo "── ApprovedHoursReadyTile component contract ──\n";
$tile = read('modules/staffing/ui/ApprovedHoursReadyTile.jsx');
ok('Component reads /modules/staffing/api/timesheets.php?action=approved_hours_ready',
   str_contains($tile, '?action=approved_hours_ready'));
ok('Component handles all three variants (billing/ap/payroll)',
   str_contains($tile, 'billing:') && str_contains($tile, 'ap:') && str_contains($tile, 'payroll:'));
ok('CTA renders Link when `to` prop given, button otherwise',
   str_contains($tile, "as: Link, to") && str_contains($tile, 'onClick: onPick'));
ok('Per-variant testid `approved-hours-ready-{variant}` present',
   (bool) preg_match('/data-testid=\{`approved-hours-ready-\$\{variant\}`\}/', $tile));

echo "── timesheets.php — approved_hours_ready aggregator endpoint ──\n";
$api = read('modules/staffing/api/timesheets.php');
ok('Endpoint `?action=approved_hours_ready` exists',
   (bool) preg_match("/action\s*===\s*'approved_hours_ready'/", $api));
ok('Returns billing bucket with hours / placements / clients',
   str_contains($api, "'billing' =>") && str_contains($api, "'placements'"));
ok('Returns ap bucket with hours / vendors',
   str_contains($api, "'ap' =>") && str_contains($api, "'vendors'"));
ok('Returns payroll bucket with hours / employees (W-2 filter)',
   str_contains($api, "'payroll' =>") && str_contains($api, "'employees'") && str_contains($api, "UPPER(COALESCE(pe.classification") );

ok('AP bucket dedupes against ap_bill_lines.source_type=time_entry',
   str_contains($api, 'ap_bill_lines') && str_contains($api, "source_type = 'time_entry'"));
ok('Billing bucket dedupes against billing_invoice_lines.source_type=time_entry',
   str_contains($api, 'billing_invoice_lines') && str_contains($api, "source_type = 'time_entry'"));

echo "\n=========================================\n";
echo "Approved-hours-ready tile mount smoke: {$pass} \u{2713} / {$fail} \u{2717}\n";
echo "=========================================\n";
exit($fail > 0 ? 1 : 0);
