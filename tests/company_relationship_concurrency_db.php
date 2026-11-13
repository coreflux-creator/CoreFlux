<?php
/** Independent native MariaDB sessions; only disposable CI fixtures, never provider calls. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('GITHUB_ACTIONS')!=='true'||getenv('CI')!=='true'||getenv('DB_NAME')!=='coreaccounting_ci'||!function_exists('proc_open'))exit(2);
if(($argv[1]??'')!=='worker'){putenv('COREFLUX_DATA_KEY='.base64_encode(random_bytes(32)));putenv('COREFLUX_INTEGRATION_INSTANCE_ID=e443cc91-9552-41a1-8876-c14d044c5591');}
require_once __DIR__.'/../modules/people/lib/company_merge_review.php';
require_once __DIR__.'/../modules/staffing/lib/job_write.php';
require_once __DIR__.'/../modules/staffing/lib/client_write.php';
require_once __DIR__.'/../modules/staffing/lib/timesheets.php';
require_once __DIR__.'/../core/jobdiva/sync.php';
require_once __DIR__.'/../core/jobdiva/sync_placements.php';
require_once __DIR__.'/../core/qbo/sync_in.php';
require_once __DIR__.'/../core/jobdiva/mapping_alignment.php';
require_once __DIR__.'/../core/jobdiva/joined_index_refresh.php';
require_once __DIR__.'/../core/jobdiva/live_contract_refresh.php';
require_once __DIR__.'/../core/jobdiva/review_contract_refresh.php';
require_once __DIR__.'/../core/jobdiva/active_status_review.php';
require_once __DIR__.'/../core/jobdiva/terminal_lifecycle.php';
require_once __DIR__.'/../core/integrations/purepay_links.php';
require_once __DIR__.'/../modules/placements/lib/merge_review.php';
require_once __DIR__.'/../modules/placements/lib/edit.php';
require_once __DIR__.'/../modules/placements/lib/source_rule_review.php';
require_once __DIR__.'/../core/CsvImportService.php';
require_once __DIR__.'/../modules/placements/lib/jobdiva_csv_write.php';
Core\CsvImportService::registerSchema('jobdiva_placement_reconciliation',jobdivaReconciliationSchema());
$pdo=getDB();if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='coreaccounting_ci')exit(2);

function nativePlacementRaceWrite(array $input): array
{
    setRequestTenantId($input['workspace']);$_SESSION['tenant_id']=$input['workspace'];setRequestModuleScope('placements');
    return ['id'=>$input['placement_id']]+placementApplyNativeEdit($input['placement_id'],$input['patch'],['id'=>$input['actor'],'role'=>'admin'],'patch_status');
}

function nativeTimeRaceWrite(array $input): array
{
    setRequestTenantId($input['workspace']);$_SESSION['tenant_id']=$input['workspace'];setRequestModuleScope('staffing');
    $result=staffingTimeEntrySave($input['actor'],$input['payload']);
    return ['id'=>$result['entry']['id']]+$result;
}

function clientSourceRaceWrite(array $input): array
{
    return companyRelationshipAtomic(getDB(),static function()use($input):array{
        if($input['source_system']==='qbo'){
            $result=qboUpsertCustomer($input['workspace'],['Id'=>$input['source_external'],'DisplayName'=>$input['source_name'],'PrimaryPhone'=>['FreeFormNumber'=>$input['phone']]]);
            return ['id'=>$result['internal_id']]+$result;
        }
        $company=jobdivaCompanyUpsertStable($input['workspace'],$input['source_external'],$input['source_name'],['primary_contact_phone'=>$input['phone']],['phone'=>$input['phone']],$input['actor'],[]);
        $result=staffingClientEnsureForCompany($input['workspace'],$company,$input['source_name'],['source_system'=>'jobdiva','source_external_id'=>$input['source_external'],'created_by_user_id'=>$input['actor']]);
        return ['id'=>$result['client_id']]+$result;
    });
}

function assignmentSourceRaceWrite(array $input): array
{
    setRequestTenantId($input['workspace']);$_SESSION['tenant_id']=$input['workspace'];setRequestModuleScope('placements');
    $result=jobdivaProjectorProjectPlacement($input['workspace'],$input['payload'],$input['actor'],['payload_is_enriched'=>true]);
    if(!$result['projected'])throw new RuntimeException('Assignment projection refused: '.implode('; ',$result['errors']),409);
    return ['id'=>$result['placement_id']]+$result;
}

function sourceRuleRaceWrite(array $input): array
{
    setRequestTenantId($input['workspace']);$_SESSION['tenant_id']=$input['workspace'];setRequestModuleScope('placements');
    return ['id'=>$input['placement_id']]+placementSourceRuleReplace($input['workspace'],$input['placement_id'],'chain',$input['body'],['id'=>$input['actor'],'role'=>'admin']);
}

function storedAssignmentRaceWrite(array $input): array
{
    setRequestTenantId($input['workspace']);$_SESSION['tenant_id']=$input['workspace'];setRequestModuleScope('placements');
    return ['id'=>$input['placement_id']]+jobdivaApplyStoredAssignmentProjection($input['workspace'],$input['actor'],[$input['start_id']],$input['review_token'],5000);
}

function csvAssignmentRaceWrite(array $input): array
{
    setRequestTenantId($input['workspace']);$_SESSION['tenant_id']=$input['workspace'];setRequestModuleScope('placements');
    return ['id'=>$input['placement_id']]+jobdivaReconciliationApply(getDB(),$input['workspace'],$input['actor'],$input['csv'],null,[$input['start_id']],$input['review_token']);
}

function assignmentRepairRaceWrite(array $input): array
{
    setRequestTenantId($input['workspace']);$_SESSION['tenant_id']=$input['workspace'];setRequestModuleScope('placements');
    return ['id'=>$input['placement_id']]+jobdivaAssignmentRepairApply($input['expected'],$input['exact'],$input['actor']);
}

function placementLifecycleRaceWrite(array $input): array
{
    setRequestTenantId($input['workspace']);$_SESSION['tenant_id']=$input['workspace'];setRequestModuleScope('placements');
    return jobdivaRepairPlacementLifecycle($input['workspace'],$input['mapping_id'],$input['actor'],false);
}

function peopleAvailabilityRaceWrite(array $input): array
{
    return ['id'=>$input['person_id']]+jobdivaPeopleLifecycleApply($input['workspace'],$input['actor'],$input['body']);
}

function sourceRateRaceWrite(array $input): array
{
    setRequestTenantId($input['workspace']);$_SESSION['tenant_id']=$input['workspace'];setRequestModuleScope('placements');
    if($input['entity']==='source_rate')return jobdivaRepairPlacementSourceRate($input['workspace'],$input['placement_id'],$input['actor']);
    return ['id'=>$input['placement_id']]+placementsRateApproveOne($input['rate_id'],['id'=>$input['actor']],false,null);
}

function joinedIndexRaceWrite(array $input): array
{
    return ['id'=>$input['placement_id']]+jobdivaJoinedIndexApply($input['expected'],$input['payload'],$input['actor']);
}

function liveContractRaceWrite(array $input): array
{
    return ['id'=>$input['placement_id']]+jobdivaLiveContractApply($input['expected'],$input['exact'],$input['payload'],null,$input['actor']);
}

function activeRestoreRaceWrite(array $input): array
{
    return ['id'=>$input['placement_id']]+jobdivaActiveRestoreApply($input['workspace'],$input['actor'],$input['body']);
}

function placementMergeRaceWrite(array $input): array
{
    return ['id'=>$input['body']['survivor_id']]+placementMergeApply($input['workspace'],$input['actor'],$input['body']);
}

// Worker mode executes the actual library in another process and connection.
if(($argv[1]??'')==='worker'){
    $path=$argv[2]??'';$directory=dirname($path);
    if(!str_starts_with($directory,sys_get_temp_dir().'/coreflux-company-race-')||realpath($path)!==$path||is_link($path))exit(2);
    $input=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
    if(!in_array($input['workspace']??0,[990671,990672],true))exit(2);
    echo json_encode(['ready'=>true,'connection'=>(int)$pdo->query('SELECT CONNECTION_ID()')->fetchColumn()]).PHP_EOL;flush();
    try{
        if($input['mode']==='merge')$result=companyMergeApply($input['workspace'],['id'=>$input['actor'],'role'=>'admin'],$input['review_token'],$input['choices']);
        elseif($input['mode']==='source')$result=staffingJobEnsureFromJobDivaPayload($input['workspace'],$input['job_external'],['title'=>$input['title'],'companyId'=>$input['company_external']],$input['actor']);
        elseif(in_array($input['mode'],['rule_hold','stored_hold','csv_hold','assignment_repair_hold','lifecycle_repair_hold','availability_hold','index_hold','live_contract_hold','active_restore_hold','placement_merge_hold'],true))$result=JobdivaStoredReviewTransaction::run(static function()use($pdo,$input):array{
            $pdo->exec('SAVEPOINT fixture_rule_hold');$result=match($input['mode']){'placement_merge_hold'=>placementMergeRaceWrite($input),'active_restore_hold'=>activeRestoreRaceWrite($input),'live_contract_hold'=>liveContractRaceWrite($input),'index_hold'=>joinedIndexRaceWrite($input),'rule_hold'=>sourceRuleRaceWrite($input),'stored_hold'=>storedAssignmentRaceWrite($input),'assignment_repair_hold'=>assignmentRepairRaceWrite($input),'lifecycle_repair_hold'=>placementLifecycleRaceWrite($input),'availability_hold'=>peopleAvailabilityRaceWrite($input),default=>csvAssignmentRaceWrite($input)};
            echo json_encode(['saved'=>true,'id'=>$result['id'],'in_transaction'=>$pdo->inTransaction()]).PHP_EOL;flush();
            stream_set_timeout(STDIN,15);$decision=trim((string)fgets(STDIN));
            if($decision!=='COMMIT')$pdo->exec('ROLLBACK TO SAVEPOINT fixture_rule_hold');
            $pdo->exec('RELEASE SAVEPOINT fixture_rule_hold');return$result;
        });
        else{
            if($input['mode']==='hold')$pdo->beginTransaction();
            if(!empty($input['snapshot'])){
                $pdo->beginTransaction();$pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
                echo json_encode(['snapshot'=>true]).PHP_EOL;flush();stream_set_timeout(STDIN,15);
                if(trim((string)fgets(STDIN))!=='CONTINUE')throw new RuntimeException('Snapshot worker was not released');
            }
            $result=match($input['entity']??'job'){
                'client'=>staffingClientSaveNative($input['workspace'],$input['client_id'],['primary_contact_phone'=>'Concurrent client change'],$input['actor'],$input['edit_revision']),
                'client_csv'=>['id'=>staffingClientImportNative($input['workspace'],$input['row'],$input['actor'],true)],
                'client_source'=>clientSourceRaceWrite($input),
                'client_accept'=>staffingClientSourceAccept($input['workspace'],$input['client_id'],$input['source_key'],$input['fields'],$input['review_token'],$input['actor']),
                'client_repair'=>jobdivaRepairPlacementClientLink($input['workspace'],$input['mapping_id'],$input['actor']),
                'native_placement'=>nativePlacementRaceWrite($input),
                'native_time'=>nativeTimeRaceWrite($input),
                'assignment_source'=>assignmentSourceRaceWrite($input),
                'economic_graph'=>['id'=>$input['placement_id']]+placementEconomicsReconcile($input['workspace'],$input['placement_id']),
                'source_rules'=>sourceRuleRaceWrite($input),
                'stored_assignment'=>storedAssignmentRaceWrite($input),
                'csv_assignment'=>csvAssignmentRaceWrite($input),
                'assignment_repair'=>assignmentRepairRaceWrite($input),
                'lifecycle_repair'=>placementLifecycleRaceWrite($input),
                'people_availability'=>peopleAvailabilityRaceWrite($input),
                'source_rate','rate_approval'=>sourceRateRaceWrite($input),
                'joined_index'=>joinedIndexRaceWrite($input),
                'live_contract'=>liveContractRaceWrite($input),
                'active_restore'=>activeRestoreRaceWrite($input),
                'placement_merge'=>placementMergeRaceWrite($input),
                'historical_contract_refresh'=>['id'=>$input['placement_id']]+jobdivaRefreshLiveContract($input['workspace'],$input['mapping_id'],$input['actor']),
                'terminal_census'=>['id'=>$input['placement_id']]+jobdivaTerminalCensusApply($input['workspace'],$input['row'],$input['actor'],$input['baseline']??null),
                'review_contract'=>['id'=>$input['placement_id']]+jobdivaReviewContractApply($input['expected'],$input['payload'],null,$input['actor']),
                'placement_history'=>JobdivaStoredReviewTransaction::run(static fn():array=>
                    ['id'=>$input['placement_id']]+purepayCorePlacementHistorySource($pdo,'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',$input['workspace'],$input['placement_id'],true)),
                'alignment_report'=>jobdivaMappingAlignmentReport($input['workspace'],['sample_limit'=>1]),
                default=>staffingJobSaveNative($input['workspace'],null,['title'=>$input['title'],'company_id'=>$input['company_id']],$input['actor']),
            };
            if($input['mode']==='hold'){
                echo json_encode(['saved'=>true,'id'=>$result['id'],'in_transaction'=>$pdo->inTransaction()]).PHP_EOL;flush();
                stream_set_timeout(STDIN,15);$decision=trim((string)fgets(STDIN));
                if($decision==='COMMIT')$pdo->commit();else$pdo->rollBack();
            }
            if(!empty($input['snapshot']))$pdo->commit();
        }
        echo json_encode(['ok'=>true,'result'=>$result],JSON_THROW_ON_ERROR).PHP_EOL;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();echo json_encode(['ok'=>false,'code'=>$e->getCode(),'error'=>$e->getMessage()],JSON_THROW_ON_ERROR).PHP_EOL;}
    exit;
}

$directory=sys_get_temp_dir().'/coreflux-company-race-'.bin2hex(random_bytes(8));if(!mkdir($directory,0700))exit(2);
$workers=[];$files=[];$companies=[];$createdTenants=[];$actor=null;$tenant=990671;$child=990672;$checks=[];
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);$checks[]=$label;};
$line=static function($pipe):array{stream_set_timeout($pipe,15);$raw=fgets($pipe);if(!is_string($raw))throw new RuntimeException('Native race worker did not respond.');return json_decode($raw,true,64,JSON_THROW_ON_ERROR);};
$spawn=static function(array $input)use(&$workers,&$files,$directory,$line):int{
    $index=count($workers);$path=$directory.'/request-'.$index.'.json';file_put_contents($path,json_encode($input,JSON_THROW_ON_ERROR));chmod($path,0600);$files[]=$path;
    $error=$directory.'/error-'.$index.'.txt';$files[]=$error;
    $handle=proc_open([PHP_BINARY,__FILE__,'worker',$path],[0=>['pipe','r'],1=>['pipe','w'],2=>['file',$error,'w']],$pipes,dirname(__DIR__));
    if(!is_resource($handle))throw new RuntimeException('Unable to start independent writer');
    $workers[$index]=['handle'=>$handle,'pipes'=>$pipes,'error_file'=>$error,'closed'=>false];$ready=$line($pipes[1]);
    if(!($ready['ready']??false))throw new RuntimeException('Writer connection not ready');$workers[$index]['connection']=$ready['connection'];return$index;
};
$finish=static function(int $index)use(&$workers,$line):array{
    $worker=&$workers[$index];$result=$line($worker['pipes'][1]);fclose($worker['pipes'][0]);fclose($worker['pipes'][1]);$exit=proc_close($worker['handle']);$worker['closed']=true;
    if($exit!==0)throw new RuntimeException('Native worker failed outside its operation');return$result;
};
$waiting=static function(int $index)use($pdo,&$workers):bool{
    $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX t ON t.trx_id=w.requesting_trx_id WHERE t.trx_mysql_thread_id=?');
    $deadline=microtime(true)+8;
    // InnoDB refreshes this shared metadata only after >100 ms without a
    // reader. Polling every 50 ms indefinitely retained an obsolete snapshot.
    do{$q->execute([$workers[$index]['connection']]);if((int)$q->fetchColumn()>0)return true;if(!proc_get_status($workers[$index]['handle'])['running'])break;usleep(200000);}while(microtime(true)<$deadline);
    // CI-only synthetic connections: retain enough evidence to distinguish a
    // coordination error from an actual write/merge locking failure.
    $connections=array_column($workers,'connection');$marks=implode(',',array_fill(0,count($connections),'?'));
    $p=$pdo->prepare('SELECT ID,COMMAND,TIME,STATE,INFO FROM information_schema.PROCESSLIST WHERE ID IN ('.$marks.')');$p->execute($connections);
    $t=$pdo->prepare('SELECT trx_mysql_thread_id,trx_state,trx_query FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id IN ('.$marks.')');$t->execute($connections);
    stream_set_blocking($workers[$index]['pipes'][1],false);$output=stream_get_contents($workers[$index]['pipes'][1],4000);stream_set_blocking($workers[$index]['pipes'][1],true);
    fwrite(STDERR,json_encode(['wait_failed'=>$workers[$index]['connection'],'processes'=>$p->fetchAll(PDO::FETCH_ASSOC),'transactions'=>$t->fetchAll(PDO::FETCH_ASSOC),'worker_output'=>$output,'worker_error'=>substr((string)file_get_contents($workers[$index]['error_file']),0,4000)],JSON_THROW_ON_ERROR).PHP_EOL);
    return false;
};
$read=static function(string $table,int $id)use($pdo):array{$q=$pdo->prepare('SELECT * FROM '.$table.' WHERE id=?');$q->execute([$id]);return$q->fetch(PDO::FETCH_ASSOC)?:[];};
$reviewChoices=static fn(array $r):array=>['fields'=>array_fill_keys($r['fields'],'survivor'),'custom_fields'=>[],'primary_contact_id'=>0,'primary_addresses'=>[]];
try{
    if($pdo->query('SELECT id FROM tenants WHERE id IN(990671,990672)')->fetchColumn())throw new RuntimeException('Race fixture already exists');
    $pdo->exec('INSERT INTO tenants(id,name,is_active,is_simulation,tenant_type) VALUES(990671,"Synthetic company race",1,1,"master")');$createdTenants[]=$tenant;
    $pdo->exec('INSERT INTO tenants(id,name,is_active,is_simulation,tenant_type,parent_id) VALUES(990672,"Synthetic company race child",1,1,"sub",990671)');$createdTenants[]=$child;
    foreach(['companies'=>'shared','placements'=>'isolated','staffing'=>'isolated']as$module=>$mode)$pdo->prepare('INSERT INTO tenant_module_scope(tenant_id,module_key,scope_mode) VALUES(?,?,?)')->execute([$child,$module,$mode]);
    $pdo->exec('INSERT INTO users(tenant_id,name,email,role,is_active,is_global_admin,password,password_hash) VALUES(990671,"Synthetic race actor","company-race@example.invalid","admin",1,0,NULL,NULL)');$actor=(int)$pdo->lastInsertId();$user=['id'=>$actor,'role'=>'admin'];
    $pair=static function(string $suffix)use($tenant,$actor,&$companies):array{
        $a=jobdivaUpsertCompanyMapped($tenant,'race-A-'.$suffix,'Race Alpha '.$suffix,[],['id'=>'race-A-'.$suffix],$actor,[]);
        $b=jobdivaUpsertCompanyMapped($tenant,'race-B-'.$suffix,'Race Beta '.$suffix,[],['id'=>'race-B-'.$suffix],$actor,[]);$companies[]=$a;$companies[]=$b;return[$a,$b];
    };
    foreach(['native','source']as$mode){
        [$a,$b]=$pair($mode);$client=null;
        if($mode==='source'){$pdo->prepare('INSERT INTO staffing_clients(tenant_id,name,company_id) VALUES(?,?,?)')->execute([$tenant,'Concurrent source client',$b]);$client=(int)$pdo->lastInsertId();}
        $review=companyMergePreview($tenant,$user,$a,$b);
        $pdo->beginTransaction();companyMergeApply($tenant,$user,$review['review_token'],$reviewChoices($review));
        $worker=$spawn(['mode'=>$mode,'workspace'=>$tenant,'actor'=>$actor,'company_id'=>$b,'company_external'=>'race-B-'.$mode,'job_external'=>'race-job-'.$mode,'title'=>'Late '.$mode.' job']);
        $check($waiting($worker),$mode.' writer is observed waiting on the uncommitted merge through native InnoDB lock metadata');
        $pdo->commit();$result=$finish($worker);
        if($mode==='native'){
            $check(!$result['ok']&&(int)$result['code']===409,'Stale native company choice is rejected after the merge commits');
            $q=$pdo->prepare('SELECT COUNT(*) FROM staffing_jobs WHERE tenant_id=? AND title=?');$q->execute([$tenant,'Late native job']);$check((int)$q->fetchColumn()===0,'Rejected stale native writer creates no job');
        }else{
            $check($result['ok']&&(int)$result['result']['company_id']===$a,'Waiting source writer resolves the committed alias before saving the job');
            $check((int)$result['result']['client_id']===$client,'Waiting source writer reads the reparented client rather than an obsolete transaction snapshot');
            $mapping=mappingFindInternal($tenant,'jobdiva','company','race-B-source');$check((int)$mapping['internal_entity_id']===$b,'Concurrent source save preserves the original provider binding');
        }
    }
    foreach(['COMMIT','ROLLBACK']as$decision){
        [$a,$b]=$pair($decision);$review=companyMergePreview($tenant,$user,$a,$b);
        $writer=$spawn(['mode'=>'hold','workspace'=>$tenant,'actor'=>$actor,'company_id'=>$b,'title'=>'Held '.$decision.' job']);
        $saved=$line($workers[$writer]['pipes'][1]);$check(!empty($saved['saved'])&&!empty($saved['in_transaction']),'Independent writer holds its saved job before '.$decision);
        $merger=$spawn(['mode'=>'merge','workspace'=>$tenant,'actor'=>$actor,'review_token'=>$review['review_token'],'choices'=>$reviewChoices($review)]);
        $check($waiting($merger),'Merge is observed waiting for the independent '.$decision.' writer');
        fwrite($workers[$writer]['pipes'][0],$decision.PHP_EOL);fflush($workers[$writer]['pipes'][0]);
        $writerResult=$finish($writer);$merged=$finish($merger);$check($writerResult['ok'],'Writer completes its '.$decision.' decision');
        if($decision==='COMMIT'){
            $check(!$merged['ok']&&(int)$merged['code']===409&&str_contains($merged['error'],'changed'),'Committed reference changes invalidate the waiting merge review');
            $check($read('companies',$b)['deleted_at']===null&&(int)$read('staffing_jobs',(int)$saved['id'])['company_id']===$b,'Stale merge preserves the active company and independently committed job');
        }else{
            $check($merged['ok']&&$read('companies',$b)['deleted_at']!==null,'Rolled-back reference does not falsely invalidate the waiting merge');
            $check($read('staffing_jobs',(int)$saved['id'])===[],'Rolled-back writer leaves no job behind');
        }
    }
    [$a,$b]=$pair('client-first');
    $pdo->prepare('INSERT INTO staffing_clients(tenant_id,name,company_id) VALUES(?,?,?)')->execute([$tenant,'Concurrent native client',$b]);$client=(int)$pdo->lastInsertId();
    $clientBefore=$read('staffing_clients',$client);$revision=staffingClientRevision($pdo,companyRelationshipScope($pdo,$tenant,'placements',false),$clientBefore);
    $review=companyMergePreview($tenant,$user,$a,$b);
    $pdo->beginTransaction();companyMergeApply($tenant,$user,$review['review_token'],$reviewChoices($review));
    $worker=$spawn(['mode'=>'native','entity'=>'client','workspace'=>$tenant,'actor'=>$actor,'client_id'=>$client,'edit_revision'=>$revision]);
    $check($waiting($worker),'Native client edit is observed waiting on the uncommitted merge');$pdo->commit();$result=$finish($worker);
    $clientAfter=$read('staffing_clients',$client);
    $check(!$result['ok']&&(int)$result['code']===409&&(int)$clientAfter['company_id']===$a&&$clientAfter['primary_contact_phone']===$clientBefore['primary_contact_phone'],'Stale client editor preserves the merged relationship and saved profile');
    foreach(['COMMIT','ROLLBACK']as$decision){
        [$a,$b]=$pair('client-'.$decision);
        $pdo->prepare('INSERT INTO staffing_clients(tenant_id,name,company_id) VALUES(?,?,?)')->execute([$tenant,'Held client '.$decision,$b]);$client=(int)$pdo->lastInsertId();
        $revision=staffingClientRevision($pdo,companyRelationshipScope($pdo,$tenant,'placements',false),$read('staffing_clients',$client));
        $review=companyMergePreview($tenant,$user,$a,$b);
        $writer=$spawn(['mode'=>'hold','entity'=>'client','workspace'=>$tenant,'actor'=>$actor,'client_id'=>$client,'edit_revision'=>$revision]);
        $saved=$line($workers[$writer]['pipes'][1]);$check(!empty($saved['saved'])&&!empty($saved['in_transaction']),'Native client writer holds company and client before '.$decision);
        $merger=$spawn(['mode'=>'merge','workspace'=>$tenant,'actor'=>$actor,'review_token'=>$review['review_token'],'choices'=>$reviewChoices($review)]);
        $check($waiting($merger),'Merge waits on the independent native client '.$decision);
        fwrite($workers[$writer]['pipes'][0],$decision.PHP_EOL);fflush($workers[$writer]['pipes'][0]);$writerResult=$finish($writer);$result=$finish($merger);
        $check($writerResult['ok'],'Native client completes its '.$decision.' decision');
        if($decision==='COMMIT')$check(!$result['ok']&&(int)$result['code']===409&&$read('companies',$b)['deleted_at']===null,'Committed native company/client edit invalidates the waiting merge review');
        else$check($result['ok']&&(int)$read('staffing_clients',$client)['company_id']===$a&&$read('staffing_clients',$client)['primary_contact_phone']===null,'Rolled-back client edit leaves no value and permits the reviewed merge');
    }
    [$a,$b]=$pair('csv-after-merge');
    $pdo->prepare('INSERT INTO staffing_clients(tenant_id,name,company_id) VALUES(?,?,?)')->execute([$tenant,'Concurrent CSV client',$b]);$client=(int)$pdo->lastInsertId();
    $review=companyMergePreview($tenant,$user,$a,$b);
    $pdo->beginTransaction();companyMergeApply($tenant,$user,$review['review_token'],$reviewChoices($review));$survivorBefore=$read('companies',$a);
    $worker=$spawn(['mode'=>'native','entity'=>'client_csv','workspace'=>$tenant,'actor'=>$actor,'row'=>['client_id'=>$client,'name'=>'Concurrent CSV client','notes'=>'Imported after merge']]);
    $check($waiting($worker),'CSV client import is observed waiting on the uncommitted merge');$pdo->commit();$result=$finish($worker);
    $check($result['ok']&&(int)$result['result']['id']===$client&&(int)$read('staffing_clients',$client)['company_id']===$a&&$read('companies',$a)===$survivorBefore,'Waiting CSV import uses the retained client and committed survivor without replacing its profile');
    foreach(['COMMIT','ROLLBACK']as$decision){
        [$a,$b]=$pair('csv-'.$decision);$name='Held CSV client '.$decision;
        $pdo->prepare('INSERT INTO staffing_clients(tenant_id,name,company_id) VALUES(?,?,?)')->execute([$tenant,$name,$b]);$client=(int)$pdo->lastInsertId();
        $review=companyMergePreview($tenant,$user,$a,$b);
        $writer=$spawn(['mode'=>'hold','entity'=>'client_csv','workspace'=>$tenant,'actor'=>$actor,'row'=>['client_id'=>$client,'name'=>$name,'primary_contact_phone'=>'CSV change']]);
        $saved=$line($workers[$writer]['pipes'][1]);$check(!empty($saved['saved'])&&!empty($saved['in_transaction']),'CSV writer holds company and client before '.$decision);
        $merger=$spawn(['mode'=>'merge','workspace'=>$tenant,'actor'=>$actor,'review_token'=>$review['review_token'],'choices'=>$reviewChoices($review)]);
        $check($waiting($merger),'Merge waits for the independent CSV '.$decision);
        fwrite($workers[$writer]['pipes'][0],$decision.PHP_EOL);fflush($workers[$writer]['pipes'][0]);$writerResult=$finish($writer);$result=$finish($merger);
        $check($writerResult['ok'],'CSV writer completes its '.$decision.' decision');
        if($decision==='COMMIT')$check(!$result['ok']&&(int)$result['code']===409&&$read('companies',$b)['deleted_at']===null,'Committed CSV company/client edits invalidate the waiting merge review');
        else$check($result['ok']&&(int)$read('staffing_clients',$client)['company_id']===$a&&$read('staffing_clients',$client)['primary_contact_phone']===null,'Rolled-back CSV leaves no value and permits the reviewed merge');
    }
    // Actual source writers use independent connections in both directions:
    // a merge/native edit can win first, or a source update can win first.
    foreach(['jobdiva','qbo']as$system){
        $sourceFixture=static function(string $suffix)use($system,$pair,$tenant,$actor,&$companies):array{
            [$a,$b]=$pair('client-source-'.$system.'-'.$suffix);
            $external='source-client-'.$system.'-'.$suffix;$name='Source client '.$system.' '.$suffix;
            if($system==='qbo'){
                $created=qboUpsertCustomer($tenant,['Id'=>$external,'DisplayName'=>$name,'PrimaryPhone'=>['FreeFormNumber'=>'Original source phone']]);
                $id=$created['internal_id'];$q=getDB()->prepare('SELECT company_id FROM staffing_clients WHERE tenant_id=? AND id=?');$q->execute([$tenant,$id]);$b=(int)$q->fetchColumn();$companies[]=$b;
            }else{
                // Use the exact company already created by the fixture, with
                // its provider ID retained throughout the concurrency test.
                $external='race-B-client-source-'.$system.'-'.$suffix;$name='Race Beta client-source-'.$system.'-'.$suffix;
                $created=staffingClientEnsureForCompany($tenant,$b,$name,['source_system'=>'jobdiva','source_external_id'=>$external,'created_by_user_id'=>$actor]);$id=$created['client_id'];
            }
            return [$a,$b,$id,['entity'=>'client_source','workspace'=>$tenant,'actor'=>$actor,'source_system'=>$system,'source_external'=>$external,'source_name'=>$name,'phone'=>'Concurrent source phone']];
        };
        [$a,$b,$id,$input]=$sourceFixture('merge-first');
        $review=companyMergePreview($tenant,$user,$a,$b);
        $pdo->beginTransaction();companyMergeApply($tenant,$user,$review['review_token'],$reviewChoices($review));$survivor=$read('companies',$a);$clientBefore=$read('staffing_clients',$id);
        $worker=$spawn($input+['mode'=>'native']);$check($waiting($worker),$system.' client source waits on the uncommitted company merge');$pdo->commit();$result=$finish($worker);
        $clientAfter=$read('staffing_clients',$id);
        $check($result['ok']&&(int)$result['result']['id']===$id&&(int)$clientAfter['company_id']===$a&&$clientAfter['primary_contact_phone']===$clientBefore['primary_contact_phone']&&$read('companies',$a)===$survivor,$system.' client source retains the CL, committed survivor and reviewed profile');
        $mapping=mappingFindInternal($tenant,$system==='qbo'?QBO_SOURCE:'jobdiva',$system==='qbo'?'customer':'company',$input['source_external']);
        $check((int)$mapping['internal_entity_id']===($system==='qbo'?$id:$b),$system.' source cannot retarget its original provider binding after a concurrent merge');

        [$a,$b,$id,$input]=$sourceFixture('native-first');$before=$read('staffing_clients',$id);
        $pdo->beginTransaction();staffingClientSaveNative($tenant,$id,['primary_contact_phone'=>'Native choice'],$actor,staffingClientRevision($pdo,companyRelationshipScope($pdo,$tenant,'placements',false),$before));
        $worker=$spawn($input+['mode'=>'native']);$check($waiting($worker),$system.' source waits on the uncommitted native client edit');$pdo->commit();$result=$finish($worker);
        $check($result['ok']&&$read('staffing_clients',$id)['primary_contact_phone']==='Native choice'&&$read('companies',$b)['primary_contact_phone']==='Native choice',$system.' waiting source respects newly committed company and client field ownership');

        foreach(['COMMIT','ROLLBACK']as$decision){
            [$a,$b,$id,$input]=$sourceFixture('source-'.$decision);$before=$read('staffing_clients',$id);
            $review=companyMergePreview($tenant,$user,$a,$b);$writer=$spawn($input+['mode'=>'hold']);$saved=$line($workers[$writer]['pipes'][1]);
            $check(!empty($saved['saved'])&&!empty($saved['in_transaction']),$system.' source holds its complete company/client update before '.$decision);
            $merger=$spawn(['mode'=>'merge','workspace'=>$tenant,'actor'=>$actor,'review_token'=>$review['review_token'],'choices'=>$reviewChoices($review)]);
            $check($waiting($merger),'Merge waits for the independent '.$system.' source '.$decision);
            fwrite($workers[$writer]['pipes'][0],$decision.PHP_EOL);fflush($workers[$writer]['pipes'][0]);$writerResult=$finish($writer);$merged=$finish($merger);
            $check($writerResult['ok'],$system.' source completes its '.$decision.' decision');
            if($decision==='COMMIT')$check(!$merged['ok']&&(int)$merged['code']===409&&$read('companies',$b)['deleted_at']===null&&$read('staffing_clients',$id)['primary_contact_phone']==='Concurrent source phone',$system.' committed source profile invalidates a stale merge review');
            else$check($merged['ok']&&(int)$read('staffing_clients',$id)['company_id']===$a&&$read('staffing_clients',$id)['primary_contact_phone']===$before['primary_contact_phone'],$system.' rolled-back source leaves no value and permits the reviewed merge');
        }
        [$a,$b,$id,$input]=$sourceFixture('receipt-snapshot');clientSourceRaceWrite($input);
        $review=staffingClientSourceReview($tenant,$id);$sourceKey=array_key_first($review['observations']);
        $worker=$spawn(['mode'=>'native','entity'=>'client_accept','snapshot'=>true,'workspace'=>$tenant,'actor'=>$actor,'client_id'=>$id,'source_key'=>$sourceKey,'fields'=>['primary_contact_phone'],'review_token'=>$review['review_token']]);
        $snapshot=$line($workers[$worker]['pipes'][1]);$check(!empty($snapshot['snapshot']),$system.' receipt retry holds a repeatable-read snapshot predating acceptance');
        staffingClientSourceAccept($tenant,$id,$sourceKey,['primary_contact_phone'],$review['review_token'],$actor);
        $before=[$read('staffing_clients',$id),$read('companies',$b)];
        fwrite($workers[$worker]['pipes'][0],'CONTINUE'.PHP_EOL);fflush($workers[$worker]['pipes'][0]);$result=$finish($worker);
        $check($result['ok']&&!empty($result['result']['replayed'])&&[$read('staffing_clients',$id),$read('companies',$b)]===$before,$system.' retry reads the committed receipt instead of reapplying from its older snapshot');
        [$a,$b,$id,$input]=$sourceFixture('review-snapshot');clientSourceRaceWrite($input);
        $review=staffingClientSourceReview($tenant,$id);$sourceKey=array_key_first($review['observations']);
        $worker=$spawn(['mode'=>'native','entity'=>'client_accept','snapshot'=>true,'workspace'=>$tenant,'actor'=>$actor,'client_id'=>$id,'source_key'=>$sourceKey,'fields'=>['primary_contact_phone'],'review_token'=>$review['review_token']]);
        $snapshot=$line($workers[$worker]['pipes'][1]);$check(!empty($snapshot['snapshot']),$system.' source review holds a snapshot before a later provider update');
        clientSourceRaceWrite(array_replace($input,['phone'=>'Changed after review']));$before=[$read('staffing_clients',$id),$read('companies',$b)];
        fwrite($workers[$worker]['pipes'][0],'CONTINUE'.PHP_EOL);fflush($workers[$worker]['pipes'][0]);$result=$finish($worker);
        $check(!$result['ok']&&(int)$result['code']===409&&[$read('staffing_clients',$id),$read('companies',$b)]===$before,$system.' source acceptance rejects the changed proposal even inside an older transaction snapshot');
    }
    foreach(['COMMIT','ROLLBACK']as$decision){
        foreach(['native','merge']as$mode){
            $pdo->prepare('UPDATE tenant_module_scope SET scope_mode="shared" WHERE tenant_id=? AND module_key="companies"')->execute([$child]);
            [$a,$b]=$pair('policy-'.$mode.'-'.$decision);$review=companyMergePreview($child,$user,$a,$b);
            $pdo->beginTransaction();$pdo->prepare('UPDATE tenant_module_scope SET scope_mode="isolated" WHERE tenant_id=? AND module_key="companies"')->execute([$child]);
            $worker=$spawn(['mode'=>$mode,'workspace'=>$child,'actor'=>$actor,'company_id'=>$b,'title'=>'Policy '.$decision.' job','review_token'=>$review['review_token'],'choices'=>$reviewChoices($review)]);
            $check($waiting($worker),$mode.' waits for the independent directory-policy '.$decision);
            if($decision==='COMMIT')$pdo->commit();else$pdo->rollBack();$result=$finish($worker);
            if($decision==='COMMIT'){
                $check(!$result['ok'],'Committed company-directory change rejects the old '.$mode.' choice');
                $q=$pdo->prepare('SELECT COUNT(*) FROM staffing_jobs WHERE tenant_id=? AND title=?');$q->execute([$child,'Policy COMMIT job']);
                $check((int)$q->fetchColumn()===0&&$read('companies',$b)['deleted_at']===null,'Policy conflict leaves no job or retired company: '.$mode);
            }else{
                $check($result['ok'],'Rolled-back company-directory change allows the reviewed '.$mode.' operation');
                if($mode==='native')$check((int)$result['result']['tenant_id']===$child&&(int)$result['result']['company_id']===$b,'Waiting job uses its own Staffing owner and the restored shared company');
                else $check($read('companies',$b)['deleted_at']!==null,'Waiting merge completes only after the original sharing policy is restored');
            }
        }
    }
    $repairFixture=static function(string $label,int $workspace)use($pdo,$pair,$tenant,$child,$actor,$read,&$companies):array{
        static $sequence=62000;$start=(string)++$sequence;
        $pdo->prepare('INSERT INTO tenant_module_scope(tenant_id,module_key,scope_mode) VALUES(?,"companies","shared") ON DUPLICATE KEY UPDATE scope_mode="shared"')->execute([$child]);
        $pdo->prepare('INSERT INTO tenant_module_scope(tenant_id,module_key,scope_mode) VALUES(?,"placements","shared") ON DUPLICATE KEY UPDATE scope_mode="shared"')->execute([$child]);
        [$a,$b]=$pair($label);$external='race-B-'.$label;$name=$read('companies',$b)['name'];
        if($workspace!==$tenant){$external.='-child';$name.=' child';$b=jobdivaCompanyUpsertStable($workspace,$external,$name,[],[],$actor,[]);$companies[]=$b;}
        $client=staffingClientEnsureForCompany($workspace,$b,$name,['source_system'=>'jobdiva','source_external_id'=>$external,'created_by_user_id'=>$actor])['client_id'];
        $pdo->prepare('INSERT INTO people(tenant_id,first_name,last_name,email_primary) VALUES(?,"Synthetic","Repair race",?)')->execute([$tenant,'repair-race-'.$start.'@example.invalid']);$person=(int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO placements(tenant_id,person_id,title,status,engagement_type,start_date) VALUES(?,?,"Repair race","draft","W2","2026-11-01")')->execute([$tenant,$person]);$placement=(int)$pdo->lastInsertId();
        $payload=['startId'=>$start,'candidateId'=>'71001','jobId'=>'81001','_jd_contract'=>['source'=>'EmployeeAssignmentRecordsDetail','start_id'=>$start,'client_company_id'=>$external,'client_company_name'=>$name]];
        mappingUpsert($workspace,'jobdiva','placement',$start,$placement,$payload,'pull',$actor);$mapping=mappingFindInternal($workspace,'jobdiva','placement',$start);
        return[$a,$b,$client,$placement,['mode'=>'native','entity'=>'client_repair','workspace'=>$workspace,'actor'=>$actor,'mapping_id'=>(int)$mapping['id']]];
    };
    foreach(['COMMIT','ROLLBACK']as$decision){
        [$a,$b,$client,$placement,$input]=$repairFixture('repair-native-'.$decision,$tenant);
        $pdo->beginTransaction();$pdo->prepare('UPDATE placements SET coreflux_overridden_fields=? WHERE id=?')->execute(['["client_id"]',$placement]);
        $worker=$spawn($input);$check($waiting($worker),'Billing repair waits for the native placement ownership '.$decision);
        if($decision==='COMMIT')$pdo->commit();else$pdo->rollBack();$result=$finish($worker);
        $check($result['ok']&&($decision==='COMMIT'?($result['result']['reason']==='core_owned'&&$read('placements',$placement)['client_id']===null):((int)$read('placements',$placement)['client_id']===$client)),'Billing repair observes the current ownership after '.$decision);
        [$a,$b,$client,$placement,$input]=$repairFixture('repair-policy-'.$decision,$child);
        $pdo->beginTransaction();$pdo->prepare('UPDATE tenant_module_scope SET scope_mode="isolated" WHERE tenant_id=? AND module_key="placements"')->execute([$child]);
        $worker=$spawn($input);$check($waiting($worker),'Billing repair waits for the placement-sharing policy '.$decision);
        if($decision==='COMMIT')$pdo->commit();else$pdo->rollBack();$result=$finish($worker);
        $check($decision==='COMMIT'?(!$result['ok']&&$read('placements',$placement)['client_id']===null):($result['ok']&&(int)$read('placements',$placement)['client_id']===$client),'Billing repair uses fresh placement scope after '.$decision);
        [$a,$b,$client,$placement,$input]=$repairFixture('repair-first-'.$decision,$tenant);$review=companyMergePreview($tenant,$user,$a,$b);
        $writer=$spawn(array_replace($input,['mode'=>'hold']));$saved=$line($workers[$writer]['pipes'][1]);$check(!empty($saved['saved']),'Billing repair holds its complete source/client/placement transaction');
        $merger=$spawn(['mode'=>'merge','workspace'=>$tenant,'actor'=>$actor,'review_token'=>$review['review_token'],'choices'=>$reviewChoices($review)]);
        $check($waiting($merger),'Company merge waits for the billing repair '.$decision);
        fwrite($workers[$writer]['pipes'][0],$decision.PHP_EOL);fflush($workers[$writer]['pipes'][0]);$written=$finish($writer);$merged=$finish($merger);
        $check($written['ok']&&$merged['ok']&&(int)$read('staffing_clients',$client)['company_id']===$a
            &&($decision==='COMMIT'?(int)$read('placements',$placement)['client_id']===$client:$read('placements',$placement)['client_id']===null),
            'Company merge retains the exact client number and the committed or rolled-back billing reference: '.$decision);
    }
    [$a,$b,$client,$placement,$input]=$repairFixture('repair-after-merge',$tenant);$review=companyMergePreview($tenant,$user,$a,$b);
    $pdo->beginTransaction();companyMergeApply($tenant,$user,$review['review_token'],$reviewChoices($review));$worker=$spawn($input);$check($waiting($worker),'Billing repair waits for the company merge to finish');$pdo->commit();$result=$finish($worker);
    $check($result['ok']&&(int)$read('placements',$placement)['client_id']===$client&&(int)$read('staffing_clients',$client)['company_id']===$a,'Billing repair links the retained CL after the committed company alias');
    [$a,$b,$client,$placement,$input]=$repairFixture('repair-old-snapshot',$tenant);
    $worker=$spawn($input+['snapshot'=>true]);$snapshot=$line($workers[$worker]['pipes'][1]);$check(!empty($snapshot['snapshot']),'Billing repair holds a snapshot predating the native ownership decision');
    $pdo->prepare('UPDATE placements SET coreflux_overridden_fields=? WHERE id=?')->execute(['["client_id"]',$placement]);
    fwrite($workers[$worker]['pipes'][0],'CONTINUE'.PHP_EOL);fflush($workers[$worker]['pipes'][0]);$result=$finish($worker);
    $check($result['ok']&&$result['result']['reason']==='core_owned'&&$read('placements',$placement)['client_id']===null,'Billing repair does not overwrite native ownership from an older transaction snapshot');
    $nativeFixture=static function(string $suffix,int $workspace)use($pdo,$tenant,$child,$actor,$pair):array{
        [$a,$b]=$pair('native-placement-'.$suffix);
        $pdo->prepare('UPDATE tenant_module_scope SET scope_mode="shared" WHERE tenant_id=? AND module_key IN("placements","companies")')->execute([$child]);
        $pdo->prepare('INSERT INTO people(tenant_id,first_name,last_name,email_primary) VALUES(?,"Native","Race worker",?)')->execute([$tenant,'native-race-'.$suffix.'@example.invalid']);$person=(int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO placements(tenant_id,person_id,title,status,engagement_type,start_date) VALUES(?,?,"Native race placement","draft","w2","2026-11-01")')->execute([$tenant,$person]);$placement=(int)$pdo->lastInsertId();
        return[$a,$b,$placement,['mode'=>'native','entity'=>'native_placement','workspace'=>$workspace,'actor'=>$actor,'placement_id'=>$placement,'patch'=>['end_client_company_id'=>$b]]];
    };
    foreach(['COMMIT','ROLLBACK']as$decision){
        [$a,$b,$placement,$input]=$nativeFixture('policy-'.$decision,$child);
        $pdo->beginTransaction();$pdo->prepare('UPDATE tenant_module_scope SET scope_mode="isolated" WHERE tenant_id=? AND module_key="placements"')->execute([$child]);
        $worker=$spawn($input);$check($waiting($worker),'Native placement waits for current directory policy '.$decision);
        if($decision==='COMMIT')$pdo->commit();else$pdo->rollBack();$result=$finish($worker);
        $check($decision==='COMMIT'?(!$result['ok']&&(int)$result['code']===409&&$read('placements',$placement)['end_client_company_id']===null):($result['ok']&&(int)$read('placements',$placement)['end_client_company_id']===$b),'Native placement observes committed or rolled-back scope without writing the old owner: '.$decision);
        [$a,$b,$placement,$input]=$nativeFixture('merge-first-'.$decision,$tenant);$review=companyMergePreview($tenant,$user,$a,$b);
        $pdo->beginTransaction();companyMergeApply($tenant,$user,$review['review_token'],$reviewChoices($review));
        $worker=$spawn($input);$check($waiting($worker),'Native placement waits for current company merge '.$decision);
        if($decision==='COMMIT')$pdo->commit();else$pdo->rollBack();$result=$finish($worker);
        $check($decision==='COMMIT'?(!$result['ok']&&(int)$result['code']===409&&$read('placements',$placement)['end_client_company_id']===null):($result['ok']&&(int)$read('placements',$placement)['end_client_company_id']===$b),'Native placement rejects a committed retired number and accepts the rolled-back merge: '.$decision);
        [$a,$b,$placement,$input]=$nativeFixture('native-first-'.$decision,$tenant);$review=companyMergePreview($tenant,$user,$a,$b);
        $worker=$spawn(array_replace($input,['mode'=>'hold']));$saved=$line($workers[$worker]['pipes'][1]);$check(!empty($saved['saved'])&&!empty($saved['in_transaction']),'Native placement holds reference, graph, ownership and audit before '.$decision);
        $merger=$spawn(['mode'=>'merge','workspace'=>$tenant,'actor'=>$actor,'review_token'=>$review['review_token'],'choices'=>$reviewChoices($review)]);
        $check($waiting($merger),'Company merge waits for the native placement writer '.$decision);
        fwrite($workers[$worker]['pipes'][0],$decision.PHP_EOL);fflush($workers[$worker]['pipes'][0]);$written=$finish($worker);$merged=$finish($merger);
        $check($written['ok']&&($decision==='COMMIT'?(!$merged['ok']&&(int)$merged['code']===409&&(int)$read('placements',$placement)['end_client_company_id']===$b):($merged['ok']&&$read('placements',$placement)['end_client_company_id']===null)),'Merge rechecks committed placement references and ignores rolled-back choices: '.$decision);
    }
    [$a,$b,$placement,$input]=$nativeFixture('old-snapshot',$tenant);
    $worker=$spawn($input+['snapshot'=>true]);$snapshot=$line($workers[$worker]['pipes'][1]);$check(!empty($snapshot['snapshot']),'Native placement transaction snapshot predates the merge');
    $review=companyMergePreview($tenant,$user,$a,$b);companyMergeApply($tenant,$user,$review['review_token'],$reviewChoices($review));
    fwrite($workers[$worker]['pipes'][0],'CONTINUE'.PHP_EOL);fflush($workers[$worker]['pipes'][0]);$result=$finish($worker);
    $check(!$result['ok']&&(int)$result['code']===409&&$read('placements',$placement)['end_client_company_id']===null,'Old transaction snapshot cannot restore a merged company into a native placement');
    // Full ordinary source projection, including derived economics, must use
    // committed identities and policies after waiting on another connection.
    $pdo->prepare('INSERT INTO accounting_entities(tenant_id,code,legal_name,active) VALUES(?,"RACE-CI","Synthetic assignment race employer",1)')->execute([$tenant]);
    $projectionFixture=static function(string $label,int $workspace)use($pdo,$tenant,$child,$actor,$read):array{
        static $sequence=99200000;$n=$sequence+=100;
        foreach(['companies','people','placements','staffing','accounting']as$module)$pdo->prepare('INSERT INTO tenant_module_scope(tenant_id,module_key,scope_mode) VALUES(?,?,"shared") ON DUPLICATE KEY UPDATE scope_mode="shared"')->execute([$child,$module]);
        $payload=['startId'=>(string)$n,'candidateId'=>(string)($n+1),'jobId'=>(string)($n+2),
            'firstName'=>'Synthetic','lastName'=>'Projection race '.$n,'candidateEmail'=>'assignment-race-'.$n.'@example.invalid',
            'startDate'=>'2026-11-01','title'=>'Assignment race '.$label,'status'=>'on_hold','worksiteCountry'=>'US',
            '_jd_job'=>['jobId'=>(string)($n+2),'title'=>'Projection race job '.$n],
            '_jd_contract'=>['source'=>'EmployeeAssignmentRecordsDetail','start_id'=>(string)$n,'engagement_type'=>'w2','placement_status'=>'on_hold','start_date'=>'2026-11-01',
                'client_company_id'=>(string)($n+3),'client_company_name'=>'Projection race debtor '.$n,
                'end_client_company_id'=>(string)($n+4),'end_client_name'=>'Projection race recipient '.$n,
                'bill_rate'=>100,'pay_rate'=>50,'bill_rate_unit'=>'hour','pay_rate_unit'=>'hour','currency'=>'USD','worksite_country'=>'US']];
        $input=['mode'=>'native','entity'=>'assignment_source','workspace'=>$workspace,'actor'=>$actor,'payload'=>$payload];
        $result=assignmentSourceRaceWrite($input);$placement=$read('placements',(int)$result['id']);
        $client=$read('staffing_clients',(int)$placement['client_id']);$b=(int)$client['company_id'];
        $a=jobdivaCompanyUpsertStable($workspace,'race-projection-survivor-'.$n,'Projection survivor '.$n,[],[],$actor,[]);
        return[$a,$b,(int)$placement['id'],$input];
    };
    $projectionRows=static function()use($pdo,$tenant,$child):array{
        $rows=[];foreach(['people','companies','staffing_clients','staffing_jobs','placements','placement_rates','placement_client_chain','placement_corp_details','placement_referrals','placement_commissions','placement_economic_parties','external_entity_mappings','entity_sync_history','audit_log','jobdiva_sync_audit']as$table){
            $q=$pdo->prepare('SELECT * FROM '.$table.' WHERE tenant_id IN(?,?) ORDER BY '.($table==='placement_corp_details'?'placement_id':'id'));$q->execute([$tenant,$child]);$rows[$table]=$q->fetchAll(PDO::FETCH_ASSOC);
        }return$rows;
    };
    foreach(['COMMIT','ROLLBACK']as$decision){
        foreach(['companies','placements']as$module){
            [$a,$b,$placement,$input]=$projectionFixture('policy-'.$module.'-'.$decision,$child);$before=$projectionRows();
            $pdo->beginTransaction();$pdo->prepare('UPDATE tenant_module_scope SET scope_mode="isolated" WHERE tenant_id=? AND module_key=?')->execute([$child,$module]);
            $worker=$spawn($input);$check($waiting($worker),'Full assignment projection waits for '.$module.' policy '.$decision);
            if($decision==='COMMIT')$pdo->commit();else$pdo->rollBack();$result=$finish($worker);
            $check($decision==='COMMIT'?(!$result['ok']&&$projectionRows()===$before):($result['ok']&&(int)$result['result']['id']===$placement),'Full projection observes '.$module.' policy '.$decision.' without writing a stale directory');
        }
        [$a,$b,$placement,$input]=$projectionFixture('merge-first-'.$decision,$tenant);$review=companyMergePreview($tenant,$user,$a,$b);
        $pdo->beginTransaction();companyMergeApply($tenant,$user,$review['review_token'],$reviewChoices($review));
        $worker=$spawn($input);$check($waiting($worker),'Full assignment projection waits for company merge '.$decision);
        if($decision==='COMMIT')$pdo->commit();else$pdo->rollBack();$result=$finish($worker);
        $client=$read('staffing_clients',(int)$read('placements',$placement)['client_id']);
        $parties=array_column(placementEconomicsParties($tenant,$placement),null,'source_ref');$expected=$decision==='COMMIT'?$a:$b;
        $check($result['ok']&&(int)$client['company_id']===$expected&&(int)$parties['placement:bill_to']['company_id']===$expected,'Waiting full projection and derived debtor use the same committed company identity: '.$decision);
        $link=mappingFindInternal($tenant,'jobdiva','company',$input['payload']['_jd_contract']['client_company_id']);
        $check((int)$link['internal_entity_id']===$b,'Concurrent projection retains the original provider company binding: '.$decision);

        [$a,$b,$placement,$input]=$projectionFixture('writer-first-'.$decision,$tenant);$review=companyMergePreview($tenant,$user,$a,$b);
        // A second source assignment references the reviewed debtor. Its entire
        // graph is invisible until commit and disappears completely on rollback.
        $payload=$input['payload'];$n=(int)$payload['startId']+50;
        $payload['startId']=(string)$n;$payload['_jd_contract']['start_id']=(string)$n;
        $payload['candidateId']=(string)($n+1);$payload['candidateEmail']='assignment-race-'.$n.'@example.invalid';
        $payload['jobId']=(string)($n+2);$payload['_jd_job']=['jobId'=>(string)($n+2),'title'=>'Held projection job '.$n];
        $worker=$spawn(array_replace($input,['mode'=>'hold','payload'=>$payload]));$saved=$line($workers[$worker]['pipes'][1]);
        $check(!empty($saved['saved'])&&!empty($saved['in_transaction']),'Full assignment writer holds all graph and source changes before '.$decision);
        $merger=$spawn(['mode'=>'merge','workspace'=>$tenant,'actor'=>$actor,'review_token'=>$review['review_token'],'choices'=>$reviewChoices($review)]);
        $check($waiting($merger),'Company merge waits for the complete assignment projection '.$decision);
        fwrite($workers[$worker]['pipes'][0],$decision.PHP_EOL);fflush($workers[$worker]['pipes'][0]);$written=$finish($worker);$merged=$finish($merger);
        $newLink=mappingFindInternal($tenant,'jobdiva','placement',(string)$n);
        $check($written['ok']&&($decision==='COMMIT'?(!$merged['ok']&&(int)$merged['code']===409&&$newLink&&(int)$newLink['internal_entity_id']===(int)$saved['id']):($merged['ok']&&!$newLink&&$read('placements',(int)$saved['id'])===[])), 'Merge rechecks committed source references and ignores rolled-back full projections: '.$decision);
    }
    [$a,$b,$placement,$input]=$projectionFixture('old-snapshot',$tenant);
    $worker=$spawn($input+['snapshot'=>true]);$snapshot=$line($workers[$worker]['pipes'][1]);$check(!empty($snapshot['snapshot']),'Full assignment projection holds a snapshot predating the company merge');
    $review=companyMergePreview($tenant,$user,$a,$b);companyMergeApply($tenant,$user,$review['review_token'],$reviewChoices($review));
    fwrite($workers[$worker]['pipes'][0],'CONTINUE'.PHP_EOL);fflush($workers[$worker]['pipes'][0]);$result=$finish($worker);
    $parties=array_column(placementEconomicsParties($tenant,$placement),null,'source_ref');
    $check($result['ok']&&(int)$parties['placement:bill_to']['company_id']===$a&&$read('companies',$b)['deleted_at']!==null,'Old transaction snapshot cannot revive a merged debtor through full projection or derived economics');

    [$a,$b,$placement,$input]=$projectionFixture('derived-old-snapshot',$tenant);
    $pdo->prepare('UPDATE placements SET engagement_type="c2c" WHERE id=?')->execute([$placement]);
    $pdo->prepare('INSERT INTO placement_corp_details(tenant_id,placement_id,company_id,corp_legal_name) VALUES(?,?,?,?)')->execute([$tenant,$placement,$b,'Synthetic graph corporation']);
    $pdo->prepare('INSERT INTO placement_client_chain(tenant_id,placement_id,position,party_role,party_name,company_id,is_payable,portal_fee_flat) VALUES(?,?,9,"prime_vendor","Synthetic graph fee",?,1,10)')->execute([$tenant,$placement,$b]);$chain=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO placement_referrals(tenant_id,placement_id,referrer_type,referrer_company_id,referrer_vendor_name,fee_basis,fee_flat,start_date) VALUES(?,?,"vendor",?,"Synthetic graph referral","per_hour",1,"2026-11-01")')->execute([$tenant,$placement,$b]);$referral=(int)$pdo->lastInsertId();
    $check(placementEconomicsReconcile($tenant,$placement)['available'],'Derived graph race starts with valid corporation, chain and referral recipients');
    $worker=$spawn(['mode'=>'native','entity'=>'economic_graph','workspace'=>$tenant,'actor'=>$actor,'placement_id'=>$placement,'snapshot'=>true]);$snapshot=$line($workers[$worker]['pipes'][1]);
    $check(!empty($snapshot['snapshot']),'Derived graph transaction snapshot predates company merge and changed source terms');
    $review=companyMergePreview($tenant,$user,$a,$b);companyMergeApply($tenant,$user,$review['review_token'],$reviewChoices($review));
    $pdo->prepare('UPDATE placement_client_chain SET portal_fee_flat=17 WHERE id=?')->execute([$chain]);
    $pdo->prepare('UPDATE placement_referrals SET fee_flat=3 WHERE id=?')->execute([$referral]);
    fwrite($workers[$worker]['pipes'][0],'CONTINUE'.PHP_EOL);fflush($workers[$worker]['pipes'][0]);$result=$finish($worker);
    $parties=array_column(placementEconomicsParties($tenant,$placement),null,'source_ref');
    $check($result['ok']&&!empty($result['result']['available'])&&(int)$parties['corp:'.$placement]['company_id']===$a
        &&(int)$parties['chain:'.$chain]['company_id']===$a&&(float)$parties['chain:'.$chain]['fee_flat']===17.0
        &&(int)$parties['referral:'.$referral]['company_id']===$a&&(float)$parties['referral:'.$referral]['fee_flat']===3.0,
        'Derived graph uses current corporation, chain and referral identities and terms despite an older snapshot');

    $ruleFixture=static function(string $label,int $workspace)use($pdo,$projectionFixture,$read,$tenant,$actor):array{
        [$msp,$survivor,$placement,$input]=$projectionFixture('rule-'.$label,$workspace);$payload=$input['payload'];$start=$payload['startId'];
        $payload['mspName']=$read('companies',$msp)['name'];$payload['mspCompanyId']='race-projection-survivor-'.$start;$payload['mspFeePct']=2;
        $payload['_jd_contract']+=['client_bill_cycle'=>'monthly','vendor_pay_cycle'=>'biweekly','client_payment_terms'=>'NET30','vendor_payment_terms'=>'NET30','paid_when_paid'=>false];
        mappingUpsert($workspace,'jobdiva','jobdiva_assignment',$start,$placement,$payload);
        $parent=$read('placements',$placement);
        $chain=jobdivaSyncUpsertPlacementChain($tenant,$placement,(int)$parent['end_client_company_id'],$parent['end_client_name'],$payload,$actor,$workspace)['rows'];
        placementSourceRuleEdit($tenant,$placement,'chain','update',$chain['msp'],['portal_fee_pct'=>'0.0300'],['id'=>$actor,'role'=>'admin']);
        $review=placementSourceRuleReview($workspace,$placement,'chain');
        if(!$review['selectable'])throw new RuntimeException('Rule race source blocked: '.implode('; ',$review['issues']));
        $matches=[];foreach($review['proposed']as$p){$same=array_values(array_filter($review['current'],static fn($c)=>$c['identity']===$p['identity']));$matches[$p['key']]=$same[0]['id']??0;}
        $body=['confirm'=>'REPLACE_REVIEWED_SOURCE_RULES','review_token'=>$review['review_token'],'request_id'=>'99200000-0000-4000-8000-'.sprintf('%012d',(int)$start),'matches'=>$matches];
        return[$survivor,$msp,$placement,$chain['msp'],['mode'=>'native','entity'=>'source_rules','workspace'=>$workspace,'actor'=>$actor,'placement_id'=>$placement,'body'=>$body],$payload];
    };
    foreach(['COMMIT','ROLLBACK']as$decision){
        foreach(['companies','placements']as$module){
            [$a,$b,$placement,$msp,$input]=$ruleFixture('policy-'.$module.'-'.$decision,$child);$before=$projectionRows();
            $pdo->beginTransaction();$pdo->prepare('UPDATE tenant_module_scope SET scope_mode="isolated" WHERE tenant_id=? AND module_key=?')->execute([$child,$module]);
            $worker=$spawn($input);$check($waiting($worker),'Reviewed source handback waits for '.$module.' policy '.$decision);
            if($decision==='COMMIT')$pdo->commit();else$pdo->rollBack();$result=$finish($worker);
            $check($decision==='COMMIT'?(!$result['ok']&&$projectionRows()===$before):($result['ok']&&(float)$read('placement_client_chain',$msp)['portal_fee_pct']===0.02),'Reviewed handback respects current '.$module.' scope after '.$decision);
        }
        [$a,$b,$placement,$msp,$input,$payload]=$ruleFixture('source-first-'.$decision,$tenant);
        $pdo->beginTransaction();mappingUpsert($tenant,'jobdiva','jobdiva_assignment',$payload['startId'],$placement,array_replace($payload,['mspFeePct'=>4]));
        $worker=$spawn($input);$check($waiting($worker),'Reviewed handback waits for the saved source mirror '.$decision);
        if($decision==='COMMIT')$pdo->commit();else$pdo->rollBack();$result=$finish($worker);
        $check($decision==='COMMIT'?(!$result['ok']&&(int)$result['code']===409&&(float)$read('placement_client_chain',$msp)['portal_fee_pct']===0.03):($result['ok']&&(float)$read('placement_client_chain',$msp)['portal_fee_pct']===0.02),'Committed new evidence invalidates handback; rolled-back evidence preserves the review: '.$decision);
        [$a,$b,$placement,$msp,$input]=$ruleFixture('merge-first-'.$decision,$tenant);$review=companyMergePreview($tenant,$user,$a,$b);
        $pdo->beginTransaction();companyMergeApply($tenant,$user,$review['review_token'],$reviewChoices($review));
        $worker=$spawn($input);$check($waiting($worker),'Reviewed handback waits for the recipient merge '.$decision);
        if($decision==='COMMIT')$pdo->commit();else$pdo->rollBack();$result=$finish($worker);
        $check($decision==='COMMIT'?(!$result['ok']&&(int)$result['code']===409&&(float)$read('placement_client_chain',$msp)['portal_fee_pct']===0.03):($result['ok']&&(float)$read('placement_client_chain',$msp)['portal_fee_pct']===0.02),'Handback never silently accepts a changed recipient review after '.$decision);
        [$a,$b,$placement,$msp,$input]=$ruleFixture('review-first-'.$decision,$tenant);$review=companyMergePreview($tenant,$user,$a,$b);
        foreach($input['body']['matches']as$key=>$id)if($id===$msp)$input['body']['matches'][$key]=0;
        $worker=$spawn(array_replace($input,['mode'=>'rule_hold']));$saved=$line($workers[$worker]['pipes'][1]);$check(!empty($saved['saved']),'Reviewed handback holds matched choices, source ownership and receipt before '.$decision);
        $merger=$spawn(['mode'=>'merge','workspace'=>$tenant,'actor'=>$actor,'review_token'=>$review['review_token'],'choices'=>$reviewChoices($review)]);
        $check($waiting($merger),'Recipient merge waits for reviewed rule replacement '.$decision);
        fwrite($workers[$worker]['pipes'][0],$decision.PHP_EOL);fflush($workers[$worker]['pipes'][0]);$written=$finish($worker);$merged=$finish($merger);
        $check($written['ok']&&($decision==='COMMIT'?(!$merged['ok']&&(int)$merged['code']===409&&$read('placement_client_chain',$msp)===[]):($merged['ok']&&(int)$read('placement_client_chain',$msp)['company_id']===$a)), 'Merge rechecks committed replacement IDs and ignores rolled-back choices: '.$decision);
        [$a,$b,$placement,$msp,$input]=$ruleFixture('retry-'.$decision,$tenant);
        $worker=$spawn(array_replace($input,['mode'=>'rule_hold']));$saved=$line($workers[$worker]['pipes'][1]);$check(!empty($saved['saved']),'First handback holds its receipt before concurrent retry '.$decision);
        $retry=$spawn($input);$check($waiting($retry),'Identical handback retry waits for first request '.$decision);
        fwrite($workers[$worker]['pipes'][0],$decision.PHP_EOL);fflush($workers[$worker]['pipes'][0]);$written=$finish($worker);$again=$finish($retry);
        $q=$pdo->prepare('SELECT COUNT(*) FROM audit_log WHERE tenant_id=? AND target_id=? AND event="placement.source_rules.replaced"');$q->execute([$tenant,$placement]);
        $check($written['ok']&&$again['ok']&&(int)$q->fetchColumn()===1&&(float)$read('placement_client_chain',$msp)['portal_fee_pct']===0.02
            &&(!empty($again['result']['replayed'])===($decision==='COMMIT')),'Concurrent retry records exactly one replacement after '.$decision);
    }
    $storedFixture=static function(string $label,int $workspace)use($ruleFixture,$actor):array{
        [$a,$b,$placement,$msp,$ruleInput,$payload]=$ruleFixture('stored-'.$label,$workspace);
        $payload['_jd_contract']['worksite_city']='Reviewed stored city';
        mappingUpsert($workspace,'jobdiva','jobdiva_assignment',$payload['startId'],$placement,$payload);
        $plan=jobdivaStoredAssignmentProjectionPlan($workspace,5000,[],false);$row=array_column($plan['rows'],null,'start_id')[$payload['startId']];
        if(!$row['selectable'])throw new RuntimeException('Stored race source blocked: '.implode('; ',$row['errors']));
        return[$a,$b,$placement,['mode'=>'native','entity'=>'stored_assignment','workspace'=>$workspace,'actor'=>$actor,'placement_id'=>$placement,'start_id'=>$payload['startId'],'review_token'=>$plan['dry_run_token']],$payload];
    };
    foreach(['COMMIT','ROLLBACK']as$decision){
        foreach(['companies','placements']as$module){
            [$a,$b,$placement,$input]=$storedFixture('policy-'.$module.'-'.$decision,$child);$before=$projectionRows();
            $pdo->beginTransaction();$pdo->prepare('UPDATE tenant_module_scope SET scope_mode="isolated" WHERE tenant_id=? AND module_key=?')->execute([$child,$module]);
            $worker=$spawn($input);$check($waiting($worker),'Stored assignment waits for '.$module.' policy '.$decision);
            if($decision==='COMMIT')$pdo->commit();else$pdo->rollBack();$result=$finish($worker);
            $check($decision==='COMMIT'?(!$result['ok']&&$projectionRows()===$before):($result['ok']&&$result['result']['projected']===1&&$read('placements',$placement)['worksite_city']==='Reviewed stored city'),'Stored assignment respects current '.$module.' scope after '.$decision);
        }
        [$a,$b,$placement,$input,$payload]=$storedFixture('source-first-'.$decision,$tenant);
        $pdo->beginTransaction();mappingUpsert($tenant,'jobdiva','jobdiva_assignment',$payload['startId'],$placement,array_replace($payload,['title'=>'Changed after stored review']));
        $worker=$spawn($input);$check($waiting($worker),'Stored assignment waits for its saved source evidence '.$decision);
        if($decision==='COMMIT')$pdo->commit();else$pdo->rollBack();$before=$projectionRows();$result=$finish($worker);
        $check($decision==='COMMIT'?(!$result['ok']&&$projectionRows()===$before):($result['ok']&&$read('placements',$placement)['worksite_city']==='Reviewed stored city'),'Stored apply rejects changed evidence and accepts rolled-back evidence after '.$decision);
        [$a,$b,$placement,$input]=$storedFixture('merge-first-'.$decision,$tenant);$review=companyMergePreview($tenant,$user,$a,$b);
        $pdo->beginTransaction();companyMergeApply($tenant,$user,$review['review_token'],$reviewChoices($review));
        $worker=$spawn($input);$check($waiting($worker),'Stored assignment waits for its recipient merge '.$decision);
        if($decision==='COMMIT')$pdo->commit();else$pdo->rollBack();$before=$projectionRows();$result=$finish($worker);
        $check($decision==='COMMIT'?(!$result['ok']&&$projectionRows()===$before):($result['ok']&&$read('placements',$placement)['worksite_city']==='Reviewed stored city'),'Stored review never silently accepts a changed recipient after '.$decision);
        [$a,$b,$placement,$input]=$storedFixture('concurrent-apply-'.$decision,$tenant);
        $worker=$spawn(array_replace($input,['mode'=>'stored_hold']));$saved=$line($workers[$worker]['pipes'][1]);$check(!empty($saved['saved'])&&!empty($saved['in_transaction']),'Stored apply holds its complete assignment and audit before '.$decision);
        $retry=$spawn($input);$check($waiting($retry),'Concurrent stored apply waits for the first reviewed assignment '.$decision);
        fwrite($workers[$worker]['pipes'][0],$decision.PHP_EOL);fflush($workers[$worker]['pipes'][0]);$written=$finish($worker);$again=$finish($retry);
        $q=$pdo->prepare('SELECT COUNT(*) FROM jobdiva_sync_audit WHERE tenant_id=? AND action="stored_assignment_reconciliation" AND JSON_UNQUOTE(JSON_EXTRACT(detail,"$.start_id"))=?');$q->execute([$tenant,$input['start_id']]);
        $check($written['ok']&&($decision==='COMMIT'?!$again['ok']:$again['ok'])&&(int)$q->fetchColumn()===1&&$read('placements',$placement)['worksite_city']==='Reviewed stored city','Concurrent stored requests commit exactly one reviewed projection after '.$decision);
    }
    $csvFixture=static function(string $label,int $workspace)use($projectionFixture,$pdo,$actor):array{
        [$a,$b,$placement,$input]=$projectionFixture('csv-'.$label,$workspace);$start=$input['payload']['startId'];
        $csv="Start ID,Title,Worksite City\n{$start},Reviewed concurrent CSV,CSV reviewed city\n";
        $plan=jobdivaReconciliationBuildPlan($pdo,$workspace,null,$csv,null);
        if(!$plan['rows'][0]['selectable'])throw new RuntimeException('CSV race source blocked: '.implode('; ',$plan['rows'][0]['errors']));
        return[$a,$b,$placement,['mode'=>'native','entity'=>'csv_assignment','workspace'=>$workspace,'actor'=>$actor,'placement_id'=>$placement,'start_id'=>$start,'review_token'=>$plan['dry_run_token'],'csv'=>$csv],$input['payload']];
    };
    foreach(['COMMIT','ROLLBACK']as$decision){
        foreach(['companies','placements']as$module){
            [$a,$b,$placement,$input]=$csvFixture('policy-'.$module.'-'.$decision,$child);$before=$projectionRows();
            $pdo->beginTransaction();$pdo->prepare('UPDATE tenant_module_scope SET scope_mode="isolated" WHERE tenant_id=? AND module_key=?')->execute([$child,$module]);
            $worker=$spawn($input);$check($waiting($worker),'CSV assignment waits for '.$module.' policy '.$decision);
            if($decision==='COMMIT')$pdo->commit();else$pdo->rollBack();$result=$finish($worker);
            $check($decision==='COMMIT'?(!$result['ok']&&$projectionRows()===$before):($result['ok']&&$result['result']['updated']===1&&$read('placements',$placement)['worksite_city']==='CSV reviewed city'),'CSV apply respects current '.$module.' scope after '.$decision);
        }
        [$a,$b,$placement,$input,$payload]=$csvFixture('source-first-'.$decision,$tenant);
        $pdo->beginTransaction();mappingUpsert($tenant,'jobdiva','placement',$payload['startId'],$placement,array_replace($payload,['title'=>'Changed source after CSV preview']));
        $worker=$spawn($input);$check($waiting($worker),'CSV apply waits for retained provider mapping '.$decision);
        if($decision==='COMMIT')$pdo->commit();else$pdo->rollBack();$before=$projectionRows();$result=$finish($worker);
        $check($decision==='COMMIT'?(!$result['ok']&&$projectionRows()===$before):($result['ok']&&$read('placements',$placement)['worksite_city']==='CSV reviewed city'),'CSV revalidates committed source metadata after '.$decision);
        [$a,$b,$placement,$input]=$csvFixture('merge-first-'.$decision,$tenant);$review=companyMergePreview($tenant,$user,$a,$b);
        $pdo->beginTransaction();companyMergeApply($tenant,$user,$review['review_token'],$reviewChoices($review));
        $worker=$spawn($input);$check($waiting($worker),'CSV apply waits for recipient merge '.$decision);
        if($decision==='COMMIT')$pdo->commit();else$pdo->rollBack();$before=$projectionRows();$result=$finish($worker);
        $check($decision==='COMMIT'?(!$result['ok']&&$projectionRows()===$before):($result['ok']&&$read('placements',$placement)['worksite_city']==='CSV reviewed city'),'CSV never silently accepts changed recipients after '.$decision);
        [$a,$b,$placement,$input]=$csvFixture('concurrent-apply-'.$decision,$tenant);
        $worker=$spawn(array_replace($input,['mode'=>'csv_hold']));$saved=$line($workers[$worker]['pipes'][1]);$check(!empty($saved['saved'])&&!empty($saved['in_transaction']),'CSV apply holds its complete assignment and audit before '.$decision);
        $retry=$spawn($input);$check($waiting($retry),'Concurrent CSV apply waits for first request '.$decision);
        fwrite($workers[$worker]['pipes'][0],$decision.PHP_EOL);fflush($workers[$worker]['pipes'][0]);$written=$finish($worker);$again=$finish($retry);
        $q=$pdo->prepare('SELECT COUNT(*) FROM audit_log WHERE tenant_id=? AND event="placement.jobdiva_reconciliation.applied" AND target_id=?');$q->execute([$tenant,$placement]);
        $check($written['ok']&&($decision==='COMMIT'?!$again['ok']:$again['ok'])&&(int)$q->fetchColumn()===1&&$read('placements',$placement)['worksite_city']==='CSV reviewed city','Concurrent CSV requests commit exactly one reviewed change after '.$decision);
    }
    $repairFixture=static function(string $label,int $workspace)use($projectionFixture,$pdo,$actor):array{
        [$a,$b,$placement,$input]=$projectionFixture('repair-'.$label,$workspace);$start=$input['payload']['startId'];
        $q=$pdo->prepare('SELECT id FROM external_entity_mappings WHERE tenant_id=? AND source_system="jobdiva" AND internal_entity_type="placement" AND external_id=?');$q->execute([$workspace,$start]);$mapping=(int)$q->fetchColumn();
        $expected=JobdivaStoredReviewTransaction::run(static fn()=>jobdivaAssignmentRepairSnapshot($workspace,$mapping),true);
        $remote=array_replace($input['payload'],['title'=>'Verified concurrent source title']);
        $exact=['status'=>'verified','row'=>$remote,'identity'=>['channel'=>'searchStart:criteria_exact'],'seen_ids'=>[$start]];
        return[$placement,['mode'=>'native','entity'=>'assignment_repair','workspace'=>$workspace,'actor'=>$actor,'placement_id'=>$placement,'expected'=>$expected,'exact'=>$exact]];
    };
    foreach(['COMMIT','ROLLBACK']as$decision){
        foreach(['companies','placements','mapping','ownership','person']as$change){
            [$placement,$input]=$repairFixture($change.'-'.$decision,$child);
            $pdo->beginTransaction();
            if(in_array($change,['companies','placements'],true))$pdo->prepare('UPDATE tenant_module_scope SET scope_mode="isolated" WHERE tenant_id=? AND module_key=?')->execute([$child,$change]);
            if($change==='mapping')$pdo->prepare('UPDATE external_entity_mappings SET last_error="Concurrent source update" WHERE tenant_id=? AND id=?')->execute([$child,$input['expected']['mapping']['id']]);
            if($change==='ownership')$pdo->prepare('UPDATE placements SET coreflux_overridden_fields=\'["status"]\' WHERE tenant_id=? AND id=?')->execute([$tenant,$placement]);
            if($change==='person')$pdo->prepare('UPDATE people SET status="inactive" WHERE tenant_id=? AND id=?')->execute([$tenant,$input['expected']['person']['id']]);
            $worker=$spawn($input);$check($waiting($worker),'Source repair waits for concurrent '.$change.' '.$decision);
            if($decision==='COMMIT')$pdo->commit();else$pdo->rollBack();$before=$projectionRows();$result=$finish($worker);
            $check($decision==='COMMIT'?(!$result['ok']&&$projectionRows()===$before):($result['ok']&&$result['result']['mapping_changed']),'Source repair revalidates '.$change.' after '.$decision);
        }
        [$placement,$input]=$repairFixture('duplicate-'.$decision,$tenant);
        $worker=$spawn(array_replace($input,['mode'=>'assignment_repair_hold']));$saved=$line($workers[$worker]['pipes'][1]);$check(!empty($saved['saved'])&&!empty($saved['in_transaction']),'Source repair holds mapping and required audit before '.$decision);
        $retry=$spawn($input);$check($waiting($retry),'Concurrent source repair waits for first result '.$decision);
        fwrite($workers[$worker]['pipes'][0],$decision.PHP_EOL);fflush($workers[$worker]['pipes'][0]);$written=$finish($worker);$again=$finish($retry);
        $q=$pdo->prepare('SELECT COUNT(*) FROM audit_log WHERE tenant_id=? AND event="placement.assignment_source_repaired" AND target_id=?');$q->execute([$tenant,$placement]);
        $check($written['ok']&&($decision==='COMMIT'?!$again['ok']:$again['ok'])&&(int)$q->fetchColumn()===1,'Concurrent source repairs commit exactly one result after '.$decision);
    }
    $lifecycleFixture=static function(string $label,int $workspace)use($repairFixture,$pdo,$actor):array{
        [$placement,$input]=$repairFixture('lifecycle-'.$label,$workspace);$mapping=$input['expected']['mapping'];
        $payload=json_decode($mapping['payload_snapshot'],true,512,JSON_THROW_ON_ERROR);$payload['status']='Completed';
        mappingBindStable($workspace,'jobdiva','placement',$mapping['external_id'],$placement,$payload,'pull',$actor);
        $pdo->prepare('UPDATE placements SET status="active" WHERE tenant_id=? AND id=?')->execute([$input['expected']['context']['placement_owner'],$placement]);
        return[$placement,['mode'=>'native','entity'=>'lifecycle_repair','workspace'=>$workspace,'actor'=>$actor,'placement_id'=>$placement,'mapping_id'=>(int)$mapping['id']]];
    };
    foreach(['COMMIT','ROLLBACK']as$decision){
        foreach(['companies','placements','ownership','source']as$change){
            [$placement,$input]=$lifecycleFixture($change.'-'.$decision,$child);$pdo->beginTransaction();
            if(in_array($change,['companies','placements'],true))$pdo->prepare('UPDATE tenant_module_scope SET scope_mode="isolated" WHERE tenant_id=? AND module_key=?')->execute([$child,$change]);
            if($change==='ownership')$pdo->prepare('UPDATE placements SET coreflux_overridden_fields=\'["status"]\' WHERE tenant_id=? AND id=?')->execute([$tenant,$placement]);
            if($change==='source')$pdo->prepare('UPDATE external_entity_mappings SET sync_status="error" WHERE tenant_id=? AND id=?')->execute([$child,$input['mapping_id']]);
            $worker=$spawn($input);$check($waiting($worker),'Lifecycle repair waits for current '.$change.' '.$decision);
            if($decision==='COMMIT')$pdo->commit();else$pdo->rollBack();$before=$projectionRows();$result=$finish($worker);
            $blocked=$decision==='COMMIT';$expectedFailure=$blocked&&in_array($change,['companies','placements'],true);
            $check($expectedFailure?(!$result['ok']&&$projectionRows()===$before):($result['ok']&&($blocked?($result['result']['status']==='skipped'&&$projectionRows()===$before):($result['result']['status']==='updated'&&$read('placements',$placement)['status']==='ended'))),'Lifecycle repair respects committed '.$change.' and rolled-back evidence after '.$decision);
        }
        [$placement,$input]=$lifecycleFixture('duplicate-'.$decision,$tenant);
        $worker=$spawn(array_replace($input,['mode'=>'lifecycle_repair_hold']));$saved=$line($workers[$worker]['pipes'][1]);$check(!empty($saved['saved'])&&!empty($saved['in_transaction']),'Lifecycle repair holds status, graph and audit before '.$decision);
        $retry=$spawn($input);$check($waiting($retry),'Concurrent lifecycle repair waits for first status change '.$decision);
        fwrite($workers[$worker]['pipes'][0],$decision.PHP_EOL);fflush($workers[$worker]['pipes'][0]);$written=$finish($worker);$again=$finish($retry);
        $q=$pdo->prepare('SELECT COUNT(*) FROM audit_log WHERE tenant_id=? AND event="placement.status_repaired_from_jobdiva_lifecycle" AND target_id=?');$q->execute([$tenant,$placement]);
        $check($written['ok']&&$again['ok']&&$again['result']['status']===($decision==='COMMIT'?'skipped':'updated')&&(int)$q->fetchColumn()===1&&$read('placements',$placement)['status']==='ended','Concurrent lifecycle repairs commit exactly one transition after '.$decision);
    }
    $availabilityFixture=static function(string $label,int $workspace)use($projectionFixture,$pdo,$actor,$read):array{
        [$a,$b,$placement,$source]=$projectionFixture('availability-'.$label,$workspace);
        $row=$read('placements',$placement);$person=(int)$row['person_id'];
        $pdo->prepare('UPDATE placements SET status="ended" WHERE id=?')->execute([$placement]);
        $review=jobdivaPeopleLifecycleReview($workspace,$actor);
        if(!in_array($person,array_column($review['rows'],'id'),true))throw new RuntimeException('Concurrent availability fixture is not reviewable');
        $body=['confirm'=>'APPLY_REVIEWED_PEOPLE_AVAILABILITY','review_token'=>$review['review_token'],'request_id'=>bin2hex(random_bytes(4)).'-0000-4000-8000-'.bin2hex(random_bytes(6)),'reason'=>'Synthetic concurrent availability review','decisions'=>[$person=>'bench'],'limit'=>5000];
        return[$placement,['mode'=>'native','entity'=>'people_availability','workspace'=>$workspace,'actor'=>$actor,'person_id'=>$person,'body'=>$body]];
    };
    foreach(['COMMIT','ROLLBACK']as$decision){
        foreach(['people','placements','person','mapping','live_placement','new_placement']as$change){
            [$placement,$input]=$availabilityFixture($change.'-'.$decision,$child);$person=$input['person_id'];$pdo->beginTransaction();
            if(in_array($change,['people','placements'],true))$pdo->prepare('UPDATE tenant_module_scope SET scope_mode="isolated" WHERE tenant_id=? AND module_key=?')->execute([$child,$change]);
            if($change==='person')$pdo->prepare('UPDATE people SET status="inactive" WHERE id=?')->execute([$person]);
            if($change==='mapping')$pdo->prepare('UPDATE external_entity_mappings SET last_error="Concurrent availability evidence" WHERE tenant_id=? AND internal_entity_type="person" AND internal_entity_id=?')->execute([$child,$person]);
            if($change==='live_placement')$pdo->prepare('UPDATE placements SET status="active" WHERE id=?')->execute([$placement]);
            if($change==='new_placement')$pdo->prepare('INSERT INTO placements(tenant_id,person_id,title,status,engagement_type,start_date,worksite_country) VALUES(?,?,"New concurrent assignment","active","w2","2026-11-01","US")')->execute([$tenant,$person]);
            $worker=$spawn($input);$check($waiting($worker),'Availability review waits for concurrent '.$change.' '.$decision);
            if($decision==='COMMIT')$pdo->commit();else$pdo->rollBack();$before=$projectionRows();$result=$finish($worker);
            $check($decision==='COMMIT'?(!$result['ok']&&$projectionRows()===$before):($result['ok']&&$result['result']['benched']===1&&$read('people',$person)['status']==='bench'),'Availability revalidates '.$change.' after '.$decision);
        }
        [$placement,$input]=$availabilityFixture('duplicate-'.$decision,$tenant);
        $worker=$spawn(array_replace($input,['mode'=>'availability_hold']));$saved=$line($workers[$worker]['pipes'][1]);
        $check(!empty($saved['saved'])&&!empty($saved['in_transaction']),'Availability holds selected decision and receipt before '.$decision);
        $retry=$spawn($input);$check($waiting($retry),'Concurrent identical availability request waits for first receipt '.$decision);
        fwrite($workers[$worker]['pipes'][0],$decision.PHP_EOL);fflush($workers[$worker]['pipes'][0]);$written=$finish($worker);$again=$finish($retry);
        $q=$pdo->prepare('SELECT COUNT(*) FROM audit_log WHERE tenant_id=? AND event="jobdiva.people_availability_applied" AND JSON_UNQUOTE(JSON_EXTRACT(meta_json,"$.request_id"))=?');$q->execute([$tenant,$input['body']['request_id']]);
        $check($written['ok']&&$again['ok']&&$again['result']['replayed']===($decision==='COMMIT')&&(int)$q->fetchColumn()===1&&$read('people',$input['person_id'])['status']==='bench','Concurrent availability retry commits one decision and one receipt after '.$decision);
    }
    require __DIR__.'/fixtures/source_rate_concurrency_checks.php';
    require __DIR__.'/fixtures/joined_index_concurrency_checks.php';
    require __DIR__.'/fixtures/live_contract_concurrency_checks.php';
    require __DIR__.'/fixtures/alignment_report_concurrency_checks.php';
    require __DIR__.'/fixtures/active_restore_concurrency_checks.php';
    require __DIR__.'/fixtures/placement_identity_concurrency_checks.php';
    require __DIR__.'/fixtures/placement_merge_concurrency_checks.php';
    require __DIR__.'/fixtures/placement_merge_source_concurrency_checks.php';
    require __DIR__.'/fixtures/placement_terminal_census_concurrency_checks.php';
    require __DIR__.'/fixtures/review_contract_concurrency_checks.php';
    require __DIR__.'/fixtures/native_time_concurrency_checks.php';
    $snapshotGraph=static function()use($pdo,$tenant):array{$rows=[];foreach(['placements','staffing_clients','companies','external_entity_mappings','audit_log']as$table){$q=$pdo->prepare('SELECT * FROM '.$table.' WHERE tenant_id=? ORDER BY id');$q->execute([$tenant]);$rows[$table]=$q->fetchAll(PDO::FETCH_ASSOC);}return$rows;};
    $before=$snapshotGraph();$handle=proc_open([PHP_BINARY,dirname(__DIR__).'/scripts/repair_staffing_client_catalog.php',(string)$tenant],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));
    fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($handle);$report=json_decode($stdout,true,512,JSON_THROW_ON_ERROR);
    $check(in_array($exit,[0,1],true)&&$stderr===''&&$report['mode']==='read_only'&&$report['assignment_client_links']===null&&$snapshotGraph()===$before,'Actual catalog CLI defaults to reporting and leaves assignments, client lifecycle, companies, mappings and audit unchanged');
    echo json_encode(['passed'=>true,'checks'=>count($checks),'labels'=>$checks,'provider_calls'=>0,'financial_execution'=>false],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
}finally{
    if($pdo->inTransaction())$pdo->rollBack();
    foreach($workers as&$worker)if(!$worker['closed']){proc_terminate($worker['handle']);foreach($worker['pipes']as$pipe)if(is_resource($pipe))fclose($pipe);proc_close($worker['handle']);}unset($worker);
    foreach($createdTenants as$createdTenant){
        $pdo->prepare('DELETE ma FROM membership_module_access ma JOIN tenant_memberships tm ON tm.id=ma.membership_id WHERE tm.tenant_id=?')->execute([$createdTenant]);
        foreach(['tenant_memberships','jobdiva_connections','payroll_profiles','people_employment_links','people_employees']as$table)$pdo->prepare('DELETE FROM '.$table.' WHERE tenant_id=?')->execute([$createdTenant]);
        foreach(['quanta_time_imports','time_entries','staffing_timesheets','time_periods','artifact_events','artifact_relationships','artifact_objects']as$table)$pdo->prepare('DELETE FROM '.$table.' WHERE tenant_id=?')->execute([$createdTenant]);
    }
    foreach($createdTenants as$createdTenant)foreach(['placement_economic_parties','placement_rates','placement_client_chain','placement_corp_details','placement_referrals','placement_commissions','ap_vendors_index','placements','people','staffing_jobs','staffing_clients','staffing_operating_cycles','external_entity_mappings','entity_sync_history','companies','accounting_entities']as$table)$pdo->prepare('DELETE FROM '.$table.' WHERE tenant_id=?')->execute([$createdTenant]);
    foreach($createdTenants as$createdTenant)foreach(['core_record_numbers','audit_log','jobdiva_sync_audit','tenant_module_scope']as$table)$pdo->prepare('DELETE FROM '.$table.' WHERE tenant_id=?')->execute([$createdTenant]);
    if($actor!==null)$pdo->prepare('DELETE FROM users WHERE tenant_id=? AND id=?')->execute([$tenant,$actor]);
    foreach($createdTenants as$createdTenant)$pdo->prepare('UPDATE tenants SET is_active=0 WHERE id=?')->execute([$createdTenant]);
    foreach($files as$file)if(is_file($file))unlink($file);if(is_dir($directory))rmdir($directory);
}
