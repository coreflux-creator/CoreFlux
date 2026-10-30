<?php
/** Approve the browser-created synthetic hour with a separate, temporary test principal. */
declare(strict_types=1);
const TH_BROWSER_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const TH_BROWSER_HELPER='/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030/time-reviewer-browser.php';
const TH_BROWSER_SOURCE='69f7fc04-ce35-497b-ad2d-589eabca1455';
if(PHP_SAPI!=='cli'||($argv[1]??'')!=='--approve-browser-test-hour'||getenv('COREFLUX_ENV')!=='staging'
    ||realpath(getcwd())!==TH_BROWSER_ROOT||!is_file(TH_BROWSER_HELPER)
    ||hash('sha256',rtrim(str_replace("\r\n","\n",file_get_contents(TH_BROWSER_HELPER)),"\r\n"))!=='8a4c8cb0042a3882fd8b035c3e4121510da855a787f2fd5de9f340d4d581371e')exit(2);
require_once TH_BROWSER_ROOT.'/core/quanta/time_review_delivery.php';
require_once TH_BROWSER_ROOT.'/core/memberships.php';
require_once TH_BROWSER_ROOT.'/core/RBAC.php';
require_once TH_BROWSER_ROOT.'/core/rbac/permissions.php';
require_once TH_BROWSER_ROOT.'/core/rbac/legacy_map.php';
require_once TH_BROWSER_HELPER;
$pdo=getDB();
if(!$pdo||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||!COREFLUX_STAGING
    ||integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf'
    ||quantaApiBase()!=='https://helloquanta.app:19443/api/v1'
    ||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=2002 AND is_active=1')->fetchColumn()!==1)exit(2);
$report=['checked_at'=>gmdate('c'),'tenant_id'=>2002,'entry_id'=>3,'timesheet_id'=>4,'hours'=>1,
    'production_changed'=>false,'independent_human_review'=>false,'payroll_or_financial_postings'=>false];
$phase='guard';
try{
    setRequestTenantId(2002);
    foreach(['people','placements','time']as$module)if(effectiveTenantIdForModule($module,2002)!==2002)throw new RuntimeException('Owner mismatch');
    $operator=$pdo->query("SELECT id,role FROM users WHERE id=4 AND email='stage-admin@coreflux.test' AND is_active=1")->fetch(PDO::FETCH_ASSOC);
    if(!$operator||!RBACResolver::can(4,2002,'time','admin')||!RBACResolver::can(4,2002,'integrations','admin'))throw new RuntimeException('Operator mismatch');
    $operator['global_role']=$operator['role'];$operator['role']=RBACResolver::personaTypeOf(4,2002);$_SESSION['user']=$operator;
    $entry=timeEntryGet(3);$rate=timeResolveRateSnapshot(864,'2026-10-26');
    foreach(['tenant_id'=>2002,'person_id'=>358,'placement_id'=>864,'timesheet_id'=>4,'created_by_user_id'=>4]as$field=>$value)
        if((int)($entry[$field]??0)!==$value)throw new RuntimeException('Browser test identity differs');
    if(!in_array($entry['status'],['pending_review','approved'],true)||$entry['work_date']!=='2026-10-26'||(float)$entry['hours']!==1.0
        ||$entry['source_system']!=='quanta'||$entry['hour_type']!=='regular'||(int)$rate['id']!==2
        ||(float)$rate['bill_rate']!==98.0||(float)$rate['pay_rate']!==65.0||$rate['currency']!=='USD')throw new RuntimeException('Browser test source differs');
    foreach(['bill_extracted_at','ap_extracted_at','payroll_extracted_at','superseded_by_id']as$field)
        if($entry[$field]!==null)throw new RuntimeException('Browser hour already consumed');
    $source=quantaTimeReviewSnapshot(2002,TH_BROWSER_SOURCE);
    if($source['payload']['workspace_id']!=='4f93d4e7-af74-4765-b812-3464bb6d974b'
        ||$source['entry']['source_approval']['timesheet_id']!=='0c5accda-db6f-4d24-b5ba-960b9be0018d')throw new RuntimeException('Source approval differs');
    $protected=static function()use($pdo):string{
        $all=[];
        foreach(['people','placements','placement_rates','payroll_source_authorities','payroll_provider_results','staffing_onboarding_cases',
            'quanta_time_routes','quanta_connections','quanta_workspace_bindings','purepay_core_connections','time_entries','staffing_timesheets']as$table){
            $where=$table==='time_entries'?' WHERE id<>3':($table==='staffing_timesheets'?' WHERE id<>4':'');
            $rows=array_map('serialize',$pdo->query('SELECT * FROM '.$table.$where)->fetchAll(PDO::FETCH_ASSOC));
            sort($rows,SORT_STRING);$all[$table]=hash('sha256',serialize($rows));
        }
        return hash('sha256',serialize($all));
    };
    $before=$protected();
    $report['original_reviewer_still_retired']=thunderhawkTimeReviewerVerifyRetired($pdo,52);
    if($entry['status']==='pending_review'){
        $phase='provision_separate_browser_hour_reviewer';
        $report['reviewer']=thunderhawkTimeReviewerProvision($pdo,'browser-hour');$reviewerId=$report['reviewer']['user_id'];
        $st=$pdo->prepare('SELECT id,role FROM users WHERE id=?');$st->execute([$reviewerId]);$reviewer=$st->fetch(PDO::FETCH_ASSOC);
        $reviewer['global_role']=$reviewer['role'];$reviewer['role']=RBACResolver::personaTypeOf($reviewerId,2002);
        $phase='native_approval';$pdo->beginTransaction();
        try{timeApproveEntry(3,$operator,'manual');throw new LogicException('Self approval allowed');}
        catch(RuntimeException $error){if($error->getCode()!==403)throw $error;}
        $report['self_approval_rejected']=true;$_SESSION['user']=$reviewer;
        $approved=timeApproveEntry(3,$reviewer,'manual');
        $audit=$pdo->prepare('SELECT meta_json FROM audit_log WHERE tenant_id=2002 AND actor_user_id=? AND event="time.entry.approved" AND target_id=3 ORDER BY id DESC LIMIT 1');
        $audit->execute([$reviewerId]);$meta=json_decode((string)$audit->fetchColumn(),true);
        if(($meta['quanta_source_check']['workspace_id']??null)!=='4f93d4e7-af74-4765-b812-3464bb6d974b'
            ||($meta['quanta_source_check']['source_entries']??null)!==1||$protected()!==$before)throw new RuntimeException('Native source evidence differs');
        platformAuditLogWrite(2002,$reviewerId,'time.pilot.reviewed',3,['synthetic'=>true,'independent_human_review'=>false,
            'source_entry_id'=>TH_BROWSER_SOURCE,'native_source_proof'=>$meta['quanta_source_check']],['source'=>'thunderhawk_browser_pilot']);
        $pdo->commit();
        $repeat=timeEntryGet(3);
        try{timeApproveEntry(3,$reviewer,'manual');throw new LogicException('Duplicate approval allowed');}
        catch(RuntimeException $error){if($error->getCode()!==409)throw $error;}
        if(timeEntryGet(3)!==$repeat)throw new RuntimeException('Duplicate approval changed time');
        $report['duplicate_approval_rejected']=true;$report['native_approval_state']=$approved['status'];
    }else{
        $reviewerId=(int)$entry['approved_by_user_id'];
        $identity=thunderhawkTimeReviewerIdentity('browser-hour');
        $st=$pdo->prepare('SELECT is_active FROM users WHERE id=? AND tenant_id=2002 AND email=?');$st->execute([$reviewerId,$identity['email']]);
        $active=$st->fetchColumn();if($active===false)throw new RuntimeException('Recovered approval belongs to another reviewer');
        $report['reviewer']=(int)$active===1?thunderhawkTimeReviewerVerify($pdo,$reviewerId,'browser-hour'):thunderhawkTimeReviewerVerifyRetired($pdo,$reviewerId,'browser-hour');
        $report['recovered_existing_native_approval']=true;$report['native_approval_state']='approved';
    }
    $phase='return_accepted_review';$_SESSION['user']=$operator;
    $preview=quantaTimeReviewPreview(2002,4,TH_BROWSER_SOURCE);
    if($preview['state']!=='accepted')throw new RuntimeException('Return state differs');
    if($preview['status']!=='unchanged'){
        $d=$preview['delivery'];$sent=quantaTimeReviewSend(2002,4,(int)$d['id'],$d['review_hash']);
        if($sent['state']!=='delivered'||quantaTimeReviewSend(2002,4,(int)$d['id'],$d['review_hash'])!==$sent)throw new RuntimeException('Return replay differs');
        $report['delivery']=array_intersect_key($sent,array_flip(['id','state','remote_id','remote_receipt_id']));
    }
    $fresh=quantaTimeReviewSnapshot(2002,TH_BROWSER_SOURCE);
    if($fresh['status']!=='unchanged'||$fresh['remote']['current']['state']!=='accepted')throw new RuntimeException('Return reconciliation differs');
    $report['provider_core_review_status']='accepted';
    $phase='retire_test_approver';
    $active=$pdo->query('SELECT is_active FROM users WHERE id='.(int)$reviewerId)->fetchColumn();
    $report['reviewer_retirement']=(int)$active===1?thunderhawkTimeReviewerRetire($pdo,$reviewerId,'browser-hour'):thunderhawkTimeReviewerVerifyRetired($pdo,$reviewerId,'browser-hour');
    if($protected()!==$before)throw new RuntimeException('Prior identities, time or payroll changed');
    $report['prior_records_unchanged']=true;$report['passed']=true;
}catch(Throwable $error){
    if($pdo->inTransaction())$pdo->rollBack();
    $report['passed']=false;$report['failed_phase']=$phase;$report['failure_class']=get_class($error);
    $report['failure_source']=basename($error->getFile());$report['failure_line']=$error->getLine();
    if($error instanceof QuantaApiException)$report['provider_http_status']=$error->httpStatus;
}finally{unset($_SESSION['user']);}
echo json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
exit($report['passed']?0:1);
