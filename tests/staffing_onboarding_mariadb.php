<?php
/** Real transaction, ownership, evidence invalidation and replay checks; disposable database only. */
declare(strict_types=1);
$ci=getenv('GITHUB_ACTIONS')==='true'&&getenv('CI')==='true';
$local=getenv('COREFLUX_DELIVERY_LOCAL_FIXTURE')==='1'&&in_array('--confirm-disposable-fixture',$argv,true)&&(string)getenv('COREFLUX_DELIVERY_TEST_PASSWORD')!=='';
if(!$ci&&!$local)throw new RuntimeException('Explicit disposable fixture configuration required');
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../modules/people/lib/onboarding.php';
require_once __DIR__.'/../core/migrate.php';
require_once __DIR__.'/../core/rbac/legacy_map.php';
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false];
$password=$ci?'root':getenv('COREFLUX_DELIVERY_TEST_PASSWORD');
if(($argv[1]??'')==='--retry-child'){
    if(!$ci||($argv[2]??'')!=='coreflux_onboard_ci')throw new RuntimeException('Exact disposable child database required');
    $child=new PDO('mysql:host=127.0.0.1;port=3306;dbname=coreflux_onboard_ci;charset=utf8mb4','root',$password,$options);
    $scope=json_decode(base64_decode($argv[3],true),true,32,JSON_THROW_ON_ERROR);
    $body=json_decode(base64_decode($argv[4],true),true,32,JSON_THROW_ON_ERROR);
    echo onboardingJson(onboardingCommand($child,$scope,$body,static fn($key)=>null));exit;
}
$pdo=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root',$password,$options);
$db='coreflux_onboard_'.($ci?'ci':gmdate('YmdHis').'_'.getmypid());$pdo->exec('CREATE DATABASE '.$db);$pdo->exec('USE '.$db);
$observer=new PDO('mysql:host=127.0.0.1;port=3306;dbname='.$db.';charset=utf8mb4','root',$password,$options);
$passed=0;
$check=static function(bool $ok,string $label)use(&$passed){if(!$ok)throw new RuntimeException($label);$passed++;echo "PASS $label\n";};
$fails=static function(callable $call,string $label)use($check){try{$call();}catch(InvalidArgumentException|OnboardingConflict $e){$check(true,$label);return;}throw new RuntimeException('Did not reject: '.$label);};
try{
    foreach([1,2]as$pass)foreach(coreflux_split_sql_statements(file_get_contents(__DIR__.'/../core/migrations/171_staffing_onboarding.sql'))as$sql)$pdo->exec($sql);
    $check(true,'migration replays on populated-compatible schema');
    $pdo->exec('CREATE TABLE people(id BIGINT PRIMARY KEY,tenant_id BIGINT,status VARCHAR(30),deleted_at DATETIME NULL)');
    $pdo->exec('CREATE TABLE placements(id BIGINT PRIMARY KEY,tenant_id BIGINT,person_id BIGINT,status VARCHAR(30),engagement_type VARCHAR(20),start_date DATE,end_date DATE,accounting_entity_id BIGINT,branch VARCHAR(50),workers_comp_class VARCHAR(30),worksite_state VARCHAR(20),worksite_country VARCHAR(10),deleted_at DATETIME NULL)');
    $pdo->exec('CREATE TABLE core_record_numbers(tenant_id BIGINT,entity_type VARCHAR(30),record_id BIGINT,record_number VARCHAR(64))');
    $pdo->exec('CREATE TABLE people_documents(id BIGINT PRIMARY KEY,tenant_id BIGINT,person_id BIGINT,doc_type VARCHAR(30),storage_object_id BIGINT,signed INT,signed_at DATETIME NULL,expires_at DATE NULL,deleted_at DATETIME NULL)');
    $pdo->exec('CREATE TABLE storage_objects(id BIGINT PRIMARY KEY,tenant_id BIGINT,s3_key VARCHAR(200),module VARCHAR(30),entity_type VARCHAR(30),entity_id VARCHAR(30),soft_deleted_at DATETIME NULL)');
    $pdo->exec("INSERT INTO people VALUES(1,10,'active',NULL),(2,10,'active',NULL),(3,20,'active',NULL)");
    $pdo->exec("INSERT INTO placements VALUES(1,11,1,'active','w2','2026-01-01','2027-01-01',8,'East','8810','NJ','US',NULL),(2,11,2,'active','w2','2026-01-01',NULL,8,'East','8810','NJ','US',NULL),(3,21,3,'active','w2','2026-01-01',NULL,8,'East','8810','NJ','US',NULL)");
    $pdo->exec("INSERT INTO core_record_numbers VALUES(10,'person',1,'P-1'),(10,'person',2,'P-2'),(20,'person',3,'P-3'),(11,'placement',1,'PL-1'),(11,'placement',2,'PL-2'),(21,'placement',3,'PL-3')");
    $pdo->exec("INSERT INTO storage_objects VALUES(1,10,'people/10/document/1/example.pdf','people','document','1',NULL),(2,10,'people/10/document/2/other.pdf','people','document','2',NULL),(3,20,'people/20/document/3/foreign.pdf','people','document','3',NULL)");
    $pdo->exec("INSERT INTO people_documents VALUES(1,10,1,'offer',1,1,NULL,NULL,NULL),(2,10,2,'offer',2,1,NULL,NULL,NULL),(3,20,3,'offer',3,1,NULL,NULL,NULL),(4,10,1,'offer',2,1,NULL,NULL,NULL)");
    $scope=['tenant_id'=>1,'people_tenant_id'=>10,'placements_tenant_id'=>11,'actor_user_id'=>90,'permissions'=>['view'=>true,'manage'=>true,'review'=>true]];
    $blob=['etag'=>'v1','size_bytes'=>50,'version_id'=>'version-1','last_modified'=>'2026-10-22T00:00:00Z'];$duringHead=null;
    $head=static function($key)use(&$blob,&$duringHead){if($duringHead){$f=$duringHead;$duringHead=null;$f();}return $blob;};
    $create=['action'=>'create','request_id'=>onboardingUuid(),'person_id'=>1,'placement_id'=>1];
    $receipt=onboardingCommand($pdo,$scope,$create,$head);$id=$receipt['case_id'];
    $view=static fn()=>onboardingView($pdo,$scope,$id,$head);
    $command=static function(string $action,array $fields=[])use($pdo,$scope,$id,$head){$case=onboardingCase($pdo,$scope,$id);return onboardingCommand($pdo,$scope,['action'=>$action,'request_id'=>onboardingUuid(),'case_id'=>$id,'expected_version'=>$case['version']]+$fields,$head);};
    $check($view()['readiness']==='requirements_unconfirmed','empty case cannot be complete');
    $check($view()['person_number']==='P-1'&&$view()['placement_number']==='PL-1','canonical numbers from separate authorized directories');
    $check(onboardingCommand($pdo,$scope,$create,$head)['replayed']===true,'response loss replays same creation once');
    $check((int)$pdo->query('SELECT COUNT(*) FROM staffing_onboarding_events')->fetchColumn()===1,'creation replay has one event');
    $fails(fn()=>onboardingCommand($pdo,$scope,array_replace($create,['person_id'=>2]),$head),'reusing request ID with changed payload rejected');
    $fails(fn()=>onboardingCommand($pdo,array_replace($scope,['actor_user_id'=>91]),$create,$head),'another actor cannot replay the same request');
    $fails(fn()=>$command('confirm_plan'),'empty requirement list cannot be confirmed');
    $fails(fn()=>onboardingCommand($pdo,$scope,array_replace($create,['request_id'=>onboardingUuid(),'person_id'=>2]),$head),'placement-person mismatch rejected');
    $fails(fn()=>onboardingCommand($pdo,$scope,array_replace($create,['request_id'=>onboardingUuid()]),$head),'duplicate case for a placement rejected');
    $fails(fn()=>onboardingCase($pdo,array_replace($scope,['tenant_id'=>2]),$id),'another operating tenant cannot read shared-directory case');
    $fails(fn()=>onboardingCase($pdo,array_replace($scope,['people_tenant_id'=>20]),$id),'changed People directory cannot read old case');
    $fails(fn()=>onboardingCase($pdo,array_replace($scope,['placements_tenant_id'=>21]),$id),'changed Placement directory cannot read old case');
    $denied=$scope;$denied['permissions']['manage']=false;
    try{onboardingCommand($pdo,$denied,array_replace($create,['request_id'=>onboardingUuid()]),$head);throw new LogicException('manage permission missing');}catch(RuntimeException $e){$check(!$e instanceof LogicException,'mutation requires explicit management authority');}
    foreach(['view','manage','review']as$p)$check(RbacLegacyMap::resolve('people.onboarding.'.$p)===['people','admin'],'RBAC maps '.$p.' to admin authority');
    $definition=['title'=>'Review offer','instructions'=>'Check the signed offer for this placement.','assignee'=>'worker','evidence_kind'=>'document','required'=>true];
    $add=$command('add_task',$definition);$task=$add['task_id'];
    $check($view()['tasks'][0]['state']==='requested','new task requests evidence without approval');
    $check($view()['required_count']===1,'required task counts toward readiness');
    $command('confirm_plan');$check($view()['readiness']==='incomplete','confirming list does not approve evidence');
    $fails(fn()=>$command('review',['task_id'=>$task,'decision'=>'approve','reason'=>'Reviewed']),'cannot approve unsubmitted evidence');
    $submit=['task_id'=>$task,'acknowledged'=>true,'document_id'=>1];
    $fails(fn()=>$command('submit',array_replace($submit,['document_id'=>2])),'same-tenant other-person document rejected');
    $fails(fn()=>$command('submit',array_replace($submit,['document_id'=>3])),'cross-tenant document rejected');
    $fails(fn()=>$command('submit',array_replace($submit,['document_id'=>4])),'mislinked storage object from another person rejected');
    $before=$view()['version'];$duringHead=static function()use($observer,$id,$before,$check){$st=$observer->prepare('SELECT version FROM staffing_onboarding_cases WHERE tenant_id=1 AND id=?');$st->execute([$id]);$check((int)$st->fetchColumn()===$before,'independent reader sees no uncommitted evidence revision');};
    $command('submit',$submit);$v=$view();
    $check($v['tasks'][0]['state']==='submitted'&&$v['readiness']==='incomplete','submission waits for employer review');
    $check($v['tasks'][0]['evidence']['source']==='core_staff','staff submission never impersonates worker');
    $check(!str_contains(onboardingJson($v),'example.pdf')&&!str_contains(onboardingJson($v),'s3_key'),'case and audit responses exclude storage keys and URLs');
    $stale=['action'=>'review','request_id'=>onboardingUuid(),'case_id'=>$id,'expected_version'=>$v['version']-1,'task_id'=>$task,'decision'=>'approve','reason'=>'Reviewed'];
    $fails(fn()=>onboardingCommand($pdo,$scope,$stale,$head),'stale conditional review rejected');
    $blob['etag']='v2';$fails(fn()=>$command('review',['task_id'=>$task,'decision'=>'approve','reason'=>'Reviewed']),'object overwrite after submission prevents approval');
    $command('submit',$submit);$review=$command('review',['task_id'=>$task,'decision'=>'approve','reason'=>'Document reviewed for placement','expires_at'=>gmdate('Y-m-d',time()+86400)]);
    $check($view()['readiness']==='complete','confirmed list with current reviewed evidence becomes complete');
    $check($view()['payroll_authorization']==='not_evaluated'&&$view()['portal_delivery']==='not_checked','completed tasks grant no payroll or portal authority');
    $blob['version_id']='version-2';$check($view()['readiness']==='incomplete'&&$view()['tasks'][0]['effective_state']==='needs_review','changed object version invalidates displayed approval');
    $blob['version_id']='version-1';$blob=null;$check($view()['tasks'][0]['effective_state']==='evidence_unavailable','storage outage fails closed');
    $blob=['etag'=>'v2','size_bytes'=>50,'version_id'=>'version-1','last_modified'=>'2026-10-22T00:00:00Z'];
    $pdo->exec("UPDATE people_documents SET expires_at=DATE_SUB(UTC_DATE(), INTERVAL 1 DAY) WHERE id=1");
    $check($view()['tasks'][0]['effective_state']==='expired'&&$view()['readiness']==='incomplete','document expiry invalidates approved evidence');
    $fails(fn()=>$command('submit',$submit),'expired document cannot be resubmitted as current');
    $pdo->exec('UPDATE people_documents SET expires_at=NULL WHERE id=1');
    $pdo->exec("UPDATE placements SET accounting_entity_id=9 WHERE id=1");
    $check($view()['readiness']==='requirements_unconfirmed'&&$view()['tasks'][0]['effective_state']==='needs_review','changed legal entity invalidates both plan and evidence review');
    $command('confirm_plan');$check($view()['readiness']==='incomplete','reconfirming changed placement does not carry old approval');
    $command('submit',$submit);$command('review',['task_id'=>$task,'decision'=>'reject','reason'=>'Correction required']);
    $check($view()['tasks'][0]['state']==='rejected','rejection preserves evidence and records responsible reviewer');
    $command('submit',$submit);$command('review',['task_id'=>$task,'decision'=>'waive','reason'=>'Employer exception recorded']);
    $check($view()['readiness']==='complete'&&$view()['tasks'][0]['review']['decision']==='waive','explicit waiver is visible and can satisfy requirement');
    $fails(fn()=>$command('review',['task_id'=>$task,'decision'=>'waive','reason'=>'']),'waiver requires a reason');
    $definition['title']='Updated offer requirement';$command('revise_task',['task_id'=>$task]+$definition);
    $v=$view();$check($v['readiness']==='requirements_unconfirmed'&&$v['tasks'][0]['evidence']===null&&$v['tasks'][0]['review']===null,'revised requirement clears evidence and confirmation');
    $command('retire_task',['task_id'=>$task,'reason'=>'Replaced by new requirement']);
    $check($view()['required_count']===0&&$view()['readiness']==='requirements_unconfirmed','retiring final requirement cannot produce an empty completed case');
    $fails(fn()=>$command('submit',$submit),'retired task cannot be reopened or submitted');
    $attestation=$command('add_task',['title'=>'Orientation','instructions'=>'Confirm orientation was delivered.','assignee'=>'staff','evidence_kind'=>'attestation','required'=>true]);$task2=$attestation['task_id'];
    $command('submit',['task_id'=>$task2,'acknowledged'=>true]);$command('review',['task_id'=>$task2,'decision'=>'approve','reason'=>'Orientation record checked']);
    $command('confirm_plan');$check($view()['readiness']==='complete','attestation receives separate submission and review');
    $v=$view();$reopen=['action'=>'reopen','request_id'=>onboardingUuid(),'case_id'=>$id,'expected_version'=>$v['version'],'task_id'=>$task2,'reason'=>'New evidence requested'];
    $r=onboardingCommand($pdo,$scope,$reopen,$head);$r2=onboardingCommand($pdo,$scope,$reopen,$head);
    $check($r2['replayed']&&$r2['case_version']===$r['case_version'],'replaying a mutation returns the same durable receipt');
    $fails(fn()=>$command('reopen',['task_id'=>onboardingUuid(),'reason'=>'Other task']),'foreign task identifier rejected');
    $fails(fn()=>$command('add_task',array_replace($definition,['required'=>1])),'truthy integers cannot replace strict required flag');
    $fails(fn()=>$command('add_task',$definition+['payroll_approved'=>true]),'unowned extra fields rejected');
    $fails(fn()=>$command('submit',['task_id'=>$task2,'acknowledged'=>true,'document_id'=>1]),'attestation cannot smuggle document evidence');
    $before=$view()['version'];$events=(int)$pdo->query('SELECT COUNT(*) FROM staffing_onboarding_events')->fetchColumn();
    $pdo->exec("CREATE TRIGGER fixture_reject_event BEFORE INSERT ON staffing_onboarding_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic audit failure'");
    try{$command('submit',['task_id'=>$task2,'acknowledged'=>true]);throw new LogicException('audit failure ignored');}catch(PDOException $e){$check(true,'audit persistence failure is surfaced');}
    $check($view()['version']===$before&&(int)$pdo->query('SELECT COUNT(*) FROM staffing_onboarding_events')->fetchColumn()===$events,'audit failure rolls back the state revision too');
    $pdo->exec('DROP TRIGGER fixture_reject_event');
    $pdo->exec('UPDATE placements SET person_id=2 WHERE id=1');$fails($view,'reassigned placement cannot reveal former person case');$pdo->exec('UPDATE placements SET person_id=1 WHERE id=1');
    $pdo->exec('UPDATE people SET deleted_at=UTC_TIMESTAMP() WHERE id=1');$fails($view,'deleted person cannot retain accessible onboarding');$pdo->exec('UPDATE people SET deleted_at=NULL WHERE id=1');
    if($ci){
        $before=$view()['version'];$concurrent=['action'=>'reopen','request_id'=>onboardingUuid(),'case_id'=>$id,'expected_version'=>$before,'task_id'=>$task2,'reason'=>'Concurrent retry fixture'];
        $children=[];$observer->beginTransaction();$lock=$observer->prepare('SELECT id FROM staffing_onboarding_cases WHERE tenant_id=1 AND id=? FOR UPDATE');$lock->execute([$id]);$lock->fetchAll();
        try{
            foreach([1,2]as$i){$pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'--retry-child',$db,base64_encode(onboardingJson($scope)),base64_encode(onboardingJson($concurrent))],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('Could not start concurrency fixture');fclose($pipes[0]);$children[]=[$process,$pipes];}
            $waiting=0;$deadline=microtime(true)+8;
            do{$waiting=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.innodb_lock_waits w JOIN information_schema.innodb_trx t ON t.trx_id=w.requesting_trx_id WHERE t.trx_query LIKE 'SELECT * FROM staffing_onboarding_cases%'")->fetchColumn();if($waiting>=2)break;usleep(100000);}while(microtime(true)<$deadline);
            $check($waiting>=2,'two independent requests reach the conditional row lock concurrently');
            $observer->commit();$results=[];
            foreach($children as[$process,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);if($exit!==0)throw new RuntimeException('Concurrent request failed: '.$err);$results[]=json_decode($out,true,32,JSON_THROW_ON_ERROR);}
            $children=[];
            $check($results[0]['case_version']===$before+1&&$results[1]['case_version']===$before+1&&$results[0]['replayed']!==$results[1]['replayed'],'concurrent retry returns the committed receipt instead of a stale-version conflict');
            $check($view()['version']===$before+1,'concurrent retry creates exactly one state revision');
        }finally{if($observer->inTransaction())$observer->rollBack();foreach($children as[$process,$pipes]){if(is_resource($process))proc_terminate($process);foreach([1,2]as$i)if(is_resource($pipes[$i]))fclose($pipes[$i]);if(is_resource($process))proc_close($process);}}
    }
    $v=$view();$check(count($v['history'])===$v['version'],'every committed revision has a durable history event');
    $check((int)$pdo->query("SELECT COUNT(*) FROM people WHERE status='active'")->fetchColumn()===3,'workflow does not activate or alter people');
    $check((int)$pdo->query('SELECT COUNT(*) FROM storage_objects')->fetchColumn()===3,'workflow does not copy or upload documents');
    echo "PASS {$passed} onboarding checks\n";
    require __DIR__.'/fixtures/quanta_onboarding_checks.php';
}finally{$pdo->exec('DROP DATABASE '.$db);}
