<?php
/** Native approval and status reconciliation for the previously accepted eight-hour demo only. */
declare(strict_types=1);
const PILOT_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const PILOT_SOURCE='d9caaf9a-877b-4f59-89d9-c2b71983a676';
if (PHP_SAPI!=='cli'||($argv[1]??'')!=='--approve-demo-time-review'||getenv('COREFLUX_ENV')!=='staging'
    ||realpath(getcwd())!==PILOT_ROOT) {fwrite(STDERR,"Exact isolated pilot and explicit approval mode required.\n");exit(2);}
$files=['modules/time/lib/time.php','core/quanta/time_current.php','core/quanta/time_review_delivery.php',
    'core/quanta/time_review_contract.php','core/quanta/core_contract.php','core/quanta/sync.php',
    'core/quanta/workspace.php','core/integrations/delivery.php','core/memberships.php',
    'core/RBAC.php','core/rbac_config.php','core/rbac/permissions.php','core/rbac/legacy_map.php','core/audit.php'];
foreach($files as $file) {
    $reviewed=dirname(__DIR__).'/'.$file;$installed=PILOT_ROOT.'/'.$file;
    if(!is_file($reviewed)||!is_file($installed)||realpath($reviewed)===realpath($installed)
        ||!hash_equals(hash_file('sha256',$reviewed),hash_file('sha256',$installed))) {
        fwrite(STDERR,"Installed native services differ from reviewed source.\n");exit(2);
    }
}
require_once PILOT_ROOT.'/core/db.php';$pdo=getDB();
if(!$pdo||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||!defined('COREFLUX_STAGING')||COREFLUX_STAGING!==true
    ||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=999 AND is_active=1')->fetchColumn()!==1) {
    fwrite(STDERR,"Isolated simulation database required.\n");exit(2);
}
foreach(['core/sub_tenants.php','core/quanta/time_review_delivery.php','core/memberships.php','core/RBAC.php',
    'core/rbac/permissions.php','core/rbac/legacy_map.php','core/audit.php','modules/time/lib/time.php'] as $file) require_once PILOT_ROOT.'/'.$file;
require_once __DIR__.'/synthetic_time_reviewer.php';
require_once __DIR__.'/synthetic_time_approval_guard.php';
$report=['checked_at'=>gmdate('c'),'mode'=>'synthetic_native_time_approval','tenant_id'=>999,
    'production_changed'=>false,'application_deployed'=>false,'independent_human_review'=>false];
