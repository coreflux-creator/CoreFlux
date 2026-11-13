<?php
/** Exact isolated pilot, invented company/contact, temporary limited reviewer. */
declare(strict_types=1);
const CN_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const CN_PRIVATE='/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030';
const CN_STATE=CN_PRIVATE.'/company-source-native-state-20261112.json';
const CN_BEFORE=CN_PRIVATE.'/company-source-native-before-20261112.private.json';
const CN_CREDS=CN_PRIVATE.'/company-source-native-credentials-20261112.private.json';
if(PHP_SAPI!=='cli'||getenv('COREFLUX_ENV')!=='staging'||getcwd()!==CN_ROOT||posix_geteuid()!==1003
    ||!in_array($argv[1]??'',['prepare','inspect','advance','reader','editor','retire'],true))exit(2);
require_once CN_ROOT.'/core/db.php';require_once CN_ROOT.'/core/memberships.php';require_once CN_ROOT.'/core/rbac/permissions.php';
require_once CN_ROOT.'/core/integrations/identity.php';require_once CN_ROOT.'/modules/people/lib/companies.php';
require_once CN_ROOT.'/modules/people/lib/company_source_ownership.php';
$pdo=getDB();
function cnNeed(bool $yes,string $message):void{if(!$yes)throw new RuntimeException($message);}
function cnSave(string $path,array $data,bool $new=false):void{
 cnNeed(realpath(dirname($path))===CN_PRIVATE&&!is_link($path),'Private target');$bytes=json_encode($data,JSON_THROW_ON_ERROR);
 $old=umask(0077);$f=fopen($path,$new?'x':'w');umask($old);cnNeed((bool)$f,'Existing or unavailable checkpoint');
 cnNeed(fwrite($f,$bytes)===strlen($bytes)&&fflush($f)&&fsync($f),'Incomplete checkpoint');fclose($f);
}
function cnSnapshot(PDO $pdo):array{
 $out=[];foreach($pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN)as$t){
  cnNeed((bool)preg_match('/^[A-Za-z0-9_]+$/D',$t),'Table name');$out[$t]=[];
  foreach($pdo->query('SELECT * FROM `'.$t.'`')->fetchAll(PDO::FETCH_ASSOC)as$r)$out[$t][]=hash('sha256',serialize($r));sort($out[$t]);
 }ksort($out);return$out;
}
function cnSource(string $table):array{return ['system'=>'jobdiva','workspace_id'=>2002,'external_id'=>$table==='companies'?'synthetic-source-company-20261112':'synthetic-source-contact-20261112'];}
cnNeed($pdo->query('SELECT DATABASE()')->fetchColumn()==='muzqvdvqbx'&&COREFLUX_STAGING
 &&integrationInstanceId()==='9f4b6dfb-e52e-464c-9a9d-af7766928ddf'&&COREFLUX_INTEGRATION_DELIVERY_TENANTS==='2002','Exact isolated pilot');
