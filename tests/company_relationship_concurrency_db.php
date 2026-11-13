<?php
/** Independent native MariaDB sessions; only disposable CI fixtures, never provider calls. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('GITHUB_ACTIONS')!=='true'||getenv('CI')!=='true'||getenv('DB_NAME')!=='coreaccounting_ci'||!function_exists('proc_open'))exit(2);
if(($argv[1]??'')!=='worker'){putenv('COREFLUX_DATA_KEY='.base64_encode(random_bytes(32)));putenv('COREFLUX_INTEGRATION_INSTANCE_ID=e443cc91-9552-41a1-8876-c14d044c5591');}
require_once __DIR__.'/../modules/people/lib/company_merge_review.php';
require_once __DIR__.'/../modules/staffing/lib/job_write.php';
require_once __DIR__.'/../modules/staffing/lib/client_write.php';
require_once __DIR__.'/../core/jobdiva/sync.php';
require_once __DIR__.'/../core/qbo/sync_in.php';
$pdo=getDB();if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='coreaccounting_ci')exit(2);

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
        else{
            if($input['mode']==='hold')$pdo->beginTransaction();
            $result=match($input['entity']??'job'){
                'client'=>staffingClientSaveNative($input['workspace'],$input['client_id'],['primary_contact_phone'=>'Concurrent client change'],$input['actor'],$input['edit_revision']),
                'client_csv'=>['id'=>staffingClientImportNative($input['workspace'],$input['row'],$input['actor'],true)],
                'client_source'=>clientSourceRaceWrite($input),
                default=>staffingJobSaveNative($input['workspace'],null,['title'=>$input['title'],'company_id'=>$input['company_id']],$input['actor']),
            };
            if($input['mode']==='hold'){
                echo json_encode(['saved'=>true,'id'=>$result['id'],'in_transaction'=>$pdo->inTransaction()]).PHP_EOL;flush();
                stream_set_timeout(STDIN,15);$decision=trim((string)fgets(STDIN));
                if($decision==='COMMIT')$pdo->commit();else$pdo->rollBack();
            }
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
    echo json_encode(['passed'=>true,'checks'=>count($checks),'labels'=>$checks,'provider_calls'=>0,'financial_execution'=>false],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
}finally{
    if($pdo->inTransaction())$pdo->rollBack();
    foreach($workers as&$worker)if(!$worker['closed']){proc_terminate($worker['handle']);foreach($worker['pipes']as$pipe)if(is_resource($pipe))fclose($pipe);proc_close($worker['handle']);}unset($worker);
    foreach($createdTenants as$createdTenant)foreach(['staffing_jobs','staffing_clients','external_entity_mappings','entity_sync_history']as$table)$pdo->prepare('DELETE FROM '.$table.' WHERE tenant_id=?')->execute([$createdTenant]);
    foreach($companies as$id)$pdo->prepare('DELETE FROM companies WHERE tenant_id=? AND id=?')->execute([$tenant,$id]);
    foreach($createdTenants as$createdTenant)foreach(['core_record_numbers','audit_log','tenant_module_scope']as$table)$pdo->prepare('DELETE FROM '.$table.' WHERE tenant_id=?')->execute([$createdTenant]);
    if($actor!==null)$pdo->prepare('DELETE FROM users WHERE tenant_id=? AND id=?')->execute([$tenant,$actor]);
    foreach($createdTenants as$createdTenant)$pdo->prepare('UPDATE tenants SET is_active=0 WHERE id=?')->execute([$createdTenant]);
    foreach($files as$file)if(is_file($file))unlink($file);if(is_dir($directory))rmdir($directory);
}
