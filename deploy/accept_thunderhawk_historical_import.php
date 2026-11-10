<?php
/** Import only the ten verified historical workbook entries into the isolated pilot. */
declare(strict_types=1);
const TH_HISTORY_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const TH_HISTORY_WORKSPACE='4f93d4e7-af74-4765-b812-3464bb6d974b';
const TH_HISTORY_WORKER='cfw_9fe254742aedc66ec5ab66c9baba07e2acb5c1581de10d30e7ecf931133895fa';
const TH_HISTORY_SITE='1bc9011d-12df-458e-b79d-c31f09b9ee09';
const TH_HISTORY_HASH='38a576b558d80bc63a65ec5472680c45ae5ba65744fda98109e4092881ee5ac8';
$mode=$argv[1]??'';
if(PHP_SAPI!=='cli'||!in_array($mode,['--inspect-historical-source','--import-historical-source'],true)
    ||getenv('COREFLUX_ENV')!=='staging'||realpath(getcwd())!==TH_HISTORY_ROOT)exit(2);
require_once TH_HISTORY_ROOT.'/core/quanta/sync.php';
require_once TH_HISTORY_ROOT.'/core/quanta/time_review_delivery.php';
require_once TH_HISTORY_ROOT.'/core/rbac/permissions.php';
$pdo=getDB();
if(!$pdo||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||!COREFLUX_STAGING
    ||integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf'
    ||quantaApiBase()!=='https://helloquanta.app:19443/api/v1'
    ||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=2002 AND parent_id IS NULL AND is_active=1')->fetchColumn()!==1
    ||!RBACResolver::can(4,2002,'time','admin')||!RBACResolver::can(4,2002,'integrations','admin'))exit(2);
$report=['checked_at'=>gmdate('c'),'tenant_id'=>2002,'mode'=>$mode,'source_sha256'=>TH_HISTORY_HASH,
    'person_number'=>'P-358','placement_number'=>'PL-864','production_changed'=>false,
    'independent_human_review'=>false,'original_client_approval_stamp_present'=>false,
    'core_time_approved'=>false,'payroll_or_financial_postings'=>false];
