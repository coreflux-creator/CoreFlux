<?php
/** Approval evidence is resolved and written under the original workspace locks. */
declare(strict_types=1);
require_once __DIR__.'/native_submission.php';

function timeNativeApprovalSnapshot(array $entry, ?int $actor): array
{
    if (!StaffingNativeTimeEdit::active()) throw new LogicException('Approval requires a protected time transaction.');
    if ($entry['status'] !== 'pending_review') throw new RuntimeException('Only pending_review entries can be approved.',409);
    if ($actor !== null && ($actor<=0 || (int)($entry['created_by_user_id']??0)===$actor)) {
        throw new RuntimeException('This time requires a different approver; you cannot approve your own entry.',403);
    }
    if ($actor !== null && timePersonIdForUser(['id'=>$actor])===(int)$entry['person_id']) {
        throw new RuntimeException('This time requires a different approver; you cannot approve your own time.',403);
    }
    timeNativeValidateSubmission($entry);
    $workspace=staffingTimeWorkspace();
    $owner=StaffingNativeTimeEdit::lockOwner('accounting');
    $rate=timeResolveRateSnapshot((int)$entry['placement_id'],(string)$entry['work_date'],$workspace);
    if (!$rate) throw new RuntimeException('No approved rate covers this entry. Approve the placement rate first.',422);
    $context=staffingAssignmentDimensionContext($workspace,(int)$entry['placement_id'],0,(string)$entry['work_date']);
    $missing=staffingDimensionBlockingMissing($context,'time');
    if ($missing) throw new RuntimeException('Complete this placement before approving time. Missing: '.implode(', ',staffingDimensionMissingLabels($missing)).'.',422);
    $scope=companyRelationshipScope(getDB(),$workspace,'placements');
    foreach (array_unique(array_filter([(int)($context['placement']['staffing_client_company_id']??0),(int)($context['vendor_company_id']??0)])) as $company) {
        companyRelationshipCurrent(getDB(),$scope,$company);
    }
    $engagement=(string)$context['placement']['engagement_type'];
    $referralFee=null;
    if ($engagement==='referral') {
        $q=getDB()->prepare('SELECT fee_flat FROM placement_referrals WHERE tenant_id=? AND placement_id=? AND fee_basis="per_hour" AND start_date<=? AND (end_date IS NULL OR end_date>=?) ORDER BY id FOR UPDATE');
        $q->execute([StaffingNativeTimeEdit::owner('placements'),$entry['placement_id'],$entry['work_date'],$entry['work_date']]);
        $referralFee=number_format(array_sum(array_map('floatval',$q->fetchAll(PDO::FETCH_COLUMN))),4,'.','');
    }
    $snapshot=[
        'dimensions'=>$context['dimensions'], 'vendor_dimension'=>$context['vendor_dimension']??null,
        'vendor_economic_party_id'=>$context['vendor_economic_party_id']??null,
        'vendor_company_id'=>$context['vendor_company_id']??null, 'vendor_ap_id'=>$context['vendor_ap_id']??null,
        'event_entity_id'=>$context['event_entity_id'], 'engagement_type'=>$engagement,
        'referral_fee_per_hour'=>$referralFee, 'missing'=>$context['missing']??[],
        'source_rate_hash'=>hash('sha256',staffingDimensionSnapshotCanonicalJson($rate)),
    ];
    return ['rate_id'=>(int)$rate['id'],'dimensions'=>$snapshot,
        'entry_revision'=>hash('sha256',staffingDimensionSnapshotCanonicalJson([
            'workspace'=>$workspace,'people_owner'=>StaffingNativeTimeEdit::owner('people'),
            'placement_owner'=>StaffingNativeTimeEdit::owner('placements'),'accounting_owner'=>$owner,
            'entry'=>$entry,'snapshot'=>$snapshot,
        ]))];
}

/** Completing a week also preserves and verifies its already approved siblings. */
function timeNativeApprovalSourceIds(array $entries,?int $actor): array
{
    if (!StaffingNativeTimeEdit::active()) throw new LogicException('A protected approval transaction is required.');
    $selected=array_map('intval',array_column($entries,'id'));$sourceIds=$selected;$headers=[];
    foreach($entries as$entry) if(!empty($entry['timesheet_id'])) $headers[(int)$entry['timesheet_id']]=true;
    foreach(array_keys($headers)as$id) {
        $siblings=staffingTimeQuery('SELECT * FROM time_entries WHERE tenant_id=:tenant_id AND timesheet_id=:id AND status!="superseded" ORDER BY id',['id'=>$id]);
        if (array_filter($siblings,static fn($row)=>$row['status']!=='approved' && !in_array((int)$row['id'],$selected,true))) continue;
        $header=staffingTimesheetRow(staffingTimeWorkspace(),$id,true);
        if (!$header) throw new RuntimeException('The original week is unavailable.',409);
        staffingTimesheetApprovalPlan($actor,$header,staffingTimeWorkspace());
        $sourceIds=[...$sourceIds,...array_map('intval',array_column($siblings,'id'))];
    }
    $sourceIds=array_values(array_unique($sourceIds));sort($sourceIds,SORT_NUMERIC);return $sourceIds;
}

function timeNativeExternalReviewer(array $entry, string $email): void
{
    if (!StaffingNativeTimeEdit::active()) throw new LogicException('An external review requires a protected transaction.');
    if (!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('An identified client approver is required.',422);
    $q=getDB()->prepare('SELECT email_primary FROM people WHERE tenant_id=? AND id=? FOR UPDATE');
    $q->execute([StaffingNativeTimeEdit::owner('people'),$entry['person_id']]);$workerEmail=$q->fetchColumn();
    $q=getDB()->prepare('SELECT email FROM users WHERE id=? FOR UPDATE');$q->execute([(int)($entry['created_by_user_id']??0)]);$creatorEmail=$q->fetchColumn();
    foreach([$workerEmail,$creatorEmail]as$ownEmail) if ($ownEmail && strcasecmp(trim($ownEmail),trim($email))===0) throw new RuntimeException('This time requires a different approver.',403);
}

function timeNativeWriteApproval(array $entry, array $snapshot, ?int $actor, string $via, array $context=[]): array
{
    if (!StaffingNativeTimeEdit::active()) throw new LogicException('Approval requires a protected time transaction.');
    if (!in_array($via,['manual','tokenized_client_email','bulk_pre_approved'],true)) throw new RuntimeException('Unsupported time approval method',422);
    $email=$context['client_approver_email']??null;
    if ($actor===null) {
        if ($via!=='tokenized_client_email' || !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('An identified approver is required.',422);
        timeNativeExternalReviewer($entry,$email);
    }
    $json=staffingDimensionSnapshotCanonicalJson($snapshot['dimensions']);
    $q=getDB()->prepare('UPDATE time_entries SET status="approved",rate_snapshot_id=?,dimension_snapshot_json=?,dimension_snapshot_hash=?,approved_at=NOW(),approved_by_user_id=?,approved_via=?,client_approver_email=?,rejected_reason=NULL WHERE tenant_id=? AND id=? AND status="pending_review"');
    $q->execute([$snapshot['rate_id'],$json,hash('sha256',$json),$actor,$via,$email,staffingTimeWorkspace(),$entry['id']]);
    if ($q->rowCount()!==1) throw new RuntimeException('The time entry changed during approval. Refresh and review it again.',409);
    $approved=timeEntryGet((int)$entry['id']);
    timeEntryApprovedEmit((int)$entry['id'],$approved,$via,['approver_user_id'=>$actor]+$context);
    return $approved;
}
