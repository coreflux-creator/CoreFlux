<?php
/** Isolated test hours: scoped route setup, native import, replay, and return status. */
declare(strict_types=1);
const TH_TIME_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const TH_TIME_WORKSPACE='4f93d4e7-af74-4765-b812-3464bb6d974b';
const TH_TIME_WORKER='cfw_9fe254742aedc66ec5ab66c9baba07e2acb5c1581de10d30e7ecf931133895fa';
const TH_TIME_SOURCE='ad44e619-7f34-4f8e-a008-1cd1bf33992e';
if(PHP_SAPI!=='cli'||($argv[1]??'')!=='--import-isolated-test-hours'||getenv('COREFLUX_ENV')!=='staging'
    ||realpath(getcwd())!==TH_TIME_ROOT)exit(2);
require_once TH_TIME_ROOT.'/core/quanta/sync.php';
require_once TH_TIME_ROOT.'/core/quanta/time_review_delivery.php';
require_once TH_TIME_ROOT.'/core/rbac/permissions.php';
$pdo=getDB();
if(!$pdo||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||!COREFLUX_STAGING
    ||integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf'
    ||quantaApiBase()!=='https://helloquanta.app:19443/api/v1'
    ||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=2002 AND parent_id IS NULL AND is_active=1')->fetchColumn()!==1
    ||!RBACResolver::can(4,2002,'time','admin')||!RBACResolver::can(4,2002,'integrations','admin'))exit(2);
$report=['checked_at'=>gmdate('c'),'tenant_id'=>2002,'source_entry_id'=>TH_TIME_SOURCE,
    'mode'=>'isolated_test_hours','production_changed'=>false,'core_time_approved'=>false,'payroll_or_financial_postings'=>false];
$phase='preflight';$flag=getenv('COREFLUX_QUANTA_TIME_REVIEW_DELIVERY');
try{
    foreach(['people','placements','time'] as $module)if(effectiveTenantIdForModule($module,2002)!==2002)throw new RuntimeException('Owner mismatch');
    setRequestTenantId(2002);$_SESSION['user']=['id'=>4,'role'=>'tenant_admin','tenant_id'=>2002];
    $access=quantaWorkspaceForDelivery(2002);
    if($access['context']['workspace']['id']!==TH_TIME_WORKSPACE)throw new RuntimeException('Workspace mismatch');
    $sites=quantaWorksitesById(quantaListAll($access['api_key'],'/worksites',[],2000));
    $raw=quantaEntries($access['api_key'],'2026-10-17','approved');
    if(count($raw)!==1)throw new RuntimeException('Unexpected source entry count');
    $entry=quantaNormalizeEntry($raw[0],$sites,'approved');
    if($entry['id']!==TH_TIME_SOURCE||$entry['worker_id']!==TH_TIME_WORKER||$entry['work_date']!=='2026-10-19'
        ||(float)$entry['hours']!==8.0||$entry['components']!==['regular'=>8.0]
        ||$entry['worksite_id']!==''||$entry['source_approval']['timesheet_id']!=='96c12ef7-bd65-4909-b27e-eb68fb551f4d')throw new RuntimeException('Test source differs');
    $phase='route_setup';
    // The API route's native schema/ownership checks, limited to the reviewed published assignment.
    $pdo->beginTransaction();
    quantaWorkspaceAssertCurrent($pdo,2002,$access,true);
    $links=quantaWorkerPersonMap(quantaWorkerLinks(2002,true));
    quantaAssertWorkerPerson(TH_TIME_WORKER,358,$links);
    $placement=$pdo->query("SELECT person_id,status,start_date,end_date FROM placements WHERE tenant_id=2002 AND id=864 AND external_id='jd:57862243' AND deleted_at IS NULL FOR UPDATE")->fetch(PDO::FETCH_ASSOC);
    if(!$placement||(int)$placement['person_id']!==358||$placement['status']!=='active'
        ||$placement['start_date']!=='2026-05-18'||$placement['end_date']!=='2027-03-20')throw new RuntimeException('Placement differs');
    $routes=quantaRoutes(2002);
    if(!$routes){
        $pdo->prepare('INSERT INTO quanta_time_routes(tenant_id,worker_id,worksite_id,dimension_key,dimension_values_json,placement_id,effective_from,effective_to,created_by_user_id)
            VALUES(2002,?,?,?,?,864,?,?,4)')->execute([TH_TIME_WORKER,'',$entry['dimension_key'],json_encode($entry['dimension_values'],JSON_THROW_ON_ERROR),$placement['start_date'],$placement['end_date']]);
        platformAuditLogWrite(2002,4,'quanta.routes.saved',null,['count'=>1,'isolated_test'=>true],['source'=>'thunderhawk_pilot']);
        $routes=quantaRoutes(2002);
    }
    if(count($routes)!==1)throw new RuntimeException('Unexpected route count');
    $route=quantaRouteFor($routes,$entry,$links);
    if((int)$route['placement_id']!==864||$route['placement_number']!=='PL-864'||$route['person_number']!=='P-358')throw new RuntimeException('Typed route differs');
    $report['route_id']=(int)$route['id'];$pdo->commit();
    $phase='native_import';
    $preview=quantaPreviewRows(2002,$raw,$sites,'approved',$access);
    if(!in_array($preview['rows'][0]['status'],['ready','imported'],true))throw new RuntimeException('Source not ready for import');
    $import=quantaImportSelected(2002,4,$raw,$sites,'approved',[TH_TIME_SOURCE],$access);
    $replay=quantaImportSelected(2002,4,$raw,$sites,'approved',[TH_TIME_SOURCE],$access);
    if($replay['inserted']!==0||$replay['updated']!==0||$replay['already_imported']!==1)throw new RuntimeException('Import replay differs');
    $rows=$pdo->query('SELECT id,person_id,placement_id,hours,status,timesheet_id,work_date,created_by_user_id FROM time_entries WHERE tenant_id=2002')->fetchAll(PDO::FETCH_ASSOC);
    $local=$rows[0]??[];
    if(count($rows)!==1||(int)$local['person_id']!==358||(int)$local['placement_id']!==864
        ||(float)$local['hours']!==8.0||$local['status']!=='pending_review'||(int)$local['created_by_user_id']!==4)throw new RuntimeException('Pending-review import differs');
    $report['entry_id']=(int)$local['id'];$report['timesheet_id']=(int)$local['timesheet_id'];
    $report['import']=$import;$report['replay']=$replay;$report['source_approval_preserved']=true;
    $phase='return_pending_review';
    putenv('COREFLUX_QUANTA_TIME_REVIEW_DELIVERY=1');
    $preview=quantaTimeReviewPreview(2002,4,TH_TIME_SOURCE);
    if($preview['state']!=='pending_review')throw new RuntimeException('Unexpected return status');
    if($preview['status']!=='unchanged'){
        $delivery=$preview['delivery'];
        $sent=quantaTimeReviewSend(2002,4,(int)$delivery['id'],$delivery['review_hash']);
        $again=quantaTimeReviewSend(2002,4,(int)$delivery['id'],$delivery['review_hash']);
        if($sent['state']!=='delivered'||$sent!==$again)throw new RuntimeException('Return-status replay differs');
        $report['delivery']=array_intersect_key($sent,array_flip(['id','state','remote_id','remote_receipt_id']));
    }
    $fresh=quantaTimeReviewSnapshot(2002,TH_TIME_SOURCE);
    if($fresh['status']!=='unchanged'||$fresh['remote']['current']['state']!=='pending_review')throw new RuntimeException('Return status did not reconcile');
    $report['provider_core_review_status']='pending_review';$report['passed']=true;
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
