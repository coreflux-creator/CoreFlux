<?php
/** One-shot native onboarding journey for existing synthetic P-387 / PL-902. */
declare(strict_types=1);
const OB_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const OB_PRIVATE='/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030';
const OB_STATE=OB_PRIVATE.'/operational-onboarding-browser-20261112.json';
const OB_BEFORE=OB_PRIVATE.'/operational-onboarding-before-20261112.private.json';
const OB_CREDENTIAL=OB_PRIVATE.'/operational-onboarding-credential-20261112.private.json';
if(PHP_SAPI!=='cli'||getenv('COREFLUX_ENV')!=='staging'||getcwd()!==OB_ROOT||posix_geteuid()!==1003
 ||realpath(OB_PRIVATE)!==OB_PRIVATE||(fileperms(OB_PRIVATE)&0777)!==0700
 ||!in_array($argv[1]??'',['prepare','inspect','verify','finish','retire'],true))exit(2);
require_once OB_ROOT.'/core/db.php';
require_once OB_ROOT.'/core/tenant_scope.php';
require_once OB_ROOT.'/core/memberships.php';
require_once OB_ROOT.'/core/rbac/permissions.php';
require_once OB_ROOT.'/core/integrations/identity.php';
require_once OB_ROOT.'/modules/people/lib/onboarding.php';
require_once OB_ROOT.'/core/StorageService.php';
$pdo=getDB();
if(!COREFLUX_STAGING||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'
 ||integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf'||COREFLUX_INTEGRATION_DELIVERY_TENANTS!=='2002'
 ||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=2002')->fetchColumn()!==1)exit(2);
setRequestTenantId(2002);setRequestModuleScope('people');
$pins=json_decode(file_get_contents(__DIR__.'/onboarding-runtime-pins.json'),true,512,JSON_THROW_ON_ERROR);
foreach($pins as$file=>$hash)if(hash('sha256',str_replace("\r\n","\n",file_get_contents(OB_ROOT.'/'.$file)))!==$hash)throw new RuntimeException('Runtime changed: '.$file);
function obExpect(bool$yes,string$message):void{if(!$yes)throw new RuntimeException($message);}
function obSave(string$path,array$data,bool$new=false):void{
 if(is_link($path)||realpath(dirname($path))!==OB_PRIVATE)throw new RuntimeException('Invalid checkpoint');
 $old=umask(0077);try{$f=fopen($path,$new?'x':'w');if(!$f)throw new RuntimeException('Existing or unavailable checkpoint');
 $s=json_encode($data,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT);if(fwrite($f,$s)!==strlen($s)||!fflush($f)||!fsync($f))throw new RuntimeException('Incomplete checkpoint');fclose($f);}finally{umask($old);}
}
function obSnapshot():array{
 global$pdo;$out=[];foreach($pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN)as$table){
  obExpect((bool)preg_match('/^[A-Za-z0-9_]+$/D',$table),'Invalid table');$rows=[];
  foreach($pdo->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC)as$row)$rows[]=hash('sha256',serialize($row));
  sort($rows,SORT_STRING);$out[$table]=$rows;
 }ksort($out);return$out;
}
function obCases():array{global$pdo;return$pdo->query('SELECT id,version FROM staffing_onboarding_cases WHERE tenant_id=2002 AND person_id=387 AND placement_id=902')->fetchAll(PDO::FETCH_ASSOC);}
$action=$argv[1];
if($action==='prepare'){
 obExpect(!file_exists(OB_STATE)&&!file_exists(OB_BEFORE)&&!file_exists(OB_CREDENTIAL),'Inspect existing checkpoint; never repeat preparation');
 $p=$pdo->query('SELECT * FROM placements WHERE tenant_id=2002 AND id=902')->fetch(PDO::FETCH_ASSOC);
 obExpect($p&&(int)$p['person_id']===387&&$p['status']==='active'&&$p['workers_comp_class']==='SYNTHETIC TEST ONLY','Unexpected placement');
 $t=$pdo->query('SELECT * FROM time_entries WHERE tenant_id=2002 AND id=17')->fetch(PDO::FETCH_ASSOC);
 obExpect($t&&$t['status']==='approved'&&(float)$t['hours']===2.0&&(int)$t['placement_id']===902&&(int)$t['rate_snapshot_id']===23,'Unexpected approved time');
 obExpect(obCases()===[],'Onboarding already exists; inspect before further work');
 obSave(OB_BEFORE,['tables'=>obSnapshot()],true);
 $report=['started_at'=>gmdate('c'),'phase'=>'preparing','passed'=>false,'actors'=>[],
  'feature_sha256'=>hash_file('sha256',dirname(OB_ROOT).'/private_html/coreflux.thunderhawk-pilot.php'),
  'tenant_id'=>2002,'person_number'=>'P-387','placement_number'=>'PL-902','production_changed'=>false,'arabella_changed'=>false,'financial_execution'=>false];
 obSave(OB_STATE,$report,true);$credentials=[];
 foreach(['operator'=>['people'=>'admin','placements'=>'admin','staffing'=>'read','time'=>'read','integrations'=>'admin'],
  'reviewer'=>['people'=>'admin','placements'=>'read','staffing'=>'read']]as$role=>$grants){
  $email='core-onboarding-browser-'.$role.'-20261112@example.invalid';
  $s=$pdo->prepare('SELECT COUNT(*) FROM users WHERE email=?');$s->execute([$email]);obExpect(!(int)$s->fetchColumn(),'Reserved actor exists');
  $password=bin2hex(random_bytes(32));$hash=password_hash($password,PASSWORD_DEFAULT);
  $pdo->beginTransaction();try{
   $pdo->prepare('INSERT INTO users(tenant_id,name,email,password,password_hash,role,is_active,is_global_admin) VALUES(2002,?,?,?, ?,"admin",1,0)')->execute(['Synthetic onboarding '.$role,$email,$hash,$hash]);$id=(int)$pdo->lastInsertId();
   $membership=(int)provisionMembership($id,2002,'admin',['is_primary'=>true,'persona_label'=>'Synthetic onboarding '.$role,'status'=>'active'])['membership_id'];$pdo->commit();
  }catch(Throwable$e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}
  $report['actors'][$role]=['id'=>$id,'membership_id'=>$membership,'email'=>$email,'grants'=>$grants];obSave(OB_STATE,$report);
  foreach($grants as$module=>$level)RBACResolver::grantModule($membership,$module,$level,[2002],4);
  foreach(['payroll','billing','ap','accounting','treasury']as$module)obExpect(!RBACResolver::can($id,2002,$module,'read'),'Unexpected financial access');
  $credentials[$role]=['username'=>$email,'password'=>$password];unset($password,$hash);
 }obSave(OB_CREDENTIAL,$credentials,true);unset($credentials);$report['phase']='browser_ready';obSave(OB_STATE,$report);
 echo json_encode(['phase'=>$report['phase'],'actors'=>$report['actors'],'runtime_pins_verified'=>count($pins),'credential_printed'=>false]);exit;
}
obExpect(is_file(OB_STATE)&&is_file(OB_BEFORE),'No browser checkpoint');
$report=json_decode(file_get_contents(OB_STATE),true,512,JSON_THROW_ON_ERROR);$before=json_decode(file_get_contents(OB_BEFORE),true,512,JSON_THROW_ON_ERROR);
$cases=obCases();$view=null;
if(count($cases)===1){
 $scope=['tenant_id'=>2002,'people_tenant_id'=>2002,'placements_tenant_id'=>2002,'actor_user_id'=>$report['actors']['reviewer']['id'],'permissions'=>['view'=>true]];
 $storage=null;$head=static function(string$key)use(&$storage){$storage??=new \Core\StorageService();return$storage->head($key);};
 $view=onboardingView($pdo,$scope,$cases[0]['id'],$head);
}
if($action==='inspect'){echo json_encode(['phase'=>$report['phase'],'actors'=>$report['actors'],'case'=>$view],JSON_THROW_ON_ERROR);exit;}
if($action==='verify'){
 require_once OB_ROOT.'/core/quanta/onboarding_delivery.php';
 obExpect($view&&$view['readiness']==='complete'&&$view['required_count']===2,'Complete native case required');
 $start=obSnapshot();$storage=new \Core\StorageService();$head=static fn(string$key)=>$storage->head($key);
 $scope=['tenant_id'=>2002,'people_tenant_id'=>2002,'placements_tenant_id'=>2002,'actor_user_id'=>$report['actors']['operator']['id'],
  'permissions'=>['view'=>true,'manage'=>true,'documents_manage'=>true]];
 $snapshot=quantaOnboardingSnapshot($scope,$view['id'],$head);$checks=[];
 foreach($view['tasks']as$task){
  $e=$task['evidence'];$result=$task['evidence_kind']==='document'
   ?quantaOnboardingImportDocument($scope,$view['id'],$e['provider_receipt_id'],$e['publication_delivery_id'],4,$storage)
   :quantaOnboardingImport($scope,$view['id'],$e['provider_receipt_id'],$e['publication_delivery_id'],4,$head);
  obExpect(($result['replayed']??false)===true,'Exact receipt did not replay');$checks[]=$task['evidence_kind'].'_exact_receipt_replayed';
  $rows=array_values(array_filter($snapshot['remote']['submissions'],static fn($r)=>$r['id']===$e['provider_receipt_id']));obExpect(count($rows)===1,'Original receipt missing');
  $changed=$rows[0];$changed['submitted_at']='2026-11-01T00:00:00Z';$refused=false;
  try{quantaOnboardingImportVerified($pdo,$scope,$snapshot,$changed,$e['publication_delivery_id'],8,$head);}
  catch(OnboardingConflict$expected){$refused=true;}
  obExpect($refused,'Changed receipt was accepted');$checks[]=$task['evidence_kind'].'_changed_receipt_refused';
 }
 $foreign=$scope;$foreign['tenant_id']=2013;$foreign['people_tenant_id']=2013;$foreign['placements_tenant_id']=2013;$refused=false;
 try{onboardingView($pdo,$foreign,$view['id'],$head);}catch(OnboardingConflict$expected){$refused=true;}
 obExpect($refused,'Foreign company read was accepted');$checks[]='foreign_company_case_refused';
 $end=onboardingView($pdo,$scope,$view['id'],$head);$initialView=$view;unset($end['observed_at'],$initialView['observed_at']);
 obExpect($end===$initialView,'Replay or refusal changed the case');obExpect(obSnapshot()===$start,'Replay or refusal changed saved rows');
 $result=['passed'=>true,'checks'=>$checks,'case_version'=>$view['version'],'rows_preserved'=>array_sum(array_map('count',$start)),'service_boundary_checks'=>true,'financial_execution'=>false];
 obSave(OB_PRIVATE.'/operational-onboarding-replay-20261112.json',$result);echo json_encode($result,JSON_THROW_ON_ERROR);exit;
}
obExpect(!in_array($report['phase'],['complete','retired'],true),'Checkpoint already terminal');
if($action==='finish'){
 obExpect($view&&$view['readiness']==='complete'&&$view['required_count']===2,'Both native requirements must be complete');
 foreach($view['tasks']as$task)obExpect($task['state']==='approved'&&$task['evidence']['source']==='quanta_worker'
  &&(int)$task['review']['actor_user_id']===$report['actors']['reviewer']['id'],'Independent native review of worker evidence required');
 $report['case_id']=$view['id'];$report['case_version']=$view['version'];$report['tasks']=array_map(static fn($t)=>['id'=>$t['id'],'kind'=>$t['evidence_kind'],'state'=>$t['state'],'evidence'=>$t['evidence'],'review'=>$t['review']],$view['tasks']);
}
foreach($report['actors']as$actor){
 foreach($actor['grants']as$module=>$level)RBACResolver::revokeModule($actor['membership_id'],$module,4);
 deactivateMembership($actor['id'],2002);
 $pdo->prepare('UPDATE users SET is_active=0,password=NULL,password_hash=NULL WHERE tenant_id=2002 AND id=? AND email=?')->execute([$actor['id'],$actor['email']]);
 $s=$pdo->prepare('SELECT COUNT(*) FROM membership_module_access WHERE membership_id=?');$s->execute([$actor['membership_id']]);obExpect(!(int)$s->fetchColumn(),'Actor grants remain');
}
if(is_file(OB_CREDENTIAL))obExpect(!is_link(OB_CREDENTIAL)&&unlink(OB_CREDENTIAL),'Credential removal failed');
$report['actors_retired']=true;obSave(OB_STATE,$report);
$after=obSnapshot();obExpect(array_keys($before['tables'])===array_keys($after),'Schema changed');$changed=[];
$allowed=['staffing_onboarding_cases','staffing_onboarding_events','quanta_onboarding_imports','quanta_onboarding_document_transfers','people_documents','storage_objects','integration_deliveries','integration_delivery_subjects','users','tenant_memberships','user_tenants','membership_audit','audit_log','rbac_bridge_audit'];
foreach($before['tables']as$table=>$hashes){if($hashes===$after[$table])continue;obExpect(in_array($table,$allowed,true),'Unexpected table change: '.$table);
 $counts=array_count_values($after[$table]);foreach(array_count_values($hashes)as$hash=>$n)obExpect(($counts[$hash]??0)>=$n,'Original record changed: '.$table);
 $changed[$table]=count($after[$table])-count($hashes);
}
obExpect(hash_file('sha256',dirname(OB_ROOT).'/private_html/coreflux.thunderhawk-pilot.php')===$report['feature_sha256'],'Operational switches changed');
$report['tables_verified']=count($after);$report['original_rows_checked']=array_sum(array_map('count',$before['tables']));$report['added_rows']=$changed;
$report['finished_at']=gmdate('c');$report['passed']=$action==='finish';$report['phase']=$action==='finish'?'complete':'retired';obSave(OB_STATE,$report);echo json_encode($report,JSON_THROW_ON_ERROR);
