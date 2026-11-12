<?php
/** Publish only the existing isolated placement's source reporting values. */
declare(strict_types=1);
const AC_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const AC_PRIVATE='/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030';
const AC_SITE='1bc9011d-12df-458e-b79d-c31f09b9ee09';
const AC_CASE='4e861f71-d0bd-45cb-b31b-0ccd27b0e957';
const AC_STATE=AC_PRIVATE.'/assignment-context-publish-20261112.json';
if(PHP_SAPI!=='cli'||posix_geteuid()!==1003||($argv[1]??'')!=='--publish-isolated-reporting-context'
    ||getenv('COREFLUX_ENV')!=='staging'||getcwd()!==AC_ROOT||file_exists(AC_STATE))exit(2);
require_once AC_ROOT.'/core/quanta/metadata_delivery.php';
require_once AC_ROOT.'/core/quanta/onboarding_delivery.php';
require_once AC_ROOT.'/core/rbac/permissions.php';
$pdo=getDB();
if(!$pdo||!COREFLUX_STAGING||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'
    ||integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf'
    ||quantaApiBase()!=='https://helloquanta.app:19443/api/v1'
    ||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=2002 AND is_active=1')->fetchColumn()!==1
    ||!RBACResolver::can(4,2002,'integrations','admin')||QUANTA_ASSIGNMENT_CONTEXT_VERSION!=='2026-11-12.1')exit(2);
