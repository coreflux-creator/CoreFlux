<?php
/** Reserve only the accepted demo input using installed operator services; never create payroll. */
declare(strict_types=1);
const RESERVATION_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const RESERVATION_INPUT='cpi_ee8ffda42ef94cd2ab75632afdc8e1c2fe9cd16bde05e04c620add98dd2996ed';
if(PHP_SAPI!=='cli'||($argv[1]??'')!=='--reserve-demo-hours'||getenv('COREFLUX_ENV')!=='staging'
    ||realpath(getcwd())!==RESERVATION_ROOT){fwrite(STDERR,"Exact isolated pilot and explicit reservation mode required.\n");exit(2);}
$files=['core/integrations/purepay_payroll_reservation_review.php','core/integrations/purepay_payroll_authority.php',
    'core/integrations/purepay_payroll_results.php','core/integrations/purepay_payroll_inputs.php',
    'core/integrations/purepay_links.php','core/integrations/purepay_core.php','core/integrations/delivery.php',
    'core/integrations/purepay_observations.php','core/integrations/purepay_observation_contract.php',
    'core/memberships.php','core/RBAC.php','core/rbac/permissions.php','core/rbac/legacy_map.php','api/purepay_core.php'];
foreach($files as $file){
    $reviewed=dirname(__DIR__).'/'.$file;$installed=RESERVATION_ROOT.'/'.$file;
    if(!is_file($reviewed)||!is_file($installed)||realpath($reviewed)===realpath($installed)
        ||!hash_equals(hash_file('sha256',$reviewed),hash_file('sha256',$installed))){fwrite(STDERR,"Native services differ from reviewed source.\n");exit(2);}
}
require_once RESERVATION_ROOT.'/core/db.php';$pdo=getDB();
if(!$pdo||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||!defined('COREFLUX_STAGING')||COREFLUX_STAGING!==true
    ||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=999 AND is_active=1')->fetchColumn()!==1){fwrite(STDERR,"Isolated simulation database required.\n");exit(2);}
foreach(['core/sub_tenants.php','core/integrations/purepay_payroll_reservation_review.php','core/integrations/purepay_observations.php',
    'core/memberships.php','core/RBAC.php','core/rbac/permissions.php','core/rbac/legacy_map.php'] as $file)require_once RESERVATION_ROOT.'/'.$file;
require_once __DIR__.'/synthetic_payroll_input_guard.php';
$report=['checked_at'=>gmdate('c'),'mode'=>'synthetic_payroll_source_reservation','tenant_id'=>999,'organization_id'=>SYNTHETIC_PAYROLL_ORG,
    'input_receipt_id'=>RESERVATION_INPUT,'production_changed'=>false,'application_deployed'=>false,
    'payroll_creation_requested'=>false,'payroll_execution_requested'=>false,'financial_posting_requested'=>false];
$phase='environment';$locked=false;$flag=getenv('COREFLUX_PUREPAY_PAYROLL_AUTHORITY');
try{
    $locked=(int)$pdo->query("SELECT GET_LOCK('core_sim_999_payroll_reservation_20261025',10)")->fetchColumn()===1;
    if(!$locked)throw new RuntimeException('Another demo reservation review is running');
    if(integrationInstanceId()!==SYNTHETIC_PAYROLL_INSTANCE||purepayCoreApiBase()!=='https://pure-pay-support.preview.emergentagent.com/api/v1')
        throw new RuntimeException('Instance or destination differs');
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
    setRequestTenantId(999);
    $phase='received_input';$row=integrationDeliveryGet($pdo,999,18);
    [$row,$payload]=purepayPayrollInputEnvelope($pdo,999,18,$row['review_hash'],SYNTHETIC_PAYROLL_INSTANCE,$owners);
    syntheticPayrollInputGuard($payload);
    if($row['state']!=='delivered'||$row['remote_id']!==RESERVATION_INPUT||$row['remote_receipt_id']!==RESERVATION_INPUT
        ||integrationDeliveryHash($payload['body'])!=='31e294a40ba0d2a25d1d525d27c0a4b2cd7e1ec5ee3076512500ca79dfcca3d0')
        throw new RuntimeException('Accepted demo input differs');
    $hash=$row['review_hash'];$request=hash('sha256','core-simulation-999-delivery-18-reservation-20261025');
    $authorityId='cpa_'.integrationDeliveryHash([SYNTHETIC_PAYROLL_INSTANCE,999,$request]);
    $report['authority_id']=$authorityId;
    $history=purepayPayrollReservationHistory($pdo,999,SYNTHETIC_PAYROLL_INSTANCE,$owners,18,$hash);
    if($history['next_before']!==null||count($history['items'])>1
        ||($history['items']&&($history['items'][0]['id']!==$authorityId||$history['items'][0]['state']!=='held')))
        throw new RuntimeException('Existing source reservation requires review');
    $s=$pdo->prepare('SELECT COUNT(*) FROM payroll_source_authorities WHERE tenant_id=999 AND id<>?');$s->execute([$authorityId]);
    if((int)$s->fetchColumn()!==0)throw new RuntimeException('Unexpected simulation reservation inventory');
    $s=$pdo->prepare('SELECT COUNT(*) FROM payroll_source_claims WHERE time_tenant_id=999 AND (time_entry_id<>1 OR authority_id<>?)');$s->execute([$authorityId]);
    if((int)$s->fetchColumn()!==0)throw new RuntimeException('Unexpected simulation source claims');
    $tables=$pdo->query("SELECT c.TABLE_NAME FROM information_schema.COLUMNS c JOIN information_schema.TABLES t
        ON t.TABLE_SCHEMA=c.TABLE_SCHEMA AND t.TABLE_NAME=c.TABLE_NAME WHERE c.TABLE_SCHEMA=DATABASE()
        AND c.COLUMN_NAME='tenant_id' AND t.TABLE_TYPE='BASE TABLE' ORDER BY c.TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
    $protected=static function()use($pdo,$tables):array{
        $hashes=[];foreach($tables as $table){
            if(in_array($table,['payroll_source_authorities','audit_log'],true))continue;
            if(!preg_match('/^[a-zA-Z0-9_]+$/D',$table))throw new RuntimeException('Invalid table identity');
            $rows=$pdo->query('SELECT * FROM `'.$table.'` WHERE tenant_id=999')->fetchAll(PDO::FETCH_ASSOC);
            $values=array_map(static fn($row)=>serialize($row),$rows);sort($values,SORT_STRING);$hashes[$table]=hash('sha256',serialize($values));
        }
        $hashes['test_and_operator_accounts']=hash('sha256',serialize($pdo->query('SELECT * FROM users WHERE id IN (4,51) ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)));
        return $hashes;
    };
    $before=$protected();$report['protected_local_sets']=count($before);
    [,, $access]=purepayPayrollReservationAccess($pdo,999,SYNTHETIC_PAYROLL_INSTANCE,$owners,18,$hash);
    $key=$access['key'];$body=$payload['body'];
    syntheticPayrollInputReadbackGuard(purepayCoreGet($key,'/payroll/inputs/'.RESERVATION_INPUT),$body);
    $providerProtected=static function()use($pdo,$key):array{
        $person=purepayCoreGet($key,'/records/person/'.SYNTHETIC_PAYROLL_EMPLOYEE);
        $assignment=purepayCoreGet($key,'/records/placement/'.SYNTHETIC_PAYROLL_ASSIGNMENT);
        $runs=purepayObservationPage($pdo,999,SYNTHETIC_PAYROLL_INSTANCE,100);
        if($runs['next_cursor']!==null)throw new RuntimeException('Demo payroll inventory is incomplete');
        $hashes=[];foreach($runs['items'] as $run)$hashes[$run['run_id']]=$run['observation_hash'];ksort($hashes);
        return ['person'=>$person,'assignment'=>$assignment,'payroll_runs'=>$hashes];
    };
    $phase='provider_preflight';$providerBefore=$providerProtected();
    $audit=static function(string $event,array $meta)use($pdo):void{
        $meta+=['synthetic'=>true,'payroll_creation_requested'=>false];
        $pdo->prepare('INSERT INTO audit_log(tenant_id,actor_user_id,event,target_id,meta_json,ip_address,created_at) VALUES(999,4,?,NULL,?,NULL,NOW())')
            ->execute([$event,json_encode($meta,JSON_THROW_ON_ERROR)]);
    };
    $phase='native_reservation';putenv('COREFLUX_PUREPAY_PAYROLL_AUTHORITY=1');
    $authority=purepayPayrollReservationReserve($pdo,999,4,SYNTHETIC_PAYROLL_INSTANCE,$owners,18,$hash,$request,$audit);
    if($authority['id']!==$authorityId||$authority['state']!=='held'||$authority['input_id']!==RESERVATION_INPUT)
        throw new RuntimeException('Native source reservation differs');
    $report['source_reserved']=true;
    if(purepayPayrollReservationReserve($pdo,999,4,SYNTHETIC_PAYROLL_INSTANCE,$owners,18,$hash,$request,$audit)!==$authority)
        throw new RuntimeException('Source reservation replay differs');
    $phase='native_share';$sent=purepayPayrollReservationAct($pdo,999,4,SYNTHETIC_PAYROLL_INSTANCE,$owners,18,$hash,$authorityId,'send',$audit);
    if($sent['provider_receipt']['state']!=='available'||$sent['provider_receipt']['payroll_created']!==false)
        throw new RuntimeException('Provider reservation is not available without a draft');
    if(purepayPayrollReservationAct($pdo,999,4,SYNTHETIC_PAYROLL_INSTANCE,$owners,18,$hash,$authorityId,'send',$audit)!==$sent)
        throw new RuntimeException('Provider reservation replay differs');
    $phase='receipt_readback';
    for($i=0;$i<2;$i++)if(purepayPayrollReservationAct($pdo,999,4,SYNTHETIC_PAYROLL_INSTANCE,$owners,18,$hash,$authorityId,'reconcile')!==$sent)
        throw new RuntimeException('Reservation receipt repeat differs');
    $history=purepayPayrollReservationHistory($pdo,999,SYNTHETIC_PAYROLL_INSTANCE,$owners,18,$hash);
    if($history['items']!==[$authority]||$history['has_held']!==true||$history['next_before']!==null)throw new RuntimeException('Saved reservation history differs');
    $claims=$pdo->query('SELECT time_entry_id,authority_id,placement_tenant_id,rate_snapshot_id FROM payroll_source_claims WHERE time_tenant_id=999')->fetchAll(PDO::FETCH_ASSOC);
    if(count($claims)!==1||(int)$claims[0]['time_entry_id']!==1||$claims[0]['authority_id']!==$authorityId
        ||(int)$claims[0]['placement_tenant_id']!==999||(int)$claims[0]['rate_snapshot_id']!==1)throw new RuntimeException('Source claim attribution differs');
    $phase='unchanged_state';
    if($protected()!==$before||$providerProtected()!==$providerBefore)throw new RuntimeException('Protected business or provider state changed');
    syntheticPayrollInputReadbackGuard(purepayCoreGet($key,'/payroll/inputs/'.RESERVATION_INPUT),$body);
    $s=$pdo->prepare('SELECT meta_json FROM audit_log WHERE tenant_id=999 AND actor_user_id=4 AND event="purepay_payroll_source_reserved" AND JSON_UNQUOTE(JSON_EXTRACT(meta_json,"$.authority_id"))=?');
    $s->execute([$authorityId]);$audits=$s->fetchAll(PDO::FETCH_COLUMN);
    if(count($audits)!==1||(json_decode($audits[0],true)['synthetic']??null)!==true)throw new RuntimeException('Reservation audit is not unique');
    $report+=['passed'=>true,'delivery_id'=>18,'source_time_entry_id'=>1,'hours'=>'8.00','state'=>'held','provider_state'=>'available',
        'source_claims'=>1,'native_replay_identical'=>true,'provider_send_replay_identical'=>true,'provider_receipt_reads'=>2,
        'provider_repeat_identical'=>true,'single_saved_reservation_and_audit'=>true,'source_and_protected_local_state_unchanged'=>true,
        'provider_employee_assignment_and_payroll_inventory_unchanged'=>true,'provider_run_count'=>count($providerBefore['payroll_runs'])];
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();$report['passed']=false;$report['failed_phase']=$phase;
    $report['failure_class']=get_class($e);$report['failure_source']=basename($e->getFile());$report['failure_line']=$e->getLine();
    if($e instanceof QuantaApiException)$report['provider_http_status']=$e->httpStatus;
}finally{
    putenv($flag===false?'COREFLUX_PUREPAY_PAYROLL_AUTHORITY':'COREFLUX_PUREPAY_PAYROLL_AUTHORITY='.$flag);
    $report['reservation_flag_restored']=getenv('COREFLUX_PUREPAY_PAYROLL_AUTHORITY')===$flag;setRequestTenantId(null);
    if($locked)$pdo->query("SELECT RELEASE_LOCK('core_sim_999_payroll_reservation_20261025')");
}
echo 'CORE_PILOT_PAYROLL_RESERVATION='.json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
exit($report['passed']?0:1);
