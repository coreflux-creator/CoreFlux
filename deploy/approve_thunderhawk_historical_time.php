<?php
/** Separate test-principal review of previously paid hours in the isolated pilot only. */
declare(strict_types=1);
const TH_HR_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const TH_HR_HELPER='/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030/time-reviewer-historical.php';
const TH_HR_HASH='38a576b558d80bc63a65ec5472680c45ae5ba65744fda98109e4092881ee5ac8';
const TH_HR_WORKSPACE='4f93d4e7-af74-4765-b812-3464bb6d974b';
if(PHP_SAPI!=='cli'||($argv[1]??'')!=='--approve-historical-pilot-time'||getenv('COREFLUX_ENV')!=='staging'
    ||realpath(getcwd())!==TH_HR_ROOT||!is_file(TH_HR_HELPER)||is_link(TH_HR_HELPER)
    ||hash('sha256',rtrim(str_replace("\r\n","\n",file_get_contents(TH_HR_HELPER)),"\r\n"))!=='48207b1fc1bc8a904756c7c66007fdd52a36bb8b01c2d61670365774e5df23a8')exit(2);
require_once TH_HR_ROOT.'/core/quanta/time_review_delivery.php';
require_once TH_HR_ROOT.'/core/memberships.php';
require_once TH_HR_ROOT.'/core/RBAC.php';
require_once TH_HR_ROOT.'/core/rbac/permissions.php';
require_once TH_HR_ROOT.'/core/rbac/legacy_map.php';
require_once TH_HR_HELPER;
$pdo=getDB();
if(!$pdo||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||!COREFLUX_STAGING
    ||integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf'
    ||quantaApiBase()!=='https://helloquanta.app:19443/api/v1'
    ||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=2002 AND parent_id IS NULL AND is_active=1')->fetchColumn()!==1)exit(2);
$report=['checked_at'=>gmdate('c'),'tenant_id'=>2002,'mode'=>'historical_source_replay_in_isolated_pilot',
    'source_sha256'=>TH_HR_HASH,'production_changed'=>false,'independent_human_review'=>false,
    'original_client_approval_stamp_present'=>false,'payroll_or_financial_postings'=>false];
$phase='guard';$flag=getenv('COREFLUX_QUANTA_TIME_REVIEW_DELIVERY');$reviewerId=null;
try{
    $manifest=json_decode(base64_decode($argv[2]??'',true),true,64,JSON_THROW_ON_ERROR);
    if($manifest['passed']!==true||$manifest['source_sha256']!==TH_HR_HASH||$manifest['hours']!==80
        ||$manifest['tenant_id']!==2002||$manifest['person_number']!=='P-358'||$manifest['placement_number']!=='PL-864'
        ||count($manifest['entries'])!==10||count($manifest['source_entries'])!==10)throw new RuntimeException('Imported source manifest differs');
    $ids=array_map('intval',array_column($manifest['entries'],'id'));sort($ids);
    if($ids!==range(4,13))throw new RuntimeException('Exact historical pilot rows required');
    $sources=array_column($manifest['source_entries'],null,'work_date');
    setRequestTenantId(2002);
    foreach(['people','placements','time']as$m)if(effectiveTenantIdForModule($m,2002)!==2002)throw new RuntimeException('Owner mismatch');
    $operator=$pdo->query("SELECT id,role FROM users WHERE id=4 AND email='stage-admin@coreflux.test' AND is_active=1")->fetch(PDO::FETCH_ASSOC);
    if(!$operator||!RBACResolver::can(4,2002,'time','admin')||!RBACResolver::can(4,2002,'integrations','admin'))throw new RuntimeException('Operator mismatch');
    $operator['global_role']=$operator['role'];$operator['role']=RBACResolver::personaTypeOf(4,2002);$_SESSION['user']=$operator;
    $entries=[];
    foreach($manifest['entries']as$want){
        $id=(int)$want['id'];$entry=timeEntryGet($id);$rate=timeResolveRateSnapshot(864,$want['work_date']);
        foreach(['tenant_id'=>2002,'person_id'=>358,'placement_id'=>864,'created_by_user_id'=>4,'timesheet_id'=>(int)$want['timesheet_id']]as$k=>$v)
            if((int)($entry[$k]??0)!==$v)throw new RuntimeException('Historical pilot identity differs');
        if(!in_array($entry['status'],['pending_review','approved'],true)||$entry['work_date']!==$want['work_date']||(float)$entry['hours']!==8.0
            ||$entry['source_system']!=='quanta'||$entry['hour_type']!=='regular'||(int)$rate['id']!==2
            ||(float)$rate['bill_rate']!==98.0||(float)$rate['pay_rate']!==65.0||$rate['currency']!=='USD')throw new RuntimeException('Historical hours or reviewed rate differs');
        foreach(['bill_extracted_at','ap_extracted_at','payroll_extracted_at','superseded_by_id']as$field)
            if($entry[$field]!==null)throw new RuntimeException('Historical time already consumed');
        $q=$pdo->prepare("SELECT meta_json FROM audit_log WHERE tenant_id=2002 AND actor_user_id=4 AND event='time.pilot.historical_source' AND target_id=?");
        $q->execute([$id]);$audit=$q->fetchAll(PDO::FETCH_COLUMN);$a=count($audit)===1?json_decode($audit[0],true):[];
        $source=$sources[$entry['work_date']];
        if(($a['source_sha256']??null)!==TH_HR_HASH||$a['source_cell']!==$source['source_cell']||$a['source_entry_id']!==$source['source_entry_id']
            ||$a['already_paid_source_period']!==true||$a['payroll_execution_authorized']!==false)throw new RuntimeException('Original workbook audit differs');
        $snapshot=quantaTimeReviewSnapshot(2002,$source['source_entry_id']);
        if($snapshot['payload']['workspace_id']!==TH_HR_WORKSPACE||$snapshot['entry']['source_approval']['timesheet_id']!==$source['source_timesheet_id'])throw new RuntimeException('Quanta source approval differs');
        $entries[$id]=$entry;
    }
    $states=array_unique(array_column($entries,'status'));if(count($states)!==1)throw new RuntimeException('Partial prior approval requires review');
    $protected=static function()use($pdo):array{
        $all=[];
        foreach(['people','placements','placement_rates','payroll_source_authorities','payroll_provider_results','payroll_runs','payroll_line_items',
            'ap_bills','ap_payments','billing_invoices','billing_payments','accounting_journal_entries','accounting_journal_entry_lines',
            'staffing_onboarding_cases','quanta_time_routes','quanta_workspace_bindings','purepay_core_connections','time_entries','staffing_timesheets']as$table){
            $where=$table==='time_entries'?' WHERE id NOT BETWEEN 4 AND 13':($table==='staffing_timesheets'?' WHERE id NOT IN(5,6)':'');
            $rows=array_map('serialize',$pdo->query('SELECT * FROM '.$table.$where)->fetchAll(PDO::FETCH_ASSOC));sort($rows,SORT_STRING);
            $all[$table]=['count'=>count($rows),'sha256'=>hash('sha256',serialize($rows))];
        }return$all;
    };
    $before=$protected();
    if($states[0]==='pending_review'){
        $phase='provision_separate_test_reviewer';$report['reviewer']=thunderhawkTimeReviewerProvision($pdo,'historical-october');
        $reviewerId=$report['reviewer']['user_id'];
        $q=$pdo->prepare('SELECT id,role FROM users WHERE id=?');$q->execute([$reviewerId]);$reviewer=$q->fetch(PDO::FETCH_ASSOC);
        $reviewer['global_role']=$reviewer['role'];$reviewer['role']=RBACResolver::personaTypeOf($reviewerId,2002);
        $phase='native_review';$pdo->beginTransaction();
        try{timeApproveEntry(4,$operator,'manual');throw new LogicException('Self approval allowed');}
        catch(RuntimeException $e){if($e->getCode()!==403)throw$e;}$report['self_approval_rejected']=true;
        $_SESSION['user']=$reviewer;
        foreach($entries as$id=>$entry){
            $approved=timeApproveEntry($id,$reviewer,'manual');
            $q=$pdo->prepare("SELECT meta_json FROM audit_log WHERE tenant_id=2002 AND actor_user_id=? AND event='time.entry.approved' AND target_id=? ORDER BY id DESC LIMIT 1");
            $q->execute([$reviewerId,$id]);$a=json_decode((string)$q->fetchColumn(),true);
            if($approved['status']!=='approved'||($a['quanta_source_check']['workspace_id']??null)!==TH_HR_WORKSPACE
                ||($a['quanta_source_check']['source_entries']??null)!==1)throw new RuntimeException('Native approval source proof differs');
            platformAuditLogWrite(2002,$reviewerId,'time.pilot.reviewed',$id,['historical_source_replay'=>true,'source_sha256'=>TH_HR_HASH,
                'already_paid_source_period'=>true,'independent_human_review'=>false,'payroll_execution_authorized'=>false,
                'native_source_proof'=>$a['quanta_source_check']],['source'=>'thunderhawk_historical_pilot']);
        }
        if($protected()!==$before)throw new RuntimeException('Approval changed protected records');$pdo->commit();
        $repeated=timeEntryGet(4);
        try{timeApproveEntry(4,$reviewer,'manual');throw new LogicException('Duplicate approval allowed');}
        catch(RuntimeException $e){if($e->getCode()!==409)throw$e;}
        if(timeEntryGet(4)!==$repeated)throw new RuntimeException('Duplicate changed approved time');$report['duplicate_approval_rejected']=true;
    }else{
        $reviewerIds=array_unique(array_column($entries,'approved_by_user_id'));
        if(count($reviewerIds)!==1)throw new RuntimeException('Multiple prior reviewers');$reviewerId=(int)$reviewerIds[0];
        $active=(int)$pdo->query('SELECT is_active FROM users WHERE id='.$reviewerId)->fetchColumn();
        $report['reviewer']=$active===1?thunderhawkTimeReviewerVerify($pdo,$reviewerId,'historical-october'):thunderhawkTimeReviewerVerifyRetired($pdo,$reviewerId,'historical-october');
        $report['recovered_existing_approval']=true;
    }
    $phase='return_accepted_review';$_SESSION['user']=$operator;putenv('COREFLUX_QUANTA_TIME_REVIEW_DELIVERY=1');
    foreach($sources as$source){
        $id=$source['source_entry_id'];$p=quantaTimeReviewPreview(2002,4,$id);
        if($p['state']!=='accepted')throw new RuntimeException('Accepted state differs');
        if($p['status']!=='unchanged'){
            $d=$p['delivery'];$sent=quantaTimeReviewSend(2002,4,(int)$d['id'],$d['review_hash']);
            if($sent['state']!=='delivered'||quantaTimeReviewSend(2002,4,(int)$d['id'],$d['review_hash'])!==$sent)throw new RuntimeException('Return replay differs');
        }
        $fresh=quantaTimeReviewSnapshot(2002,$id);
        if($fresh['status']!=='unchanged'||$fresh['remote']['current']['state']!=='accepted')throw new RuntimeException('Return state did not reconcile');
    }
    if($protected()!==$before)throw new RuntimeException('Protected records changed');
    $report+=['hours'=>80,'entry_ids'=>$ids,'timesheet_ids'=>[5,6],'provider_core_review_status'=>'accepted',
        'separate_test_approver_verified'=>true,'prior_records_unchanged'=>true,'passed'=>true];
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();$report['passed']=false;$report['failed_phase']=$phase;
    $report['failure_class']=get_class($e);$report['failure_source']=basename($e->getFile());$report['failure_line']=$e->getLine();
}finally{
    if($reviewerId!==null){
        try{
            $active=(int)$pdo->query('SELECT is_active FROM users WHERE id='.(int)$reviewerId)->fetchColumn();
            $report['reviewer_retirement']=$active===1?thunderhawkTimeReviewerRetire($pdo,$reviewerId,'historical-october'):thunderhawkTimeReviewerVerifyRetired($pdo,$reviewerId,'historical-october');
        }catch(Throwable $e){$report['passed']=false;$report['retirement_failure']=get_class($e);}
    }
    putenv($flag===false?'COREFLUX_QUANTA_TIME_REVIEW_DELIVERY':'COREFLUX_QUANTA_TIME_REVIEW_DELIVERY='.$flag);unset($_SESSION['user']);
}
echo json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";exit($report['passed']?0:1);
