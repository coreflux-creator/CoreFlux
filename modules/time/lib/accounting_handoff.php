<?php
/** Durable, private accounting intent from independently approved time. */
declare(strict_types=1);
require_once __DIR__.'/approval_economics.php';

function timeStaffingAccountingEvents(int $tenantId,int $headerId): array
{
    $q=getDB()->prepare('SELECT * FROM staffing_timesheets WHERE tenant_id=? AND id=?');
    $q->execute([$tenantId,$headerId]);$header=$q->fetch(PDO::FETCH_ASSOC);
    if (!$header || !in_array($header['status'],['approved','locked','billing_ready','payroll_ready'],true)) throw new RuntimeException('Only a complete approved week can reach accounting.',409);
    $q=getDB()->prepare('SELECT * FROM time_entries WHERE tenant_id=? AND timesheet_id=? AND status!="superseded" ORDER BY id');
    $q->execute([$tenantId,$headerId]);$entries=$q->fetchAll(PDO::FETCH_ASSOC);
    if (!$entries) throw new RuntimeException('The approved week has no time entries.',409);
    $groups=[];$events=[];
    foreach($entries as $entry) {
        if ((int)$entry['person_id']!==(int)$header['person_id']) throw new RuntimeException('The approved worker changed. Review the original time.',409);
        $rate=timeApprovedRate($entry,$tenantId);
        $snapshot=json_decode($entry['dimension_snapshot_json'],true,512,JSON_THROW_ON_ERROR);
        $key=$entry['placement_id'].':'.$entry['dimension_snapshot_hash'];
        if (!isset($groups[$key])) $groups[$key]=[
            'id'=>$header['id'],'person_id'=>$header['person_id'],'period_start'=>$header['period_start'],'period_end'=>$header['period_end'],
            'placement_id'=>$entry['placement_id'],'dimension_snapshot_hash'=>$entry['dimension_snapshot_hash'],
            'dimension_snapshot_json'=>$entry['dimension_snapshot_json'],'engagement_type'=>$snapshot['engagement_type'],
        ];
        foreach(timeApprovedEntryAmounts($entry,$rate,$snapshot) as $field=>$value) $groups[$key][$field]=($groups[$key][$field]??0)+$value;
    }
        foreach ($groups as $g) {
            $placementId = (int) ($g['placement_id'] ?? 0);
            if ($placementId <= 0) {
                throw new \RuntimeException("Timesheet #{$headerId} has approved hours without a placement.");
            }
            $engagementType = (string) $g['engagement_type'];
            $baseSourceId = 'timesheet:' . (string) $g['id']
                . ':placement:' . $placementId . ':' . $engagementType;
            $snapshotHash = (string) ($g['dimension_snapshot_hash'] ?? '');
            $dimensionContext=json_decode((string)$g['dimension_snapshot_json'],true,512,JSON_THROW_ON_ERROR);
            $eventEntityId = (int) ($dimensionContext['event_entity_id'] ?? 0);
            if ($eventEntityId <= 0) {
                throw new \RuntimeException("Placement #{$placementId} has no valid accounting entity.");
            }
            $blockingMissing = staffingDimensionBlockingMissing($dimensionContext, 'time');
            if ($blockingMissing) {
                throw new \RuntimeException(
                    "Placement #{$placementId} is missing dimensions required for time accounting: "
                    . implode(', ', staffingDimensionMissingLabels($blockingMissing))
                );
            }
            if (in_array($engagementType, ['1099','c2c','referral'], true)
                && empty($dimensionContext['vendor_ap_id'])) {
                throw new \RuntimeException(
                    "Placement #{$placementId} has no AP vendor linked to its primary payable party."
                );
            }
            $rev  = round((float) $g['revenue'], 2);
            $cost = round((float) $g['cost'], 2);
            $wageCost = round((float) ($g['wage_cost'] ?? 0), 2);
            $employerLoadCost = round((float) ($g['employer_load_cost'] ?? 0), 2);
            $workersCompCost = round((float) ($g['workers_comp_cost'] ?? 0), 2);
            $benefitsCost = round((float) ($g['benefits_cost'] ?? 0), 2);
            $otherDirectCost = round((float) ($g['other_direct_cost'] ?? 0), 2);
            if (in_array($engagementType, ['w2','temp_to_perm','internal'], true)) {
                $cost = round(
                    $wageCost + $employerLoadCost + $workersCompCost + $benefitsCost + $otherDirectCost,
                    2
                );
            }
            $events[] = [
                'entity_id'        => $eventEntityId,
                'event_type'       => 'staffing.worker_hours.approved',
                'source_module'    => 'staffing',
                'source_record_id' => $baseSourceId . ($snapshotHash !== ''
                    ? ':segment:' . substr($snapshotHash, 0, 24) : ''),
                'event_date'       => (string) $g['period_end'],
                'payload'          => [
                    'timesheet_id'    => (int) $g['id'],
                    'person_id'       => (int) $g['person_id'],
                    'placement_id'    => $placementId,
                    'period_start'    => $g['period_start'],
                    'period_end'      => $g['period_end'],
                    'engagement_type' => $engagementType,
                    'hours'           => (float) $g['hours'],
                    'revenue'         => $rev,
                    'cost'            => $cost,
                    'wage_cost'       => $wageCost,
                    'employer_load_cost' => $employerLoadCost,
                    'workers_comp_cost' => $workersCompCost,
                    'benefits_cost'   => $benefitsCost,
                    'other_direct_cost' => $otherDirectCost,
                    'gross_profit'    => $rev - $cost,
                    'dimensions'      => $dimensionContext['dimensions'],
                    'vendor_dimension'=> $dimensionContext['vendor_dimension'],
                    'vendor_economic_party_id'=> $dimensionContext['vendor_economic_party_id'],
                    'vendor_company_id'=> $dimensionContext['vendor_company_id'],
                    'vendor_ap_id'     => $dimensionContext['vendor_ap_id'],
                    'dimension_missing'=> $dimensionContext['missing'],
                    // Convenience flags for posting-rule `conditions` matching.
                    'is_w2'           => in_array($engagementType, ['w2','temp_to_perm'], true) ? 1 : 0,
                    'is_1099_or_c2c'  => in_array($engagementType, ['1099','c2c'], true) ? 1 : 0,
                    'is_internal'     => $engagementType === 'internal' ? 1 : 0,
                    'is_referral'     => $engagementType === 'referral' ? 1 : 0,
                ],
            ];
        }

    return $events;
}

