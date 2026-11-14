<?php
/** Client links retain their issued facts and require the exact displayed decision. */
declare(strict_types=1);
require_once __DIR__.'/approval_tokens.php';
require_once __DIR__.'/native_decisions.php';

function timeClientTokenSelection(int $placementId,int $periodId,array $ids): array
{
    if (!StaffingNativeTimeEdit::active()) throw new LogicException('A protected client review is required.');
    $ids=timeNativeDecisionIds($ids);$entries=[];$headers=[];$siblings=[];
    foreach($ids as$id) {
        $entry=timeEntryGet($id);
        if (!$entry || (int)$entry['placement_id']!==$placementId || (int)$entry['period_id']!==$periodId || $entry['status']!=='pending_review') throw new RuntimeException('The complete issued selection is no longer pending on this assignment and period. Request a new approval link.',409);
        timeNativeValidateSubmission($entry);$entries[$id]=$entry;
        if (!empty($entry['timesheet_id'])) {
            $headerId=(int)$entry['timesheet_id'];
            $headers[$headerId]=staffingTimesheetRow(staffingTimeWorkspace(),$headerId,true);
            $siblings[$headerId]=staffingTimeQuery('SELECT * FROM time_entries WHERE tenant_id=:tenant_id AND timesheet_id=:id AND status!="superseded" ORDER BY id',['id'=>$headerId]);
        }
    }
    $first=reset($entries);$placement=staffingTimeCurrentPlacement($placementId,(int)$first['person_id']);
    $email=trim((string)($placement['client_approver_email']??''));
    if ((int)($placement['tokenized_email_approval_enabled']??0)!==1 || !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Configure and enable this placement’s client approver before requesting approval.',422);
    foreach($entries as$entry) timeNativeExternalReviewer($entry,$email);
    $q=getDB()->prepare('SELECT id,first_name,last_name,email_primary FROM people WHERE tenant_id=? AND id=? FOR UPDATE');
    $q->execute([StaffingNativeTimeEdit::owner('people'),$first['person_id']]);$person=$q->fetch(PDO::FETCH_ASSOC);
    if (!$person) throw new RuntimeException('The original worker is unavailable.',409);
    $person=coreRecordNumbersAttach(getDB(),(int)StaffingNativeTimeEdit::owner('people'),'person',[$person])[0];
    $placement=coreRecordNumbersAttach(getDB(),(int)StaffingNativeTimeEdit::owner('placements'),'placement',[$placement])[0];
    $issuedHash=hash('sha256',staffingDimensionSnapshotCanonicalJson([
        'workspace'=>staffingTimeWorkspace(),'people_owner'=>StaffingNativeTimeEdit::owner('people'),
        'placement_owner'=>StaffingNativeTimeEdit::owner('placements'),'entries'=>$entries,'person'=>$person,'placement'=>$placement,
    ]));
    return compact('ids','entries','headers','siblings','person','placement','email','issuedHash');
}

/** Caller establishes issue permission in this original workspace. No email is sent here. */
function timeClientTokenIssue(int $workspace,int $actor,int $placementId,int $periodId,array $ids,int $ttlDays=7): array
{
    if ($actor<=0) throw new RuntimeException('Sign in before requesting client approval.',403);
    return StaffingNativeTimeEdit::runForWorkspace($workspace,static function()use($actor,$placementId,$periodId,$ids,$ttlDays):array {
        $state=timeClientTokenSelection($placementId,$periodId,$ids);$generated=timeTokenGenerate();
        $expires=date('Y-m-d H:i:s',time()+max(1,min(30,$ttlDays))*86400);
        $total=round(array_sum(array_column($state['entries'],'hours')),2);
        $basis=['version'=>1,'entry_ids'=>$state['ids'],'count'=>count($state['ids']),'issued_hash'=>$state['issuedHash']];
        $q=getDB()->prepare('INSERT INTO time_approval_tokens (tenant_id,placement_id,period_id,client_approver_email,token,token_hash,entries_json,entries_total_hours,issued_by_user_id,expires_at,response,email_status) VALUES (?,?,?,?,?,?,?,?,?,?,"pending","queued")');
        // The legacy unique text column holds a digest marker, never the bearer secret.
        $q->execute([staffingTimeWorkspace(),$placementId,$periodId,$state['email'],'sha256:'.bin2hex($generated['hash']),$generated['hash'],json_encode($basis,JSON_THROW_ON_ERROR),$total,$actor,$expires]);
        $id=(int)getDB()->lastInsertId();
        timeNativeRequiredAudit('time.tokenized_email.issued',$id,['token_id'=>$id,'placement_id'=>$placementId,'period_id'=>$periodId,'entry_ids'=>$state['ids'],'issued_hash'=>$state['issuedHash'],'client_approver_email'=>$state['email']],$actor);
        return ['token_id'=>$id,'raw'=>$generated['token'],'expires_at'=>$expires,'total_hours'=>$total,'entries'=>array_values($state['entries']),
            'placement'=>$state['placement']+['first_name'=>$state['person']['first_name'],'last_name'=>$state['person']['last_name']]];
    });
}

function timeClientTokenRun(string $raw,callable $work): mixed
{
    if (!preg_match('/^[a-f0-9]{64}$/D',$raw)) throw new RuntimeException('Invalid approval link.',404);
    $located=timeTokenFindByRaw($raw);
    if (!$located) throw new RuntimeException('Approval link not found.',404);
    return StaffingNativeTimeEdit::runForWorkspace((int)$located['tenant_id'],static function()use($raw,$located,$work):mixed {
        // The token establishes authority; the ambient signed-in workspace does not.
        $q=getDB()->prepare('SELECT * FROM time_approval_tokens WHERE tenant_id=? AND id=? AND token_hash=? FOR UPDATE');
        $q->execute([staffingTimeWorkspace(),$located['id'],timeTokenHash($raw)]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Approval link not found.',404);
        return $work($row);
    });
}

function timeClientTokenState(array $row,string $decision): array
{
    if (!in_array($decision,['approve','reject'],true)) throw new RuntimeException('Choose approve or reject.',422);
    if ($row['response']!=='pending') throw new RuntimeException('This approval link has already been used or revoked.',409);
    if ($row['expires_at']<date('Y-m-d H:i:s')) throw new RuntimeException('This approval link has expired. Request a new link.',410);
    $basis=json_decode((string)$row['entries_json'],true);
    if (!is_array($basis) || ($basis['version']??null)!==1 || !is_array($basis['entry_ids']??null) || !is_string($basis['issued_hash']??null)) throw new RuntimeException('This older link has no verified issued selection. Request a new approval link.',409);
    $state=timeClientTokenSelection((int)$row['placement_id'],(int)$row['period_id'],$basis['entry_ids']);
    if (!hash_equals($basis['issued_hash'],$state['issuedHash']) || $row['client_approver_email']!==$state['email']) throw new RuntimeException('The issued time or assignment has changed. Request a new approval link.',409);
    $plans=[];$sourceIds=$state['ids'];
    if ($decision==='approve') foreach($state['entries'] as$id=>$entry) $plans[$id]=timeNativeApprovalSnapshot($entry,null);
    if ($decision==='approve') $sourceIds=timeNativeApprovalSourceIds(array_values($state['entries']),null);
    $revision=hash('sha256',staffingDimensionSnapshotCanonicalJson([
        'token_id'=>(int)$row['id'],'workspace'=>staffingTimeWorkspace(),'decision'=>$decision,'expires'=>$row['expires_at'],
        'issued_hash'=>$state['issuedHash'],'email'=>$state['email'],'plans'=>$plans,'headers'=>$state['headers'],'siblings'=>$state['siblings'],
    ]));
    $view=[];
    foreach($state['entries'] as$entry) $view[]=array_intersect_key($entry,array_flip(['id','work_date','hours','category','hour_type','description','source','source_system','source_ref_id']))+[
        'person_number'=>$state['person']['person_number'],'placement_number'=>$state['placement']['placement_number'],
    ];
    return $state+['plans'=>$plans,'source_ids'=>$sourceIds,'public'=>[
        'status'=>'pending','workspace_id'=>staffingTimeWorkspace(),'decision'=>$decision,'revision'=>$revision,'expires_at'=>$row['expires_at'],
        'total_hours'=>round(array_sum(array_column($state['entries'],'hours')),2),'client_approver_email'=>$state['email'],'entries'=>$view,
        'placement'=>array_intersect_key($state['placement'],array_flip(['id','title','end_client_name','placement_number']))+[
            'first_name'=>$state['person']['first_name'],'last_name'=>$state['person']['last_name'],'person_number'=>$state['person']['person_number'],
        ],
    ]];
}

function timeClientTokenReview(string $raw,string $decision='approve'): array
{
    return timeClientTokenRun($raw,static function(array $row)use($decision):array {
        $status=$row['response']==='pending' && $row['expires_at']<date('Y-m-d H:i:s')?'expired':$row['response'];
        if ($status!=='pending') return ['status'=>$status,'responded_at'=>$row['responded_at'],'expires_at'=>$row['expires_at']];
        return timeClientTokenState($row,$decision)['public'];
    });
}

function timeClientTokenRespond(string $raw,string $decision,?string $revision,string $note='',?string $ip=null,string $userAgent=''): array
{
    if (!is_string($revision) || !preg_match('/^[a-f0-9]{64}$/D',$revision)) throw new RuntimeException('Open and confirm the complete time review first.',409);
    $note=trim($note);
    if ((function_exists('mb_strlen')?mb_strlen($note):strlen($note))>500) throw new RuntimeException('Use a note of 500 characters or fewer.',422);
    return timeClientTokenRun($raw,static function(array $row)use($decision,$revision,$note,$ip,$userAgent):array {
        $state=timeClientTokenState($row,$decision);
        if (!hash_equals($state['public']['revision'],$revision)) throw new RuntimeException('The displayed review has changed. Reload and review it again.',409);
        $context=['token_id'=>(int)$row['id'],'client_approver_email'=>$state['email'],'ip_address'=>$ip];
        if ($decision==='approve') {
            require_once __DIR__.'/../../../core/quanta/time_current.php';
            $sourceCheck=quantaTimeAssertCurrent(getDB(),staffingTimeWorkspace(),$state['source_ids']);
            if (!hash_equals(timeClientTokenState($row,$decision)['public']['revision'],$revision)) throw new RuntimeException('Time changed during source verification. Reload the review.',409);
            foreach($state['entries'] as$id=>$entry) timeNativeWriteApproval($entry,$state['plans'][$id],null,'tokenized_client_email',$context+['quanta_source_check'=>$sourceCheck?:null]);
            foreach($state['headers'] as$id=>$header) {
                timeReconcileTimesheetHeader($id,null);
                $meta=$context+['entry_ids'=>$state['ids']];
                staffingTimesheetRecordArtifactEvent($id,'timesheet.client_entries_approved',null,$meta,staffingTimeWorkspace());
                timeNativeRequiredAudit('time.timesheet.client_entries_approved',$id,$meta,null);
            }
        } else timeNativeRejectRows($state['entries'],null,$note,$context);
        $status=$decision==='approve'?'approved':'rejected';
        $q=getDB()->prepare('UPDATE time_approval_tokens SET response=?,responded_at=NOW(),responder_ip=?,responder_user_agent=?,responder_note=? WHERE tenant_id=? AND id=? AND response="pending"');
        $q->execute([$status,$ip,substr($userAgent,0,255),$note,staffingTimeWorkspace(),$row['id']]);
        if ($q->rowCount()!==1) throw new RuntimeException('This approval link has already been used.',409);
        timeNativeRequiredAudit('time.tokenized_email.responded',(int)$row['id'],$context+['decision'=>$decision,'entry_ids'=>$state['ids'],'review_revision'=>$revision],null);
        return ['ok'=>true,'response'=>$status,'entries_decided'=>count($state['ids'])];
    });
}

function timeClientTokenRevoke(int $workspace,int $actor,int $id): void
{
    StaffingNativeTimeEdit::runForWorkspace($workspace,static function()use($actor,$id):void {
        if ($actor<=0) throw new RuntimeException('Sign in to revoke an approval link.',403);
        $row=staffingTimeFind('SELECT * FROM time_approval_tokens WHERE tenant_id=:tenant_id AND id=:id',['id'=>$id]);
        if (!$row) throw new RuntimeException('Approval link not found.',404);
        if ($row['response']!=='pending') throw new RuntimeException('The approval link is already '.$row['response'].'.',409);
        $q=getDB()->prepare('UPDATE time_approval_tokens SET response="revoked",revoked_at=NOW(),revoked_by_user_id=? WHERE tenant_id=? AND id=? AND response="pending"');
        $q->execute([$actor,staffingTimeWorkspace(),$id]);
        if ($q->rowCount()!==1) throw new RuntimeException('The approval link changed. Reload and review it again.',409);
        timeNativeRequiredAudit('time.tokenized_email.revoked',$id,['token_id'=>$id],$actor);
    });
}
