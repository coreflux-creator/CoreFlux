<?php
/** Reconcile the held pilot draft to its original approved time; encrypted observation only. */
declare(strict_types=1);
const TH_RESULT_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const TH_RESULT_INSTANCE='9f4b6dfb-e52e-464c-9a9d-af7766928ddf';
const TH_RESULT_AUTHORITY='cpa_687c5888d2cc9f28aeda46fb8a1251b33562e33075f3563abc09df3073563961';
$browserHour=($argv[1]??'')==='--observe-browser-hour-held-draft';
if(PHP_SAPI!=='cli'||!in_array($argv[1]??'',['--observe-isolated-held-draft','--observe-browser-hour-held-draft'],true)||getenv('COREFLUX_ENV')!=='staging'
    ||realpath(getcwd())!==TH_RESULT_ROOT)exit(2);
require_once TH_RESULT_ROOT.'/core/integrations/purepay_payroll_results.php';
require_once TH_RESULT_ROOT.'/core/sub_tenants.php';
require_once TH_RESULT_ROOT.'/core/rbac/permissions.php';
$pdo=getDB();
if(!$pdo||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||!COREFLUX_STAGING
    ||integrationInstanceId()!==TH_RESULT_INSTANCE||purepayCoreApiBase()!=='https://purepay.online:19443/api/v1'
    ||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=2002 AND is_active=1')->fetchColumn()!==1
    ||!RBACResolver::can(4,2002,'payroll','read')||!RBACResolver::can(4,2002,'integrations','admin'))exit(2);
$authority=$browserHour?'cpa_0d44c7f660e4181fd94ee6d418082e0576c0687fdbec0e0afaf6e41ab1212357':TH_RESULT_AUTHORITY;
$deliveryId=$browserHour?40:34;
$inputId=$browserHour?'cpi_390401c8dcc2bc7b7e979232c4c294ad6347d42e4a920bd0777dd693572a399b':'cpi_2c219ec937ea221bead21b8b4cb3391b099bd7bdbe19deb570d42882d726bcd9';
$runId=$browserHour?'run_core_af63f6606e0cdfdb12fb3c9d60df37451d59feb32a1f0316fdc88d995c91c23d':'run_core_792fd19f59a1a78e389b3885a4ebbf5d13e2690e223126af839f8378826045ab';
$gross=$browserHour?'65.00':'520.00';$entryId=$browserHour?3:2;
$report=['checked_at'=>gmdate('c'),'tenant_id'=>2002,'authority_id'=>$authority,
    'production_changed'=>false,'payroll_executed'=>false,'financial_postings'=>false,
    'tax_calculation_validated'=>false,'onboarding_changed'=>false];$phase='guard';
try{
    setRequestTenantId(2002);$owners=[];
    foreach(['people','placements','time'] as $module){$owners[$module]=effectiveTenantIdForModule($module,2002);if($owners[$module]!==2002)throw new RuntimeException('Owner differs');}
    $row=$pdo->query('SELECT review_hash,state,remote_id FROM integration_deliveries WHERE tenant_id=2002 AND id='.$deliveryId)->fetch(PDO::FETCH_ASSOC);
    if(!$row||$row['state']!=='delivered'||$row['remote_id']!==$inputId)throw new RuntimeException('Input differs');
    $timeBefore=$pdo->query('SELECT * FROM time_entries WHERE tenant_id=2002')->fetchAll(PDO::FETCH_ASSOC);
    $phase='native_result';
    $audit=static function()use($pdo,$authority,$deliveryId):void{
        $pdo->prepare('INSERT INTO audit_log(tenant_id,actor_user_id,event,target_id,meta_json,created_at) VALUES(2002,4,"purepay.core.payroll_result_refresh",NULL,?,NOW())')
            ->execute([json_encode(['authority_id'=>$authority,'delivery_id'=>$deliveryId,'synthetic'=>true,'payroll_execution_requested'=>false],JSON_THROW_ON_ERROR)]);
    };
    $saved=purepayPayrollResultRefresh($pdo,2002,4,TH_RESULT_INSTANCE,$owners,$deliveryId,$row['review_hash'],$authority,$audit);
    $r=$saved['result'];
    if($r['run_id']!==$runId
        ||$r['organization_id']!=='org_0369a63ff2cb'||$r['status']!=='draft'||$r['execution_hold']!==true
        ||$r['totals']['source_gross']!==$gross||$r['totals']['gross']!==$gross||count($r['lines'])!==1
        ||$r['lines'][0]['employee_id']!=='emp_ea232efec736'||$r['lines'][0]['components'][0]['input_component']['source']['time_entry_id']!==$entryId)
        throw new RuntimeException('Held draft reconciliation differs');
    $again=purepayPayrollResultRefresh($pdo,2002,4,TH_RESULT_INSTANCE,$owners,$deliveryId,$row['review_hash'],$authority,$audit);
    if(!$again['replayed']||$again['id']!==$saved['id']||$again['result']['result_hash']!==$r['result_hash'])throw new RuntimeException('Observation replay differs');
    $st=$pdo->prepare('SELECT payload_ct FROM payroll_provider_results WHERE tenant_id=2002 AND id=?');$st->execute([$saved['id']]);$cipher=$st->fetchColumn();
    if(!is_string($cipher)||str_contains($cipher,'"gross"')||(json_decode((string)decryptField($cipher),true)['result_hash']??null)!==$r['result_hash'])throw new RuntimeException('Encrypted observation differs');
    if($timeBefore!==$pdo->query('SELECT * FROM time_entries WHERE tenant_id=2002')->fetchAll(PDO::FETCH_ASSOC))throw new RuntimeException('Observation changed time');
    $report+=['passed'=>true,'observation_id'=>$saved['id'],'run_id'=>$r['run_id'],'state'=>$r['status'],
        'calculation_mode'=>$r['calculation_mode'],'source_gross'=>$r['totals']['source_gross'],'gross'=>$r['totals']['gross'],
        'execution_hold'=>$r['execution_hold'],'result_hash'=>$r['result_hash'],'original_source_reconciled'=>true,
        'replay_reuses_observation'=>true,'encrypted_result_verified'=>true,'source_time_unchanged'=>true];
}catch(Throwable $error){
    if($pdo->inTransaction())$pdo->rollBack();
    $report['passed']=false;$report['failed_phase']=$phase;$report['failure_class']=get_class($error);
    $report['failure_source']=basename($error->getFile());$report['failure_line']=$error->getLine();
}
echo json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
exit($report['passed']?0:1);