$phase='preflight';$flag=getenv('COREFLUX_QUANTA_TIME_REVIEW_DELIVERY');
try{
    $evidence=json_decode(base64_decode($argv[2]??'',true),true,64,JSON_THROW_ON_ERROR);
    if(($evidence['passed']??null)!==true||$evidence['source_sha256']!==TH_HISTORY_HASH
        ||$evidence['workspace_id']!==TH_HISTORY_WORKSPACE||$evidence['worker_id']!==TH_HISTORY_WORKER
        ||$evidence['source_sheet']!=='Oct 26 Hrs'||$evidence['source_range']!=='M19:Z19'
        ||$evidence['hours']!==80||count($evidence['entries'])!==10)throw new RuntimeException('Source manifest differs');
    $expected=[];$days=['05','06','07','08','09','12','13','14','15','16'];
    foreach($evidence['entries']as$i=>$entry){
        if($entry['work_date']!=='2026-10-'.$days[$i]||$entry['duration_minutes']!==480
            ||$entry['worksite_id']!==TH_HISTORY_SITE||!preg_match('/^[a-f0-9-]{36}$/D',$entry['id'])
            ||!str_contains($entry['notes'],'Previously paid period; do not pay again.'))throw new RuntimeException('Source daily entry differs');
        $expected[$entry['id']]=$entry;
    }
    if(count($expected)!==10)throw new RuntimeException('Duplicate source identity');
    foreach(['people','placements','time']as$module)if(effectiveTenantIdForModule($module,2002)!==2002)throw new RuntimeException('Owner mismatch');
    setRequestTenantId(2002);$_SESSION['user']=['id'=>4,'role'=>'tenant_admin','tenant_id'=>2002];
    $access=quantaWorkspaceForDelivery(2002);
    if($access['context']['workspace']['id']!==TH_HISTORY_WORKSPACE)throw new RuntimeException('Workspace mismatch');
    $protected=static function()use($pdo):array{
        $out=[];
        foreach(['people','placements','placement_rates','payroll_source_authorities','payroll_provider_results',
            'payroll_runs','payroll_line_items','ap_bills','ap_payments','billing_invoices','billing_payments',
            'accounting_journal_entries','accounting_journal_entry_lines','staffing_onboarding_cases','staffing_onboarding_events',
            'time_entries','staffing_timesheets','quanta_time_routes']as$table){
            $where='';
            if($table==='time_entries')$where=" WHERE NOT(tenant_id=2002 AND person_id=358 AND work_date BETWEEN '2026-10-05' AND '2026-10-18')";
            if($table==='staffing_timesheets')$where=" WHERE id NOT IN(SELECT timesheet_id FROM time_entries WHERE tenant_id=2002 AND person_id=358 AND work_date BETWEEN '2026-10-05' AND '2026-10-18')";
            $rows=array_map('serialize',$pdo->query('SELECT * FROM '.$table.$where)->fetchAll(PDO::FETCH_ASSOC));
            sort($rows,SORT_STRING);$out[$table]=['count'=>count($rows),'sha256'=>hash('sha256',serialize($rows))];
        }return$out;
    };
    $before=$protected();
    $sites=quantaWorksitesById(quantaListAll($access['api_key'],'/worksites',[],2000));
    $all=quantaEntries($access['api_key'],'2026-10-03','approved');
    $raw=array_values(array_filter($all,static fn(array $row):bool=>isset($expected[$row['id']??''])));
    if(count($raw)!==10)throw new RuntimeException('Approved source entry set differs');
    $routes=quantaRoutes(2002);$links=quantaWorkerPersonMap(quantaWorkerLinks(2002,true));
    $report['source_entries']=[];
    foreach($raw as$r){
        $entry=quantaNormalizeEntry($r,$sites,'approved');$want=$expected[$entry['id']];
        if($entry['worker_id']!==TH_HISTORY_WORKER||$entry['work_date']!==$want['work_date']
            ||$entry['hours']!==8.0||$entry['components']!==['regular'=>8.0]||$entry['worksite_id']!==TH_HISTORY_SITE
            ||$entry['source_approval']['timesheet_id']!==$want['timesheet_id']||!in_array($entry['description'],['',$want['notes']],true)){
            $report['source_mismatch']=['id'=>$entry['id'],'worker_matches'=>$entry['worker_id']===TH_HISTORY_WORKER,
                'date'=>$entry['work_date'],'hours'=>$entry['hours'],'components'=>$entry['components'],
                'site_matches'=>$entry['worksite_id']===TH_HISTORY_SITE,'sheet_matches'=>$entry['source_approval']['timesheet_id']===$want['timesheet_id'],
                'description'=>$entry['description'],'source_notes'=>$want['notes']];
            throw new RuntimeException('Native source differs');
        }
        $route=quantaRouteFor($routes,$entry,$links);
        if((int)$route['placement_id']!==864||$route['placement_number']!=='PL-864'||$route['person_number']!=='P-358')throw new RuntimeException('Typed route differs');
        $report['source_entries'][]=['source_entry_id'=>$entry['id'],'work_date'=>$entry['work_date'],'hours'=>8,
            'source_cell'=>$want['source_cell'],'source_timesheet_id'=>$want['timesheet_id'],'route_id'=>(int)$route['id'],
            'notes_exposed_by_time_api'=>$entry['description']!==''];
    }
    $preview=quantaPreviewRows(2002,$raw,$sites,'approved',$access);
    foreach($preview['rows']as$row)if(!in_array($row['status'],['ready','imported'],true))throw new RuntimeException('Source not ready for import');
    $report['preview_states']=array_count_values(array_column($preview['rows'],'status'));
    if($mode==='--import-historical-source'){
        $phase='native_import';$ids=array_keys($expected);
        $report['import']=quantaImportSelected(2002,4,$raw,$sites,'approved',$ids,$access);
        $report['replay']=quantaImportSelected(2002,4,$raw,$sites,'approved',$ids,$access);
        if($report['replay']['inserted']!==0||$report['replay']['updated']!==0||$report['replay']['already_imported']!==10)throw new RuntimeException('Import replay differs');
        $rows=$pdo->query("SELECT id,person_id,placement_id,hours,status,timesheet_id,work_date,created_by_user_id,description FROM time_entries
            WHERE tenant_id=2002 AND person_id=358 AND work_date BETWEEN '2026-10-05' AND '2026-10-18' ORDER BY work_date")->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)!==10)throw new RuntimeException('Imported daily count differs');
        foreach($rows as$i=>$r){
            if((int)$r['placement_id']!==864||(float)$r['hours']!==8.0||$r['status']!=='pending_review'
                ||(int)$r['created_by_user_id']!==4||$r['work_date']!=='2026-10-'.$days[$i])throw new RuntimeException('Pending review data differs');
            // Quanta's public time projection omits notes. Preserve separately verified
            // workbook provenance in the audit; never fabricate fields in its API response.
            $source=array_values(array_filter($evidence['entries'],static fn(array $e):bool=>$e['work_date']===$r['work_date']))[0];
            $meta=['mode'=>'historical_source_replay_in_isolated_pilot','already_paid_source_period'=>true,
                'source_sha256'=>TH_HISTORY_HASH,'source_sheet'=>'Oct 26 Hrs','source_cell'=>$source['source_cell'],
                'source_entry_id'=>$source['id'],'source_timesheet_id'=>$source['timesheet_id'],
                'source_designated_by_user'=>true,'original_client_approval_stamp_present'=>false,
                'independent_human_review'=>false,'payroll_execution_authorized'=>false];
            $q=$pdo->prepare("SELECT meta_json FROM audit_log WHERE tenant_id=2002 AND actor_user_id=4 AND event='time.pilot.historical_source' AND target_id=?");
            $q->execute([(int)$r['id']]);$prior=$q->fetchAll(PDO::FETCH_COLUMN);
            if(!$prior)platformAuditLogWrite(2002,4,'time.pilot.historical_source',(int)$r['id'],$meta,['source'=>'thunderhawk_historical_pilot']);
            $q->execute([(int)$r['id']]);$prior=$q->fetchAll(PDO::FETCH_COLUMN);
            if(count($prior)!==1||array_intersect_key(json_decode($prior[0],true),$meta)!=$meta)throw new RuntimeException('Historical audit differs');
        }
        $report['entries']=$rows;$report['hours']=80;$phase='return_pending_review';
        putenv('COREFLUX_QUANTA_TIME_REVIEW_DELIVERY=1');
        foreach($ids as$id){
            $p=quantaTimeReviewPreview(2002,4,$id);
            if($p['state']!=='pending_review')throw new RuntimeException('Return state differs');
            if($p['status']!=='unchanged'){
                $d=$p['delivery'];$sent=quantaTimeReviewSend(2002,4,(int)$d['id'],$d['review_hash']);
                if($sent['state']!=='delivered'||quantaTimeReviewSend(2002,4,(int)$d['id'],$d['review_hash'])!==$sent)throw new RuntimeException('Return replay differs');
            }
            $fresh=quantaTimeReviewSnapshot(2002,$id);
            if($fresh['status']!=='unchanged'||$fresh['remote']['current']['state']!=='pending_review')throw new RuntimeException('Return reconciliation differs');
        }
        $report['provider_core_review_status']='pending_review';
    }
    if($protected()!==$before)throw new RuntimeException('Protected business records changed');
    $report['prior_records_unchanged']=true;$report['passed']=true;
}catch(Throwable $error){
    if($pdo->inTransaction())$pdo->rollBack();
    $report['passed']=false;$report['failed_phase']=$phase;$report['failure_class']=get_class($error);
    $report['failure_source']=basename($error->getFile());$report['failure_line']=$error->getLine();
    if($error instanceof QuantaApiException)$report['provider_http_status']=$error->httpStatus;
}finally{
    putenv($flag===false?'COREFLUX_QUANTA_TIME_REVIEW_DELIVERY':'COREFLUX_QUANTA_TIME_REVIEW_DELIVERY='.$flag);unset($_SESSION['user']);
}
echo json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";exit($report['passed']?0:1);
