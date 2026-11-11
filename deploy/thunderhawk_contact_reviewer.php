<?php
/** Limited disposable reviewer for the isolated contact browser acceptance. */
declare(strict_types=1);
$root='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
$private='/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030';
$mode=$argv[1]??'';
if(PHP_SAPI!=='cli'||!in_array($mode,['--prepare','--credentials','--retire','--inspect'],true)
 ||getenv('COREFLUX_ENV')!=='staging'||realpath(getcwd())!==$root||realpath($private)!==$private)exit(2);
require_once $root.'/core/quanta/profile_return_delivery.php';
require_once $root.'/core/memberships.php';
require_once $root.'/core/rbac/permissions.php';
$pdo=getDB();
if(!$pdo||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||COREFLUX_STAGING!==true
 ||integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf'||!quantaProfileReturnEnabled(2002)
 ||quantaProfileReturnEnabled(2)||quantaProfileReturnEnabled(999)
 ||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=2002')->fetchColumn()!==1)exit(2);
$file=$private.'/contact-reviewer-20261111.private.json';
$record=is_file($file)?json_decode(file_get_contents($file),true,32,JSON_THROW_ON_ERROR):null;
if($mode==='--prepare'){
 if($record!==null)throw new RuntimeException('A contact reviewer already exists; inspect or retire it');
 $person=$pdo->query('SELECT id,tenant_id,status,first_name,last_name,email_primary,phone_primary FROM people WHERE tenant_id=2002 AND id=358 AND deleted_at IS NULL')->fetch(PDO::FETCH_ASSOC);
 if(!$person||$person['status']!=='active')throw new RuntimeException('Expected pilot person unavailable');
 $worker=quantaProfileLinkedWorker($pdo,2002,358,false);
 if($worker!=='cfw_9fe254742aedc66ec5ab66c9baba07e2acb5c1581de10d30e7ecf931133895fa')throw new RuntimeException('Pilot worker identity differs');
 $password=bin2hex(random_bytes(32));$hash=password_hash($password,PASSWORD_DEFAULT);
 $email='contact-reviewer-'.bin2hex(random_bytes(8)).'@example.invalid';
 $pdo->beginTransaction();
 try{
  $pdo->prepare('INSERT INTO users(tenant_id,name,email,password,password_hash,role,is_active,is_global_admin)
   VALUES(2002,"Synthetic contact reviewer",?,?,?,"admin",1,0)')->execute([$email,$hash,$hash]);
  $actor=(int)$pdo->lastInsertId();
  $membership=(int)provisionMembership($actor,2002,'admin',['is_primary'=>true,'persona_label'=>'Synthetic contact reviewer','status'=>'active'])['membership_id'];
  RBACResolver::grantModule($membership,'integrations','admin',[2002],4);
  RBACResolver::grantModule($membership,'people','write',[2002],4);
  $record=['user_id'=>$actor,'membership_id'=>$membership,'tenant_id'=>2002,'email'=>$email,
   'password'=>$password,'person_before'=>$person,'worker_id'=>$worker,'created_at'=>gmdate('c'),'retired'=>false];
  $mask=umask(0077);try{$f=fopen($file,'x');}finally{umask($mask);}
  if(!$f)throw new RuntimeException('Private reviewer receipt unavailable');
  try{if(fwrite($f,json_encode($record,JSON_THROW_ON_ERROR))===false||!fflush($f)||!fsync($f))throw new RuntimeException('Private receipt incomplete');}finally{fclose($f);}
  $pdo->commit();
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
 unset($password,$hash);
}
if(!$record||$record['tenant_id']!==2002)throw new RuntimeException('Reviewer receipt missing');
$q=$pdo->prepare('SELECT id,tenant_id,email,is_active,is_global_admin FROM users WHERE id=? AND tenant_id=2002 AND email=?');
$q->execute([$record['user_id'],$record['email']]);$actor=$q->fetch(PDO::FETCH_ASSOC);
if(!$actor||(int)$actor['is_global_admin']!==0)throw new RuntimeException('Reviewer identity differs');
if($mode==='--credentials'){
 if($record['retired']||(int)$actor['is_active']!==1||!isset($record['password']))exit(2);
 echo json_encode(['email'=>$record['email'],'password'=>$record['password']],JSON_THROW_ON_ERROR);exit;
}
if($mode==='--retire'){
 $pdo->beginTransaction();
 try{
  foreach(['integrations','people'] as $module)RBACResolver::revokeModule($record['membership_id'],$module,4);
  deactivateMembership($record['user_id'],2002);
  $pdo->prepare('UPDATE users SET is_active=0,password=NULL,password_hash=NULL WHERE id=? AND tenant_id=2002 AND email=?')
   ->execute([$record['user_id'],$record['email']]);
  $pdo->commit();
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
 $q=$pdo->prepare("SELECT COUNT(*) FROM users u JOIN tenant_memberships tm ON tm.user_id=u.id AND tm.tenant_id=u.tenant_id
  JOIN user_tenants ut ON ut.user_id=u.id AND ut.tenant_id=u.tenant_id
  WHERE u.id=? AND u.tenant_id=2002 AND u.is_active=0 AND u.password IS NULL AND u.password_hash IS NULL
  AND tm.id=? AND tm.status='revoked' AND ut.status='inactive'");
 $q->execute([$record['user_id'],$record['membership_id']]);
 if((int)$q->fetchColumn()!==1)throw new RuntimeException('Reviewer retirement not verified');
 $q=$pdo->prepare('SELECT COUNT(*) FROM membership_module_access WHERE membership_id=?');$q->execute([$record['membership_id']]);
 if((int)$q->fetchColumn()!==0)throw new RuntimeException('Reviewer grants remain');
 $record['retired']=true;$record['retired_at']=gmdate('c');unset($record['password']);
 file_put_contents($file,json_encode($record,JSON_THROW_ON_ERROR));
}
echo json_encode(['user_id'=>$record['user_id'],'membership_id'=>$record['membership_id'],'tenant_id'=>2002,
 'retired'=>$record['retired'],'credential_returned'=>false,'production_changed'=>false,
 'person_id'=>358,'worker_id'=>$record['worker_id']],JSON_THROW_ON_ERROR)."\n";
