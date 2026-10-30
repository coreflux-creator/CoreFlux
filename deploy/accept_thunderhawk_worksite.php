<?php
/** Review one isolated worksite revision and refresh its existing onboarding publication. */
declare(strict_types=1);
const TH_SITE_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const TH_SITE_ID='1bc9011d-12df-458e-b79d-c31f09b9ee09';
const TH_SITE_CASE='4e861f71-d0bd-45cb-b31b-0ccd27b0e957';
if(PHP_SAPI!=='cli'||($argv[1]??'')!=='--review-isolated-worksite'
    ||getenv('COREFLUX_ENV')!=='staging'||realpath(getcwd())!==TH_SITE_ROOT)exit(2);
require_once TH_SITE_ROOT.'/core/quanta/metadata_delivery.php';
require_once TH_SITE_ROOT.'/core/quanta/onboarding_delivery.php';
require_once TH_SITE_ROOT.'/core/quanta/time_review_delivery.php';
require_once TH_SITE_ROOT.'/core/rbac/permissions.php';
$pdo=getDB();
if(!$pdo||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||!COREFLUX_STAGING
    ||integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf'
    ||quantaApiBase()!=='https://helloquanta.app:19443/api/v1'
    ||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=2002 AND is_active=1')->fetchColumn()!==1
    ||!RBACResolver::can(4,2002,'integrations','admin'))exit(2);
$report=['checked_at'=>gmdate('c'),'tenant_id'=>2002,'worksite_id'=>TH_SITE_ID,
    'production_changed'=>false,'payroll_or_financial_postings'=>false];
$phase='guard';$flags=[];
try{
    foreach(['people','placements','time']as$module)if(effectiveTenantIdForModule($module,2002)!==2002)throw new RuntimeException('Owner differs');
    setRequestTenantId(2002);$_SESSION['user']=['id'=>4,'role'=>'tenant_admin','tenant_id'=>2002];
    foreach(['COREFLUX_QUANTA_METADATA_DELIVERY','COREFLUX_QUANTA_ONBOARDING_DELIVERY']as$name){$flags[$name]=getenv($name);putenv($name.'=1');}
    $protected=static function()use($pdo):array{
        $out=[];foreach(['people','placements','placement_rates','time_entries','staffing_timesheets','payroll_source_authorities','payroll_provider_results','quanta_time_routes','staffing_onboarding_cases','staffing_onboarding_events']as$table){
            $rows=array_map('serialize',$pdo->query('SELECT * FROM '.$table.' WHERE tenant_id=2002')->fetchAll(PDO::FETCH_ASSOC));sort($rows,SORT_STRING);$out[$table]=hash('sha256',serialize($rows));
        }return $out;
    };
    $before=$protected();$phase='review_worksite';
    $snapshot=quantaMetadataSnapshot(2002,'placement',864,TH_SITE_ID);
    if($snapshot['verified']['context']['workspace']['id']!=='4f93d4e7-af74-4765-b812-3464bb6d974b'
        ||$snapshot['source']['reference']['number']!=='PL-864'||$snapshot['source']['person']['number']!=='P-358'
        ||$snapshot['remote']['worksite']['code']!=='CORE-PL-864-PILOT'
        ||$snapshot['remote']['worksite']['name']!=='TEST Rockefeller - Hamilton, NJ')throw new RuntimeException('Reviewed worksite differs');
    $old=$snapshot['remote']['assignment'];
    if(!in_array($old['version'],[1,2],true)||!in_array($old['worksite_id'],[null,TH_SITE_ID],true))throw new RuntimeException('Assignment changed unexpectedly');
    $preview=quantaMetadataPreview(2002,4,'placement',864,TH_SITE_ID);
    if($preview['status']==='preview'){
        if($preview['proposed']!==['expected_version'=>1,'worksite_id'=>TH_SITE_ID])throw new RuntimeException('Unexpected metadata changes');
        $d=$preview['delivery'];$sent=quantaMetadataSend(2002,4,(int)$d['id'],$d['review_hash']);
        if($sent['state']!=='delivered'||quantaMetadataSend(2002,4,(int)$d['id'],$d['review_hash'])!==$sent)throw new RuntimeException('Delivery replay differs');
        $report['assignment_delivery']=array_intersect_key($sent,array_flip(['id','state','remote_id','remote_receipt_id']));
    }elseif($preview['status']!=='unchanged')throw new RuntimeException('Assignment needs reconciliation');
    $fresh=quantaMetadataSnapshot(2002,'placement',864,TH_SITE_ID);
    if($fresh['plan']['status']!=='unchanged'||$fresh['remote']['assignment']['id']!==$old['id']
        ||$fresh['remote']['assignment']['version']!==2)throw new RuntimeException('Assignment did not reconcile');
    $phase='refresh_onboarding_publication';
    $scope=['tenant_id'=>2002,'people_tenant_id'=>2002,'placements_tenant_id'=>2002,'actor_user_id'=>4,
        'permissions'=>['view'=>true,'manage'=>true,'review'=>true,'documents_manage'=>true]];
    $storage=new \Core\StorageService();$head=static fn(string $key):?array=>$storage->head($key);
    $case=onboardingView($pdo,$scope,TH_SITE_CASE,$head);
    if($case['version']!==11||$case['readiness']!=='complete'||$case['source_changed'])throw new RuntimeException('Checklist needs new review');
    $preview=quantaOnboardingPreview($scope,TH_SITE_CASE,'published',$head);
    if($preview['status']!=='unchanged'){
        $d=$preview['delivery'];$sent=quantaOnboardingSend($scope,TH_SITE_CASE,(int)$d['id'],$d['review_hash'],$head);
        if($sent['state']!=='delivered'||quantaOnboardingSend($scope,TH_SITE_CASE,(int)$d['id'],$d['review_hash'],$head)!==$sent)throw new RuntimeException('Checklist publication replay differs');
        $report['onboarding_delivery']=array_intersect_key($sent,array_flip(['id','state','remote_id','remote_receipt_id']));
    }
    $remote=quantaOnboardingSnapshot($scope,TH_SITE_CASE,$head)['remote'];
    if($remote['publication']['assignment_version']!==2||$remote['publication']['core_version']!==11||count($remote['submissions'])!==2)throw new RuntimeException('Checklist version differs');
    foreach($remote['publication']['tasks']as$task)if($task['effective_state']!=='approved')throw new RuntimeException('Review decision changed');
    $phase='prior_time_reconciliation';
    $time=quantaTimeReviewSnapshot(2002,'ad44e619-7f34-4f8e-a008-1cd1bf33992e');
    if($time['remote']['current']['state']!=='accepted'||$protected()!==$before)throw new RuntimeException('Prior evidence changed');
    $report+=['passed'=>true,'assignment_version'=>2,'assignment_id'=>$old['id'],'onboarding_case_version'=>11,
        'portal_onboarding_version'=>$remote['version'],'prior_time_accepted'=>true,
        'source_time_payroll_routes_and_local_checklist_unchanged'=>true,'replays_verified'=>true];
}catch(Throwable $error){
    if($pdo->inTransaction())$pdo->rollBack();
    $report+=['passed'=>false,'failed_phase'=>$phase,'failure_class'=>get_class($error),
        'failure_source'=>basename($error->getFile()),'failure_line'=>$error->getLine()];
    if($error instanceof QuantaApiException)$report['provider_http_status']=$error->httpStatus;
}finally{
    foreach($flags as$name=>$value)putenv($value===false?$name:$name.'='.$value);
    unset($_SESSION['user']);
}
echo json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
exit($report['passed']?0:1);
