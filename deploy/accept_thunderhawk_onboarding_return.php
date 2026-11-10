<?php
/** Import and review synthetic portal evidence only in the isolated Thunderhawk company. */
declare(strict_types=1);
const TH_ONBOARD_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const TH_ONBOARD_CASE='4e861f71-d0bd-45cb-b31b-0ccd27b0e957';
const TH_ONBOARD_WORKSPACE='4f93d4e7-af74-4765-b812-3464bb6d974b';
const TH_ONBOARD_DOC='cf1443b1-a424-4854-b4c2-40d618b46e79';
const TH_ONBOARD_ACK='1ae0e538-7b7f-4286-a6cc-0547dc90eac1';
const TH_ONBOARD_STAFF='7660819b-1bcc-42fd-ba37-36d24a53f36b';
const TH_ONBOARD_SHA='c73ab9af7fcd5b0ee83b187a41e5419f3e7a7ca864a7296508aee16754cc4417';
if(PHP_SAPI!=='cli'||($argv[1]??'')!=='--review-isolated-sample-evidence'||getenv('COREFLUX_ENV')!=='staging'
    ||realpath(getcwd())!==TH_ONBOARD_ROOT)exit(2);
require_once TH_ONBOARD_ROOT.'/core/sub_tenants.php';
require_once TH_ONBOARD_ROOT.'/core/quanta/onboarding_delivery.php';
require_once TH_ONBOARD_ROOT.'/core/RBAC.php';
require_once TH_ONBOARD_ROOT.'/core/rbac/permissions.php';
require_once TH_ONBOARD_ROOT.'/core/rbac/legacy_map.php';
$pdo=getDB();
if(!$pdo||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||!COREFLUX_STAGING
    ||integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf'
    ||quantaApiBase()!=='https://helloquanta.app:19443/api/v1'
    ||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=2002 AND is_active=1')->fetchColumn()!==1)exit(2);
$report=['checked_at'=>gmdate('c'),'tenant_id'=>2002,'case_id'=>TH_ONBOARD_CASE,
    'production_changed'=>false,'real_employment_approval'=>false,'independent_human_review'=>false,
    'payroll_or_financial_postings'=>false,'persistent_core_configuration_changed'=>false];
$flags=[];$phase='guard';$lock=false;
try{
    foreach(['people','placements'] as $module)if(effectiveTenantIdForModule($module,2002)!==2002)throw new RuntimeException('Owner differs');
    $operator=$pdo->query("SELECT id,role FROM users WHERE id=4 AND email='stage-admin@coreflux.test' AND is_active=1")->fetch(PDO::FETCH_ASSOC);
    if(!$operator||(RBACResolver::activeMembership(4,2002)['status']??null)!=='active')throw new RuntimeException('Operator differs');
    $operator['global_role']=$operator['role'];$operator['role']=RBACResolver::personaTypeOf(4,2002);
    foreach(['people.onboarding.view','people.onboarding.manage','people.onboarding.review','people.docs.manage','people.docs.view','integrations.quanta.manage'] as $permission){
        [$module,$action]=RbacLegacyMap::resolve($permission);
        if(!RBAC::hasPermission($operator,$permission)||!RBACResolver::can($operator,2002,$module,$action))throw new RuntimeException('Required native permission missing');
    }
    setRequestTenantId(2002);$_SESSION['user']=$operator;
    $lock=(int)$pdo->query("SELECT GET_LOCK('thunderhawk_2002_onboarding_20261030',10)")->fetchColumn()===1;
    if(!$lock)throw new RuntimeException('Another pilot intake is active');
    foreach(['COREFLUX_QUANTA_ONBOARDING_DELIVERY'=>'1','COREFLUX_QUANTA_ONBOARDING_DOCUMENTS'=>'1','COREFLUX_QUANTA_ONBOARDING_DOCUMENT_TENANTS'=>'2002'] as $name=>$value){$flags[$name]=getenv($name);putenv($name.'='.$value);}
    if(!quantaOnboardingDocumentStorageReady())throw new RuntimeException('Private S3 storage unavailable');
    $scope=['tenant_id'=>2002,'people_tenant_id'=>2002,'placements_tenant_id'=>2002,'actor_user_id'=>4,
        'permissions'=>['view'=>true,'manage'=>true,'review'=>true,'documents_manage'=>true]];
    $storage=new \Core\StorageService();$head=static fn(string $key):?array=>$storage->head($key);
    $protected=static function()use($pdo):array{
        $out=[];foreach(['people','placements','placement_rates','time_entries','staffing_timesheets','payroll_source_authorities','payroll_provider_results','accounting_entities'] as $table){
            $rows=array_map('serialize',$pdo->query('SELECT * FROM '.$table.' WHERE tenant_id=2002')->fetchAll(PDO::FETCH_ASSOC));sort($rows,SORT_STRING);$out[$table]=hash('sha256',serialize($rows));
        }return $out;
    };
    $before=$protected();
    $snapshot=quantaOnboardingSnapshot($scope,TH_ONBOARD_CASE,$head);
    if($snapshot['case']['person_id']!==358||$snapshot['case']['placement_id']!==864||$snapshot['case']['source_changed']
        ||$snapshot['access']['context']['workspace']['id']!==TH_ONBOARD_WORKSPACE||count($snapshot['remote']['submissions'])!==2)throw new RuntimeException('Source case differs');
    $phase='attestation_intake';
    $ack='cfs_d40479a98fa4c29be9e7f327caf47117c55e582f19b85c360fe602381dcd148e';
    $received=quantaOnboardingImport($scope,TH_ONBOARD_CASE,$ack,30,5,$head);
    $again=quantaOnboardingImport($scope,TH_ONBOARD_CASE,$ack,30,5,$head);
    if(!$again['replayed']||$received['case_version']!==6||$received['task_id']!==TH_ONBOARD_ACK)throw new RuntimeException('Attestation replay differs');
    $report['attestation_import']=$received;
    $phase='private_document_intake';
    $doc='cfs_80b180e017f5901815b8cdf11b9c2b762123943eb224da68565658e3a2d323fa';
    $received=quantaOnboardingImportDocument($scope,TH_ONBOARD_CASE,$doc,30,6,$storage);
    $again=quantaOnboardingImportDocument($scope,TH_ONBOARD_CASE,$doc,30,6,$storage);
    if(!$again['replayed']||$received['case_version']!==7||$received['task_id']!==TH_ONBOARD_DOC
        ||$received['document_id']!==$again['document_id'])throw new RuntimeException('Document replay differs');
    $report['document_import']=$received;
    $case=onboardingView($pdo,$scope,TH_ONBOARD_CASE,$head);
    $tasks=array_column($case['tasks'],null,'id');$evidence=$tasks[TH_ONBOARD_DOC]['evidence'];
    if($evidence['sha256']!==TH_ONBOARD_SHA||$evidence['document_id']!==$received['document_id']
        ||$evidence['provider_workspace_id']!==TH_ONBOARD_WORKSPACE||$evidence['source']!=='quanta_worker')throw new RuntimeException('Document provenance differs');
    $report['document_sha256']=TH_ONBOARD_SHA;$report['native_private_storage_readback_verified']=true;
    if(!in_array($case['version'],[7,8,9,10,11],true))throw new RuntimeException('Case version differs');
    $reason='TEST ONLY: verified synthetic acknowledgment and sample PDF. No real employment, tax, payroll or payment approval.';
    $base=['case_id'=>TH_ONBOARD_CASE];$commands=[
        $base+['action'=>'review','request_id'=>'e6f14a01-a874-4a32-922e-000000000001','expected_version'=>7,'task_id'=>TH_ONBOARD_ACK,'decision'=>'approve','reason'=>$reason,'expires_at'=>null],
        $base+['action'=>'review','request_id'=>'e6f14a01-a874-4a32-922e-000000000002','expected_version'=>8,'task_id'=>TH_ONBOARD_DOC,'decision'=>'approve','reason'=>$reason,'expires_at'=>null],
        $base+['action'=>'submit','request_id'=>'e6f14a01-a874-4a32-922e-000000000003','expected_version'=>9,'task_id'=>TH_ONBOARD_STAFF,'acknowledged'=>true],
        $base+['action'=>'review','request_id'=>'e6f14a01-a874-4a32-922e-000000000004','expected_version'=>10,'task_id'=>TH_ONBOARD_STAFF,'decision'=>'approve','reason'=>$reason,'expires_at'=>null],
    ];
    $phase='stale_review_guard';$bad=$commands[0];$bad['request_id']='e6f14a01-a874-4a32-922e-000000000099';$bad['expected_version']=1;
    try{onboardingCommand($pdo,$scope,$bad,$head);throw new LogicException('Stale review succeeded');}
    catch(OnboardingConflict $e){if($e->getMessage()!=='This case changed. Reload and review the latest version.')throw $e;}
    if(onboardingCase($pdo,$scope,TH_ONBOARD_CASE)['version']!==$case['version'])throw new RuntimeException('Stale review changed case');
    $report['stale_review_rejected']=true;
    $phase='native_test_review';$results=[];
    foreach($commands as $command){
        $r=onboardingCommand($pdo,$scope,$command,$head);$again=onboardingCommand($pdo,$scope,$command,$head);
        if(!$again['replayed'])throw new RuntimeException('Review replay not retained');
        unset($r['replayed'],$again['replayed']);if($r!==$again)throw new RuntimeException('Review replay differs');$results[]=$r;
    }
    $after=onboardingView($pdo,$scope,TH_ONBOARD_CASE,$head);
    if($after['version']!==11||$after['readiness']!=='complete'||$after['satisfied_count']!==3||$after['source_changed'])throw new RuntimeException('Test checklist did not complete');
    foreach($after['tasks'] as $task)if($task['effective_state']!=='approved'||$task['review']['actor_user_id']!==4)throw new RuntimeException('Test review differs');
    $report['reviews']=$results;$phase='publish_review_return';
    $preview=quantaOnboardingPreview($scope,TH_ONBOARD_CASE,'published',$head);
    if($preview['status']!=='unchanged'){
        $d=$preview['delivery'];$sent=quantaOnboardingSend($scope,TH_ONBOARD_CASE,(int)$d['id'],$d['review_hash'],$head);
        if($sent['state']!=='delivered'||quantaOnboardingSend($scope,TH_ONBOARD_CASE,(int)$d['id'],$d['review_hash'],$head)!==$sent)throw new RuntimeException('Review publication replay differs');
        $report['delivery']=array_intersect_key($sent,array_flip(['id','state','remote_id','remote_receipt_id']));
    }
    $remote=quantaOnboardingSnapshot($scope,TH_ONBOARD_CASE,$head)['remote'];
    if($remote['publication']['core_version']!==11)throw new RuntimeException('Provider Core version differs');
    foreach($remote['publication']['tasks'] as $task)if($task['effective_state']!=='approved')throw new RuntimeException('Provider task state differs');
    if(quantaOnboardingPreview($scope,TH_ONBOARD_CASE,'published',$head)['status']!=='unchanged'||$protected()!==$before)throw new RuntimeException('Final reconciliation differs');
    $report+=['passed'=>true,'case_version'=>11,'readiness'=>'complete','required_count'=>3,'satisfied_count'=>3,
        'provider_version'=>$remote['version'],'review_and_publication_replay_verified'=>true,'protected_records_unchanged'=>true];
}catch(Throwable $error){
    if($pdo->inTransaction())$pdo->rollBack();
    $report['passed']=false;$report['failed_phase']=$phase;$report['failure_class']=get_class($error);
    $report['failure_source']=basename($error->getFile());$report['failure_line']=$error->getLine();
    if($error instanceof QuantaApiException)$report['provider_http_status']=$error->httpStatus;
    if($error instanceof OnboardingConflict||$error instanceof PeopleDocumentConflict)$report['reason']=$error->getMessage();
}finally{
    foreach($flags as $name=>$value)putenv($value===false?$name:$name.'='.$value);
    unset($_SESSION['user']);if($lock)$pdo->query("SELECT RELEASE_LOCK('thunderhawk_2002_onboarding_20261030')");
}
echo json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
exit($report['passed']?0:1);
