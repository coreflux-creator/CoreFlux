<?php
/** Native approval of exactly eight isolated test hours; no human sign-off or payroll execution. */
declare(strict_types=1);
const TH_APPROVE_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const TH_APPROVE_HELPER='/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030/time-reviewer.php';
const TH_APPROVE_SOURCE='ad44e619-7f34-4f8e-a008-1cd1bf33992e';
if(PHP_SAPI!=='cli'||($argv[1]??'')!=='--approve-isolated-test-hours'||getenv('COREFLUX_ENV')!=='staging'
    ||realpath(getcwd())!==TH_APPROVE_ROOT||!is_file(TH_APPROVE_HELPER)
    ||hash('sha256',rtrim(str_replace("\r\n","\n",file_get_contents(TH_APPROVE_HELPER)),"\r\n"))!=='7f46997ea749d4b0cea64ef225e96a60f9c3db5bfdf2fa0fa3006d1fee9f341a')exit(2);
require_once TH_APPROVE_ROOT.'/core/quanta/time_review_delivery.php';
require_once TH_APPROVE_ROOT.'/core/memberships.php';
require_once TH_APPROVE_ROOT.'/core/RBAC.php';
require_once TH_APPROVE_ROOT.'/core/rbac/permissions.php';
require_once TH_APPROVE_ROOT.'/core/rbac/legacy_map.php';
require_once TH_APPROVE_HELPER;
$pdo=getDB();
if(!$pdo||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||!COREFLUX_STAGING
    ||integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf'
    ||quantaApiBase()!=='https://helloquanta.app:19443/api/v1'
    ||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=2002 AND is_active=1')->fetchColumn()!==1)exit(2);
$report=['checked_at'=>gmdate('c'),'tenant_id'=>2002,'entry_id'=>2,'hours'=>8,
    'production_changed'=>false,'independent_human_review'=>false,'payroll_or_financial_postings'=>false];
$phase='guard';$flag=getenv('COREFLUX_QUANTA_TIME_REVIEW_DELIVERY');
try{
    setRequestTenantId(2002);
    foreach(['people','placements','time'] as $module)if(effectiveTenantIdForModule($module,2002)!==2002)throw new RuntimeException('Owner mismatch');
    $operator=$pdo->query("SELECT id,role FROM users WHERE id=4 AND email='stage-admin@coreflux.test' AND is_active=1")->fetch(PDO::FETCH_ASSOC);
    if(!$operator||!RBACResolver::can(4,2002,'time','admin')||!RBACResolver::can(4,2002,'integrations','admin'))throw new RuntimeException('Operator mismatch');
    $operator['global_role']=$operator['role'];$operator['role']=RBACResolver::personaTypeOf(4,2002);
    $_SESSION['user']=$operator;
    $entry=timeEntryGet(2);$rate=timeResolveRateSnapshot(864,'2026-10-19');
    foreach(['tenant_id'=>2002,'person_id'=>358,'placement_id'=>864,'timesheet_id'=>3,'created_by_user_id'=>4] as $field=>$value)
        if((int)($entry[$field]??0)!==$value)throw new RuntimeException('Test identity differs');
    if($entry['status']!=='pending_review'||$entry['work_date']!=='2026-10-19'||(float)$entry['hours']!==8.0
        ||$entry['source_system']!=='quanta'||$entry['hour_type']!=='regular'||(int)$rate['id']!==2
        ||(float)$rate['bill_rate']!==98.0||(float)$rate['pay_rate']!==65.0||$rate['currency']!=='USD')throw new RuntimeException('Reviewed source differs');
    foreach(['bill_extracted_at','ap_extracted_at','payroll_extracted_at','superseded_by_id'] as $field)
        if($entry[$field]!==null)throw new RuntimeException('Time already consumed');
    $snapshot=quantaTimeReviewSnapshot(2002,TH_APPROVE_SOURCE);
    if($snapshot['payload']['workspace_id']!=='4f93d4e7-af74-4765-b812-3464bb6d974b'
        ||$snapshot['payload']['body']['components']!==[['entry_id'=>2,'component'=>'regular','status'=>'pending_review']]
        ||$snapshot['entry']['source_approval']['timesheet_id']!=='96c12ef7-bd65-4909-b27e-eb68fb551f4d')throw new RuntimeException('Source approval differs');
    $tables=$pdo->query("SELECT c.TABLE_NAME FROM information_schema.COLUMNS c JOIN information_schema.TABLES t
        ON t.TABLE_SCHEMA=c.TABLE_SCHEMA AND t.TABLE_NAME=c.TABLE_NAME WHERE c.TABLE_SCHEMA=DATABASE()
        AND c.COLUMN_NAME='tenant_id' AND t.TABLE_TYPE='BASE TABLE' ORDER BY c.TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
    $protected=static function()use($pdo,$tables):array{
        $hashes=[];
        foreach($tables as $table){
            if(in_array($table,['time_entries','staffing_timesheets','audit_log','integration_deliveries','users','tenant_memberships','user_tenants','membership_audit'],true))continue;
            if(!preg_match('/^[a-zA-Z0-9_]+$/D',$table))throw new RuntimeException('Invalid table');
            $values=array_map('serialize',$pdo->query('SELECT * FROM `'.$table.'` WHERE tenant_id=2002')->fetchAll(PDO::FETCH_ASSOC));
            sort($values,SORT_STRING);$hashes[$table]=hash('sha256',serialize($values));
        }return $hashes;
    };
    $before=$protected();
    $phase='provision_separate_test_reviewer';
    $report['reviewer']=thunderhawkTimeReviewerProvision($pdo);$reviewerId=$report['reviewer']['user_id'];
    $st=$pdo->prepare('SELECT id,role FROM users WHERE id=?');$st->execute([$reviewerId]);$reviewer=$st->fetch(PDO::FETCH_ASSOC);
    $reviewer['global_role']=$reviewer['role'];$reviewer['role']=RBACResolver::personaTypeOf($reviewerId,2002);
    $phase='native_approval';$pdo->beginTransaction();
    try{timeApproveEntry(2,$operator,'manual');throw new LogicException('Self approval allowed');}
    catch(RuntimeException $error){if($error->getCode()!==403)throw $error;}
    $report['self_approval_rejected']=true;$_SESSION['user']=$reviewer;
    $approved=timeApproveEntry(2,$reviewer,'manual');
    $audit=$pdo->prepare('SELECT meta_json FROM audit_log WHERE tenant_id=2002 AND actor_user_id=? AND event="time.entry.approved" AND target_id=2 ORDER BY id DESC LIMIT 1');
    $audit->execute([$reviewerId]);$meta=json_decode((string)$audit->fetchColumn(),true);
    if(($meta['quanta_source_check']['workspace_id']??null)!=='4f93d4e7-af74-4765-b812-3464bb6d974b'
        ||($meta['quanta_source_check']['source_entries']??null)!==1||$protected()!==$before)throw new RuntimeException('Native approval evidence differs');
    platformAuditLogWrite(2002,$reviewerId,'time.pilot.reviewed',2,['synthetic'=>true,'independent_human_review'=>false,
        'source_entry_id'=>TH_APPROVE_SOURCE,'native_source_proof'=>$meta['quanta_source_check']],['source'=>'thunderhawk_pilot']);
    $pdo->commit();
    $repeatBefore=timeEntryGet(2);
    try{timeApproveEntry(2,$reviewer,'manual');throw new LogicException('Duplicate approval allowed');}
    catch(RuntimeException $error){if($error->getCode()!==409)throw $error;}
    if(timeEntryGet(2)!==$repeatBefore)throw new RuntimeException('Duplicate changed approved time');
    $report['duplicate_approval_rejected']=true;$report['native_approval_state']=$approved['status'];
    $report['rate_snapshot_id']=(int)$approved['rate_snapshot_id'];
    $phase='return_accepted_review';$_SESSION['user']=$operator;putenv('COREFLUX_QUANTA_TIME_REVIEW_DELIVERY=1');
    $preview=quantaTimeReviewPreview(2002,4,TH_APPROVE_SOURCE);$delivery=$preview['delivery'];
    if($preview['state']!=='accepted')throw new RuntimeException('Return state differs');
    $sent=quantaTimeReviewSend(2002,4,(int)$delivery['id'],$delivery['review_hash']);
    if($sent['state']!=='delivered'||quantaTimeReviewSend(2002,4,(int)$delivery['id'],$delivery['review_hash'])!==$sent)throw new RuntimeException('Status replay differs');
    $report['delivery']=array_intersect_key($sent,array_flip(['id','state','remote_id','remote_receipt_id']));
    $fresh=quantaTimeReviewSnapshot(2002,TH_APPROVE_SOURCE);
    if($fresh['status']!=='unchanged'||$fresh['remote']['current']['state']!=='accepted')throw new RuntimeException('Status reconciliation differs');
    $report['provider_core_review_status']='accepted';
    $phase='retire_test_approver';$report['reviewer_retirement']=thunderhawkTimeReviewerRetire($pdo,$reviewerId);
    if($protected()!==$before)throw new RuntimeException('Protected records changed');
    $report['protected_tables_unchanged']=count($before);$report['passed']=true;
}catch(Throwable $error){
    if($pdo->inTransaction())$pdo->rollBack();
    $report['passed']=false;$report['failed_phase']=$phase;$report['failure_class']=get_class($error);
    $report['failure_source']=basename($error->getFile());$report['failure_line']=$error->getLine();
    if($error instanceof QuantaApiException)$report['provider_http_status']=$error->httpStatus;
}finally{
    putenv($flag===false?'COREFLUX_QUANTA_TIME_REVIEW_DELIVERY':'COREFLUX_QUANTA_TIME_REVIEW_DELIVERY='.$flag);
    unset($_SESSION['user']);
}
echo json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
exit($report['passed']?0:1);
