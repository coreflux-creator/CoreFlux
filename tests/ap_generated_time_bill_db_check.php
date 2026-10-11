<?php
/** Exercise real time-to-AP draft builders in the disposable, fully migrated CI database. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'coreaccounting'
    || getenv('COREFLUX_STANDALONE_DATABASE') !== 'coreaccounting_ci'
    || !in_array('--confirm-disposable-fixture', $argv, true)) {
    fwrite(STDERR, "Disposable CoreAccounting CI database only.\n");
    exit(2);
}

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../modules/ap/lib/ap.php';
require_once __DIR__ . '/../modules/staffing/lib/timesheets.php';

$pdo = getDB();
if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'coreaccounting_ci') {
    throw new RuntimeException('Connected database is not the disposable CI database.');
}
$tenantId = (int) $pdo->query('SELECT MIN(id) FROM tenants')->fetchColumn();
if ($tenantId <= 0) throw new RuntimeException('The CI tenant was not provisioned.');
$GLOBALS['__cf_request_tenant_id'] = $tenantId;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS {$message}\n";
};
$cycleColumns = $pdo->query(
    "SELECT COLUMN_NAME FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'placements'
        AND COLUMN_NAME IN ('client_bill_cycle', 'vendor_pay_cycle')"
)->fetchAll(PDO::FETCH_COLUMN);
$assert(count($cycleColumns) === 2, 'clean-install placements support billing and vendor cadence defaults');

$pdo->beginTransaction();
try {
    $name = 'CI Approved Time Worker';
    $pdo->prepare(
        'INSERT INTO ap_vendors_index (tenant_id, vendor_name, vendor_type, requires_1099)
         VALUES (:tenant_id, :name, "1099_individual", 0)'
    )->execute(['tenant_id' => $tenantId, 'name' => $name]);
    $vendorId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        'INSERT INTO people (tenant_id, first_name, last_name, email_primary, classification)
         VALUES (:tenant_id, "CI Approved Time", "Worker", "ci-approved-time@example.invalid", "1099")'
    )->execute(['tenant_id' => $tenantId]);
    $personId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO placements (tenant_id, person_id, status, start_date, engagement_type, title)
         VALUES (:tenant_id, :person_id, "active", "2026-10-01", "1099", "CI rate correction")'
    )->execute(['tenant_id' => $tenantId, 'person_id' => $personId]);
    $placementId = (int) $pdo->lastInsertId();

    $rateInsert = $pdo->prepare(
        'INSERT INTO placement_rates
            (tenant_id, placement_id, effective_from, bill_rate, pay_rate, approved_at)
         VALUES (:tenant_id, :placement_id, "2026-10-01", 20.0000, :pay_rate, "2026-10-06 12:00:00")'
    );
    $rateIds = [];
    foreach (['10.0000', '12.0000', '1.0050'] as $rate) {
        $rateInsert->execute(['tenant_id' => $tenantId, 'placement_id' => $placementId, 'pay_rate' => $rate]);
        $rateIds[] = (int) $pdo->lastInsertId();
    }

    $timesheet = staffingTimesheetUpsert($personId, '2026-10-05', '2026-10-11', [
        'tenant_id' => $tenantId, 'source' => 'manual_entry',
    ]);
    $timesheetId = (int) $timesheet['id'];
    $assert($timesheetId > 0 && !empty($timesheet['artifact_id']),
        'synthetic approved-time week has a first-class timesheet artifact');
    $pdo->prepare(
        'INSERT INTO time_periods (tenant_id, start_date, end_date, label)
         VALUES (:tenant_id, "2026-10-05", "2026-10-11", "CI approved-time week")'
    )->execute(['tenant_id' => $tenantId]);
    $periodId = (int) $pdo->lastInsertId();

    $entryInsert = $pdo->prepare(
        'INSERT INTO time_entries
            (tenant_id, placement_id, person_id, period_id, timesheet_id, work_date,
             category, hour_type, hours, billable, payable, status, rate_snapshot_id, approved_at)
         VALUES
            (:tenant_id, :placement_id, :person_id, :period_id, :timesheet_id, "2026-10-06",
             "regular_billable", "regular", :hours, 1, 1, "approved", :rate_snapshot_id,
             "2026-10-06 12:00:00")'
    );
    $entryIds = [];
    foreach ([[3, $rateIds[0]], [4, $rateIds[1]], [1, $rateIds[2]], [1, $rateIds[2]]] as [$hours, $rateId]) {
        $entryInsert->execute([
            'tenant_id' => $tenantId, 'placement_id' => $placementId,
            'person_id' => $personId, 'period_id' => $periodId,
            'timesheet_id' => $timesheetId, 'hours' => $hours,
            'rate_snapshot_id' => $rateId,
        ]);
        $entryIds[] = (int) $pdo->lastInsertId();
    }
    $pdo->prepare(
        'UPDATE staffing_timesheets SET status = "approved", total_hours = 9,
                approved_at = "2026-10-06 12:00:00"
          WHERE tenant_id = :tenant_id AND id = :id'
    )->execute(['tenant_id' => $tenantId, 'id' => $timesheetId]);
    staffingTimesheetRecordArtifactEvent(
        $timesheetId, 'timesheet.approved', null, ['source' => 'ci_fixture'], $tenantId
    );

    $drafts = apBuildDraftFromTimeEntries($tenantId, $entryIds, 'per_day');
    $assert(count($drafts) === 1, 'approved entries on one day produce one AP bill');
    $draft = apPrepareGeneratedDraftVendor($tenantId, $drafts[0]);
    $assert((int) $draft['ap_vendor_id'] === $vendorId
        && (string) $draft['bill']['vendor_name'] === $name
        && (string) $draft['bill']['vendor_type'] === '1099_individual',
        'the generated bill resolves the reviewed vendor identity');
    $assert(count($draft['lines']) === 4
        && count(array_unique(array_column($draft['lines'], 'rate_snapshot_id'))) === 3,
        'same-day rate corrections remain separate and cent-sensitive entries do not merge');
    $lineTotal = round(array_sum(array_column($draft['lines'], 'subtotal')), 2);
    $obligationTotal = round(array_sum(array_column($draft['obligations'], 'amount')), 2);
    $assert($lineTotal === 80.02 && $obligationTotal === 80.02
        && round((float) $draft['bill']['total'], 2) === 80.02,
        'AP lines, bill total and per-entry obligations all equal the locked $80.02 cost');
    foreach ($draft['lines'] as $line) {
        $assert((int) $line['is_1099_eligible'] === 0
            && round((float) $line['quantity'] * (float) $line['unit_price'], 2) === (float) $line['subtotal'],
            'each generated line preserves the reviewed 1099 exclusion and its displayed math');
    }
    $assert(array_values(array_unique(array_map('intval', $draft['entry_ids']))) === $entryIds,
        'the generated bill retains all four source time entry IDs');

    $bill = $draft['bill'];
    $bill['tenant_id'] = $tenantId;
    $bill['internal_ref'] = apNextInternalRef($tenantId);
    $bill['bill_number'] = $bill['internal_ref'];
    $billId = scopedInsert('ap_bills', $bill);
    $lineInsert = $pdo->prepare(
        'INSERT INTO ap_bill_lines
            (bill_id, line_no, source_type, item_type, source_ref_id, placement_id, rate_snapshot_id,
             description, quantity, unit, unit_price, subtotal, tax_rate_pct, tax_amount, total,
             gl_expense_account_code, is_1099_eligible)
         VALUES
            (:bill_id, :line_no, :source_type, :item_type, :source_ref_id, :placement_id, :rate_snapshot_id,
             :description, :quantity, :unit, :unit_price, :subtotal, :tax_rate_pct, :tax_amount, :total,
             :gl_expense_account_code, :is_1099_eligible)'
    );
    foreach ($draft['lines'] as $line) {
        unset($line['_entry_ids']);
        $lineInsert->execute(['bill_id' => $billId] + $line);
    }
    $saved = $pdo->prepare(
        'SELECT b.total, b.source, COUNT(l.id) AS line_count,
                SUM(l.subtotal) AS line_total, SUM(l.is_1099_eligible) AS eligible_count
           FROM ap_bills b JOIN ap_bill_lines l ON l.bill_id = b.id
          WHERE b.tenant_id = :tenant_id AND b.id = :id
          GROUP BY b.id'
    );
    $saved->execute(['tenant_id' => $tenantId, 'id' => $billId]);
    $row = $saved->fetch(PDO::FETCH_ASSOC);
    $assert($row && $row['source'] === 'time_entries'
        && (int) $row['line_count'] === 4
        && (float) $row['total'] === 80.02
        && (float) $row['line_total'] === 80.02
        && (int) $row['eligible_count'] === 0,
        'the synthetic generated AP bill persists four correctly classified lines');

    $pdo->prepare(
        'INSERT INTO time_downstream_feed
            (tenant_id, period_id, placement_id, bundle_type, rate_snapshot_id,
             total_hours_billable, total_amount_bill, total_amount_pay, status)
         VALUES (:tenant_id, :period_id, :placement_id, "ap", NULL, 9, 180, 80.02, "ready")'
    )->execute(['tenant_id' => $tenantId, 'period_id' => $periodId, 'placement_id' => $placementId]);
    $bundleDrafts = apBuildDraftFromBundle($tenantId, $periodId, [$placementId]);
    $assert(count($bundleDrafts) === 1, 'the time-bundle builder resolves the same AP recipient');
    $bundleDraft = apPrepareGeneratedDraftVendor($tenantId, $bundleDrafts[0]);
    $assert((int) $bundleDraft['ap_vendor_id'] === $vendorId
        && (int) $bundleDraft['lines'][0]['is_1099_eligible'] === 0
        && round((float) $bundleDraft['bill']['total'], 2) === 80.02,
        'the bundle path also retains the saved vendor exclusion and approved cost');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    unset($GLOBALS['__cf_request_tenant_id']);
}
