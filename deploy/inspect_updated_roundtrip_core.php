<?php
/** Native import boundary for the exact new synthetic source; never reopens existing time. */
declare(strict_types=1);
const ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const PRIVATE_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030';
const SOURCE='5e72afa5-07b9-4681-bcc0-7e46379a205c';
if(PHP_SAPI!=='cli'||posix_geteuid()!==1003||getcwd()!==ROOT||getenv('COREFLUX_ENV')!=='staging'
 ||realpath(__DIR__)!==__DIR__||dirname(__DIR__)!==PRIVATE_ROOT||($argv[1]??'')!=='--inspect-synthetic-import')exit(2);
$manifest=json_decode(file_get_contents(__DIR__.'/manifest.json'),true,512,JSON_THROW_ON_ERROR);
if(hash_file('sha256',__DIR__.'/manifest.json')!==($argv[2]??'')||hash_file('sha256',__FILE__)!==$manifest['verifier_sha256'])exit(2);
foreach($manifest['pins']as$file=>$hash)if(hash('sha256',str_replace("\r\n","\n",file_get_contents(ROOT.'/'.$file)))!==$hash)throw new RuntimeException('Runtime changed: '.$file);
require ROOT.'/core/db.php';require_once ROOT.'/core/tenant_scope.php';require_once ROOT.'/core/memberships.php';
require_once ROOT.'/core/rbac/permissions.php';require_once ROOT.'/core/integrations/identity.php';require_once ROOT.'/modules/time/lib/time.php';
$pdo=getDB();
if(!COREFLUX_STAGING||DB_NAME!=='muzqvdvqbx'||integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf'
 ||COREFLUX_INTEGRATION_DELIVERY_TENANTS!=='2002'||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=2002')->fetchColumn()!==1)exit(2);
setRequestTenantId(2002);setRequestModuleScope('time');
$mask=umask(0077);$lock=fopen(PRIVATE_ROOT.'/contact-install.lock','c');umask($mask);
if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)||file_exists(__DIR__.'/result.json'))exit(2);
$report=['status'=>'running','pid'=>getmypid(),'started_at'=>gmdate('c'),'checks'=>[],'requests'=>[],
 'passed'=>false,'import_completed'=>false,'browser_test'=>false,'production_changed'=>false,'arabella_changed'=>false,'financial_execution'=>false];
