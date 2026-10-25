<?php
/** Retain the already-reviewed Meridian demo observation through installed Core services. */
declare(strict_types=1);

const CAPTURE_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const CAPTURE_INSTANCE='9f4b6dfb-e52e-464c-9a9d-af7766928ddf';
const CAPTURE_ORG='org_purepay_demo';
// Accepted read-only by workflow 40149435092. Includes the provider run identity and contents.
const CAPTURE_OBSERVATION='db95cf444407b8bd786028bf7fedf05e624fd09a4aced1c2bc6dd147e4b3c8c9';

if(PHP_SAPI!=='cli' || ($argv[1]??'')!=='--capture-reviewed-demo-payroll'
    || getenv('COREFLUX_ENV')!=='staging' || realpath(getcwd())!==CAPTURE_ROOT) {
    fwrite(STDERR,"The named isolated pilot and explicit demo capture are required.\n");exit(2);
}
// Comparison copies are never installed or executed.
foreach(['core/integrations/purepay_observations.php','core/integrations/purepay_observation_contract.php',
    'core/integrations/purepay_core.php','core/integrations/purepay_links.php',
    'core/integrations/purepay_payroll_inputs.php','core/integrations/delivery.php','api/purepay_core.php'] as $file) {
    $reviewed=dirname(__DIR__).'/'.$file;$installed=CAPTURE_ROOT.'/'.$file;
    if(!is_file($reviewed) || !is_file($installed) || realpath($reviewed)===realpath($installed)
        || !hash_equals(hash_file('sha256',$reviewed),hash_file('sha256',$installed))) {
        fwrite(STDERR,"Installed observation services differ from the reviewed source.\n");exit(2);
    }
}

$phase='environment';$exit=0;
$report=['checked_at'=>gmdate('c'),'tenant_id'=>999,'organization_id'=>CAPTURE_ORG,
    'mode'=>'synthetic_reviewed_payroll_capture','application_deployed'=>false,
    'permissions_changed'=>false,'provider_record_writes'=>false,'payroll_or_payments_executed'=>false,
    'core_time_match_evaluated'=>false,'settlement_evaluated'=>false,'ledger_posted'=>false];
