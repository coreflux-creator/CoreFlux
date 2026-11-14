<?php
/** Native reviewer decisions bind the displayed selection to its current source facts. */
declare(strict_types=1);
require_once __DIR__.'/native_review.php';

function timeNativeDecisionIds(array $ids): array
{
    if (!$ids || count($ids)>500) throw new RuntimeException('Select between 1 and 500 records.',422);
    foreach ($ids as $id) if ((!is_int($id) && !is_string($id)) || filter_var($id,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])===false) throw new RuntimeException('Choose valid record IDs.',422);
    $ids=array_values(array_unique(array_map('intval',$ids)));sort($ids,SORT_NUMERIC);return $ids;
}

function timeNativeRejectionReason(string $reason): string
{
    $reason=trim($reason);
    if ($reason==='' || (function_exists('mb_strlen')?mb_strlen($reason):strlen($reason))>500) throw new RuntimeException('Enter a rejection reason of 1 to 500 characters.',422);
    return $reason;
}

/** Caller has established permission; this internal state never leaves the server. */
function timeNativeDecisionState(int $actor,string $kind,array $ids,string $decision): array
{
    if (!StaffingNativeTimeEdit::active()) throw new LogicException('A protected review transaction is required.');
    if ($actor<=0) throw new RuntimeException('An identified reviewer is required.',403);
    if (!in_array($kind,['entries','weeks'],true) || !in_array($decision,['approve','reject'],true)) throw new RuntimeException('Choose a supported time decision.',422);
    $ids=timeNativeDecisionIds($ids);$entries=[];$headers=[];$plans=[];$pending=[];$view=[];$identities=[];$siblings=[];
    if ($kind==='weeks') {
        foreach ($ids as $id) {
            $header=staffingTimesheetRow(staffingTimeWorkspace(),$id,true);
            if (!$header) throw new RuntimeException('A selected timesheet is unavailable in this workspace.',404);
            if ($header['status']!=='submitted') throw new RuntimeException('Only submitted weeks can be reviewed.',409);
            staffingTimesheetValidateWeek((int)$header['person_id'],$header['period_start'],$header['period_end']);
            $headers[$id]=$header;
            $rows=staffingTimeQuery('SELECT * FROM time_entries WHERE tenant_id=:tenant_id AND timesheet_id=:id AND status!="superseded" ORDER BY id',['id'=>$id]);
            if (!$rows || !array_filter($rows,static fn($row)=>$row['status']==='pending_review')) throw new RuntimeException('This week has no submitted entries to review.',409);
            foreach ($rows as $row) {
                if ((int)$row['person_id']!==(int)$header['person_id'] || !in_array($row['status'],['pending_review','approved'],true)) throw new RuntimeException('Review the complete submitted week before deciding.',409);
                $entries[(int)$row['id']]=$row;
            }
            if ($decision==='approve') $plans[$id]=staffingTimesheetApprovalPlan($actor,$header);
        }
    } else {
        foreach ($ids as $id) {
            $entry=timeEntryGet($id);
            if (!$entry) throw new RuntimeException('A selected time entry is unavailable in this workspace.',404);
            if ($entry['status']!=='pending_review') throw new RuntimeException('Only submitted entries can be reviewed.',409);
            $entries[$id]=$entry;
            if (!empty($entry['timesheet_id'])) {
                $headerId=(int)$entry['timesheet_id'];
                $headers[$headerId]=staffingTimesheetRow(staffingTimeWorkspace(),$headerId,true);
                $siblings[$headerId]=staffingTimeQuery('SELECT * FROM time_entries WHERE tenant_id=:tenant_id AND timesheet_id=:id AND status!="superseded" ORDER BY id',['id'=>$headerId]);
            }
        }
    }
    ksort($entries,SORT_NUMERIC);
    foreach ($entries as $id=>$entry) {
        if ($entry['status']==='pending_review') {
            if ($decision==='approve' && $kind==='entries') $plans[$id]=timeNativeApprovalSnapshot($entry,$actor);
            else timeNativeValidateSubmission($entry);
            $pending[$id]=$entry;
        }
        // Even retained siblings are identified from the current directory for display.
        $q=getDB()->prepare('SELECT id,first_name,last_name FROM people WHERE tenant_id=? AND id=? FOR UPDATE');
        $q->execute([StaffingNativeTimeEdit::owner('people'),$entry['person_id']]);$person=$q->fetch(PDO::FETCH_ASSOC);
        $q=getDB()->prepare('SELECT * FROM placements WHERE tenant_id=? AND id=? FOR UPDATE');
        $q->execute([StaffingNativeTimeEdit::owner('placements'),$entry['placement_id']]);$placement=$q->fetch(PDO::FETCH_ASSOC);
        if (!$person || !$placement) throw new RuntimeException('A reviewed worker or placement is unavailable.',409);
        $person=coreRecordNumbersAttach(getDB(),(int)StaffingNativeTimeEdit::owner('people'),'person',[$person])[0];
        $placement=coreRecordNumbersAttach(getDB(),(int)StaffingNativeTimeEdit::owner('placements'),'placement',[$placement])[0];
        $identities[$id]=[$person,$placement];
        $view[]=array_intersect_key($entry,array_flip(['id','timesheet_id','work_date','hours','hour_type','category','description','source','source_system','source_ref_id','status','billable','payable']))+[
            'person_number'=>$person['person_number'],'worker_name'=>trim($person['first_name'].' '.$person['last_name']),
            'placement_number'=>$placement['placement_number'],'placement_title'=>$placement['title'],
            'will_change'=>$entry['status']==='pending_review',
        ];
    }
    if ($decision==='approve' && $kind==='entries') timeNativeApprovalSourceIds(array_values($pending),$actor);
    $revision=hash('sha256',staffingDimensionSnapshotCanonicalJson([
        'actor'=>$actor,'workspace'=>staffingTimeWorkspace(),'kind'=>$kind,'ids'=>$ids,'decision'=>$decision,
        'people_owner'=>StaffingNativeTimeEdit::owner('people'),'placement_owner'=>StaffingNativeTimeEdit::owner('placements'),
        'entries'=>$entries,'headers'=>$headers,'siblings'=>$siblings,'identities'=>$identities,'plans'=>$plans,
    ]));
    return ['ids'=>$ids,'entries'=>$entries,'pending'=>$pending,'plans'=>$plans,'headers'=>$headers,'public'=>[
        'workspace_id'=>staffingTimeWorkspace(),'kind'=>$kind,'decision'=>$decision,'ids'=>$ids,'revision'=>$revision,
        'entries'=>$view,'entry_count'=>count($pending),'total_hours'=>array_sum(array_map(static fn($row)=>(float)$row['hours'],$pending)),
    ]];
}