$save=static function()use(&$report):void{$mask=umask(0077);try{
 $f=fopen(__DIR__.'/result.next','wb');$b=json_encode($report,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT);
 if(!$f||fwrite($f,$b)!==strlen($b)||!fflush($f)||!fsync($f))throw new RuntimeException('Receipt failed');fclose($f);
 if(!rename(__DIR__.'/result.next',__DIR__.'/result.json'))throw new RuntimeException('Receipt activation failed');
}finally{umask($mask);}};
$expect=static function(bool$ok,string$label)use(&$report,$save):void{if(!$ok)throw new RuntimeException($label);$report['checks'][]=$label;$save();};
$snapshot=static function()use($pdo):array{$out=[];foreach($pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN)as$t){
 if(!preg_match('/^[A-Za-z0-9_]+$/D',$t))throw new RuntimeException('Invalid table');$hashes=[];
 foreach($pdo->query('SELECT * FROM `'.$t.'`')->fetchAll(PDO::FETCH_ASSOC)as$r)$hashes[]=hash('sha256',serialize($r));sort($hashes);$out[$t]=array_count_values($hashes);
}ksort($out);return$out;};
$before=$snapshot();$cookie=null;$actor=$membership=null;$modules=['people'=>'read','placements'=>'read','time'=>'admin','integrations'=>'admin','accounting'=>'admin'];$save();
$request=static function(string$method,string$path,?array$body=null,int$tenant=2002,bool$form=false)use(&$cookie,&$report,$save):array{
 $route=parse_url($path,PHP_URL_PATH);if(!in_array($route,['/login.php','/logout.php','/api/quanta.php'],true)||!in_array($method,['GET','POST'],true)||!in_array($tenant,[2002,2],true))throw new RuntimeException('Unreviewed request');
 $h=['Accept: application/json','X-CoreFlux-Tenant-Id: '.$tenant];$c=curl_init('https://phpstack-1516771-6707602.cloudwaysapps.com'.$path);
 $options=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>40,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2];
 if($body!==null){$options[CURLOPT_POSTFIELDS]=$form?http_build_query($body):json_encode((object)$body,JSON_THROW_ON_ERROR);$h[]='Content-Type: '.($form?'application/x-www-form-urlencoded':'application/json');}
 if($cookie){$options[CURLOPT_COOKIEFILE]=$cookie;$options[CURLOPT_COOKIEJAR]=$cookie;}$options[CURLOPT_HTTPHEADER]=$h;curl_setopt_array($c,$options);
 $raw=curl_exec($c);$status=(int)curl_getinfo($c,CURLINFO_HTTP_CODE);$location=(string)curl_getinfo($c,CURLINFO_REDIRECT_URL);curl_close($c);
 $report['requests'][]=['method'=>$method,'path'=>$path,'status'=>$status,'tenant'=>$tenant];$save();
 if($raw===false)throw new RuntimeException('Outcome uncertain; inspect the same operation');
 $decoded=json_decode($raw,true);if($status>=400){$mask=umask(0077);try{file_put_contents(__DIR__.'/response-'.count($report['requests']).'.private.json',$raw);}finally{umask($mask);}}
 return[$status,$decoded,$location];
};
try{
 $bounds=timeWeekBounds('2026-11-14');$report['core_week']=$bounds;$report['quanta_week']=['2026-11-14','2026-11-20'];
 $expect($bounds===['2026-11-09','2026-11-15'],'Observed Core calendar differs from the source week');
 $q=$pdo->prepare('SELECT id,status,period_start,period_end,total_hours FROM staffing_timesheets WHERE tenant_id=2002 AND person_id=387 AND period_start=?');$q->execute([$bounds[0]]);$header=$q->fetch(PDO::FETCH_ASSOC);
 $expect($header&&(int)$header['id']===8&&$header['status']==='approved','Existing Core week is approved');$report['existing_header']=$header;
 $q=$pdo->prepare('SELECT COUNT(*) FROM quanta_time_imports WHERE tenant_id=2002 AND quanta_entry_id=?');$q->execute([SOURCE]);$expect((int)$q->fetchColumn()===0,'New source has not been imported');
 $email='roundtrip-period-20261114-'.bin2hex(random_bytes(4)).'@example.invalid';$password=bin2hex(random_bytes(32));$hash=password_hash($password,PASSWORD_DEFAULT);
 $pdo->beginTransaction();try{
  $pdo->prepare('INSERT INTO users(tenant_id,name,email,password,password_hash,role,is_active,is_global_admin) VALUES(2002,"Synthetic period reviewer",?,?,?,"admin",1,0)')->execute([$email,$hash,$hash]);$actor=(int)$pdo->lastInsertId();
  $membership=(int)provisionMembership($actor,2002,'admin',['is_primary'=>true,'status'=>'active'])['membership_id'];
  foreach($modules as$m=>$level)RBACResolver::grantModule($membership,$m,$level,[2002],4);$pdo->commit();
 }catch(Throwable$e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}
 $report['actor_id']=$actor;$report['membership_id']=$membership;$save();$cookie=tempnam(__DIR__,'session-');chmod($cookie,0600);
 [$status,,$location]=$request('POST','/login.php',['username'=>$email,'password'=>$password,'redirect'=>'spa'],2002,true);unset($password,$hash);
 $expect($status===302&&!str_contains($location,'error='),'Native synthetic operator signed in');
 $window=['changed_since'=>'2026-11-14','source_status'=>'approved'];
 $expect($request('POST','/api/quanta.php?action=preview',$window,2)[0]===403,'Real Thunderhawk company access is denied');
 [$status,$preview]=$request('POST','/api/quanta.php?action=preview',$window);
 $rows=array_values(array_filter($preview['rows']??[],static fn($r)=>($r['id']??null)===SOURCE));$row=$rows[0]??[];
 $expect($status===200&&count($rows)===1&&($row['person_number']??null)==='P-387'&&($row['placement_number']??null)==='PL-902'&&(float)($row['hours']??0)===1.25,'Native preview finds the new source with original worker and assignment identities');
 $report['preview_status']=$row['status']??null;$report['preview_message']=$row['message']??null;$save();
 [$status,$result]=$request('POST','/api/quanta.php?action=import',$window+['entry_ids'=>[SOURCE]]);
 $report['import_status']=$status;$report['import_response']=$result;$report['import_completed']=$status===200;$save();
 $expect($status===409&&str_contains(json_encode($result),'already approved'),'Native import refuses the previously approved Core week');
 $q=$pdo->prepare('SELECT COUNT(*) FROM quanta_time_imports WHERE tenant_id=2002 AND quanta_entry_id=?');$q->execute([SOURCE]);
 $expect((int)$q->fetchColumn()===0,'Refused import leaves no partial source record');
 $report['passed']=true;$report['finding']='Calendar mismatch: preview does not establish that the destination week can accept the source.';
}catch(Throwable$e){$report['failure_class']=get_class($e);$report['failure']=$e->getMessage();$report['failure_line']=$e->getLine();}
finally{
 if($pdo->inTransaction())$pdo->rollBack();
 if($cookie){try{$request('GET','/logout.php');}catch(Throwable$e){$report['logout_failure']=true;}$report['cookie_removed']=is_file($cookie)?unlink($cookie):true;}
 if($actor&&$membership){try{
  $pdo->beginTransaction();foreach($modules as$m=>$level)RBACResolver::revokeModule($membership,$m,4);deactivateMembership($actor,2002);
  $pdo->prepare('UPDATE users SET is_active=0,password=NULL,password_hash=NULL WHERE tenant_id=2002 AND id=?')->execute([$actor]);$pdo->commit();$report['actor_retired']=true;
 }catch(Throwable$e){if($pdo->inTransaction())$pdo->rollBack();$report['passed']=false;$report['retirement_failure']=$e->getMessage();}}
}
try{
 $after=$snapshot();$expect(array_keys($before)===array_keys($after),'No schema changed');
 foreach($before as$t=>$hashes)foreach($hashes as$h=>$n)if(($after[$t][$h]??0)<$n)throw new RuntimeException('Original row changed in '.$t);
 $expect(true,'Every pre-existing Core row is unchanged');
 foreach(['time_entries','staffing_timesheets','quanta_time_imports','payroll_source_claims','payroll_source_authorities','payroll_provider_results','accounting_events','accounting_journal_entries','accounting_journal_entry_lines','ap_payments','billing_payments']as$t)$expect($before[$t]===$after[$t],'No change in '.$t);
 $q=$pdo->prepare('SELECT is_active,password,password_hash FROM users WHERE tenant_id=2002 AND id=?');$q->execute([$actor]);$a=$q->fetch(PDO::FETCH_ASSOC);
 $q=$pdo->prepare('SELECT COUNT(*) FROM membership_module_access WHERE membership_id=?');$q->execute([$membership]);
 $expect($a&&(int)$a['is_active']===0&&$a['password']===null&&$a['password_hash']===null&&(int)$q->fetchColumn()===0,'Temporary access and credentials retired');
}catch(Throwable$e){$report['passed']=false;$report['preservation_failure']=$e->getMessage();}
$report['status']='finished';$report['completed_at']=gmdate('c');$save();echo json_encode($report,JSON_THROW_ON_ERROR)."\n";exit($report['passed']?0:1);
