<?php
/** MySQL-backed submitted-week approval, snapshot, artifact, and posting test. */
declare(strict_types=1);

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/tenant_scope.php';
require_once __DIR__ . '/../modules/staffing/lib/timesheets.php';
require_once __DIR__ . '/../modules/staffing/lib/posting_rules_seed.php';

$tenantId = (int) (getenv('SIM_TENANT_ID') ?: 0);
if ($tenantId <= 0) throw new RuntimeException('SIM_TENANT_ID is required');
$pdo = getDB();
$sim = $pdo->prepare('SELECT is_simulation FROM tenants WHERE id = :id');
$sim->execute(['id' => $tenantId]);
if ((int) $sim->fetchColumn() !== 1) throw new RuntimeException('Refusing to write to a non-simulation tenant');
setRequestTenantId($tenantId);
staffingSeedPostingRules($tenantId);

$pdo->prepare(
    "INSERT INTO people (id, tenant_id, first_name, last_name, email_primary, classification, entity_id)
     VALUES (9601, :tenant_id, 'Casey', 'Rivera', 'ci-worker@coreflux.test', 'w2', 1)"
)->execute(['tenant_id' => $tenantId]);
$pdo->prepare(
    "INSERT INTO companies (id, tenant_id, name) VALUES (9601, :tenant_id, 'CI Client')"
)->execute(['tenant_id' => $tenantId]);
$pdo->prepare(
    "INSERT INTO placements
        (id, tenant_id, person_id, status, start_date, engagement_type, title,
         end_client_company_id, end_client_name, accounting_entity_id,
         worksite_state, workers_comp_class, branch)
     VALUES
        (9601, :tenant_id, 9601, 'active', '2026-01-01', 'w2', 'Consultant',
         9601, 'CI Client', 1, 'NY', '8810', 'East')"
)->execute(['tenant_id' => $tenantId]);
$pdo->prepare(
    "INSERT INTO placement_rates
        (id, tenant_id, placement_id, effective_from, bill_rate, pay_rate,
         adjusted_bill_rate, approved_at)
     VALUES (9601, :tenant_id, 9601, '2026-01-01', 100, 60, 100, NOW())"
)->execute(['tenant_id' => $tenantId]);
$pdo->prepare(
    "INSERT INTO staffing_timesheets
        (id, tenant_id, person_id, period_start, period_end, status,
         total_hours, origin_source, created_by_user_id)
     VALUES (9601, :tenant_id, 9601, '2026-01-12', '2026-01-18', 'submitted',
             6, 'bulk_upload', 2)"
)->execute(['tenant_id' => $tenantId]);
$pdo->prepare(
    "INSERT INTO time_entries
        (id, tenant_id, placement_id, person_id, period_id, timesheet_id,
         work_date, category, hour_type, billable, payable, hours, status,
         source, created_by_user_id)
     VALUES (9601, :tenant_id, 9601, 9601, 1, 9601,
             '2026-01-13', 'regular_billable', 'regular', 1, 1, 6, 'pending_review',
             'bulk_upload', 2)"
)->execute(['tenant_id' => $tenantId]);

$result = staffingTimesheetBulkApprove(1, [9601]);
if ((int) $result['approved'] !== 1 || $result['posting_warnings']) {
    throw new RuntimeException('Submitted week was not approved and posted: ' . json_encode($result));
}
$entry = $pdo->prepare(
    'SELECT status, rate_snapshot_id, dimension_snapshot_json, dimension_snapshot_hash
       FROM time_entries WHERE tenant_id = :tenant_id AND id = 9601'
);
$entry->execute(['tenant_id' => $tenantId]);
$approved = $entry->fetch(PDO::FETCH_ASSOC);
$snapshotJson = (string) ($approved['dimension_snapshot_json'] ?? '');
$snapshot = json_decode($snapshotJson, true, 512, JSON_THROW_ON_ERROR);
if ($approved['status'] !== 'approved' || (int) $approved['rate_snapshot_id'] !== 9601
    || ($snapshot['dimensions']['branch'] ?? '') !== 'East'
    || ($snapshot['engagement_type'] ?? '') !== 'w2'
    || !hash_equals((string) $approved['dimension_snapshot_hash'], hash('sha256', $snapshotJson))) {
    throw new RuntimeException('Approval did not freeze the rate and assignment dimensions');
}

$header = $pdo->prepare('SELECT status, artifact_id FROM staffing_timesheets WHERE tenant_id = :t AND id = 9601');
$header->execute(['t' => $tenantId]);
$timesheet = $header->fetch(PDO::FETCH_ASSOC);
if ($timesheet['status'] !== 'approved' || !$timesheet['artifact_id']) {
    throw new RuntimeException('Approved week has no first-class timesheet artifact');
}
$event = $pdo->prepare(
    "SELECT status, journal_entry_id, payload FROM accounting_events
      WHERE tenant_id = :t AND event_type = 'staffing.worker_hours.approved'
        AND source_record_id LIKE 'timesheet:9601:placement:9601:w2:segment:%'"
);
$event->execute(['t' => $tenantId]);
$posted = $event->fetchAll(PDO::FETCH_ASSOC);
if (count($posted) !== 1 || $posted[0]['status'] !== 'posted' || (int) $posted[0]['journal_entry_id'] <= 0) {
    throw new RuntimeException('Approval did not post one staffing journal');
}
$payload = json_decode((string) $posted[0]['payload'], true, 512, JSON_THROW_ON_ERROR);
if ((float) $payload['hours'] !== 6.0 || (float) $payload['revenue'] !== 600.0
    || (float) $payload['cost'] !== 360.0 || ($payload['dimensions']['branch'] ?? '') !== 'East') {
    throw new RuntimeException('Approved staffing event has incorrect economics or dimensions');
}

$pdo->prepare("UPDATE placements SET branch = 'West' WHERE tenant_id = :t AND id = 9601")
    ->execute(['t' => $tenantId]);
$warning = staffingEmitWorkerHoursApprovedEvent($tenantId, 9601);
if ($warning !== null) throw new RuntimeException($warning);
$event->execute(['t' => $tenantId]);
if (count($event->fetchAll(PDO::FETCH_ASSOC)) !== 1) {
    throw new RuntimeException('Replaying an approved week after an assignment edit duplicated its journal');
}
echo "Submitted week approved with frozen rate, dimensions, artifact, and one posted journal.\n";