function timeNativeDecisionReview(int $actor,string $kind,array $ids,string $decision): array
{
    return StaffingNativeTimeEdit::run(static fn()=>timeNativeDecisionState($actor,$kind,$ids,$decision)['public']);
}

/** Reject exactly the selected pending rows; preserve source facts and approved siblings. */
function timeNativeRejectRows(array $entries,?int $actor,string $reason,array $context=[]): array
{
    if (!StaffingNativeTimeEdit::active()) throw new LogicException('A protected rejection transaction is required.');
    if (($actor!==null && $actor<=0) || !$entries) throw new RuntimeException('An identified reviewer and submitted entries are required.',422);
    $reason=timeNativeRejectionReason($reason);
    foreach ($entries as $entry) {
        if ($entry['status']!=='pending_review') throw new RuntimeException('Only pending_review entries can be rejected.',409);
        timeNativeValidateSubmission($entry);
        if ($actor===null) timeNativeExternalReviewer($entry,(string)($context['client_approver_email']??''));
    }
    $headers=[];$result=[];
    $q=getDB()->prepare('UPDATE time_entries SET status="rejected",rejected_reason=? WHERE tenant_id=? AND id=? AND status="pending_review"');
    foreach ($entries as $entry) {
        $q->execute([$reason,staffingTimeWorkspace(),$entry['id']]);
        if ($q->rowCount()!==1) throw new RuntimeException('Time changed during rejection. Reload the review.',409);
        timeNativeRequiredAudit('time.entry.rejected',(int)$entry['id'],['entry_id'=>$entry['id'],'reason'=>$reason,'rejected_by_user_id'=>$actor,'prior_status'=>'pending_review','status'=>'rejected']+$context,$actor);
        if (!empty($entry['timesheet_id'])) $headers[(int)$entry['timesheet_id']][]=(int)$entry['id'];
        $result[]=timeEntryGet((int)$entry['id']);
    }
    foreach ($headers as $headerId=>$entryIds) {
        timeReconcileTimesheetHeader($headerId,$actor);
        staffingTimeUpdate('staffing_timesheets',$headerId,['rejected_at'=>date('Y-m-d H:i:s'),'rejected_by_user_id'=>$actor,'rejection_reason'=>$reason]);
        $meta=['reason'=>$reason,'entry_ids'=>$entryIds]+$context;
        staffingTimesheetRecordArtifactEvent($headerId,'timesheet.rejected',$actor,$meta,staffingTimeWorkspace());
        timeNativeRequiredAudit('time.timesheet.rejected',$headerId,$meta,$actor);
    }
    return $result;
}

