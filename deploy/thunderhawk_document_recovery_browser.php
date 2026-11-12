<?php
/** Temporary placement document reviewer in the isolated pilot; synthetic PL-902 documents only. */
declare(strict_types=1);
const FR_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const FR_PRIVATE='/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030';
const FR_STATE=FR_PRIVATE.'/document-recovery-browser-20261112.json';
const FR_CREDENTIAL=FR_PRIVATE.'/document-recovery-browser-credential.private.json';
const FR_BEFORE=FR_PRIVATE.'/document-recovery-browser-before.private.json';
const FR_EMAIL='core-document-recovery-reviewer-20261112@example.invalid';
const FR_MODULES=['people','placements'];
if(PHP_SAPI!=='cli'||getenv('COREFLUX_ENV')!=='staging'||getcwd()!==FR_ROOT||posix_geteuid()!==1003
 ||realpath(FR_PRIVATE)!==FR_PRIVATE||(fileperms(FR_PRIVATE)&0777)!==0700||!in_array($argv[1]??'',['prepare','finish'],true))exit(2);
require_once FR_ROOT.'/core/db.php';require_once FR_ROOT.'/core/integrations/identity.php';
require_once FR_ROOT.'/core/tenant_scope.php';
require_once FR_ROOT.'/core/memberships.php';require_once FR_ROOT.'/core/rbac/permissions.php';require_once FR_ROOT.'/core/RBAC.php';
$pdo=getDB();
if(!COREFLUX_STAGING||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf'
 ||COREFLUX_INTEGRATION_DELIVERY_TENANTS!=='2002'||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=2002')->fetchColumn()!==1)exit(2);
setRequestTenantId(2002);
if((int)$pdo->query('SELECT person_id FROM placements WHERE tenant_id=2002 AND id=902 AND deleted_at IS NULL')->fetchColumn()!==387)throw new RuntimeException('Synthetic placement changed');
$pins=json_decode(file_get_contents(__DIR__.'/document-recovery-runtime-pins.json'),true,64,JSON_THROW_ON_ERROR);
foreach($pins as$file=>$hash)if(hash('sha256',str_replace("\r\n","\n",file_get_contents(FR_ROOT.'/'.$file)))!==$hash)throw new RuntimeException('Runtime changed: '.$file);
$lock=fopen(FR_PRIVATE.'/contact-install.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit(2);
function frSave(string $path,array $value,bool $new=false):void{
 if(is_link($path)||realpath(dirname($path))!==FR_PRIVATE)throw new RuntimeException('Invalid private file');
 $old=umask(0077);try{$f=fopen($path,$new?'x':'w');if(!$f)throw new RuntimeException('Cannot save checkpoint');
  $bytes=json_encode($value,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT);if(fwrite($f,$bytes)!==strlen($bytes)||!fflush($f)||!fsync($f))throw new RuntimeException('Incomplete checkpoint');fclose($f);
 }finally{umask($old);}
}
function frSnapshot(PDO $pdo):array{
 $out=[];foreach($pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN)as$t){
  if(!preg_match('/^[A-Za-z0-9_]+$/D',$t))throw new RuntimeException('Unexpected table');$rows=[];
  foreach($pdo->query('SELECT * FROM `'.$t.'`')->fetchAll(PDO::FETCH_ASSOC)as$r)$rows[]=hash('sha256',serialize($r));
  sort($rows,SORT_STRING);$out[$t]=$rows;
 }ksort($out);return$out;
}
if($argv[1]==='prepare'){
 if(file_exists(FR_STATE)||file_exists(FR_CREDENTIAL)||file_exists(FR_BEFORE))throw new RuntimeException('Existing browser fixture must be inspected, never recreated');
 $s=$pdo->prepare('SELECT COUNT(*) FROM users WHERE email=?');$s->execute([FR_EMAIL]);if((int)$s->fetchColumn())throw new RuntimeException('Reserved operator exists');
 frSave(FR_BEFORE,frSnapshot($pdo),true);
 $report=['checked_at'=>gmdate('c'),'status'=>'preparing','passed'=>false,'tenant_id'=>2002,
  'production_changed'=>false,'arabella_changed'=>false,'real_payroll_payments_postings'=>false,
  'feature_sha256'=>hash_file('sha256',dirname(FR_ROOT).'/private_html/coreflux.thunderhawk-pilot.php')];
 frSave(FR_STATE,$report,true);$password=bin2hex(random_bytes(32));$hash=password_hash($password,PASSWORD_DEFAULT);
 $pdo->beginTransaction();try{
  $pdo->prepare('INSERT INTO users(tenant_id,name,email,password,password_hash,role,is_active,is_global_admin) VALUES(2002,"Synthetic document recovery reviewer",?,?,?,"admin",1,0)')->execute([FR_EMAIL,$hash,$hash]);
  $actor=(int)$pdo->lastInsertId();
  $membership=(int)provisionMembership($actor,2002,'admin',['is_primary'=>true,'persona_label'=>'Synthetic document recovery reviewer','status'=>'active'])['membership_id'];
  $pdo->commit();
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}
 $report['actor_id']=$actor;$report['membership_id']=$membership;$report['status']='operator_created';frSave(FR_STATE,$report);
 foreach(FR_MODULES as$module){
  RBACResolver::grantModule($membership,$module,$module==='placements'?'write':'read',[2002],4);
  if(!RBACResolver::can($actor,2002,$module,'read',2002)||RBACResolver::can($actor,2002,$module,'write',2002)!==($module==='placements')||RBACResolver::can($actor,2002,$module,'admin',2002))throw new RuntimeException('Document operator access differs');
 }
 foreach(['integrations','treasury','pii']as$module)if(RBACResolver::can($actor,2002,$module,'read'))throw new RuntimeException('Unexpected extra module access');
 foreach(['tenant.manage','ai.enable_auto_execute']as$permission)if(RBAC::hasPermission(['role'=>'admin'],$permission))throw new RuntimeException('Unexpected legacy platform authority');
 frSave(FR_CREDENTIAL,['username'=>FR_EMAIL,'password'=>$password],true);unset($password,$hash);
 $report['status']='browser_ready';$report['grants']=['people'=>'read','placements'=>'write'];frSave(FR_STATE,$report);
 echo json_encode(['status'=>'browser_ready','actor_id'=>$actor,'membership_id'=>$membership,'grants'=>$report['grants'],'credential_printed'=>false]);exit;
}
$report=json_decode(file_get_contents(FR_STATE),true,64,JSON_THROW_ON_ERROR);
if(($report['status']??null)!=='browser_ready')throw new RuntimeException('Inspect fixture before cleanup');
$actor=(int)$report['actor_id'];$membership=(int)$report['membership_id'];
try{
 foreach(FR_MODULES as$module)RBACResolver::revokeModule($membership,$module,4);
 deactivateMembership($actor,2002);
 $pdo->prepare('UPDATE users SET is_active=0,password=NULL,password_hash=NULL WHERE tenant_id=2002 AND id=? AND email=?')->execute([$actor,FR_EMAIL]);
 $s=$pdo->prepare('SELECT COUNT(*) FROM membership_module_access WHERE membership_id=?');$s->execute([$membership]);if((int)$s->fetchColumn())throw new RuntimeException('Temporary grants remain');
 $s=$pdo->prepare('SELECT COUNT(*) FROM users WHERE id=? AND tenant_id=2002 AND email=? AND is_active=0 AND password IS NULL AND password_hash IS NULL');$s->execute([$actor,FR_EMAIL]);if((int)$s->fetchColumn()!==1)throw new RuntimeException('Actor retirement differs');
 if(!is_file(FR_CREDENTIAL)||is_link(FR_CREDENTIAL)||!unlink(FR_CREDENTIAL))throw new RuntimeException('Temporary credential cleanup failed');
 $before=json_decode(file_get_contents(FR_BEFORE),true,64,JSON_THROW_ON_ERROR);$after=frSnapshot($pdo);
 if(array_keys($before)!==array_keys($after))throw new RuntimeException('Schema changed');
 $allowed=['users','tenant_memberships','user_tenants','membership_audit','audit_log','rbac_bridge_audit','placement_documents','storage_objects'];$added=[];
 foreach($before as$table=>$rows){
  if($rows===$after[$table])continue;if(!in_array($table,$allowed,true))throw new RuntimeException('Business rows changed: '.$table);
  $counts=array_count_values($after[$table]);foreach(array_count_values($rows)as$hash=>$n)if(($counts[$hash]??0)<$n)throw new RuntimeException('Original row changed: '.$table);
  if($table==='rbac_bridge_audit')foreach($pdo->query('SELECT * FROM rbac_bridge_audit')->fetchAll(PDO::FETCH_ASSOC)as$row)
   if(!in_array(hash('sha256',serialize($row)),$rows,true)&&((int)$row['tenant_id']!==2002||(int)$row['user_id']!==$actor))throw new RuntimeException('Unrelated permission audit added');
  if(in_array($table,['placement_documents','storage_objects'],true))foreach($pdo->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC)as$row){
   if(in_array(hash('sha256',serialize($row)),$rows,true))continue;
   if((int)$row['tenant_id']!==2002)throw new RuntimeException('Document tenant differs');
   if($table==='placement_documents'){
    if((int)$row['placement_id']!==902||(int)$row['uploaded_by_user_id']!==$actor||$row['deleted_at']===null)throw new RuntimeException('Synthetic document not retired or wrong actor');
   }else{
    if($row['module']!=='placements'||$row['entity_type']!=='document'||$row['entity_id']!=='902'||(int)$row['created_by_user_id']!==$actor||$row['soft_deleted_at']!==null)throw new RuntimeException('Unexpected storage registration');
    require_once FR_ROOT.'/modules/placements/lib/documents.php';require_once FR_ROOT.'/core/StorageService.php';
    placementDocumentKey(2002,902,$row['s3_key']);placementDocumentVerifyObject($row,fn($key)=>\Core\StorageService::getInstance()->head($key));
   }
  }
  $added[$table]=count($after[$table])-count($rows);
 }
 if(hash_file('sha256',dirname(FR_ROOT).'/private_html/coreflux.thunderhawk-pilot.php')!==$report['feature_sha256'])throw new RuntimeException('Operational settings changed');
 $report['tables_verified']=count($after);$report['original_rows_preserved']=array_sum(array_map('count',$before));$report['added_rows']=$added;
 $report['operator_retired']=true;$report['original_business_rows_unchanged']=true;$report['only_new_synthetic_documents_retired']=true;$report['passed']=true;$report['status']='complete';
}catch(Throwable $e){$report['failure_class']=get_class($e);$report['failure_line']=$e->getLine();$report['failure_message']=$e->getMessage();$report['status']='needs_inspection';}
$report['finished_at']=gmdate('c');frSave(FR_STATE,$report);echo json_encode($report,JSON_THROW_ON_ERROR);exit($report['passed']?0:1);
