<?php
/** Deliver exactly one approved demo packet through installed native review-only services. */
declare(strict_types=1);
const PAYROLL_INPUT_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
if(PHP_SAPI!=='cli'||($argv[1]??'')!=='--receive-approved-demo-hours'||getenv('COREFLUX_ENV')!=='staging'
    ||realpath(getcwd())!==PAYROLL_INPUT_ROOT){fwrite(STDERR,"Exact isolated pilot and explicit review-input mode required.\n");exit(2);}
$files=['core/integrations/purepay_payroll_inputs.php','core/integrations/purepay_links.php',
    'core/integrations/purepay_core.php','core/integrations/delivery.php','core/integrations/purepay_observations.php',
    'core/integrations/purepay_observation_contract.php','core/quanta/time_current.php',
    'core/quanta/time_review_delivery.php','core/quanta/time_review_contract.php','core/quanta/core_contract.php',
    'core/quanta/sync.php','core/quanta/workspace.php','modules/time/lib/time.php',
    'core/memberships.php','core/RBAC.php','core/rbac/permissions.php','core/rbac/legacy_map.php','api/purepay_core.php'];
foreach($files as $file){
    $reviewed=dirname(__DIR__).'/'.$file;$installed=PAYROLL_INPUT_ROOT.'/'.$file;
    if(!is_file($reviewed)||!is_file($installed)||realpath($reviewed)===realpath($installed)
        ||!hash_equals(hash_file('sha256',$reviewed),hash_file('sha256',$installed))){fwrite(STDERR,"Native services differ from reviewed source.\n");exit(2);}
}
require_once PAYROLL_INPUT_ROOT.'/core/db.php';$pdo=getDB();
if(!$pdo||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||!defined('COREFLUX_STAGING')||COREFLUX_STAGING!==true
    ||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=999 AND is_active=1')->fetchColumn()!==1){fwrite(STDERR,"Isolated simulation database required.\n");exit(2);}
foreach(['core/sub_tenants.php','core/integrations/purepay_observations.php','core/quanta/time_review_delivery.php',
    'modules/time/lib/time.php','core/memberships.php','core/RBAC.php','core/rbac/permissions.php','core/rbac/legacy_map.php'] as $file)require_once PAYROLL_INPUT_ROOT.'/'.$file;
require_once __DIR__.'/synthetic_time_reviewer.php';
require_once __DIR__.'/synthetic_time_approval_guard.php';
require_once __DIR__.'/synthetic_payroll_input_guard.php';
$report=['checked_at'=>gmdate('c'),'mode'=>'synthetic_payroll_input_review','tenant_id'=>999,'organization_id'=>SYNTHETIC_PAYROLL_ORG,
    'production_changed'=>false,'application_deployed'=>false,'payroll_execution_requested'=>false,'financial_posting_requested'=>false];
$phase='environment';$locked=false;$flag=getenv('COREFLUX_PUREPAY_PAYROLL_INPUT_DELIVERY');
try{
    $locked=(int)$pdo->query("SELECT GET_LOCK('core_sim_999_payroll_input_20261025',10)")->fetchColumn()===1;
    if(!$locked)throw new RuntimeException('Another demo input review is running');
    if(integrationInstanceId()!==SYNTHETIC_PAYROLL_INSTANCE||purepayCoreApiBase()!=='https://pure-pay-support.preview.emergentagent.com/api/v1'
        ||quantaApiBase()!=='https://clockin-hub-16.preview.emergentagent.com/api/v1')throw new RuntimeException('Instance or destination differs');
    $owners=[];foreach(['time','people','placements'] as $module){
        $owners[$module]=effectiveTenantIdForModule($module,999);
        if($owners[$module]!==999)throw new RuntimeException('Directory owner changed');
    }
    $operator=$pdo->query("SELECT id,role,is_global_admin FROM users WHERE id=4 AND email='stage-admin@coreflux.test' AND is_active=1")->fetch(PDO::FETCH_ASSOC);
    if(!$operator||(RBACResolver::activeMembership(4,999)['status']??null)!=='active')throw new RuntimeException('Operator unavailable');
    $operator['global_role']=$operator['role'];$operator['role']=RBACResolver::personaTypeOf(4,999);
    foreach(['accounting.bank.manage','payroll.runs.manage','time.view','people.view','placements.view','placements.financials.view'] as $permission){
        [$module,$action]=RbacLegacyMap::resolve($permission);
        if(!RBAC::hasPermission($operator,$permission)||!RBACResolver::can($operator,999,$module,$action))throw new RuntimeException('Operator permission missing');
    }
    setRequestTenantId(999);$report['reviewer_retirement']=syntheticTimeReviewerVerifyRetired($pdo,51);
    $phase='approved_source';$entry=timeEntryGet(1);$rate=timeResolveRateSnapshot(13,'2026-10-19');
    $quanta=quantaTimeReviewSnapshot(999,'d9caaf9a-877b-4f59-89d9-c2b71983a676');
    syntheticTimeApprovalGuard($entry??[],$rate??[],$quanta);
    if($entry['status']!=='approved'||$quanta['status']!=='unchanged'||($quanta['remote']['current']['state']??null)!=='accepted')throw new RuntimeException('Demo approval is not current');
    // All simulation-owned business rows are protected except the intended delivery and its audit.
    $tables=$pdo->query("SELECT c.TABLE_NAME FROM information_schema.COLUMNS c JOIN information_schema.TABLES t
        ON t.TABLE_SCHEMA=c.TABLE_SCHEMA AND t.TABLE_NAME=c.TABLE_NAME WHERE c.TABLE_SCHEMA=DATABASE()
        AND c.COLUMN_NAME='tenant_id' AND t.TABLE_TYPE='BASE TABLE' ORDER BY c.TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
    $protected=static function()use($pdo,$tables):array{
        $hashes=[];foreach($tables as $table){
            if(in_array($table,['integration_deliveries','audit_log'],true))continue;
            if(!preg_match('/^[a-zA-Z0-9_]+$/D',$table))throw new RuntimeException('Invalid table identity');
            $rows=$pdo->query('SELECT * FROM `'.$table.'` WHERE tenant_id=999')->fetchAll(PDO::FETCH_ASSOC);
            $values=array_map(static fn($row)=>serialize($row),$rows);sort($values,SORT_STRING);$hashes[$table]=hash('sha256',serialize($values));
        }
        $hashes['source_claims_by_time_owner']=hash('sha256',serialize($pdo->query('SELECT * FROM payroll_source_claims WHERE time_tenant_id=999 ORDER BY time_entry_id')->fetchAll(PDO::FETCH_ASSOC)));
        $hashes['test_and_operator_accounts']=hash('sha256',serialize($pdo->query('SELECT * FROM users WHERE id IN (4,51) ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)));
        return $hashes;
    };
    $before=$protected();$report['protected_local_sets']=count($before);
    $connection=purepayCoreConnection($pdo,999);
    if(!$connection||(int)$connection['revision']!==3||$connection['remote_organization_id']!==SYNTHETIC_PAYROLL_ORG)throw new RuntimeException('Company binding differs');
    $phase='native_snapshot';$snapshot=purepayPayrollInputSnapshot($pdo,999,SYNTHETIC_PAYROLL_INSTANCE,$owners,'P-15','2026-10-19','2026-10-19');
    syntheticPayrollInputGuard($snapshot['payload']);$body=$snapshot['body'];$key=$snapshot['access']['key'];
    $remoteId='cpi_'.integrationDeliveryHash([SYNTHETIC_PAYROLL_ORG,integrationDeliveryHash($body)]);
    $providerProtected=static function()use($pdo,$key):array{
        $person=purepayCoreGet($key,'/records/person/'.SYNTHETIC_PAYROLL_EMPLOYEE);
        $assignment=purepayCoreGet($key,'/records/placement/'.SYNTHETIC_PAYROLL_ASSIGNMENT);
        if(($person['status']??null)!=='inactive'||($assignment['status']??null)!=='draft')throw new RuntimeException('Demo provider lifecycle differs');
        foreach(['auto_link','auto_bills','bills_drive_invoicing'] as $field)if(($assignment[$field]??null)!==false)throw new RuntimeException('Demo assignment automation is not held');
        $runs=purepayObservationPage($pdo,999,SYNTHETIC_PAYROLL_INSTANCE,100);
        if($runs['next_cursor']!==null)throw new RuntimeException('Demo payroll inventory is incomplete');
        // Observation time is a read timestamp; compare the validated content hashes instead.
        $runHashes=[];foreach($runs['items'] as $run)$runHashes[$run['run_id']]=$run['observation_hash'];ksort($runHashes);
        return ['person'=>$person,'assignment'=>$assignment,'payroll_runs'=>$runHashes];
    };
    $phase='provider_preflight';$providerBefore=$providerProtected();
    $inputs=purepayCoreGet($key,'/payroll/inputs?limit=100');
    if(!is_array($inputs['items']??null)||!array_key_exists('next_cursor',$inputs)||$inputs['next_cursor']!==null||count($inputs['items'])>1
        ||(count($inputs['items'])===1&&($inputs['items'][0]['id']??null)!==$remoteId))throw new RuntimeException('Unexpected demo input inventory');
    $existing=$pdo->query('SELECT * FROM integration_deliveries WHERE tenant_id=999 AND provider="purepayroll" AND operation="payroll.inputs" ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    if(count($existing)>1)throw new RuntimeException('Unexpected local input history');
    if($existing){
        [$row,$payload]=purepayPayrollInputEnvelope($pdo,999,(int)$existing[0]['id'],$existing[0]['review_hash'],SYNTHETIC_PAYROLL_INSTANCE,$owners);
        if(integrationDeliveryHash($payload)!==integrationDeliveryHash($snapshot['payload']))throw new RuntimeException('Existing input differs');
    }
    $historyBefore=$pdo->query('SELECT * FROM integration_deliveries WHERE tenant_id=999 AND NOT(provider="purepayroll" AND operation="payroll.inputs") ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $phase='review_preview';$preview=purepayPayrollInputPreview($pdo,999,4,SYNTHETIC_PAYROLL_INSTANCE,$owners,'P-15','2026-10-19','2026-10-19');
    if(integrationDeliveryHash($preview['packet'])!==integrationDeliveryHash($body))throw new RuntimeException('Source changed before preview');
    $delivery=$preview['delivery'];$id=(int)$delivery['id'];$hash=$delivery['review_hash'];$report['delivery_id']=$id;
    $audit=static function()use($pdo,$id):void{
        $pdo->prepare('INSERT INTO audit_log(tenant_id,actor_user_id,event,target_id,meta_json,ip_address,created_at) VALUES(999,4,?,NULL,?,NULL,NOW())')
            ->execute(['purepay.core.payroll_input_send',json_encode(['action'=>'payroll_input_send','delivery_id'=>$id,'synthetic'=>true,'payroll_execution_requested'=>false],JSON_THROW_ON_ERROR)]);
    };
    $phase='native_delivery';putenv('COREFLUX_PUREPAY_PAYROLL_INPUT_DELIVERY=1');
    if(in_array($delivery['state'],['sending','uncertain'],true))$sent=purepayPayrollInputReconcile($pdo,999,SYNTHETIC_PAYROLL_INSTANCE,$owners,$id,$hash,$audit);
    else $sent=purepayPayrollInputSend($pdo,999,4,SYNTHETIC_PAYROLL_INSTANCE,$owners,$id,$hash,$audit);
    if($sent['state']!=='delivered')throw new RuntimeException('Input receipt is unresolved; reconcile without resending');
    $report['input_delivered']=true;
    if(purepayPayrollInputSend($pdo,999,4,SYNTHETIC_PAYROLL_INSTANCE,$owners,$id,$hash,$audit)!==$sent)throw new RuntimeException('Native delivery replay differs');
    $phase='provider_readback';$first=null;
    for($attempt=0;$attempt<2;$attempt++){
        $remote=purepayCoreGet($key,'/payroll/inputs/'.$remoteId);syntheticPayrollInputReadbackGuard($remote,$body);
        if($attempt===0)$first=$remote;elseif($remote!==$first)throw new RuntimeException('Provider receipt repeat differs');
    }
    $inputsAfter=purepayCoreGet($key,'/payroll/inputs?limit=100');
    if(!array_key_exists('next_cursor',$inputsAfter)||$inputsAfter['next_cursor']!==null||count($inputsAfter['items']??[])!==1||($inputsAfter['items'][0]['id']??null)!==$remoteId)throw new RuntimeException('Input receipt inventory differs');
    $phase='unchanged_state';$afterSnapshot=purepayPayrollInputSnapshot($pdo,999,SYNTHETIC_PAYROLL_INSTANCE,$owners,'P-15','2026-10-19','2026-10-19');
    if(integrationDeliveryHash($afterSnapshot['payload'])!==integrationDeliveryHash($snapshot['payload'])
        ||$protected()!==$before||$providerProtected()!==$providerBefore||purepayCoreConnection($pdo,999)!==$connection
        ||$pdo->query('SELECT * FROM integration_deliveries WHERE tenant_id=999 AND NOT(provider="purepayroll" AND operation="payroll.inputs") ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)!==$historyBefore)
        throw new RuntimeException('Protected source, payroll or integration state changed');
    $history=purepayPayrollInputHistory($pdo,999,SYNTHETIC_PAYROLL_INSTANCE,$owners,'P-15');
    if(count($history['items'])!==1||$history['next_before']!==null||$history['items'][0]['delivery']!==$sent)throw new RuntimeException('Saved input history differs');
    $auditCheck=$pdo->prepare('SELECT meta_json FROM audit_log WHERE tenant_id=999 AND actor_user_id=4 AND event="purepay.core.payroll_input_send" AND JSON_EXTRACT(meta_json,"$.delivery_id")=?');
    $auditCheck->execute([$id]);$audits=$auditCheck->fetchAll(PDO::FETCH_COLUMN);
    if(count($audits)!==1||(json_decode($audits[0],true)['synthetic']??null)!==true)throw new RuntimeException('Input audit is not unique');
    $report+=['passed'=>true,'person_number'=>'P-15','placement_number'=>'PL-13','source_time_entry_id'=>1,
        'hours'=>'8.00','pay_rate'=>'30.0000','currency'=>'USD','earnings_preview'=>'240.00','input_receipt_id'=>$remoteId,
        'content_hash'=>integrationDeliveryHash($body),'provider_state'=>'received_for_review','can_apply'=>false,
        'payroll_created'=>false,'source_time_reserved'=>false,'native_replay_identical'=>true,'provider_receipt_reads'=>2,
        'provider_repeat_identical'=>true,'single_saved_input_and_audit'=>true,'source_and_protected_local_state_unchanged'=>true,
        'provider_employee_assignment_and_payroll_inventory_unchanged'=>true,'provider_run_count'=>count($providerBefore['payroll_runs'])];
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();$report['passed']=false;$report['failed_phase']=$phase;
    $report['failure_class']=get_class($e);$report['failure_source']=basename($e->getFile());$report['failure_line']=$e->getLine();
    if($e instanceof QuantaApiException)$report['provider_http_status']=$e->httpStatus;
}finally{
    putenv($flag===false?'COREFLUX_PUREPAY_PAYROLL_INPUT_DELIVERY':'COREFLUX_PUREPAY_PAYROLL_INPUT_DELIVERY='.$flag);
    $report['delivery_flag_restored']=getenv('COREFLUX_PUREPAY_PAYROLL_INPUT_DELIVERY')===$flag;setRequestTenantId(null);
    if($locked)$pdo->query("SELECT RELEASE_LOCK('core_sim_999_payroll_input_20261025')");
}
echo 'CORE_PILOT_PAYROLL_INPUT='.json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
exit($report['passed']?0:1);
