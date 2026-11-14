<?php
/** Weekly external decisions retain the issued week and exact displayed review. */
declare(strict_types=1);
require_once __DIR__.'/approval_tokens.php';
require_once __DIR__.'/../modules/time/lib/native_decisions.php';

function staffingEmailReviewRequireSchema(): void
{
    $ready=getDB()->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='approval_tokens' AND COLUMN_NAME='issued_review_json'")->fetchColumn();
    if (!$ready) throw new RuntimeException('Weekly approval review needs a system update. Contact your administrator.',503);
}

function staffingEmailReviewSelection(int $id,string $email): array
{
    if (!StaffingNativeTimeEdit::active()) throw new LogicException('Weekly email review requires a protected transaction.');
    if (!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Choose a valid external approver email.',422);
    $header=staffingTimesheetRow(staffingTimeWorkspace(),$id,true);
    if (!$header) throw new RuntimeException('Timesheet not found in the original workspace.',404);
    if ($header['status']!=='submitted') throw new RuntimeException('Only submitted timesheets can be reviewed by email.',409);
    staffingTimesheetValidateWeek((int)$header['person_id'],$header['period_start'],$header['period_end']);
    $entries=staffingTimeQuery('SELECT * FROM time_entries WHERE tenant_id=:tenant_id AND timesheet_id=:id AND status!="superseded" ORDER BY id',['id'=>$id]);
    if (!$entries || count($entries)>5000) throw new RuntimeException('A complete submitted week of 1 to 5000 entries is required.',422);
    $pending=[];$identities=[];$view=[];
    $q=getDB()->prepare('SELECT id,first_name,last_name,email_primary FROM people WHERE tenant_id=? AND id=? FOR UPDATE');
    $q->execute([StaffingNativeTimeEdit::owner('people'),$header['person_id']]);$person=$q->fetch(PDO::FETCH_ASSOC);
    if (!$person) throw new RuntimeException('The original worker is unavailable.',409);
    $person=coreRecordNumbersAttach(getDB(),(int)StaffingNativeTimeEdit::owner('people'),'person',[$person])[0];
    foreach($entries as$entry) {
        if ((int)$entry['person_id']!==(int)$header['person_id'] || !in_array($entry['status'],['pending_review','approved'],true)) throw new RuntimeException('Review the complete submitted week before requesting a decision.',409);
        if ($entry['status']==='pending_review') {
            timeNativeValidateSubmission($entry);timeNativeExternalReviewer($entry,$email);$pending[(int)$entry['id']]=$entry;
        }
        $q=getDB()->prepare('SELECT * FROM placements WHERE tenant_id=? AND id=? FOR UPDATE');
        $q->execute([StaffingNativeTimeEdit::owner('placements'),$entry['placement_id']]);$placement=$q->fetch(PDO::FETCH_ASSOC);
        if (!$placement) throw new RuntimeException('An original assignment is unavailable.',409);
        $placement=coreRecordNumbersAttach(getDB(),(int)StaffingNativeTimeEdit::owner('placements'),'placement',[$placement])[0];
        $identities[(int)$entry['id']]=$placement;
        $view[]=array_intersect_key($entry,array_flip(['id','work_date','hours','hour_type','category','description','source','source_system','status']))+[
            'person_number'=>$person['person_number'],'placement_number'=>$placement['placement_number'],'placement_title'=>$placement['title'],
            'will_change'=>$entry['status']==='pending_review',
        ];
    }
    if (!$pending) throw new RuntimeException('This week has no submitted entries awaiting a decision.',409);
    $issuedHash=hash('sha256',staffingDimensionSnapshotCanonicalJson([
        'workspace'=>staffingTimeWorkspace(),'people_owner'=>StaffingNativeTimeEdit::owner('people'),'placement_owner'=>StaffingNativeTimeEdit::owner('placements'),
        'header'=>$header,'entries'=>$entries,'person'=>$person,'placements'=>$identities,'email'=>$email,
    ]));
    return compact('header','entries','pending','person','view','issuedHash');
}

/** The native dispatcher establishes staffing.time.approve permission first. */
function staffingEmailReviewIssue(int $workspace,int $actor,int $id,string $email,?string $name=null,int $ttlHours=72): array
{
    if ($actor<=0) throw new RuntimeException('Sign in before requesting external review.',403);
    $email=trim($email);$name=trim((string)$name);
    return StaffingNativeTimeEdit::runForWorkspace($workspace,static function()use($actor,$id,$email,$name,$ttlHours):array {
        staffingEmailReviewRequireSchema();$state=staffingEmailReviewSelection($id,$email);
        staffingTimesheetEnsureArtifact($state['header'],['tenant_id'=>staffingTimeWorkspace(),'created_by_user_id'=>$actor]);
        $state=staffingEmailReviewSelection($id,$email);
        $prior=staffingTimeQuery('SELECT id FROM approval_tokens WHERE tenant_id=:tenant_id AND subject_type="staffing_timesheet" AND subject_id=:id AND consumed_at IS NULL ORDER BY id',['id'=>$id]);
        $q=getDB()->prepare('UPDATE approval_tokens SET consumed_at=NOW(),consumed_via_action="superseded" WHERE tenant_id=? AND subject_type="staffing_timesheet" AND subject_id=? AND consumed_at IS NULL');
        $q->execute([staffingTimeWorkspace(),$id]);
        $issued=approvalTokenIssue(staffingTimeWorkspace(),'staffing_timesheet',$id,null,$email,['approve','reject'],max(1,min(720,$ttlHours)),['issued_by_user_id'=>$actor]);
        $basis=['version'=>1,'issued_hash'=>$state['issuedHash'],'entry_ids'=>array_map('intval',array_column($state['entries'],'id')),'approver_name'=>$name];
        getDB()->prepare('UPDATE approval_tokens SET issued_review_json=? WHERE tenant_id=? AND id=?')->execute([json_encode($basis,JSON_THROW_ON_ERROR),staffingTimeWorkspace(),$issued['token_id']]);
        $meta=['token_id'=>$issued['token_id'],'approver_email'=>$email,'entry_ids'=>$basis['entry_ids'],'issued_hash'=>$state['issuedHash'],'expires_at'=>$issued['expires_at'],'prior_token_ids'=>array_map('intval',array_column($prior,'id'))];
        $header=staffingTimesheetRecordArtifactEvent($id,'timesheet.approval_token_issued',$actor,$meta,staffingTimeWorkspace());
        timeNativeRequiredAudit('time.timesheet.email_token_issued',(int)$issued['token_id'],$meta,$actor);
        return $issued+['header'=>$header,'worker_name'=>trim($state['person']['first_name'].' '.$state['person']['last_name']),
            'person_number'=>$state['person']['person_number'],'total_hours'=>array_sum(array_column($state['entries'],'hours'))];
    });
}

function staffingEmailReviewRun(string $raw,callable $work): mixed
{
    if (!preg_match('/^[a-f0-9]{64}$/D',$raw)) throw new RuntimeException('Invalid approval link.',404);
    $located=approvalTokenLookup($raw);
    if (!$located || $located['subject_type']!=='staffing_timesheet') throw new RuntimeException('Weekly approval link not found.',404);
    return StaffingNativeTimeEdit::runForWorkspace((int)$located['tenant_id'],static function()use($raw,$located,$work):mixed {
        staffingEmailReviewRequireSchema();
        $q=getDB()->prepare('SELECT * FROM approval_tokens WHERE tenant_id=? AND id=? AND token_hash=? AND subject_type="staffing_timesheet" FOR UPDATE');
        $q->execute([staffingTimeWorkspace(),$located['id'],hash('sha256',$raw)]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Weekly approval link not found.',404);
        return $work($row);
    });
}

function staffingEmailReviewState(array $row,string $action): array
{
    if (!in_array($action,['approve','reject'],true) || !in_array($action,json_decode((string)$row['actions_json'],true)??[],true)) throw new RuntimeException('This link does not allow that decision.',422);
    if ($row['consumed_at']!==null) throw new RuntimeException('This approval link has already been used or replaced.',409);
    if ($row['expires_at']<date('Y-m-d H:i:s')) throw new RuntimeException('This approval link has expired. Request a new link.',410);
    $basis=json_decode((string)($row['issued_review_json']??''),true);
    if (!is_array($basis) || ($basis['version']??null)!==1 || !is_string($basis['issued_hash']??null)) throw new RuntimeException('This older link has no verified issued week. Request a new link.',409);
    $state=staffingEmailReviewSelection((int)$row['subject_id'],(string)$row['actor_email']);
    if (!hash_equals($basis['issued_hash'],$state['issuedHash']) || ($basis['entry_ids']??[])!==array_map('intval',array_column($state['entries'],'id'))) throw new RuntimeException('The issued week, worker or assignment has changed. Request a new approval link.',409);
    $plans=$action==='approve'?staffingTimesheetApprovalPlan(null,$state['header'],staffingTimeWorkspace()):[];
    $revision=hash('sha256',staffingDimensionSnapshotCanonicalJson(['token_id'=>(int)$row['id'],'workspace'=>staffingTimeWorkspace(),'action'=>$action,'issued_hash'=>$state['issuedHash'],'plans'=>$plans,'email'=>$row['actor_email'],'expires'=>$row['expires_at']]));
    return $state+['plans'=>$plans,'public'=>[
        'state'=>'pending','workspace_id'=>staffingTimeWorkspace(),'timesheet_id'=>(int)$row['subject_id'],'display_id'=>$state['header']['display_id'],
        'period_start'=>$state['header']['period_start'],'period_end'=>$state['header']['period_end'],'worker_name'=>trim($state['person']['first_name'].' '.$state['person']['last_name']),
        'person_number'=>$state['person']['person_number'],'entries'=>$state['view'],'pending_hours'=>array_sum(array_column($state['pending'],'hours')),
        'total_hours'=>array_sum(array_column($state['entries'],'hours')),'revision'=>$revision,'decision'=>$action,'expires_at'=>$row['expires_at'],
    ]];
}

function staffingEmailApprovalReview(string $raw,string $action): array
{
    return staffingEmailReviewRun($raw,static function(array $row)use($action):array {
        if ($row['consumed_at']!==null) return ['state'=>'already_acted','message'=>'This approval link has already been used or replaced.'];
        if ($row['expires_at']<date('Y-m-d H:i:s')) return ['state'=>'expired','message'=>'This approval link has expired. Request a new link.'];
        return staffingEmailReviewState($row,$action)['public'];
    });
}

/** Pure decision transaction; the external wrapper handles the existing post-commit emission. */
function staffingEmailApprovalDecide(string $raw,string $action,?string $revision,string $note='',?string $ip=null): array
{
    if (!is_string($revision) || !preg_match('/^[a-f0-9]{64}$/D',$revision)) throw new RuntimeException('Open and confirm the full weekly review first.',409);
    $note=trim($note);
    if ((function_exists('mb_strlen')?mb_strlen($note):strlen($note))>500) throw new RuntimeException('Use a note of 500 characters or fewer.',422);
    return staffingEmailReviewRun($raw,static function(array $row)use($action,$revision,$note,$ip):array {
        $state=staffingEmailReviewState($row,$action);
        if (!hash_equals($state['public']['revision'],$revision)) throw new RuntimeException('The displayed week or approval evidence changed. Reload and review the week again.',409);
        $id=(int)$row['subject_id'];$email=(string)$row['actor_email'];
        if ($action==='approve') staffingTimesheetApplyApproval(null,$id,$state['plans'],[
            'tenant_id'=>staffingTimeWorkspace(),'header_approved_via'=>'external_email','entry_approved_via'=>'tokenized_client_email',
            'external_approver_email'=>$email,'approval_note'=>$note,'approval_token_id'=>(int)$row['id'],
        ]);
        else timeNativeRejectRows($state['pending'],null,$note,['approval_token_id'=>(int)$row['id'],'client_approver_email'=>$email]);
        $q=getDB()->prepare('UPDATE approval_tokens SET consumed_at=NOW(),consumed_via_action=?,consumed_ip=? WHERE tenant_id=? AND id=? AND consumed_at IS NULL');
        $q->execute([$action,$ip,staffingTimeWorkspace(),$row['id']]);
        if ($q->rowCount()!==1) throw new RuntimeException('This approval link was used in another session.',409);
        $q=getDB()->prepare('UPDATE approval_tokens SET consumed_at=NOW(),consumed_via_action="superseded" WHERE tenant_id=? AND subject_type="staffing_timesheet" AND subject_id=? AND id!=? AND consumed_at IS NULL');
        $q->execute([staffingTimeWorkspace(),$id,$row['id']]);
        $meta=['approval_token_id'=>(int)$row['id'],'action'=>$action,'external_approver_email'=>$email,'review_revision'=>$revision,'entry_ids'=>array_keys($state['pending']),'sibling_tokens_closed'=>$q->rowCount()];
        staffingTimesheetRecordArtifactEvent($id,'timesheet.approval_token_consumed',null,$meta,staffingTimeWorkspace());
        timeNativeRequiredAudit('time.timesheet.email_token_consumed',(int)$row['id'],$meta,null);
        return ['ok'=>true,'state'=>$action==='approve'?'approved':'rejected','timesheet_id'=>$id,'workspace_id'=>staffingTimeWorkspace(),
            'message'=>$action==='approve'?'Approval recorded.':'Rejection recorded. The reason is available in Core.'];
    });
}
