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
    "INSERT INTO companies (id, tenant_id, name) VALUES (9601, :tenant_id, 'CI Work Recipient')"
)->execute(['tenant_id' => $tenantId]);
$pdo->prepare("INSERT INTO companies (id, tenant_id, name) VALUES (9602, :t, 'CI Billing Client')")
    ->execute(['t' => $tenantId]);
$pdo->prepare("INSERT INTO staffing_clients (id, tenant_id, company_id, name) VALUES (9601, :t, 9602, 'CI Billing Client')")
    ->execute(['t' => $tenantId]);
$pdo->prepare(
    "INSERT INTO placements
        (id, tenant_id, person_id, status, start_date, engagement_type, title,
         end_client_company_id, end_client_name, accounting_entity_id, client_id,
         worksite_state, workers_comp_class, branch)
     VALUES
        (9601, :tenant_id, 9601, 'active', '2026-01-01', 'w2', 'Consultant',
         9601, 'CI Work Recipient', 1, 9601, 'NY', '8810', 'East')"
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
    "INSERT INTO time_periods (id, tenant_id, start_date, end_date, label, status)
     VALUES (9601, :tenant_id, '2026-01-12', '2026-01-18', 'CI submitted approval week', 'open')"
)->execute(['tenant_id' => $tenantId]);
$pdo->prepare(
    "INSERT INTO time_entries
        (id, tenant_id, placement_id, person_id, period_id, timesheet_id,
         work_date, category, hour_type, billable, payable, hours, status,
         source, created_by_user_id)
     VALUES (9601, :tenant_id, 9601, 9601, 9601, 9601,
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
    || (int) ($snapshot['dimensions']['client'] ?? 0) !== 9602
    || ($snapshot['engagement_type'] ?? '') !== 'w2'
    || !hash_equals(
        (string) $approved['dimension_snapshot_hash'],
        hash('sha256', staffingDimensionSnapshotCanonicalJson($snapshot))
    )) {
    throw new RuntimeException('Approval did not freeze the rate and assignment dimensions');
}

$header = $pdo->prepare('SELECT status, artifact_id FROM staffing_timesheets WHERE tenant_id = :t AND id = 9601');
$header->execute(['t' => $tenantId]);
$timesheet = $header->fetch(PDO::FETCH_ASSOC);
if ($timesheet['status'] !== 'approved' || !$timesheet['artifact_id']) {
    throw new RuntimeException('Approved week has no first-class timesheet artifact');
}
$audit = $pdo->prepare(
    "SELECT COUNT(*) FROM audit_log
      WHERE tenant_id = :t AND event = 'staffing.timesheet.approved' AND target_id = 9601"
);
$audit->execute(['t' => $tenantId]);
if ((int) $audit->fetchColumn() !== 1) throw new RuntimeException('Timesheet approval was not audited');
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
    || (float) $payload['cost'] !== 360.0 || ($payload['dimensions']['branch'] ?? '') !== 'East'
    || (int) ($payload['dimensions']['client'] ?? 0) !== 9602) {
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

require_once __DIR__.'/../core/staffing_email_approval.php';
$pdo->prepare("INSERT INTO staffing_timesheets (id,tenant_id,person_id,period_start,period_end,status,total_hours,origin_source,created_by_user_id) VALUES (9602,?,9601,'2026-01-19','2026-01-25','submitted',6,'bulk_upload',2)")->execute([$tenantId]);
$pdo->prepare("INSERT INTO time_periods (id,tenant_id,start_date,end_date,label,status) VALUES (9602,?,'2026-01-19','2026-01-25','CI weekly email review','open')")->execute([$tenantId]);
$pdo->prepare("INSERT INTO time_entries (id,tenant_id,placement_id,person_id,period_id,timesheet_id,work_date,category,hour_type,billable,payable,hours,status,source,created_by_user_id) VALUES (9602,?,9601,9601,9602,9602,'2026-01-20','regular_billable','regular',1,1,6,'pending_review','bulk_upload',2)")->execute([$tenantId]);
$external='independent-weekly-reviewer@example.invalid';
$issued=staffingEmailReviewIssue($tenantId,1,9602,$external);
$review=staffingEmailApprovalReview($issued['token'],'approve');
$result=staffingEmailApprovalConsume($issued['token'],'approve','Reviewed six hours','127.0.0.1',$review['revision']);
if (!$result['ok'] || isset($result['posting_warning'])) throw new RuntimeException('Weekly email approval did not complete its accounting handoff: '.json_encode($result));
$q=$pdo->prepare('SELECT * FROM time_entries WHERE tenant_id=? AND id=9602');$q->execute([$tenantId]);$row=$q->fetch(PDO::FETCH_ASSOC);
$dim=json_decode($row['dimension_snapshot_json'],true,512,JSON_THROW_ON_ERROR);
if ($row['status']!=='approved' || $row['approved_by_user_id']!==null || $row['client_approver_email']!==$external || (int)$row['rate_snapshot_id']!==9601 || $dim['dimensions']['branch']!=='West') throw new RuntimeException('Weekly email approval lost external actor, rate or current dimensions');
$q=$pdo->prepare("SELECT status,journal_entry_id,payload FROM accounting_events WHERE tenant_id=? AND event_type='staffing.worker_hours.approved' AND source_record_id LIKE 'timesheet:9602:placement:9601:w2:segment:%'");
$q->execute([$tenantId]);$events=$q->fetchAll(PDO::FETCH_ASSOC);
if (count($events)!==1 || $events[0]['status']!=='posted' || (int)$events[0]['journal_entry_id']<=0) throw new RuntimeException('Weekly email approval did not post exactly one journal');
$payload=json_decode($events[0]['payload'],true,512,JSON_THROW_ON_ERROR);
if ((float)$payload['hours']!==6.0 || (float)$payload['revenue']!==600.0 || (float)$payload['cost']!==360.0 || $payload['dimensions']['branch']!=='West') throw new RuntimeException('Weekly email journal economics disagree with the approved week');
$repeat=staffingEmailApprovalConsume($issued['token'],'approve','Repeated click','127.0.0.1',$review['revision']);
$q->execute([$tenantId]);
if ($repeat['ok'] || $repeat['status_code']!==409 || $q->fetchAll(PDO::FETCH_ASSOC)!==$events) throw new RuntimeException('Repeated weekly email decision changed its journal');
echo "Weekly email review approved with actual external actor, frozen evidence, one posted journal and unchanged replay.\n";

// No dispatcher can post inside an outer transaction. A crash after commit
// leaves a complete request, whose retry uses the approval-time rate values.
$pdo->prepare("INSERT INTO staffing_timesheets (id,tenant_id,person_id,period_start,period_end,status,total_hours,origin_source,created_by_user_id) VALUES (9603,?,9601,'2026-01-26','2026-02-01','submitted',6,'bulk_upload',2)")->execute([$tenantId]);
$pdo->prepare("INSERT INTO time_periods (id,tenant_id,start_date,end_date,label,status) VALUES (9603,?,'2026-01-26','2026-02-01','CI durable accounting handoff','open')")->execute([$tenantId]);
$pdo->prepare("INSERT INTO time_entries (id,tenant_id,placement_id,person_id,period_id,timesheet_id,work_date,category,hour_type,billable,payable,hours,status,source,created_by_user_id) VALUES (9603,?,9601,9601,9603,9603,'2026-01-27','regular_billable','regular',1,1,6,'pending_review','bulk_upload',2)")->execute([$tenantId]);
$eventCount=static function()use($pdo,$tenantId):int {$q=$pdo->prepare('SELECT COUNT(*) FROM accounting_events WHERE tenant_id=? AND source_record_id LIKE "timesheet:9603:%"');$q->execute([$tenantId]);return (int)$q->fetchColumn();};
foreach(['rollback','commit'] as $decision) {
    $pdo->beginTransaction();
    $result=staffingTimesheetBulkApprove(1,[9603]);
    $handoff=timeAccountingHandoffStatus($tenantId,9603);
    if (!$result['posting_warnings'] || $eventCount()!==0 || !$handoff || $handoff['status']!=='pending') throw new RuntimeException('Nested approval posted early or omitted its durable accounting intent');
    if ($decision==='rollback') {
        $pdo->rollBack();
        $q=$pdo->prepare('SELECT COUNT(*) FROM time_approval_rates WHERE tenant_id=? AND time_entry_id=9603');$q->execute([$tenantId]);
        if ($q->fetchColumn() || timeAccountingHandoffStatus($tenantId,9603)!==null || $eventCount()) throw new RuntimeException('Outer rollback left an archived rate or financial handoff');
    } else $pdo->commit();
}
$pdo->prepare('UPDATE placement_rates SET bill_rate=200,adjusted_bill_rate=200,pay_rate=90 WHERE tenant_id=? AND id=9601')->execute([$tenantId]);
try {
    if (staffingEmitWorkerHoursApprovedEvent($tenantId,9603)!==null) throw new RuntimeException('Retained approval could not recover after the rate changed');
    $q=$pdo->prepare('SELECT payload FROM accounting_events WHERE tenant_id=? AND source_record_id LIKE "timesheet:9603:%"');$q->execute([$tenantId]);$payload=json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
    if ($eventCount()!==1 || (float)$payload['revenue']!==600.0 || (float)$payload['cost']!==360.0) throw new RuntimeException('Retry restated approved economics from the later master rate');
    if (staffingEmitWorkerHoursApprovedEvent($tenantId,9603)!==null || $eventCount()!==1) throw new RuntimeException('Retried handoff duplicated accounting');
} finally {$pdo->prepare('UPDATE placement_rates SET bill_rate=100,adjusted_bill_rate=100,pay_rate=60 WHERE tenant_id=? AND id=9601')->execute([$tenantId]);}
echo "Durable approval handoff rolls back atomically, recovers after interruption at original rates, and posts exactly once.\n";