$lock=fopen(AC_PRIVATE.'/contact-install.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit(2);
$report=['started_at'=>gmdate('c'),'passed'=>false,'tenant_id'=>2002,'placement'=>'PL-864','person'=>'P-358',
    'production_changed'=>false,'financial_execution'=>false,'browser_acceptance'=>false];
function acSave(string $step):void{global $report;$report['step']=$step;file_put_contents(AC_STATE,json_encode($report,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT),LOCK_EX);}
$oldMask=umask(0077);$f=fopen(AC_STATE,'x');umask($oldMask);if(!$f)exit(2);fclose($f);
$flags=[];
try{
    foreach(['people','placements','time','companies','ap']as$module)if(effectiveTenantIdForModule($module,2002)!==2002)throw new RuntimeException('Owner differs');
    setRequestTenantId(2002);$_SESSION['user']=['id'=>4,'role'=>'tenant_admin','tenant_id'=>2002];
    foreach(['COREFLUX_QUANTA_METADATA_DELIVERY','COREFLUX_QUANTA_ONBOARDING_DELIVERY']as$name){$flags[$name]=getenv($name);putenv($name.'=1');}
    $max=[];foreach(['integration_deliveries','audit_log']as$table)$max[$table]=(int)$pdo->query('SELECT COALESCE(MAX(id),0) FROM '.$table)->fetchColumn();
    $snapshot=static function()use($pdo,$max):array{
        $out=[];
        foreach($pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN)as$table){
            if(!preg_match('/^[A-Za-z0-9_]+$/D',$table))throw new RuntimeException('Unexpected table');
            $rows=[];$sql='SELECT * FROM `'.$table.'`'.(isset($max[$table])?' WHERE id<='.$max[$table]:'');
            foreach($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC)as$row)$rows[]=hash('sha256',serialize($row));sort($rows);
            $out[$table]=['count'=>count($rows),'sha256'=>hash('sha256',implode("\n",$rows))];
        }ksort($out);return $out;
    };
    $before=$snapshot();acSave('review_source');
    $expected=['branch'=>'Jen/Saurabh','department'=>null,'service_line'=>null,'cost_center'=>null,'workers_comp_class'=>null,'remote_policy'=>'onsite'];
    $source=quantaMetadataSnapshot(2002,'placement',864,AC_SITE);$old=$source['remote']['assignment'];
    if($source['verified']['context']['workspace']['id']!=='4f93d4e7-af74-4765-b812-3464bb6d974b'
        ||$source['source']['reference']['number']!=='PL-864'||$source['source']['person']['number']!=='P-358'
        ||$source['source']['assignment']['assignment_context']!==$expected||($old['status']??null)!=='active'
        ||($old['worksite_id']??null)!==AC_SITE||array_key_exists('assignment_context',$old))throw new RuntimeException('Source or original assignment differs');
    $report['original_assignment_version']=$old['version'];$report['assignment_id']=$old['id'];$report['reviewed_context']=$expected;acSave('source_reviewed');
    $scope=['tenant_id'=>2002,'people_tenant_id'=>2002,'placements_tenant_id'=>2002,'actor_user_id'=>4,
        'permissions'=>['view'=>true,'manage'=>true,'review'=>true,'documents_manage'=>true]];
    $storage=new \Core\StorageService();$head=static fn(string $key):?array=>$storage->head($key);
    $case=onboardingView($pdo,$scope,AC_CASE,$head);
    if($case['version']!==11||$case['readiness']!=='complete'||$case['source_changed'])throw new RuntimeException('Existing checklist needs review');
    $preview=quantaMetadataPreview(2002,4,'placement',864,AC_SITE);
    if($preview['status']!=='preview'||$preview['proposed']!==['expected_version'=>$old['version'],'assignment_context'=>$expected])throw new RuntimeException('Unexpected assignment changes');
    $delivery=$preview['delivery'];$report['assignment_delivery_id']=(int)$delivery['id'];acSave('assignment_send_prepared');
    $sent=quantaMetadataSend(2002,4,(int)$delivery['id'],$delivery['review_hash']);
    if($sent['state']!=='delivered'||quantaMetadataSend(2002,4,(int)$delivery['id'],$delivery['review_hash'])!==$sent)throw new RuntimeException('Metadata receipt mismatch');
    acSave('assignment_confirmed');
    $fresh=quantaMetadataSnapshot(2002,'placement',864,AC_SITE);$current=$fresh['remote']['assignment'];
    if($fresh['plan']['status']!=='unchanged'||$current['id']!==$old['id']||$current['version']!==$old['version']+1||$current['assignment_context']!==$expected)throw new RuntimeException('Assignment did not reconcile');
    foreach(['person','placement','worker_id','person_link_id','client','vendor','end_client','status','start_date','end_date','worksite_id','work_location']as$field)
        if(($current[$field]??null)!==($old[$field]??null))throw new RuntimeException('Operational assignment changed');
    $preview=quantaOnboardingPreview($scope,AC_CASE,'published',$head);
    if($preview['status']!=='unchanged'){
        $delivery=$preview['delivery'];$report['onboarding_delivery_id']=(int)$delivery['id'];acSave('onboarding_refresh_prepared');
        $sent=quantaOnboardingSend($scope,AC_CASE,(int)$delivery['id'],$delivery['review_hash'],$head);
        if($sent['state']!=='delivered'||quantaOnboardingSend($scope,AC_CASE,(int)$delivery['id'],$delivery['review_hash'],$head)!==$sent)throw new RuntimeException('Onboarding receipt mismatch');
    }
    $onboarding=quantaOnboardingSnapshot($scope,AC_CASE,$head)['remote'];
    if($onboarding['publication']['assignment_version']!==$current['version']||$onboarding['publication']['core_version']!==11)throw new RuntimeException('Onboarding revision differs');
    foreach($onboarding['publication']['tasks']as$task)if($task['effective_state']!=='approved')throw new RuntimeException('Existing task decision changed');
    if($snapshot()!==$before)throw new RuntimeException('Original Core records changed');
    $report+=['passed'=>true,'assignment_version'=>$current['version'],'onboarding_case_version'=>11,
        'portal_onboarding_version'=>$onboarding['version'],'tables_preserved'=>count($before),'all_original_core_records_preserved'=>true,
        'replay_verified'=>true,'unchanged_preview_verified'=>true,'operational_identity_preserved'=>true];$report['passed']=true;acSave('complete');
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    $report+=['failure_class'=>get_class($e),'failure_message'=>$e->getMessage()];acSave('needs_inspection');
}finally{
    foreach($flags as$name=>$value)putenv($value===false?$name:$name.'='.$value);unset($_SESSION['user']);
    $report['completed_at']=gmdate('c');file_put_contents(AC_STATE,json_encode($report,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT),LOCK_EX);
}
echo json_encode($report,JSON_THROW_ON_ERROR)."\n";exit($report['passed']?0:1);