try {
    require_once CAPTURE_ROOT.'/core/db.php';
    require_once CAPTURE_ROOT.'/core/integrations/purepay_observations.php';
    require_once CAPTURE_ROOT.'/core/sub_tenants.php';
    require_once CAPTURE_ROOT.'/core/RBAC.php';
    require_once CAPTURE_ROOT.'/core/rbac/permissions.php';
    require_once CAPTURE_ROOT.'/core/rbac/legacy_map.php';
    $pdo=getDB();
    if(!$pdo || $pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'
        || !defined('COREFLUX_STAGING') || COREFLUX_STAGING!==true
        || integrationInstanceId()!==CAPTURE_INSTANCE
        || purepayCoreApiBase()!=='https://pure-pay-support.preview.emergentagent.com/api/v1'
        || (int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=999 AND is_active=1')->fetchColumn()!==1)
        throw new RuntimeException('Simulation identity changed');
    $owners=[];
    foreach(['people','placements','time'] as $module) {
        $owners[$module]=effectiveTenantIdForModule($module,999);
        if($owners[$module]!==999)throw new RuntimeException('Directory owner changed');
    }
    $phase='existing_operator';
    $operator=$pdo->query("SELECT id,role FROM users WHERE id=4 AND email='stage-admin@coreflux.test'")->fetch(PDO::FETCH_ASSOC);
    $membership=RBACResolver::activeMembership(4,999);
    if(!$operator || ($membership['status']??null)!=='active')throw new RuntimeException('Existing operator unavailable');
    $operator['global_role']=$operator['role'];$operator['role']=RBACResolver::personaTypeOf(4,999);
    foreach(['accounting.bank.manage','payroll.view','people.view','time.view','placements.view',
        'placements.financials.view','payroll.runs.manage'] as $permission) {
        [$module,$action]=RbacLegacyMap::resolve($permission);
        if(!RBAC::hasPermission($operator,$permission) || !RBACResolver::can($operator,999,$module,$action))
            throw new RuntimeException('Existing operator lacks permission');
    }
    setRequestTenantId(999);
    $phase='company_binding';
    $connection=purepayCoreConnection($pdo,999);
    if(!$connection || (int)$connection['revision']!==3 || $connection['remote_organization_id']!==CAPTURE_ORG
        || !purepayCoreReview($pdo,999,CAPTURE_INSTANCE)['verified'])throw new RuntimeException('Company binding changed');
    $snapshot=static function()use($pdo):array {
        $rows=[];
        foreach(['integration_deliveries','time_entries','staffing_timesheets','payroll_runs',
            'payroll_source_authorities','payroll_provider_results'] as $table) {
            $rows[$table]=integrationDeliveryHash($pdo->query('SELECT * FROM '.$table.' WHERE tenant_id=999 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
        }
        $rows['payroll_source_claims']=integrationDeliveryHash($pdo->query('SELECT * FROM payroll_source_claims WHERE time_tenant_id=999 ORDER BY time_entry_id')->fetchAll(PDO::FETCH_ASSOC));
        foreach(['purepay_connections','purepay_core_connections'] as $table)
            $rows[$table]=integrationDeliveryHash($pdo->query('SELECT * FROM '.$table.' ORDER BY tenant_id')->fetchAll(PDO::FETCH_ASSOC));
        return $rows;
    };
    $phase='unchanged_state';$before=$snapshot();
    $phase='reviewed_provider_run';
    $page=purepayObservationPage($pdo,999,CAPTURE_INSTANCE,1);
    if(count($page['items'])!==1 || $page['items'][0]['observation_hash']!==CAPTURE_OBSERVATION)
        throw new RuntimeException('Previously reviewed demo observation changed');
    $runId=$page['items'][0]['run_id'];
    $review=purepayObservationRead($pdo,999,CAPTURE_INSTANCE,$owners,$runId);
    if($review['observation']['observation_hash']!==CAPTURE_OBSERVATION
        || count($review['observation']['lines'])!==2
        || array_filter($review['comparison']['people'],static fn($p)=>$p['link_status']!=='unlinked' || $p['person']!==null))
        throw new RuntimeException('Previously reviewed demo comparison changed');
    $count=static fn():int=>(int)$pdo->query('SELECT COUNT(*) FROM payroll_native_observations WHERE tenant_id=999')->fetchColumn();
    $captureBefore=$count();
    // The runner may recover its own one capture, but cannot append to unrelated demo history.
    $history=purepayObservationHistory($pdo,999,CAPTURE_INSTANCE,$owners,$runId);
    if($captureBefore!==count($history['items']) || $captureBefore>1 || $history['next_before']!==null
        || ($captureBefore===1 && ($history['items'][0]['saved']['review_hash']!==$review['review_hash'])))
        throw new RuntimeException('Unexpected existing capture history');
    $auditCount=static function()use($pdo,$runId):int {
        $s=$pdo->prepare('SELECT COUNT(*) FROM audit_log WHERE tenant_id=999 AND actor_user_id=4 AND event=? AND JSON_UNQUOTE(JSON_EXTRACT(meta_json,"$.provider_run_id"))=?');
        $s->execute(['purepay.core.payroll_observation_save',$runId]);return (int)$s->fetchColumn();
    };
    $auditBefore=$auditCount();
    if($auditBefore!==$captureBefore)throw new RuntimeException('Unexpected existing capture audit');
    $audit=static function()use($pdo,$runId):void {
        $pdo->prepare('INSERT INTO audit_log(tenant_id,actor_user_id,event,target_id,meta_json,ip_address,created_at) VALUES(999,4,?,NULL,?,NULL,NOW())')
            ->execute(['purepay.core.payroll_observation_save',json_encode(['action'=>'payroll_observation_save','provider_run_id'=>$runId],JSON_THROW_ON_ERROR)]);
    };
    $phase='capture';
    $saved=purepayObservationSave($pdo,999,4,CAPTURE_INSTANCE,$owners,$runId,$review['review_hash'],$audit);
    $report['capture_id']=$saved['id'];$report['existing_capture_recovered']=$saved['replayed'];
    $phase='replay';
    $replayed=purepayObservationSave($pdo,999,4,CAPTURE_INSTANCE,$owners,$runId,$review['review_hash'],$audit);
    if(!$replayed['replayed'] || $saved['id']!==$replayed['id'] || integrationDeliveryHash($saved['saved'])!==integrationDeliveryHash($replayed['saved'])
        || $count()!==1 || $auditCount()!==1)throw new RuntimeException('Capture replay or audit differs');
    $phase='encrypted_history';
    $history=purepayObservationHistory($pdo,999,CAPTURE_INSTANCE,$owners,$runId);
    if(count($history['items'])!==1 || $history['items'][0]['id']!==$saved['id']
        || integrationDeliveryHash($history['items'][0]['saved'])!==integrationDeliveryHash($saved['saved']) || $history['next_before']!==null)
        throw new RuntimeException('Saved history differs');
    $s=$pdo->prepare('SELECT payload_ct FROM payroll_native_observations WHERE id=? AND tenant_id=999');$s->execute([$saved['id']]);
    $ciphertext=$s->fetchColumn();
    if(!is_string($ciphertext) || $ciphertext==='' || str_contains($ciphertext,'"gross"')
        || integrationDeliveryJson(json_decode((string)decryptField($ciphertext),true,64,JSON_THROW_ON_ERROR))!==integrationDeliveryJson($saved['saved']))
        throw new RuntimeException('Capture encryption did not verify');
    $phase='unchanged_after_capture';
    if($snapshot()!==$before)throw new RuntimeException('Unrelated local state changed');
    $report+=['passed'=>true,'observation_hash'=>CAPTURE_OBSERVATION,'line_count'=>2,'linked_people'=>0,
        'captures_added'=>1-$captureBefore,'audits_added'=>1-$auditBefore,'capture_saved'=>true,
        'identical_replay'=>true,'encrypted_history_verified'=>true,'unrelated_local_state_unchanged'=>true];
}catch(Throwable $e){
    $report['passed']=false;$report['failed_phase']=$phase;$report['failure_class']=get_class($e);
    $report['source_file']=basename($e->getFile());$report['source_line']=$e->getLine();$exit=1;
}finally{
    if(function_exists('setRequestTenantId'))setRequestTenantId(null);
}
echo 'CORE_PILOT_PAYROLL_CAPTURE='.json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
exit($exit);
