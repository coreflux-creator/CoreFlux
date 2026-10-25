<?php
/** Read back the exact held demo draft through installed native result services. */
declare(strict_types=1);
const RESULT_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const RESULT_INPUT='cpi_ee8ffda42ef94cd2ab75632afdc8e1c2fe9cd16bde05e04c620add98dd2996ed';
if(PHP_SAPI!=='cli'||($argv[1]??'')!=='--observe-demo-draft'||getenv('COREFLUX_ENV')!=='staging'
    ||realpath(getcwd())!==RESULT_ROOT){fwrite(STDERR,"Exact isolated pilot and explicit result review required.\n");exit(2);}
$files=['core/integrations/purepay_payroll_reservation_review.php','core/integrations/purepay_payroll_authority.php',
    'core/integrations/purepay_payroll_results.php','core/integrations/purepay_payroll_inputs.php',
    'core/integrations/purepay_links.php','core/integrations/purepay_core.php','core/integrations/delivery.php',
    'core/integrations/purepay_observations.php','core/integrations/purepay_observation_contract.php',
    'core/memberships.php','core/RBAC.php','core/rbac/permissions.php','core/rbac/legacy_map.php','api/purepay_core.php'];
foreach($files as $file){
    $reviewed=dirname(__DIR__).'/'.$file;$installed=RESULT_ROOT.'/'.$file;
    if(!is_file($reviewed)||!is_file($installed)||realpath($reviewed)===realpath($installed)
        ||!hash_equals(hash_file('sha256',$reviewed),hash_file('sha256',$installed))){fwrite(STDERR,"Native services differ from reviewed source.\n");exit(2);}
}
require_once RESULT_ROOT.'/core/db.php';$pdo=getDB();
if(!$pdo||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||!defined('COREFLUX_STAGING')||COREFLUX_STAGING!==true
    ||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=999 AND is_active=1')->fetchColumn()!==1){fwrite(STDERR,"Isolated simulation database required.\n");exit(2);}
foreach(['core/sub_tenants.php','core/integrations/purepay_payroll_reservation_review.php','core/integrations/purepay_observations.php',
    'core/memberships.php','core/RBAC.php','core/rbac/permissions.php','core/rbac/legacy_map.php'] as $file)require_once RESULT_ROOT.'/'.$file;
require_once __DIR__.'/synthetic_payroll_input_guard.php';
$report=['checked_at'=>gmdate('c'),'mode'=>'synthetic_held_payroll_result','tenant_id'=>999,'organization_id'=>SYNTHETIC_PAYROLL_ORG,
    'input_receipt_id'=>RESULT_INPUT,'production_changed'=>false,'application_deployed'=>false,'provider_record_writes'=>false,
    'payroll_execution_requested'=>false,'financial_posting_requested'=>false,'source_release_requested'=>false];
$phase='environment';$locked=false;
try{
    $locked=(int)$pdo->query("SELECT GET_LOCK('core_sim_999_payroll_result_20261025',10)")->fetchColumn()===1;
    if(!$locked)throw new RuntimeException('Another demo result review is running');
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
    setRequestTenantId(999);$phase='original_source';$row=integrationDeliveryGet($pdo,999,18);
    [$row,$payload]=purepayPayrollInputEnvelope($pdo,999,18,$row['review_hash'],SYNTHETIC_PAYROLL_INSTANCE,$owners);
    syntheticPayrollInputGuard($payload);
    if($row['state']!=='delivered'||$row['remote_id']!==RESULT_INPUT||$row['remote_receipt_id']!==RESULT_INPUT
        ||integrationDeliveryHash($payload['body'])!=='31e294a40ba0d2a25d1d525d27c0a4b2cd7e1ec5ee3076512500ca79dfcca3d0')
        throw new RuntimeException('Accepted demo input differs');
    $hash=$row['review_hash'];$request=hash('sha256','core-simulation-999-delivery-18-reservation-20261025');
    $authorityId='cpa_'.integrationDeliveryHash([SYNTHETIC_PAYROLL_INSTANCE,999,$request]);
    $authority=purepayPayrollResultAuthority($pdo,999,SYNTHETIC_PAYROLL_INSTANCE,$owners,$row,$payload,$authorityId);
    if($authority['state']!=='held')throw new RuntimeException('Expected source reservation is not held');
    $body=purepayPayrollAuthorityBody($authority);$runId='run_core_'.integrationDeliveryHash([SYNTHETIC_PAYROLL_ORG,$authorityId]);
    $claims=$pdo->query('SELECT * FROM payroll_source_claims WHERE time_tenant_id=999 ORDER BY time_entry_id')->fetchAll(PDO::FETCH_ASSOC);
    if(count($claims)!==1||(int)$claims[0]['time_entry_id']!==1||$claims[0]['authority_id']!==$authorityId
        ||(int)$claims[0]['placement_tenant_id']!==999||(int)$claims[0]['rate_snapshot_id']!==1)throw new RuntimeException('Source claim attribution differs');
    $tables=$pdo->query("SELECT c.TABLE_NAME FROM information_schema.COLUMNS c JOIN information_schema.TABLES t
        ON t.TABLE_SCHEMA=c.TABLE_SCHEMA AND t.TABLE_NAME=c.TABLE_NAME WHERE c.TABLE_SCHEMA=DATABASE()
        AND c.COLUMN_NAME='tenant_id' AND t.TABLE_TYPE='BASE TABLE' ORDER BY c.TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
    $protected=static function()use($pdo,$tables):array{
        $hashes=[];foreach($tables as $table){
            if(in_array($table,['payroll_provider_results','audit_log'],true))continue;
            if(!preg_match('/^[a-zA-Z0-9_]+$/D',$table))throw new RuntimeException('Invalid table identity');
            $rows=$pdo->query('SELECT * FROM `'.$table.'` WHERE tenant_id=999')->fetchAll(PDO::FETCH_ASSOC);
            $values=array_map(static fn($row)=>serialize($row),$rows);sort($values,SORT_STRING);$hashes[$table]=hash('sha256',serialize($values));
        }
        $hashes['source_claims']=hash('sha256',serialize($pdo->query('SELECT * FROM payroll_source_claims WHERE time_tenant_id=999 ORDER BY time_entry_id')->fetchAll(PDO::FETCH_ASSOC)));
        $hashes['test_and_operator_accounts']=hash('sha256',serialize($pdo->query('SELECT * FROM users WHERE id IN (4,51) ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)));
        return $hashes;
    };
    $before=$protected();$report['protected_local_sets']=count($before);
    $access=purepayPayrollAuthorityAccess($pdo,999,SYNTHETIC_PAYROLL_INSTANCE,$authority,false);$key=$access['key'];
    $guard=static function(array $result)use($authorityId,$runId):void{
        if($result['organization_id']!==SYNTHETIC_PAYROLL_ORG||$result['input_id']!==RESULT_INPUT||$result['run_id']!==$runId
            ||$result['status']!=='draft'||$result['execution_hold']!==true||$result['archived']!==false
            ||$result['authority_receipt']['authority']['id']!==$authorityId||$result['authority_receipt']['state']!=='allocated'
            ||$result['totals']['source_gross']!=='240.00'||$result['totals']['gross']!=='240.00'
            ||$result['totals']['bonus']!=='0.00'||$result['totals']['reimbursements']!=='0.00'
            ||count($result['lines'])!==1||$result['lines'][0]['employee_id']!==SYNTHETIC_PAYROLL_EMPLOYEE)
            throw new RuntimeException('The exact held demo draft is required');
    };
    $phase='provider_preflight';
    $first=purepayPayrollResultValidate(purepayCoreGet($key,'/payroll/authorities/'.$authorityId.'/result'),$body,$payload['body']);$guard($first);
    $providerProtected=static function()use($pdo,$key):array{
        $person=purepayCoreGet($key,'/records/person/'.SYNTHETIC_PAYROLL_EMPLOYEE);
        $assignment=purepayCoreGet($key,'/records/placement/'.SYNTHETIC_PAYROLL_ASSIGNMENT);
        $runs=purepayObservationPage($pdo,999,SYNTHETIC_PAYROLL_INSTANCE,100);
        if($runs['next_cursor']!==null)throw new RuntimeException('Demo payroll inventory is incomplete');
        $hashes=[];foreach($runs['items'] as $run)$hashes[$run['run_id']]=$run['observation_hash'];ksort($hashes);
        return ['person'=>$person,'assignment'=>$assignment,'payroll_runs'=>$hashes];
    };
    $providerBefore=$providerProtected();$audit=static function()use($pdo,$authorityId):void{
        $pdo->prepare('INSERT INTO audit_log(tenant_id,actor_user_id,event,target_id,meta_json,ip_address,created_at) VALUES(999,4,?,NULL,?,NULL,NOW())')
            ->execute(['purepay.core.payroll_result_refresh',json_encode(['authority_id'=>$authorityId,'delivery_id'=>18,'synthetic'=>true,'payroll_execution_requested'=>false],JSON_THROW_ON_ERROR)]);
    };
    $phase='native_result_observation';$saved=purepayPayrollResultRefresh($pdo,999,4,SYNTHETIC_PAYROLL_INSTANCE,$owners,18,$hash,$authorityId,$audit);$guard($saved['result']);
    if($saved['result']['result_hash']!==$first['result_hash'])throw new RuntimeException('Provider result changed during review');
    $again=purepayPayrollResultRefresh($pdo,999,4,SYNTHETIC_PAYROLL_INSTANCE,$owners,18,$hash,$authorityId,$audit);$guard($again['result']);
    if(!$again['replayed']||$again['id']!==$saved['id']||$again['result']['result_hash']!==$saved['result']['result_hash'])throw new RuntimeException('Result replay differs');
    $phase='saved_history';$history=purepayPayrollResultHistory($pdo,999,SYNTHETIC_PAYROLL_INSTANCE,$owners,18,$hash);
    if($history['more_authorities']||$history['next_before']!==null||count($history['authorities'])!==1||count($history['items'])!==1
        ||$history['items'][0]['id']!==$saved['id']||$history['items'][0]['result']['result_hash']!==$saved['result']['result_hash'])throw new RuntimeException('Saved result history differs');
    $s=$pdo->prepare('SELECT payload_ct FROM payroll_provider_results WHERE tenant_id=999 AND id=?');$s->execute([$saved['id']]);$cipher=$s->fetchColumn();
    if(!is_string($cipher)||str_contains($cipher,'"gross"')
        ||(json_decode((string)decryptField($cipher),true)['result_hash']??null)!==$saved['result']['result_hash'])throw new RuntimeException('Saved result encryption differs');
    $phase='unchanged_state';
    if($protected()!==$before||$providerProtected()!==$providerBefore)throw new RuntimeException('Protected source or provider state changed');
    $s=$pdo->prepare('SELECT COUNT(*) FROM audit_log WHERE tenant_id=999 AND actor_user_id=4 AND event="purepay.core.payroll_result_refresh" AND JSON_UNQUOTE(JSON_EXTRACT(meta_json,"$.authority_id"))=?');$s->execute([$authorityId]);
    if((int)$s->fetchColumn()!==1)throw new RuntimeException('Result audit is not unique');
    $report+=['passed'=>true,'delivery_id'=>18,'authority_id'=>$authorityId,'provider_run_id'=>$runId,'observation_id'=>$saved['id'],
        'result_hash'=>$saved['result']['result_hash'],'hours'=>'8.00','state'=>'draft','execution_hold'=>true,'totals'=>$saved['result']['totals'],
        'provider_result_reads'=>3,'native_replay_reuses_observation'=>true,'single_saved_result_and_audit'=>true,'saved_result_encrypted'=>true,
        'source_and_claims_unchanged'=>true,'provider_employee_assignment_and_payroll_inventory_unchanged'=>true,
        'provider_run_count'=>count($providerBefore['payroll_runs']),'delivery_or_settlement_evaluated'=>false];
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();$report['passed']=false;$report['failed_phase']=$phase;
    $report['failure_class']=get_class($e);$report['failure_source']=basename($e->getFile());$report['failure_line']=$e->getLine();
    if($e instanceof QuantaApiException)$report['provider_http_status']=$e->httpStatus;
}finally{
    setRequestTenantId(null);if($locked)$pdo->query("SELECT RELEASE_LOCK('core_sim_999_payroll_result_20261025')");
}
echo 'CORE_PILOT_PAYROLL_RESULT='.json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
exit($report['passed']?0:1);