/** Public entrypoints require a review revision; internal rejection callers may build it in this transaction. */
function timeNativeDecisionCommit(int $actor,string $kind,array $ids,string $decision,?string $revision,string $reason='',bool $requireReview=true): array
{
    $result=StaffingNativeTimeEdit::run(static function()use($actor,$kind,$ids,$decision,$revision,$reason,$requireReview):array {
        if ($requireReview && (!is_string($revision) || !preg_match('/^[a-f0-9]{64}$/D',$revision))) throw new RuntimeException('Open and confirm the time review before deciding.',409);
        $state=timeNativeDecisionState($actor,$kind,$ids,$decision);
        if ($requireReview && !hash_equals($state['public']['revision'],$revision)) throw new RuntimeException('The reviewed time, assignment or rates changed. Reload and review the complete selection.',409);
        if ($decision==='reject') $saved=timeNativeRejectRows($state['pending'],$actor,$reason);
        elseif ($kind==='weeks') {
            foreach ($state['plans'] as $headerId=>$plan) staffingTimesheetApplyApproval($actor,(int)$headerId,$plan);
            $saved=array_map(static fn($id)=>timeEntryGet((int)$id),array_keys($state['pending']));
        } else {
            $saved=[];
            foreach ($state['ids'] as $id) $saved[]=timeApproveEntry($id,['id'=>$actor],'bulk_review');
        }
        timeNativeRequiredAudit('time.review.decided',0,['kind'=>$kind,'ids'=>$state['ids'],'decision'=>$decision,'review_revision'=>$state['public']['revision'],'entry_ids'=>array_keys($state['pending'])],$actor);
        return ['ok'=>true,'workspace_id'=>staffingTimeWorkspace(),'kind'=>$kind,'decision'=>$decision,'ids'=>$state['ids'],'entries'=>$saved,
            'succeeded'=>count($state['ids']),'failed'=>0,'results'=>array_map(static fn($id)=>['id'=>$id,'ok'=>true],$state['ids']),
            $decision==='approve'?'approved':'rejected'=>count($state['ids'])];
    });
    // Retain the existing weekly posting behavior after the native decision commits.
    $result['posting_warnings']=[];
    if ($kind==='weeks' && $decision==='approve') foreach ($result['ids'] as $id) {
        $warning=staffingEmitWorkerHoursApprovedEvent($result['workspace_id'],$id);
        if ($warning!==null) $result['posting_warnings'][]=['id'=>$id,'warning'=>$warning];
    }
    return $result;
}
