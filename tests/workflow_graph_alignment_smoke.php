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
$approval = $c('modules/time/lib/approval_economics.php');
$a('time previews cannot manufacture missing historical approval rates',
    !str_contains($time, 'timeRepairApprovedRateSnapshots')
    && !str_contains($time, 'timeRateSnapshotsById'));
$a('time approval reader verifies original entry facts and retained values',
    str_contains($approval, 'function timeApprovedRatesByEntry')
    && str_contains($approval, 'timeApprovedEntryFactsHash($entry)')
    && str_contains($approval, 'entry_facts_hash'));
$a('time bundle build prices each original-workspace entry independently',
    substr_count($time, 'timeApprovedRatesByEntry($entries, staffingTimeWorkspace())') === 2
    && str_contains($time, 'timeEntrySnapshotFinancialTotals($group, $ratesByEntry)')
    && str_contains($time, 'staffingTimeQuery('));

$entriesApi = $c('modules/time/api/entries.php');
$nativeEntries = $c('modules/time/lib/native_entries.php');
$a('manual time entry placement validation uses placement graph tenant',
    str_contains($entriesApi, 'timeNativeCreate(')
    && str_contains($nativeEntries, "placementIdentityResolve(getDB(),(int)StaffingNativeTimeEdit::owner('placements')"));

$staffingLib = $c('modules/staffing/lib/timesheets.php');
$nativeReview = $c('modules/time/lib/native_review.php');
$a('staffing approval locks rate_snapshot_id per entry',
    str_contains($staffingLib, 'timeNativeApprovalSnapshot(')
    && str_contains($staffingLib, 'timeNativeWriteApproval(')
    && str_contains($nativeReview, 'timeResolveRateSnapshot((int)$entry[\'placement_id\'],(string)$entry[\'work_date\'],$workspace)')
    && str_contains($nativeReview, 'rate_snapshot_id=?')
    && str_contains($nativeReview, 'No approved rate covers this entry.'));
$a('staffing week uses placement graph and posting uses approved archives',
    str_contains($staffingLib, 'p.tenant_id = :placements_tid')
    && str_contains($c('modules/time/lib/accounting_handoff.php'),'timeApprovedRate($entry,$tenantId)'));

$tokens = $c('modules/time/api/approval_tokens.php');
$tokenReview = $c('modules/time/lib/client_token_review.php');
$a('tokenized time approval locks rate snapshots with token tenant',
    str_contains($tokens, 'timeClientTokenRespond(')
    && str_contains($tokenReview, "StaffingNativeTimeEdit::runForWorkspace((int)\$located['tenant_id']")
    && str_contains($tokenReview, 'timeNativeApprovalSnapshot($entry,null)')
    && str_contains($tokenReview, 'timeNativeWriteApproval('));
$a('tokenized placement preview uses placement/people graph tenants',
    str_contains($tokens, 'timeClientTokenReview(')
    && str_contains($tokenReview, "StaffingNativeTimeEdit::owner('placements')")
    && str_contains($tokenReview, "StaffingNativeTimeEdit::owner('people')"));

$billing = $c('modules/billing/lib/billing.php');
$ap = $c('modules/ap/lib/ap.php');
$a('billing from bundles/time entries joins canonical placement and people graphs',
    substr_count($billing, 'p.tenant_id = :placements_tid') >= 2
    && substr_count($billing, 'pe.tenant_id = :people_tid') >= 2
    && str_contains($billing, 'locked bill-rate snapshot'));
$a('billing selected-time and suggestion paths price from locked snapshots',
    !str_contains($billing, 'timeRepairApprovedRateSnapshots')
    && substr_count($billing, 'timeApprovedRatesByEntry') >= 2
    && str_contains($billing, "Entry #{\$e['id']} is approved but has no locked bill-rate snapshot")
    && str_contains($billing, "\$rate['adjusted_bill_rate'] ?? \$rate['bill_rate']"));
$a('AP from bundles/time entries joins canonical placement and people graphs',
    substr_count($ap, 'p.tenant_id = :placements_tid') >= 2
    && substr_count($ap, 'pe.tenant_id = :people_tid') >= 2
    && str_contains($ap, 'locked pay-rate snapshot'));
$a('AP selected-time path prices from locked pay snapshots',
    !str_contains($ap, 'timeRepairApprovedRateSnapshots')
    && str_contains($ap, 'timeApprovedRatesByEntry')
    && str_contains($ap, "Entry #{\$e['id']} is approved but has no locked pay-rate snapshot")
    && str_contains($ap, "\$rate['pay_rate'] ?? 0"));

$settlementCreate = $c('modules/time/lib/settlement_create.php');
$settlementReady = $c('modules/time/lib/settlement.php');
$a('settlement create uses placement graph tenant for placement rows and rates',
    str_contains($settlementCreate, '$placementsTenantId = effectiveTenantIdForModule(\'placements\'')
    && str_contains($settlementCreate, 'p.tenant_id = ?')
    && str_contains($settlementCreate, 'timeApprovedRatesByEntry($entries, $tenantId)'));
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
$placementTimeReader = $c('modules/staffing/lib/placement_timesheets.php');
$classification = $c('modules/staffing/api/classification_mix.php');
$a('staffing pickers and ready tiles consume people/placement graph tenants',
    str_contains($staffingApi, 'pl.tenant_id = :placements_tid')
    && str_contains($staffingApi, 'p.tenant_id = :people_tid')
    && str_contains($staffingApi, 'staffingPlacementTimesheets($tenantId,')
    && str_contains($placementTimeReader, "companyRelationshipScope(\$pdo,\$workspace,'people')")
    && str_contains($placementTimeReader, "companyRelationshipScope(\$pdo,\$workspace,'placements')")
    && str_contains($placementTimeReader, 'pl.tenant_id=:owner')
    && str_contains($placementTimeReader, 'p.tenant_id=:people_owner'));
$a('classification mix reads engagement type from placement graph',
    str_contains($classification, 'pl.tenant_id = :placements_tid')
    && str_contains($classification, 'p.tenant_id = :people_tid'));

$payrollPreflight = $c('modules/payroll/api/preflight.php');
$a('payroll preflight maps employee to shared person before placement-rate check',
    str_contains($payrollPreflight, 'employee_user_id')
    && str_contains($payrollPreflight, 'LOWER(email_primary)')
    && str_contains($payrollPreflight, 'pl.person_id IN (')
    && str_contains($payrollPreflight, 'pr.tenant_id = :placements_rate_tid')
    && str_contains($payrollPreflight, 'pl.tenant_id = :placements_record_tid'));

exit($fail ? 1 : 0);