/** Approval persists intent, not journals, in its original transaction. */
function timeAccountingHandoffEnqueue(int $tenant,int $headerId): array
{
    if (!StaffingNativeTimeEdit::active() || !getDB()->inTransaction() || staffingTimeWorkspace()!==$tenant) throw new LogicException('Accounting intent requires the original protected time transaction.');
    timeApprovalEconomicsRequireSchema();
    $owner=StaffingNativeTimeEdit::lockOwner('accounting');
    $json=staffingDimensionSnapshotCanonicalJson(timeStaffingAccountingEvents($tenant,$headerId));
    $hash=hash('sha256',$json);
    $q=getDB()->prepare('SELECT * FROM time_accounting_handoffs WHERE tenant_id=? AND timesheet_id=? AND is_current=1 FOR UPDATE');
    $q->execute([$tenant,$headerId]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        if ((int)$row['accounting_tenant_id']!==$owner || !hash_equals($row['events_hash'],$hash) || $row['events_json']!==$json) throw new RuntimeException('This week already has different accounting intent. Use its correction workflow.',409);
        return $row;
    }
    getDB()->prepare('INSERT INTO time_accounting_handoffs (tenant_id,timesheet_id,accounting_tenant_id,events_hash,events_json) VALUES (?,?,?,?,?)')->execute([$tenant,$headerId,$owner,$hash,$json]);
    $q->execute([$tenant,$headerId]);return $q->fetch(PDO::FETCH_ASSOC);
}