$phase='guard';$flag=getenv('COREFLUX_QUANTA_TIME_REVIEW_DELIVERY');$locked=false;
try {
    $locked=(int)$pdo->query("SELECT GET_LOCK('core_sim_999_time_review_20261025',10)")->fetchColumn()===1;
    if(!$locked)throw new RuntimeException('Another demo review is running');
    foreach(['people','placements','companies','time'] as $module)
        if(effectiveTenantIdForModule($module,999)!==999)throw new RuntimeException('Directory owner changed');
    if(integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf'
        ||quantaApiBase()!=='https://clockin-hub-16.preview.emergentagent.com/api/v1')throw new RuntimeException('Instance or destination differs');
    if(!$pdo->query("SELECT id FROM people WHERE tenant_id=999 AND id=15 AND status='active' AND deleted_at IS NULL
            AND email_primary='core-integration-20261023@example.com'")->fetchColumn()
        ||!$pdo->query("SELECT id FROM placements WHERE tenant_id=999 AND id=13 AND person_id=15 AND status='active'
            AND title='Synthetic Core Integration 20261023' AND deleted_at IS NULL")->fetchColumn())throw new RuntimeException('Demo identity differs');
    $report['reviewer']=syntheticTimeReviewerVerify($pdo,51);
    $reviewer=['id'=>51,'role'=>'manager','global_role'=>'manager','is_global_admin'=>0];
    $operator=$pdo->query("SELECT id,role,is_global_admin FROM users WHERE id=4 AND email='stage-admin@coreflux.test' AND is_active=1")->fetch(PDO::FETCH_ASSOC);
    if(!$operator||(RBACResolver::activeMembership(4,999)['status']??null)!=='active')throw new RuntimeException('Operator unavailable');
    $operator['global_role']=$operator['role'];$operator['role']=RBACResolver::personaTypeOf(4,999);
    foreach(['integrations.quanta.manage','time.view','placements.view','people.view'] as $permission) {
        [$module,$action]=RbacLegacyMap::resolve($permission);
        if(!RBAC::hasPermission($operator,$permission)||!RBACResolver::can($operator,999,$module,$action))throw new RuntimeException('Operator permission missing');
    }
    setRequestTenantId(999);
    $phase='source_preflight';$entry=timeEntryGet(1);$rate=timeResolveRateSnapshot(13,'2026-10-19');
    $snapshot=quantaTimeReviewSnapshot(999,PILOT_SOURCE);
    syntheticTimeApprovalGuard($entry??[],$rate??[],$snapshot);
    $report['source_identity_and_rates_verified']=true;
    // Snapshot every simulation-owned table except the explicitly allowed approval/delivery/audit rows.
    $tables=$pdo->query("SELECT c.TABLE_NAME FROM information_schema.COLUMNS c JOIN information_schema.TABLES t
        ON t.TABLE_SCHEMA=c.TABLE_SCHEMA AND t.TABLE_NAME=c.TABLE_NAME WHERE c.TABLE_SCHEMA=DATABASE()
        AND c.COLUMN_NAME='tenant_id' AND t.TABLE_TYPE='BASE TABLE' ORDER BY c.TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
    $excluded=['time_entries','staffing_timesheets','audit_log','integration_deliveries'];
    $protected=static function()use($pdo,$tables,$excluded):array {
        $hashes=[];foreach($tables as $table){
            if(in_array($table,$excluded,true))continue;
            if(!preg_match('/^[a-zA-Z0-9_]+$/D',$table))throw new RuntimeException('Invalid table identity');
            $rows=$pdo->query('SELECT * FROM `'.$table.'` WHERE tenant_id=999')->fetchAll(PDO::FETCH_ASSOC);
            $values=array_map(static fn($r)=>serialize($r),$rows);sort($values,SORT_STRING);
            $hashes[$table]=hash('sha256',serialize($values));
        }return $hashes;
    };
    $protectedBefore=$protected();$report['protected_tables_checked']=count($protectedBefore);
    $timeBefore=$pdo->query('SELECT * FROM time_entries WHERE tenant_id=999 AND id<>1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $sheetId=(int)$entry['timesheet_id'];
    $sheetStmt=$pdo->prepare('SELECT * FROM staffing_timesheets WHERE tenant_id=999 AND id<>? ORDER BY id');
    $sheetStmt->execute([$sheetId]);$sheetsBefore=$sheetStmt->fetchAll(PDO::FETCH_ASSOC);
    $phase='native_approval';
    if($entry['status']==='pending_review') {
        $_SESSION['user']=$reviewer;$pdo->beginTransaction();
        // The normal self-approval gate must still reject the original creator before any write.
        try{timeApproveEntry(1,$operator,'synthetic_pilot_review');throw new LogicException('Self-approval gate failed');}
        catch(RuntimeException $e){if($e->getCode()!==403)throw $e;}
        $report['self_approval_rejected']=true;
        $approved=timeApproveEntry(1,$reviewer,'synthetic_pilot_review');
        if($protected()!==$protectedBefore)throw new RuntimeException('Approval changed protected records');
        $audit=$pdo->prepare('SELECT meta_json FROM audit_log WHERE tenant_id=999 AND actor_user_id=51 AND event="time.entry.approved" AND target_id=1 ORDER BY id DESC LIMIT 1');
        $audit->execute();$meta=json_decode((string)$audit->fetchColumn(),true);
        if(($meta['approved_via']??null)!=='synthetic_pilot_review'||($meta['quanta_source_check']['workspace_id']??null)!=='0598d4ef-3075-4289-9714-c93f904aa85d'
            ||($meta['quanta_source_check']['source_entries']??null)!==1)throw new RuntimeException('Native approval audit proof missing');
        $pdo->commit();$report['new_approval']=true;
    } else {
        $approved=$entry;$report['new_approval']=false;
        $audit=$pdo->prepare('SELECT meta_json FROM audit_log WHERE tenant_id=999 AND actor_user_id=51 AND event="time.entry.approved" AND target_id=1 ORDER BY id DESC LIMIT 1');
        $audit->execute();$meta=json_decode((string)$audit->fetchColumn(),true);
        if(($meta['approved_via']??null)!=='synthetic_pilot_review'||($meta['quanta_source_check']['workspace_id']??null)!=='0598d4ef-3075-4289-9714-c93f904aa85d'
            ||($meta['quanta_source_check']['source_entries']??null)!==1)throw new RuntimeException('Prior native approval audit proof missing');
    }
    $report['time_approved']=true;$report['entry_id']=1;$report['approver_user_id']=51;$report['rate_snapshot_id']=(int)$approved['rate_snapshot_id'];
    $report['hours']=8;$report['native_approval_state']=$approved['status'];
    $phase='approval_repeat';$beforeRepeat=timeEntryGet(1);
    try{timeApproveEntry(1,$reviewer,'synthetic_pilot_review');throw new LogicException('Duplicate approval was accepted');}
    catch(RuntimeException $e){if($e->getCode()!==409)throw $e;}
    if(timeEntryGet(1)!==$beforeRepeat)throw new RuntimeException('Rejected repeat changed time');
    $report['duplicate_approval_rejected']=true;
    $phase='status_publication';$_SESSION['user']=$operator;
    if(defined('COREFLUX_QUANTA_TIME_REVIEW_DELIVERY')&&COREFLUX_QUANTA_TIME_REVIEW_DELIVERY!==true)throw new RuntimeException('Persistent publication hold');
    putenv('COREFLUX_QUANTA_TIME_REVIEW_DELIVERY=1');
    $preview=quantaTimeReviewPreview(999,4,PILOT_SOURCE);
    if(!empty($preview['receipt_only'])) {
        $d=$preview['delivery'];$recovered=quantaTimeReviewReceipt(999,(int)$d['id'],$d['review_hash']);
        if($recovered['delivery']['state']!=='delivered')throw new RuntimeException('Original status receipt remains uncertain');
        $report['recovered_delivery_id']=(int)$d['id'];$preview=quantaTimeReviewPreview(999,4,PILOT_SOURCE);
    }
    if(($preview['state']??null)!=='accepted'||!empty($preview['receipt_only']))throw new RuntimeException('Approved review not ready');
    if($preview['status']!=='unchanged') {
        $d=$preview['delivery'];$sent=quantaTimeReviewSend(999,4,(int)$d['id'],$d['review_hash']);
        if($sent['state']!=='delivered'||quantaTimeReviewSend(999,4,(int)$d['id'],$d['review_hash'])!==$sent)throw new RuntimeException('Status replay differs');
        $report['delivery']=array_intersect_key($sent,array_flip(['id','state','remote_id','remote_receipt_id']));
        $report['native_delivery_replay_verified']=true;
    }
    $phase='fresh_reconciliation';$fresh=quantaTimeReviewSnapshot(999,PILOT_SOURCE);
    syntheticTimeApprovalGuard(timeEntryGet(1)??[],timeResolveRateSnapshot(13,'2026-10-19')??[],$fresh);
    if($fresh['status']!=='unchanged'||$fresh['remote']['current']['state']!=='accepted')throw new RuntimeException('Quanta approval status differs');
    $report['remote']=array_intersect_key($fresh['remote']['current'],array_flip(['id','version','state','assignment_id','source_entry_id']));
    $report['fresh_comparison']='unchanged';
    $sheetStmt->execute([$sheetId]);
    if($protected()!==$protectedBefore||$timeBefore!==$pdo->query('SELECT * FROM time_entries WHERE tenant_id=999 AND id<>1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)
        ||$sheetsBefore!==$sheetStmt->fetchAll(PDO::FETCH_ASSOC))throw new RuntimeException('Unrelated records changed');
    $report['protected_records_unchanged']=true;$report['payroll_or_financial_writes']=false;
    $phase='retire_test_reviewer';
    $report['reviewer_retirement']=syntheticTimeReviewerRetire($pdo,51);
    $report['reviewer_cleanup_pending']=false;$report['passed']=true;
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    $report['passed']=false;$report['failed_phase']=$phase;$report['failure_class']=get_class($e);
    $report['failure_source']=basename($e->getFile());$report['failure_line']=$e->getLine();
    if($e instanceof QuantaApiException)$report['provider_http_status']=$e->httpStatus;
}finally{
    putenv($flag===false?'COREFLUX_QUANTA_TIME_REVIEW_DELIVERY':'COREFLUX_QUANTA_TIME_REVIEW_DELIVERY='.$flag);
    $report['publication_flag_restored']=getenv('COREFLUX_QUANTA_TIME_REVIEW_DELIVERY')===$flag;
    unset($_SESSION['user']);setRequestTenantId(null);
    if($locked)$pdo->query("SELECT RELEASE_LOCK('core_sim_999_time_review_20261025')");
}
echo 'CORE_PILOT_TIME_APPROVAL='.json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
exit($report['passed']?0:1);
