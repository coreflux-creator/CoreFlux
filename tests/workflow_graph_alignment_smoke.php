<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$fail = 0;
$a = function (string $label, bool $ok) use (&$fail): void {
    echo ($ok ? "OK  " : "FAIL ") . $label . PHP_EOL;
    if (!$ok) $fail++;
};
$c = fn (string $path): string => (string) file_get_contents($root . '/' . $path);

echo "Workflow graph alignment smoke\n";

$placements = $c('modules/placements/lib/placements.php');
$a('placementCurrentRate reads placement graph tenant explicitly',
    str_contains($placements, 'function placementsGraphTenantId')
    && str_contains($placements, 'effectiveTenantIdForModule(\'placements\', $tenantId)')
    && str_contains($placements, '$stmt = $pdo->prepare(')
    && str_contains($placements, 'FROM placement_rates'));

$time = $c('modules/time/lib/time.php');
$a('timeResolveRateSnapshot accepts token/session tenant and reads placement graph',
    str_contains($time, 'function timeResolveRateSnapshot(int $placementId, string $workDate, ?int $tenantId = null)')
    && str_contains($time, '$tenantId = timePlacementGraphTenantId($tenantId);')
    && str_contains($time, 'AND approved_at IS NOT NULL'));
$a('time repairs legacy approved rows missing rate snapshots',
    str_contains($time, 'function timeRepairApprovedRateSnapshots')
    && str_contains($time, "status IN ('approved','locked','billing_ready','payroll_ready')")
    && str_contains($time, 'rate_snapshot_id IS NULL')
    && str_contains($time, 'timeResolveRateSnapshot((int) $row[\'placement_id\']'));
$a('time rate snapshot loader only returns approved placement rates',
    str_contains($time, 'function timeRateSnapshotsById')
    && str_contains($time, 'AND approved_at IS NOT NULL')
    && str_contains($time, 'function timeEntrySnapshotFinancialTotals'));
$a('time bundle build reads placement-rate snapshots from placement graph tenant',
    str_contains($time, "timeRepairApprovedRateSnapshots(currentTenantId(), ['period_id' => \$periodId]")
    && str_contains($time, 'timeEntrySnapshotFinancialTotals($group, $ratesById)')
    && str_contains($time, "'rate_ids' => \$calc['rate_ids']"));

$entriesApi = $c('modules/time/api/entries.php');
$a('manual time entry placement validation uses placement graph tenant',
    str_contains($entriesApi, 'effectiveTenantIdForModule(\'placements\'')
    && str_contains($entriesApi, 'WHERE tenant_id = :placements_tid AND id = :id'));

$staffingLib = $c('modules/staffing/lib/timesheets.php');
$a('staffing approval locks rate_snapshot_id per entry',
    str_contains($staffingLib, 'timeResolveRateSnapshot((int) $entry[\'placement_id\'], (string) $entry[\'work_date\'])')
    && str_contains($staffingLib, 'rate_snapshot_id = :rid')
    && str_contains($staffingLib, 'has no approved rate covering'));
$a('staffing week and posting event use placement graph joins',
    str_contains($staffingLib, 'p.tenant_id = :placements_tid')
    && str_contains($staffingLib, 'pl.tenant_id = :placements_tid'));

$tokens = $c('modules/time/api/approval_tokens.php');
$a('tokenized time approval locks rate snapshots with token tenant',
    str_contains($tokens, 'timeResolveRateSnapshot(')
    && str_contains($tokens, '(int) $row[\'tenant_id\']')
    && str_contains($tokens, 'rate_snapshot_id = :rate_snapshot_id'));
$a('tokenized placement preview uses placement/people graph tenants',
    str_contains($tokens, 'effectiveTenantIdForModule(\'placements\', (int) $row[\'tenant_id\'])')
    && str_contains($tokens, 'effectiveTenantIdForModule(\'people\', (int) $row[\'tenant_id\'])')
    && str_contains($tokens, 'pe.tenant_id = :people_tid'));

