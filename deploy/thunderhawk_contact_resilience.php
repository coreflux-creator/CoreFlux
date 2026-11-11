<?php
/** Process-local fault injection against installed isolated services; never a web endpoint. */
declare(strict_types=1);
$root='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
$private=dirname($root).'/private_html/thunderhawk-pilot-20261030';
if(PHP_SAPI!=='cli'||getenv('COREFLUX_ENV')!=='staging'||realpath(getcwd())!==$root||posix_geteuid()!==1003)exit(2);
require_once $root.'/core/quanta/profile_return_delivery.php';
require_once $root.'/core/rbac/permissions.php';
$pdo=getDB();
if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||!COREFLUX_STAGING
 ||integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf'||quantaApiBase()!=='https://helloquanta.app:19443/api/v1'
 ||!quantaProfileReturnEnabled(2002)||quantaProfileReturnEnabled(2))exit(2);
$actor=json_decode(file_get_contents($private.'/contact-reviewer-resilience-20261111.private.json'),true,32,JSON_THROW_ON_ERROR);
$user=$pdo->query('SELECT id,tenant_id,is_active,is_global_admin FROM users WHERE id='.(int)$actor['user_id'])->fetch(PDO::FETCH_ASSOC);
if(!$user||$actor['retired']||(int)$user['tenant_id']!==2002||(int)$user['is_active']!==1||(int)$user['is_global_admin']!==0
 ||!RBACResolver::can((int)$user['id'],2002,'integrations','admin',2002)
 ||!RBACResolver::can((int)$user['id'],2002,'people','write',2002))exit(2);
$input=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);$mode=$input['mode']??'';
$id=$input['change_id']??null;$uid=(int)$user['id'];
if($id!==null&&(!is_string($id)||!preg_match('/^cpc_[a-f0-9]{64}$/D',$id)))exit(2);
$original=$actor['person_before']['phone_primary'];
$phones=['original'=>$original,'missing'=>'+1 202 555 0190','conflict'=>'+1 202 555 0188','newer'=>'+1 202 555 0189','offboarding'=>'+1 202 555 0187'];
$native=function()use($pdo,$actor):array{
 $p=$pdo->query('SELECT id,tenant_id,status,first_name,last_name,email_primary,phone_primary FROM people WHERE tenant_id=2002 AND id=358 AND deleted_at IS NULL')->fetch(PDO::FETCH_ASSOC);
 return ['original_contacts'=>$p===$actor['person_before'],'phone_sha256'=>hash('sha256',(string)$p['phone_primary']),
  'worker_link_unchanged'=>quantaProfileLinkedWorker($pdo,2002,358,false)===$actor['worker_id']];
};
$http=function(string $method,string $url,array $headers,string $body):array{
 if(!str_starts_with($url,'https://helloquanta.app:19443/api/v1/'))throw new RuntimeException('Non-pilot destination');
 $c=curl_init($url);curl_setopt_array($c,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_POSTFIELDS=>$body,
  CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>25,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
 $raw=curl_exec($c);$status=(int)curl_getinfo($c,CURLINFO_HTTP_CODE);curl_close($c);
 if($raw===false)throw new RuntimeException('Pilot transport failed');return ['status'=>$status,'body'=>$raw];
};
$out=['mode'=>$mode,'tenant_id'=>2002,'actor_id'=>$uid,'scope'=>'installed isolated services'];
if($mode==='baseline'){
 $r=quantaProfileBaselinePreview(2002,$uid,358);
 if($r['status']!=='unchanged')$r=quantaProfileBaselineSend(2002,$uid,(int)$r['delivery']['id'],$r['delivery']['review_hash']);
 $out['state']=$r['state']??$r['status'];
}elseif($mode==='review'){
 if(!$id||!isset($phones[$input['phone']??'']))exit(2);
 $r=quantaProfileReviewPreview(2002,$uid,358,$id);
 if($r['proposal']['changed_fields']!==['phone_primary']||$r['proposal']['proposed']['phone_primary']!==$phones[$input['phone']]
  ||$r['comparison']['state']==='conflict')throw new RuntimeException('Proposal differs from reviewed pilot change');
 $d=quantaProfileReviewApply(2002,$uid,$r['review_token'],'accepted','Isolated contact resilience verification.');
 $out+=['change_id'=>$id,'decision'=>$d['decision'],'changed_fields'=>$d['changed_fields']];
}elseif(in_array($mode,['send','resume','inspect'],true)){
 if(!$id)exit(2);$fault=$input['fault']??null;
 $owned=$pdo->prepare('SELECT COUNT(*) FROM quanta_profile_decisions WHERE tenant_id=2002 AND person_id=358 AND actor_id=? AND worker_id=? AND change_id=?');
 $owned->execute([$uid,$actor['worker_id'],$id]);if((int)$owned->fetchColumn()!==1)exit(2);
 if($fault!==null&&!in_array($fault,['before_arrival','lost_ack'],true))exit(2);
 $writes=0;$arrivals=0;$receipt=null;
 if($fault!==null)$GLOBALS['__quanta_core_write_transport']=static function($method,$url,$headers,$json)use($id,$fault,$http,&$writes,&$arrivals,&$receipt){
  if($method!=='PUT'||!str_ends_with($url,'/profile-changes/'.$id.'/decision'))throw new RuntimeException('Unexpected fault target');
  $writes++;if($fault==='before_arrival')throw new RuntimeException('Injected interruption before arrival');
  $r=$http($method,$url,$headers,$json);if($r['status']!==200)throw new RuntimeException('Pilot receiver rejected request');
  $receipt=json_decode($r['body'],true,64,JSON_THROW_ON_ERROR);$arrivals++;
  throw new RuntimeException('Injected loss of successful receiver acknowledgement');
 };
 try{$out['result']=$mode==='inspect'?quantaProfileReturnStatus(2002,$uid,$id):quantaProfileReturnSend(2002,$uid,$id,$mode==='resume');}
 catch(Throwable $e){$out['refused']=true;$out['error_class']=get_class($e);$out['error']=$e->getMessage();}
 unset($GLOBALS['__quanta_core_write_transport']);
 $q=$pdo->prepare('SELECT state,attempt_count,body_hash,receipt_hash,error_code FROM quanta_profile_returns WHERE tenant_id=2002 AND change_id=?');$q->execute([$id]);
 $out+=['change_id'=>$id,'fault'=>$fault,'fault_target_calls'=>$writes,'confirmed_provider_arrivals'=>$arrivals,
  'lost_ack_receiver_outcome'=>$receipt['outcome']['contact_state']??null,'local'=>$q->fetch(PDO::FETCH_ASSOC)?:null];
}elseif($mode==='worker_patch'){
 $kind=$input['kind']??'';
 $patch=match($kind){'newer'=>['phone'=>$phones['newer']],'restore'=>['phone'=>$original],
  'disable'=>['active'=>false],'enable'=>['active'=>true],default=>throw new RuntimeException('Unreviewed worker change')};
 $key=quantaApiKey(2002);$url=quantaApiBase().'/workers/'.$actor['worker_id'];
 $r=$http('PATCH',$url,['Authorization: Bearer '.$key,'Content-Type: application/json','Accept: application/json',
  'Idempotency-Key: contact-resilience-20261111-'.$kind],json_encode($patch,JSON_THROW_ON_ERROR));
 if($r['status']!==200)throw new RuntimeException('Isolated worker patch refused: '.$r['status']);
 $out+=['patch'=>$kind,'http_status'=>$r['status']];
}elseif($mode!=='inspect_native')throw new RuntimeException('Unknown isolated check');
$out['native']=$native();
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