cnNeed((int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=2002')->fetchColumn()===1&&RBACResolver::can(4,2002,'accounting','admin'),'Pilot administrative scope');
cnNeed(companiesDirectoryTenantId(2002)===2002,'Isolated company directory');
$manifest=json_decode(file_get_contents(__DIR__.'/company-source-installed-pins.json'),true,512,JSON_THROW_ON_ERROR);
foreach($manifest as$f=>$h)cnNeed(hash('sha256',str_replace("\r\n","\n",file_get_contents(CN_ROOT.'/'.$f)))===$h,'Runtime changed: '.$f);
$mode=$argv[1];
if($mode==='prepare'){
 cnNeed(!file_exists(CN_STATE)&&!file_exists(CN_BEFORE)&&!file_exists(CN_CREDS),'Inspect existing preparation; never repeat');
 $email='core-company-source-review-20261112@example.invalid';
 $q=$pdo->prepare('SELECT COUNT(*) FROM users WHERE email=?');$q->execute([$email]);cnNeed(!(int)$q->fetchColumn(),'Reserved actor exists');
 $q=$pdo->prepare('SELECT COUNT(*) FROM companies WHERE tenant_id=2002 AND name=?');$q->execute(['Synthetic company source review 20261112']);cnNeed(!(int)$q->fetchColumn(),'Reserved company exists');
 foreach(['companies','company_contacts']as$table)cnNeed(mappingFindInternal(2002,'jobdiva',$table==='companies'?'company':'contact',cnSource($table)['external_id'])===null,'Reserved source binding exists');
 cnSave(CN_BEFORE,['tables'=>cnSnapshot($pdo),'feature_sha256'=>hash_file('sha256',dirname(CN_ROOT).'/private_html/coreflux.thunderhawk-pilot.php')],true);
 $state=['started_at'=>gmdate('c'),'phase'=>'preparing','financial_execution'=>false,'provider_calls'=>0];cnSave(CN_STATE,$state,true);
 $password=bin2hex(random_bytes(32));$hash=password_hash($password,PASSWORD_DEFAULT);
 $pdo->beginTransaction();try{
  $pdo->prepare('INSERT INTO users(tenant_id,name,email,password,password_hash,role,is_active,is_global_admin) VALUES(2002,?,?,?, ?,"admin",1,0)')
   ->execute(['Synthetic company source reviewer',$email,$hash,$hash]);$actor=(int)$pdo->lastInsertId();
  $member=(int)provisionMembership($actor,2002,'admin',['is_primary'=>true,'persona_label'=>'Synthetic company source reviewer','status'=>'active'])['membership_id'];
  RBACResolver::grantModule($member,'people','write',[2002],4);RBACResolver::grantModule($member,'companies','read',[2002],4);
  foreach(['treasury','pii','integrations','accounting','payroll','placements','ap']as$module)cnNeed(!RBACResolver::can($actor,2002,$module,'read'),'Unexpected extra access');
  cnNeed(!RBACResolver::can($actor,2013,'people','read'),'Cross-company access');
  $pdo->prepare('INSERT INTO companies(tenant_id,name,country,created_by_user_id) VALUES(2002,?,?,?)')->execute(['Synthetic company source review 20261112','US',$actor]);$company=(int)$pdo->lastInsertId();
  $pdo->prepare('INSERT INTO company_contacts(tenant_id,company_id,name,email) VALUES(2002,?,?,?)')->execute([$company,'Synthetic source contact','synthetic-source-contact@example.invalid']);$contact=(int)$pdo->lastInsertId();
  foreach(['companies'=>[$company,'Synthetic company source review 20261112'],'company_contacts'=>[$contact,'Synthetic source contact']]as$table=>[$id,$name]){
   $source=cnSource($table);mappingUpsert(2002,'jobdiva',$table==='companies'?'company':'contact',$source['external_id'],$id,null,'pull',$actor);
   $proposal=['name'=>$name,'phone'=>'JobDiva initial phone'];if($table==='companies')$proposal['roles']=['vendor'];
   companySourceObserve($pdo,2002,$table,$id,$proposal,$source,true);
   companyNativeFields($pdo,2002,2002,$table,$id,['name'=>$name,'phone'=>'Core protected phone'],$actor);
   $proposal['name']=$table==='companies'?'JobDiva proposed company name':'JobDiva proposed contact name';
   $proposal['phone']=$table==='companies'?'JobDiva proposed company phone':'JobDiva proposed contact phone';
   companySourceObserve($pdo,2002,$table,$id,$proposal,$source);
  }
  $state+=['actor'=>['id'=>$actor,'membership_id'=>$member,'email'=>$email],'company_id'=>$company,'contact_id'=>$contact];
  cnSave(CN_CREDS,['editor'=>['username'=>$email,'password'=>$password]],true);
  $pdo->commit();
 }catch(Throwable$e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}finally{unset($password,$hash);}
 $state['phase']='browser_ready';cnSave(CN_STATE,$state);echo json_encode($state);exit;
}
$state=json_decode(file_get_contents(CN_STATE),true,512,JSON_THROW_ON_ERROR);
cnNeed(isset($state['actor'],$state['company_id'],$state['contact_id']),'Preparation did not finish; inspect it before recovery');
$actor=$state['actor'];
$q=$pdo->prepare('SELECT tenant_id,email FROM users WHERE id=?');$q->execute([$actor['id']]);$user=$q->fetch(PDO::FETCH_ASSOC);
cnNeed($user&&(int)$user['tenant_id']===2002&&$user['email']===$actor['email'],'Actor identity changed');
foreach(['companies'=>$state['company_id'],'company_contacts'=>$state['contact_id']]as$table=>$id){
 $q=$pdo->prepare('SELECT * FROM '.$table.' WHERE tenant_id=2002 AND id=?');$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
 cnNeed($row&&companySourceState($row,$table)['source']===cnSource($table),'Fixture source identity changed');
}
if($mode==='advance'){
 cnNeed($state['phase']==='browser_ready','Source proposal was already advanced or fixture was retired');
 companySourceObserve($pdo,2002,'companies',$state['company_id'],['name'=>'JobDiva proposed company name','phone'=>'JobDiva refreshed company phone','roles'=>['vendor']],cnSource('companies'));
 $state['phase']='source_advanced';cnSave(CN_STATE,$state);
}
if(in_array($mode,['reader','editor'],true)){
 cnNeed($state['phase']!=='retired','Retired reviewer');
 RBACResolver::grantModule($actor['membership_id'],'people',$mode==='reader'?'read':'write',[2002],4);
 $state['reviewer_level']=$mode;cnSave(CN_STATE,$state);
}
if($mode==='retire'){
 $pdo->beginTransaction();try{
  $pdo->prepare('DELETE FROM membership_module_access WHERE membership_id=?')->execute([$actor['membership_id']]);
  deactivateMembership((int)$actor['id'],2002);
  $pdo->prepare('UPDATE users SET is_active=0,password=NULL,password_hash=NULL WHERE id=? AND tenant_id=2002')->execute([$actor['id']]);
  $pdo->prepare('UPDATE companies SET deleted_at=COALESCE(deleted_at,NOW()) WHERE id=? AND tenant_id=2002')->execute([$state['company_id']]);
  $pdo->commit();
 }catch(Throwable$e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}
 if(file_exists(CN_CREDS))cnNeed(!is_link(CN_CREDS)&&unlink(CN_CREDS),'Credential retirement');
 $state['phase']='retired';$state['retired_at']=gmdate('c');cnSave(CN_STATE,$state);
}
$before=json_decode(file_get_contents(CN_BEFORE),true,512,JSON_THROW_ON_ERROR);$after=cnSnapshot($pdo);$missing=[];$added=[];
cnNeed(array_keys($before['tables'])===array_keys($after),'Schema changed');
foreach($before['tables']as$t=>$rows){$old=array_count_values($rows);$new=array_count_values($after[$t]);foreach($old as$h=>$n)if(($new[$h]??0)<$n)$missing[$t]=($missing[$t]??0)+$n-($new[$h]??0);if(count($after[$t])!==count($rows))$added[$t]=count($after[$t])-count($rows);}
$q=$pdo->prepare('SELECT is_active,password IS NULL AS password_absent,password_hash IS NULL AS hash_absent FROM users WHERE id=?');$q->execute([$actor['id']]);$actor+=$q->fetch(PDO::FETCH_ASSOC);
$q=$pdo->prepare('SELECT status FROM tenant_memberships WHERE id=? AND user_id=? AND tenant_id=2002');$q->execute([$actor['membership_id'],$actor['id']]);$actor['membership_status']=$q->fetchColumn();
$q=$pdo->prepare('SELECT COUNT(*) FROM membership_module_access WHERE membership_id=?');$q->execute([$actor['membership_id']]);$actor['module_grants']=(int)$q->fetchColumn();
$records=[];foreach(['companies'=>$state['company_id'],'company_contacts'=>$state['contact_id']]as$table=>$id){
 $q=$pdo->prepare('SELECT * FROM '.$table.' WHERE tenant_id=2002 AND id=?');$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
 $records[$table]=array_intersect_key($row,array_flip(['id','tenant_id','name','phone','deleted_at']));
 $records[$table]['ownership']=companySourceState($row,$table);
 $records[$table]['mapping']=mappingFindInternal(2002,'jobdiva',$table==='companies'?'company':'contact',cnSource($table)['external_id']);
}
$q=$pdo->prepare('SELECT event,target_id,meta_json FROM audit_log WHERE tenant_id=2002 AND actor_user_id=? ORDER BY id');$q->execute([$actor['id']]);
echo json_encode(['phase'=>$state['phase'],'actor'=>$actor,'records'=>$records,'audits'=>$q->fetchAll(PDO::FETCH_ASSOC),'original_missing_or_changed'=>$missing,
 'added_rows'=>$added,'original_rows'=>array_sum(array_map('count',$before['tables'])),'current_rows'=>array_sum(array_map('count',$after)),
 'feature_preserved'=>hash_file('sha256',dirname(CN_ROOT).'/private_html/coreflux.thunderhawk-pilot.php')===$before['feature_sha256'],'credential_file_present'=>file_exists(CN_CREDS)]);