/** Idempotent internal-ledger delivery. Never run before an outer approval commits. */
function timeAccountingHandoffDispatch(int $tenant,int $headerId,?string $reviewRevision=null,?int $actor=null): ?string
{
    require_once __DIR__.'/../../staffing/lib/timesheets.php';
    require_once __DIR__.'/../../../core/posting_engine/process.php';
    if (getDB()->inTransaction()) return 'Accounting is queued until the approval transaction commits.';
    try {
        return StaffingNativeTimeEdit::runForWorkspace($tenant,static function()use($tenant,$headerId,$reviewRevision,$actor):?string {
            timeApprovalEconomicsRequireSchema();
            $pdo=getDB();
            $q=$pdo->prepare('SELECT * FROM time_accounting_handoffs WHERE tenant_id=? AND timesheet_id=? AND is_current=1 FOR UPDATE');
            $q->execute([$tenant,$headerId]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if ($reviewRevision!==null && (!$row || !hash_equals(timeAccountingHandoffRevision($row),$reviewRevision))) throw new RuntimeException('The accounting handoff changed. Refresh its status before retrying.',409);
            // Explicit replay of older verified approvals can establish intent.
            // Missing/unverifiable historical rates are refused by the builder.
            if (!$row) $row=timeAccountingHandoffEnqueue($tenant,$headerId);
            $pdo->exec('SAVEPOINT time_accounting_delivery');
            try {
                $owner=StaffingNativeTimeEdit::lockOwner('accounting');
                if ($owner!==(int)$row['accounting_tenant_id']) throw new RuntimeException('Accounting ownership changed. Review the original destination before retrying.',409);
                if (!hash_equals($row['events_hash'],hash('sha256',$row['events_json']))) throw new RuntimeException('The retained accounting intent failed its integrity check.',409);
                $events=timeStaffingAccountingEvents($tenant,$headerId);
                if (!hash_equals($row['events_hash'],hash('sha256',staffingDimensionSnapshotCanonicalJson($events)))) throw new RuntimeException('Approved time differs from its retained accounting intent. Review its correction history.',409);
                foreach($events as $event) {
                    $base=preg_replace('/:segment:[a-f0-9]+$/D','',$event['source_record_id']);
                    $legacy=$pdo->prepare('SELECT id FROM accounting_events WHERE tenant_id=? AND source_module="staffing" AND event_type="staffing.worker_hours.approved" AND source_record_id IN (?,?) AND status="posted"');
                    $legacy->execute([$owner,$headerId.':'.$event['payload']['engagement_type'],$base]);
                    if ($legacy->fetchColumn()) throw new RuntimeException('This week has a legacy aggregate journal. Reconcile that journal before assignment-level posting.',409);
                    $result=accountingProcessEvent($owner,$event,null);
                    if (($result['status']??'')!=='posted') throw new RuntimeException((string)($result['error']??'Approved time was not posted to accounting.'));
                }
                $pdo->prepare('UPDATE time_accounting_handoffs SET status="posted",attempts=attempts+1,last_error=NULL,completed_at=COALESCE(completed_at,NOW()) WHERE tenant_id=? AND id=?')->execute([$tenant,$row['id']]);
                if ($actor!==null) timeNativeRequiredAudit('time.accounting_handoff.retried',$headerId,['handoff_id'=>(int)$row['id'],'events_hash'=>$row['events_hash']],$actor);
                $pdo->exec('RELEASE SAVEPOINT time_accounting_delivery');
                return null;
            } catch(Throwable $e) {
                $pdo->exec('ROLLBACK TO SAVEPOINT time_accounting_delivery');
                $pdo->exec('RELEASE SAVEPOINT time_accounting_delivery');
                $error=substr($e->getMessage(),0,2000);
                $pdo->prepare('UPDATE time_accounting_handoffs SET status="failed",attempts=attempts+1,last_error=? WHERE tenant_id=? AND id=?')->execute([$error,$tenant,$row['id']]);
                if ($actor!==null) timeNativeRequiredAudit('time.accounting_handoff.failed',$headerId,['handoff_id'=>(int)$row['id'],'events_hash'=>$row['events_hash']],$actor);
                return $error;
            }
        });
    } catch(Throwable $e) {
        error_log('[time accounting] Handoff for '.$tenant.':'.$headerId.' could not complete: '.$e->getMessage());
        return $e->getMessage();
    }
}

function timeAccountingHandoffRevision(array $row): string
{
    return hash('sha256',staffingDimensionSnapshotCanonicalJson($row));
}

function timeAccountingHandoffStatus(int $tenant,int $headerId,bool $canReadAccounting=false): ?array
{
    timeApprovalEconomicsRequireSchema();
    $q=getDB()->prepare('SELECT * FROM time_accounting_handoffs WHERE tenant_id=? AND timesheet_id=? ORDER BY id DESC LIMIT 1');
    $q->execute([$tenant,$headerId]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $revision=timeAccountingHandoffRevision($row);
    if (!$canReadAccounting && $row['last_error']!==null) $row['last_error']='Accounting needs attention. Ask an accounting reviewer to inspect this handoff.';
    return array_intersect_key($row,array_flip(['id','timesheet_id','accounting_tenant_id','status','attempts','last_error','created_at','completed_at','cancelled_at','cancelled_by_user_id','cancellation_reason']))+[
        'workspace_id'=>$tenant,'review_revision'=>$revision,
    ];
}

/** Only a reviewed, unconsumed time correction may withdraw an unposted intent.
 * Its immutable payload remains in history; the correction receipt and these
 * changes share the caller's transaction. Callers hold the original owner locks.
 */
function timeAccountingHandoffWithdraw(int $tenant,array $headerIds,int $actor,string $reason): array
{
    $pdo=getDB();
    if (!$pdo->inTransaction() || $tenant<=0 || $actor<=0 || trim($reason)==='') throw new LogicException('A reviewed correction transaction is required.');
    timeApprovalEconomicsRequireSchema();
    $ids=array_values(array_unique(array_filter(array_map('intval',$headerIds))));sort($ids,SORT_NUMERIC);$withdrawn=[];
    foreach($ids as $headerId) {
        $q=$pdo->prepare('SELECT * FROM time_accounting_handoffs WHERE tenant_id=? AND timesheet_id=? AND is_current=1 FOR UPDATE');
        $q->execute([$tenant,$headerId]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if (!$row) continue;
        if (!in_array($row['status'],['pending','failed'],true)) throw new RuntimeException('This week has completed accounting. Use its downstream correction workflow.',409);
        $q=$pdo->prepare('SELECT id FROM accounting_events WHERE tenant_id=? AND source_module="staffing" AND event_type="staffing.worker_hours.approved" AND (source_record_id LIKE ? OR source_record_id LIKE ?) LIMIT 1 FOR UPDATE');
        $q->execute([$row['accounting_tenant_id'],$headerId.':%','timesheet:'.$headerId.':placement:%']);
        if ($q->fetchColumn()) throw new RuntimeException('This week has accounting history. Reconcile it before withdrawing the request.',409);
        $pdo->prepare('UPDATE time_accounting_handoffs SET status="cancelled",is_current=NULL,cancelled_at=NOW(),cancelled_by_user_id=?,cancellation_reason=? WHERE tenant_id=? AND id=? AND is_current=1')->execute([$actor,$reason,$tenant,$row['id']]);
        $withdrawn[]=(int)$row['id'];
    }
    return $withdrawn;
}
