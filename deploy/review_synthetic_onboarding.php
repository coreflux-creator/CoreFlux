<?php
/** Review only the accepted synthetic worker evidence and return the decisions to Acme. */
declare(strict_types=1);
const REVIEW_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const REVIEW_CASE='480d78b8-8de0-4e80-a5dd-0c7fe98ec42c';
const REVIEW_INSTANCE='9f4b6dfb-e52e-464c-9a9d-af7766928ddf';
const REVIEW_WORKSPACE='0598d4ef-3075-4289-9714-c93f904aa85d';
const REVIEW_WORKER='cfw_2c5e38cf6059e5d00f16b534feb7e2006c697e0e82b5b009cabcfa86eef0892c';
const REVIEW_DOCUMENT='34002a90-6c88-4df8-a266-0f38885256a4';
const REVIEW_ACK='0998a264-6b69-4fab-a41e-59329bc547e7';
const REVIEW_STAFF='95d87325-0294-463f-b586-25186a8b6ff2';
$execute=($argv[1]??'')==='--review-demo-onboarding';
if(PHP_SAPI!=='cli'||!in_array($argv[1]??'',['--inspect-demo-review','--review-demo-onboarding'],true)
    ||getenv('COREFLUX_ENV')!=='staging'||realpath(getcwd())!==REVIEW_ROOT)exit(2);