$billing = $c('modules/billing/lib/billing.php');
$ap = $c('modules/ap/lib/ap.php');
$a('billing from bundles/time entries joins canonical placement and people graphs',
    substr_count($billing, 'p.tenant_id = :placements_tid') >= 2
    && substr_count($billing, 'pe.tenant_id = :people_tid') >= 2
    && str_contains($billing, 'locked bill-rate snapshot'));
$a('billing selected-time and suggestion paths price from locked snapshots',
    substr_count($billing, 'timeRepairApprovedRateSnapshots') >= 2
    && substr_count($billing, 'timeRateSnapshotsById') >= 2
    && str_contains($billing, "Entry #{\$e['id']} is approved but has no locked bill-rate snapshot")
    && str_contains($billing, "\$rate['adjusted_bill_rate'] ?? \$rate['bill_rate']"));
$a('AP from bundles/time entries joins canonical placement and people graphs',
    substr_count($ap, 'p.tenant_id = :placements_tid') >= 2
    && substr_count($ap, 'pe.tenant_id = :people_tid') >= 2
    && str_contains($ap, 'locked pay-rate snapshot'));
$a('AP selected-time path prices from locked pay snapshots',
    str_contains($ap, 'timeRepairApprovedRateSnapshots')
    && str_contains($ap, 'timeRateSnapshotsById')
    && str_contains($ap, "Entry #{\$e['id']} is approved but has no locked pay-rate snapshot")
    && str_contains($ap, "\$rate['pay_rate'] ?? 0"));

$settlementCreate = $c('modules/time/lib/settlement_create.php');
$settlementReady = $c('modules/time/lib/settlement.php');
$a('settlement create uses placement graph tenant for placement rows and rates',
    str_contains($settlementCreate, '$placementsTenantId = effectiveTenantIdForModule(\'placements\'')
    && str_contains($settlementCreate, 'p.tenant_id = ?')
    && str_contains($settlementCreate, 'timeRateSnapshotsById(array_column($entries, \'rate_snapshot_id\')'));
$a('settlement readiness uses placement graph tenant for cycle metadata',
    str_contains($settlementReady, 'placements_tid')
    && str_contains($settlementReady, 'p.tenant_id = :placements_tid'));
$a('settlement extract/create refuse approved rows without locked snapshots',
    str_contains($settlementReady, 'rate_snapshot_id')
    && str_contains($settlementCreate, 'is approved but has no locked rate snapshot')
    && str_contains($settlementCreate, 'INSERT INTO ap_bill_lines'));

$reports = [
    $c('modules/reports/api/client_profitability.php'),
    $c('modules/reports/api/rate_spread.php'),
    $c('modules/reports/api/overtime_watch.php'),
];
$a('profitability reports join placement/people graphs explicitly',
    str_contains($reports[0], 'pl.tenant_id = :placements_tid')
    && str_contains($reports[1], 'pl.tenant_id = :placements_tid')
    && str_contains($reports[1], 'pe.tenant_id = :people_tid')
    && str_contains($reports[2], 'pe.tenant_id = :people_tid')
    && str_contains($reports[2], 'pl.tenant_id = :placements_tid'));

$staffingApi = $c('modules/staffing/api/timesheets.php');
$classification = $c('modules/staffing/api/classification_mix.php');
$a('staffing pickers and ready tiles consume people/placement graph tenants',
    substr_count($staffingApi, ':placements_tid') >= 4
    && substr_count($staffingApi, ':people_tid') >= 4);
$a('classification mix reads engagement type from placement graph',
    str_contains($classification, 'pl.tenant_id = :placements_tid')
    && str_contains($classification, 'p.tenant_id = :people_tid'));

$payrollPreflight = $c('modules/payroll/api/preflight.php');
$a('payroll preflight maps employee to shared person before placement-rate check',
    str_contains($payrollPreflight, 'employee_user_id')
    && str_contains($payrollPreflight, 'LOWER(email_primary)')
    && str_contains($payrollPreflight, 'pl.person_id IN (')
    && str_contains($payrollPreflight, 'pr.tenant_id = :placements_tid'));

exit($fail ? 1 : 0);
