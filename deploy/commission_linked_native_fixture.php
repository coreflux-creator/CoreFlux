<?php
/** Temporary, tenant-scoped accounts and preservation evidence for native commission acceptance. */
declare(strict_types=1);
const CN_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const CN_PRIVATE='/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030';
const CN_STATE=CN_PRIVATE.'/commission-linked-native-state-20261112.json';
const CN_BEFORE=CN_PRIVATE.'/commission-linked-native-before-20261112.private.json';
const CN_CREDS=CN_PRIVATE.'/commission-linked-native-credentials-20261112.private.json';
if(PHP_SAPI!=='cli'||getenv('COREFLUX_ENV')!=='staging'||getcwd()!==CN_ROOT||posix_geteuid()!==1003||!in_array($argv[1]??'',['prepare','inspect','retire'],true))exit(2);
require_once CN_ROOT.'/core/db.php';require_once CN_ROOT.'/core/memberships.php';require_once CN_ROOT.'/core/rbac/permissions.php';require_once CN_ROOT.'/core/integrations/identity.php';
$pdo=getDB();
function cnNeed(bool $yes,string $message):void{if(!$yes)throw new RuntimeException($message);}
function cnSave(string $path,array $data,bool $new=false):void{cnNeed(realpath(dirname($path))===CN_PRIVATE&&!is_link($path),'Private target');$bytes=json_encode($data,JSON_THROW_ON_ERROR);$old=umask(0077);$f=fopen($path,$new?'x':'w');umask($old);cnNeed((bool)$f,'Existing or unavailable checkpoint');cnNeed(fwrite($f,$bytes)===strlen($bytes)&&fflush($f)&&fsync($f),'Incomplete checkpoint');fclose($f);}
function cnSnapshot(PDO $pdo):array{$out=[];foreach($pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN)as$t){cnNeed((bool)preg_match('/^[A-Za-z0-9_]+$/D',$t),'Table name');$out[$t]=[];foreach($pdo->query('SELECT * FROM `'.$t.'`')->fetchAll(PDO::FETCH_ASSOC)as$r)$out[$t][]=hash('sha256',serialize($r));sort($out[$t]);}ksort($out);return$out;}
cnNeed($pdo->query('SELECT DATABASE()')->fetchColumn()==='muzqvdvqbx'&&COREFLUX_STAGING&&integrationInstanceId()==='9f4b6dfb-e52e-464c-9a9d-af7766928ddf'&&COREFLUX_INTEGRATION_DELIVERY_TENANTS==='2002','Exact isolated pilot');
cnNeed((int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=2002')->fetchColumn()===1&&RBACResolver::can(4,2002,'accounting','admin'),'Pilot administrative scope');
$manifest=json_decode(file_get_contents(__DIR__.'/commission-installed-pins.json'),true,512,JSON_THROW_ON_ERROR);
foreach($manifest as$f=>$h)cnNeed(hash('sha256',str_replace("\r\n","\n",file_get_contents(CN_ROOT.'/'.$f)))===$h,'Runtime changed: '.$f);
$mode=$argv[1];
if($mode==='prepare'){
 cnNeed(!file_exists(CN_STATE)&&!file_exists(CN_BEFORE)&&!file_exists(CN_CREDS),'Inspect existing preparation; never repeat');
 cnNeed((int)$pdo->query('SELECT COUNT(*) FROM placements WHERE tenant_id=2002 AND title="Synthetic linked commission 20261112"')->fetchColumn()===0,'Fixture already exists');
 $fixture=$pdo->query('SELECT id,person_id,client_id,status,workers_comp_class FROM placements WHERE tenant_id=2002 AND id=902')->fetch(PDO::FETCH_ASSOC);
 cnNeed($fixture&&(int)$fixture['person_id']===387&&(int)$fixture['client_id']===1280&&$fixture['status']==='active'&&$fixture['workers_comp_class']==='SYNTHETIC TEST ONLY','Original operational fixture changed');
 cnSave(CN_BEFORE,['tables'=>cnSnapshot($pdo),'feature_sha256'=>hash_file('sha256',dirname(CN_ROOT).'/private_html/coreflux.thunderhawk-pilot.php')],true);
 $state=['started_at'=>gmdate('c'),'phase'=>'preparing','actors'=>[],'financial_execution'=>false];cnSave(CN_STATE,$state,true);$credentials=[];
 foreach(['editor','approver']as$role){
  $email='core-commission-linked-'.$role.'-20261112@example.invalid';$s=$pdo->prepare('SELECT COUNT(*) FROM users WHERE email=?');$s->execute([$email]);cnNeed(!(int)$s->fetchColumn(),'Reserved actor exists');
  $password=bin2hex(random_bytes(32));$hash=password_hash($password,PASSWORD_DEFAULT);
  $pdo->beginTransaction();try{$pdo->prepare('INSERT INTO users(tenant_id,name,email,password,password_hash,role,is_active,is_global_admin) VALUES(2002,?,?,?, ?,"admin",1,0)')->execute(['Synthetic commission '.$role,$email,$hash,$hash]);$id=(int)$pdo->lastInsertId();$member=(int)provisionMembership($id,2002,'admin',['is_primary'=>true,'persona_label'=>'Synthetic commission '.$role,'status'=>'active'])['membership_id'];$pdo->commit();}catch(Throwable$e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}
  $state['actors'][$role]=['id'=>$id,'membership_id'=>$member,'email'=>$email];cnSave(CN_STATE,$state);
  $grants=$role==='editor'?['people'=>'write','placements'=>'admin','companies'=>'read','ap'=>'read','payroll'=>'read','accounting'=>'admin']:['people'=>'read','placements'=>'admin'];
  foreach($grants as$module=>$level)RBACResolver::grantModule($member,$module,$level,[2002],4);
  foreach(['treasury','pii','integrations']as$module)cnNeed(!RBACResolver::can($id,2002,$module,'read'),'Unexpected extra access');
  cnNeed(!RBACResolver::can($id,2013,'placements','read'),'Cross-company access');
  $credentials[$role]=['username'=>$email,'password'=>$password];unset($password,$hash);
  cnSave(CN_CREDS,$credentials,$role==='editor');
 }
 $state['phase']='browser_ready';cnSave(CN_STATE,$state);echo json_encode($state);exit;
}
$state=json_decode(file_get_contents(CN_STATE),true,512,JSON_THROW_ON_ERROR);
if($mode==='retire'){
 foreach($state['actors']as$a){
  $s=$pdo->prepare('SELECT tenant_id,email FROM users WHERE id=?');$s->execute([$a['id']]);$u=$s->fetch(PDO::FETCH_ASSOC);cnNeed($u&&(int)$u['tenant_id']===2002&&$u['email']===$a['email'],'Actor identity changed');
  $pdo->beginTransaction();try{
   $pdo->prepare('DELETE FROM membership_module_access WHERE membership_id=?')->execute([$a['membership_id']]);
   $pdo->prepare('UPDATE tenant_memberships SET status="revoked" WHERE id=? AND user_id=? AND tenant_id=2002')->execute([$a['membership_id'],$a['id']]);
   $pdo->prepare('UPDATE user_tenants SET status="inactive" WHERE user_id=? AND tenant_id=2002')->execute([$a['id']]);
   $pdo->prepare('UPDATE users SET is_active=0,password=NULL,password_hash=NULL WHERE id=? AND tenant_id=2002')->execute([$a['id']]);$pdo->commit();
  }catch(Throwable$e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}
 }
 if(file_exists(CN_CREDS)){cnNeed(!is_link(CN_CREDS)&&unlink(CN_CREDS),'Credential retirement');}
 $state['phase']='retired';$state['retired_at']=gmdate('c');cnSave(CN_STATE,$state);
}
$before=json_decode(file_get_contents(CN_BEFORE),true,512,JSON_THROW_ON_ERROR);$after=cnSnapshot($pdo);$missing=[];$added=[];
cnNeed(array_keys($before['tables'])===array_keys($after),'Schema changed');
foreach($before['tables']as$t=>$rows){$old=array_count_values($rows);$new=array_count_values($after[$t]);foreach($old as$h=>$n)if(($new[$h]??0)<$n)$missing[$t]=($missing[$t]??0)+$n-($new[$h]??0);if(count($after[$t])!==count($rows))$added[$t]=count($after[$t])-count($rows);}
$actors=[];foreach($state['actors']as$role=>$a){$s=$pdo->prepare('SELECT is_active,password IS NULL AS password_absent,password_hash IS NULL AS hash_absent FROM users WHERE id=?');$s->execute([$a['id']]);$actors[$role]=$a+$s->fetch(PDO::FETCH_ASSOC);}
$placements=$pdo->query('SELECT id,person_id,client_id,status,start_date,end_date FROM placements WHERE tenant_id=2002 AND title="Synthetic linked commission 20261112"')->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['phase'=>$state['phase'],'actors'=>$actors,'placements'=>$placements,'original_missing_or_changed'=>$missing,'added_rows'=>$added,'original_rows'=>array_sum(array_map('count',$before['tables'])),'current_rows'=>array_sum(array_map('count',$after)),'feature_preserved'=>hash_file('sha256',dirname(CN_ROOT).'/private_html/coreflux.thunderhawk-pilot.php')===$before['feature_sha256'],'credential_file_present'=>file_exists(CN_CREDS)]);