$native=[
 'modules/people/lib/onboarding.php'=>'bb5d830f615003e8ce065f7f61653c132d21a824c3adabdf4054149d409a756d',
 'modules/people/lib/documents.php'=>'3ad7ae24e4cd9b1f5a08560aec074d9a4a0ebb7a495319d0a9dcbaba4b44a5f6',
 'core/quanta/onboarding_delivery.php'=>'5e148d9e51f575341c92ddad623866e6b8cd3c2bca8f3c7f38365b4b14813587',
 'core/quanta/onboarding_contract.php'=>'2607ae58214ff6b99c9180fd8cdbd787b64ee73aa4a2dc98d98db8094cdd380d',
 'core/quanta/onboarding_documents.php'=>'01ed029666373855343da5160b69e2f8b4dcd62674358a3b1362389f26d875c8',
 'core/StorageService.php'=>'eaa14242b2cadc4d487abdee88729256273ad9d5689bcaf89a27d7132ad7a904',
];
foreach($native as$file=>$hash)if(!is_file(REVIEW_ROOT.'/'.$file)||!hash_equals($hash,hash_file('sha256',REVIEW_ROOT.'/'.$file))){fwrite(STDERR,"Installed native source differs: ".$file."\n");exit(2);}
require_once REVIEW_ROOT.'/core/db.php';$pdo=getDB();
if(!$pdo||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||!defined('COREFLUX_STAGING')||COREFLUX_STAGING!==true
 ||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=999 AND is_active=1')->fetchColumn()!==1)exit(2);
foreach(['core/sub_tenants.php','core/quanta/onboarding_delivery.php','core/RBAC.php','core/rbac/permissions.php','core/rbac/legacy_map.php']as$file)require_once REVIEW_ROOT.'/'.$file;
$report=['checked_at'=>gmdate('c'),'mode'=>$execute?'synthetic_onboarding_review_return':'read_only_review_preflight','tenant_id'=>999,'person_number'=>'P-15','placement_number'=>'PL-13',
 'production_changed'=>false,'financial_or_payroll_writes'=>false,'real_employment_approval'=>false,'independent_human_review'=>false,'permissions_changed'=>false,'persistent_configuration_changed'=>false];
$phase='guard';$exit=0;$locked=false;$flag=getenv('COREFLUX_QUANTA_ONBOARDING_DELIVERY');
try{
 if(integrationInstanceId()!==REVIEW_INSTANCE||quantaApiBase()!=='https://clockin-hub-16.preview.emergentagent.com/api/v1'
  ||effectiveTenantIdForModule('people',999)!==999||effectiveTenantIdForModule('placements',999)!==999)throw new RuntimeException('Reviewed scope changed');
 $locked=(int)$pdo->query("SELECT GET_LOCK('core_sim_999_onboarding_20261026',10)")->fetchColumn()===1;if(!$locked)throw new RuntimeException('Another intake or review is running');
 if(!$pdo->query("SELECT id FROM people WHERE tenant_id=999 AND id=15 AND first_name='Synthetic' AND last_name='Core Integration' AND deleted_at IS NULL")->fetchColumn()
  ||!$pdo->query("SELECT id FROM placements WHERE tenant_id=999 AND id=13 AND person_id=15 AND title='Synthetic Core Integration 20261023' AND deleted_at IS NULL")->fetchColumn())throw new RuntimeException('Synthetic fixture changed');
 $operator=$pdo->query("SELECT id,role FROM users WHERE id=4 AND email='stage-admin@coreflux.test' AND is_active=1")->fetch(PDO::FETCH_ASSOC);
 if(!$operator||(RBACResolver::activeMembership(4,999)['status']??null)!=='active')throw new RuntimeException('Existing operator unavailable');
 $operator['global_role']=$operator['role'];$operator['role']=RBACResolver::personaTypeOf(4,999);
 foreach(['people.onboarding.view','people.onboarding.manage','people.onboarding.review','placements.view','people.docs.view','integrations.quanta.manage']as$p){
  [$module,$action]=RbacLegacyMap::resolve($p);if(!RBAC::hasPermission($operator,$p)||!RBACResolver::can($operator,999,$module,$action))throw new RuntimeException('Existing operator lacks review permission');
 }
 $scope=['tenant_id'=>999,'people_tenant_id'=>999,'placements_tenant_id'=>999,'actor_user_id'=>4,'permissions'=>['view'=>true,'manage'=>true,'review'=>true]];
 if(quantaOnboardingDocumentsEnabled(999)||!quantaOnboardingDocumentStorageReady())throw new RuntimeException('Expected private storage and disabled document intake');
 $storage=new \Core\StorageService();if($storage->driver_name()!=='s3')throw new RuntimeException('Private storage is required');$head=static fn($key)=>$storage->head($key);
 $phase='fresh_evidence';$snapshot=quantaOnboardingSnapshot($scope,REVIEW_CASE,$head);$case=$snapshot['case'];
 if($snapshot['access']['context']['workspace']['id']!==REVIEW_WORKSPACE||$snapshot['worker']!==REVIEW_WORKER||$snapshot['assignment']['version']!==3
  ||($snapshot['remote']['assignment_current']??false)!==true||$case['source_changed']||$case['plan_version']!==4||$case['confirmed_plan_version']!==4
  ||!in_array($case['version'],[7,8,9,10,11],true)||$case['required_count']!==3||count($case['tasks'])!==3)throw new RuntimeException('Reviewed evidence or publication changed');
 $expected=[REVIEW_ACK=>['attestation','worker','cfs_fd119b04123a0428671204a33651b0632772c007d06098a9656b1d56a334c31d'],
  REVIEW_DOCUMENT=>['document','worker','cfs_092b070de8c916023900976f4d8de4310d315a6586a811e70488293056f5ab88'],REVIEW_STAFF=>['attestation','staff',null]];
 foreach($case['tasks']as$t){
  $spec=$expected[$t['id']]??null;if(!$spec||!str_starts_with($t['title'],'TEST: ')||$t['evidence_kind']!==$spec[0]||$t['assignee']!==$spec[1]||!$t['required'])throw new RuntimeException('Unexpected requirement');
  if($spec[2]!==null&&(($t['evidence']['source']??null)!=='quanta_worker'||($t['evidence']['provider_receipt_id']??null)!==$spec[2]
   ||($t['evidence']['provider_workspace_id']??null)!==REVIEW_WORKSPACE||($t['evidence']['provider_worker_id']??null)!==REVIEW_WORKER
   ||$t['evidence']['source_revision']!==$case['source_revision']||$t['evidence']['publication_delivery_id']!==19))throw new RuntimeException('Worker evidence changed');
  if($t['id']===REVIEW_DOCUMENT){
   if(($t['evidence']['document_id']??null)!==1||$t['evidence']['sha256']!=='83a54f86476cc843811d6a74c9c278748f4fd5b14ffe218493b55fdd05e46793')throw new RuntimeException('Sample evidence changed');
   $verified=onboardingDocument($pdo,$case,1,$head);if(!hash_equals($t['evidence']['revision'],$verified['revision']))throw new RuntimeException('Stored evidence changed');
   $key=$pdo->query('SELECT s.s3_key FROM people_documents d JOIN storage_objects s ON s.id=d.storage_object_id AND s.tenant_id=d.tenant_id WHERE d.tenant_id=999 AND d.person_id=15 AND d.id=1 AND d.deleted_at IS NULL AND s.soft_deleted_at IS NULL')->fetchColumn();
   $bytes=$storage->read($key,1907);if(!is_string($bytes)||strlen($bytes)!==1907||hash('sha256',$bytes)!==$t['evidence']['sha256'])throw new RuntimeException('Stored sample bytes changed');unset($bytes);
  }
 }
 $report+=['case_version_before'=>$case['version'],'required_count'=>3,'satisfied_before'=>$case['satisfied_count'],'private_sample_verified'=>true,'native_source_files_verified'=>count($native)];
 if(!$execute){$report['passed']=true;}else{
  $tables=$pdo->query("SELECT c.TABLE_NAME FROM information_schema.COLUMNS c JOIN information_schema.TABLES t ON t.TABLE_SCHEMA=c.TABLE_SCHEMA AND t.TABLE_NAME=c.TABLE_NAME WHERE c.TABLE_SCHEMA=DATABASE() AND c.COLUMN_NAME='tenant_id' AND t.TABLE_TYPE='BASE TABLE' ORDER BY c.TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
  $protected=static function()use($pdo,$tables):array{$hashes=[];foreach($tables as$table){if(!preg_match('/^[a-zA-Z0-9_]+$/D',$table))throw new RuntimeException('Invalid table');
   $where=match($table){'staffing_onboarding_cases'=>'NOT (tenant_id=999 AND id="'.REVIEW_CASE.'")','staffing_onboarding_events'=>'NOT (tenant_id=999 AND case_id="'.REVIEW_CASE.'")',
    'integration_deliveries'=>'NOT (tenant_id=999 AND provider="quanta" AND operation="onboarding.publish" AND entity_tenant_id=999 AND entity_type="placement" AND entity_id=13)',
    'audit_log'=>'tenant_id<>999',default=>'tenant_id=999'};
   $rows=$pdo->query('SELECT * FROM `'.$table.'` WHERE '.$where)->fetchAll(PDO::FETCH_ASSOC);$values=array_map(static fn($row)=>serialize($row),$rows);sort($values,SORT_STRING);$hashes[$table]=hash('sha256',serialize($values));}return$hashes;};
  $before=$protected();$report['protected_table_sets']=count($before);
  $base=['case_id'=>REVIEW_CASE];$reason='TEST ONLY: verified synthetic worker evidence and private sample PDF; no real employment, tax, payroll or payment approval.';
  $commands=[
   $base+['action'=>'review','request_id'=>'6d9c9a62-861e-4b91-8dd0-a00000000001','expected_version'=>7,'task_id'=>REVIEW_ACK,'decision'=>'approve','reason'=>$reason,'expires_at'=>null],
   $base+['action'=>'review','request_id'=>'6d9c9a62-861e-4b91-8dd0-a00000000002','expected_version'=>8,'task_id'=>REVIEW_DOCUMENT,'decision'=>'approve','reason'=>$reason,'expires_at'=>null],
   $base+['action'=>'submit','request_id'=>'6d9c9a62-861e-4b91-8dd0-a00000000003','expected_version'=>9,'task_id'=>REVIEW_STAFF,'acknowledged'=>true],
   $base+['action'=>'review','request_id'=>'6d9c9a62-861e-4b91-8dd0-a00000000004','expected_version'=>10,'task_id'=>REVIEW_STAFF,'decision'=>'approve','reason'=>$reason,'expires_at'=>null],
  ];
  $phase='stale_review_guard';$bad=$commands[0];$bad['request_id']='6d9c9a62-861e-4b91-8dd0-a00000000099';$bad['expected_version']=1;
  try{onboardingCommand($pdo,$scope,$bad,$head);throw new RuntimeException('Stale review unexpectedly succeeded');}catch(OnboardingConflict$e){if($e->getMessage()!=='This case changed. Reload and review the latest version.')throw$e;}
  if(onboardingCase($pdo,$scope,REVIEW_CASE)['version']!==$case['version'])throw new RuntimeException('Stale review changed the case');$report['stale_review_rejected']=true;
  $phase='native_employer_review';$results=[];
  foreach($commands as$command){$result=onboardingCommand($pdo,$scope,$command,$head);$replay=onboardingCommand($pdo,$scope,$command,$head);if(!$replay['replayed'])throw new RuntimeException('Review replay was not retained');unset($result['replayed'],$replay['replayed']);if($result!==$replay)throw new RuntimeException('Review replay differs');$results[]=$result;}
  $after=onboardingView($pdo,$scope,REVIEW_CASE,$head);if($after['version']!==11||$after['readiness']!=='complete'||$after['satisfied_count']!==3)throw new RuntimeException('Synthetic checklist did not complete');
  foreach($after['tasks']as$t)if($t['effective_state']!=='approved'||$t['review']['actor_user_id']!==4)throw new RuntimeException('Unexpected task review');
  $phase='return_decisions';
  if(defined('COREFLUX_QUANTA_ONBOARDING_DELIVERY')&&COREFLUX_QUANTA_ONBOARDING_DELIVERY!==true)throw new RuntimeException('Publication prohibited');putenv('COREFLUX_QUANTA_ONBOARDING_DELIVERY=1');
  $preview=quantaOnboardingPreview($scope,REVIEW_CASE,'published',$head);
  if($preview['status']==='preview'){
   $d=$preview['delivery'];if(in_array($d['state'],['sending','uncertain'],true))$d=quantaOnboardingCheckReceipt($scope,REVIEW_CASE,(int)$d['id'],$d['review_hash'],$head)['delivery'];
   $sent=quantaOnboardingSend($scope,REVIEW_CASE,(int)$d['id'],$d['review_hash'],$head);$again=quantaOnboardingSend($scope,REVIEW_CASE,(int)$d['id'],$d['review_hash'],$head);
   if($sent['state']!=='delivered'||$sent!==$again)throw new RuntimeException('Decision publication did not reconcile');$report['publication']=array_intersect_key($sent,array_flip(['id','state','remote_id','remote_receipt_id','attempt_count']));
  }elseif($preview['status']!=='unchanged')throw new RuntimeException('Unexpected publication state');
  $remote=quantaOnboardingSnapshot($scope,REVIEW_CASE,$head);if($remote['remote']['publication']['core_version']!==11||count($remote['remote']['publication']['tasks'])!==3)throw new RuntimeException('Provider did not retain decisions');
  foreach($remote['remote']['publication']['tasks']as$t)if($t['effective_state']!=='approved')throw new RuntimeException('Provider review differs');
  $check=quantaOnboardingPreview($scope,REVIEW_CASE,'published',$head);if($check['status']!=='unchanged'||$before!==$protected())throw new RuntimeException('Publication or protected records changed');
  $report+=['native_reviews'=>$results,'review_replay_verified'=>true,'publication_replay_verified'=>true,'fresh_publication_comparison'=>'unchanged','protected_data_unchanged'=>true,'case_version_after'=>11,'readiness'=>'complete','satisfied_count'=>3,'provider_core_version'=>11,'provider_version'=>$remote['remote']['version'],'passed'=>true];
 }
}catch(Throwable$e){$report['passed']=false;$report['failed_phase']=$phase;$report['failure_class']=get_class($e);if($e instanceof QuantaApiException)$report['provider_http_status']=$e->httpStatus;elseif($e instanceof OnboardingConflict||$e instanceof PeopleDocumentConflict)$report['reason']=$e->getMessage();$exit=1;}
finally{putenv($flag===false?'COREFLUX_QUANTA_ONBOARDING_DELIVERY':'COREFLUX_QUANTA_ONBOARDING_DELIVERY='.$flag);$report['publication_flag_restored']=getenv('COREFLUX_QUANTA_ONBOARDING_DELIVERY')===$flag;$report['document_intake_enabled_after']=quantaOnboardingDocumentsEnabled(999);if($locked)$pdo->query("SELECT RELEASE_LOCK('core_sim_999_onboarding_20261026')");}
echo 'SYNTHETIC_ONBOARDING_REVIEW='.json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";exit($exit);
