<?php
/** MySQL-backed approval-to-ledger regression, run only in the CI sim tenant. */
declare(strict_types=1);

require_once __DIR__ . '/../modules/staffing/lib/timesheets.php';
require_once __DIR__ . '/../modules/staffing/lib/posting_rules_seed.php';

$tenantId = (int) (getenv('SIM_TENANT_ID') ?: 0);
if ($tenantId <= 0) throw new RuntimeException('SIM_TENANT_ID is required');
$pdo = getDB();
$sim = $pdo->prepare('SELECT is_simulation FROM tenants WHERE id = :id');
$sim->execute(['id' => $tenantId]);
if ((int) $sim->fetchColumn() !== 1) throw new RuntimeException('Refusing to write to a non-simulation tenant');

staffingSeedPostingRules($tenantId);
$pdo->prepare(
    "INSERT INTO placements (id, tenant_id, person_id, status, start_date, engagement_type, title)
     VALUES (9401, :tenant_id, 9401, 'active', '2026-01-01', 'w2', 'Simulation consultant')"
)->execute(['tenant_id' => $tenantId]);
$pdo->prepare(
    "INSERT INTO placement_rates
        (id, tenant_id, placement_id, effective_from, bill_rate, pay_rate,
         adjusted_bill_rate, approved_at)
     VALUES (9401, :tenant_id, 9401, '2026-01-01', 100, 60, 100, NOW())"
)->execute(['tenant_id' => $tenantId]);
$pdo->prepare(
    "INSERT INTO staffing_timesheets
        (id, tenant_id, person_id, period_start, period_end, status, total_hours)
     VALUES (9401, :tenant_id, 9401, '2026-01-05', '2026-01-11', 'approved', 7)"
)->execute(['tenant_id' => $tenantId]);

$insert = $pdo->prepare(
    "INSERT INTO time_entries
        (id, tenant_id, placement_id, person_id, period_id, timesheet_id,
         work_date, category, hour_type, billable, payable, hours, status,
         rate_snapshot_id, dimension_snapshot_json, dimension_snapshot_hash)
     VALUES
        (:id, :tenant_id, 9401, 9401, 1, 9401,
         :work_date, 'regular_billable', 'regular', 1, 1, :hours, 'approved',
         9401, :snapshot, :snapshot_hash)"
);
foreach ([
    [9401, '2026-01-06', 4, 'East'],
    [9402, '2026-01-07', 3, 'West'],
] as [$id, $workDate, $hours, $branch]) {
    $snapshot = [
        'dimensions' => [
            'client' => 42, 'placement' => 9401, 'worker' => 9401,
            'legal_entity' => 1, 'work_state' => 'NY', 'wc_class' => '8810',
            'branch' => $branch,
        ],
        'vendor_dimension' => null,
        'vendor_economic_party_id' => null,
        'vendor_company_id' => null,
        'vendor_ap_id' => null,
        'event_entity_id' => 1,
        'engagement_type' => 'w2',
        'referral_fee_per_hour' => null,
        'missing' => [],
    ];
    $json = staffingDimensionSnapshotCanonicalJson($snapshot);
    $insert->execute([
        'id' => $id, 'tenant_id' => $tenantId, 'work_date' => $workDate,
        'hours' => $hours, 'snapshot' => $json,
        'snapshot_hash' => hash('sha256', $json),
    ]);
}

// A later master-data edit must not reclassify the already approved week.
$pdo->prepare("UPDATE placements SET engagement_type = 'c2c' WHERE tenant_id = :tenant_id AND id = 9401")
    ->execute(['tenant_id' => $tenantId]);
$error = staffingEmitWorkerHoursApprovedEvent($tenantId, 9401);
if ($error !== null) throw new RuntimeException($error);

$events = $pdo->prepare(
    "SELECT source_record_id, status, journal_entry_id, payload
       FROM accounting_events
      WHERE tenant_id = :tenant_id AND event_type = 'staffing.worker_hours.approved'
        AND source_record_id LIKE 'timesheet:9401:placement:9401:w2:segment:%'"
);
$events->execute(['tenant_id' => $tenantId]);
$rows = $events->fetchAll(PDO::FETCH_ASSOC);
if (count($rows) !== 2) throw new RuntimeException('Two dimension versions must produce two staffing postings');
$byBranch = [];
foreach ($rows as $row) {
    if ($row['status'] !== 'posted' || (int) $row['journal_entry_id'] <= 0) {
        throw new RuntimeException('Approved hours did not post a journal');
    }
    $payload = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);
    $byBranch[(string) $payload['dimensions']['branch']] = $payload;
}
foreach (['East' => [4, 400, 240], 'West' => [3, 300, 180]] as $branch => [$hours, $revenue, $cost]) {
    $payload = $byBranch[$branch] ?? null;
    if (!$payload || $payload['engagement_type'] !== 'w2'
        || (float) $payload['hours'] !== (float) $hours
        || (float) $payload['revenue'] !== (float) $revenue
        || (float) $payload['cost'] !== (float) $cost) {
        throw new RuntimeException("Approved {$branch} dimensions or economics changed");
    }
}

$error = staffingEmitWorkerHoursApprovedEvent($tenantId, 9401);
if ($error !== null) throw new RuntimeException($error);
$events->execute(['tenant_id' => $tenantId]);
if (count($events->fetchAll(PDO::FETCH_ASSOC)) !== 2) {
    throw new RuntimeException('Replaying an approved week duplicated its journal events');
}
echo "Approved staffing dimensions posted and replayed without restatement.\n";
