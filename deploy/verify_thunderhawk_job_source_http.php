<?php
/** Fresh isolated company and native job ownership review; no provider or financial execution. */
declare(strict_types=1);
const PILOT_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const PILOT_PRIVATE='/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030';
if(PHP_SAPI!=='cli'||getcwd()!==PILOT_ROOT||posix_geteuid()!==1003||getenv('COREFLUX_ENV')!=='staging'
 ||realpath(__DIR__)!==__DIR__||dirname(__DIR__)!==PILOT_PRIVATE||($argv[1]??'')!=='--synthetic-native-review')exit(2);
$input=json_decode(file_get_contents(__DIR__.'/manifest.json'),true,512,JSON_THROW_ON_ERROR);
if(hash_file('sha256',__DIR__.'/manifest.json')!==($argv[2]??'')||hash_file('sha256',__FILE__)!==$input['verifier_sha256']
 ||!str_starts_with($input['installation_directory'],PILOT_PRIVATE.'/job-source-')
 ||realpath($input['installation_directory'])!==$input['installation_directory'])exit(2);
$installed=json_decode(file_get_contents($input['installation_directory'].'/installation-state.json'),true,512,JSON_THROW_ON_ERROR);
if($installed['phase']!=='completed'||$installed['candidate']!=='ff88d483858c58f971694dd9263c2e8fc30da12e'
 ||$installed['package_sha256']!==$input['package_sha256']
 ||hash_file('sha256',$input['installation_directory'].'/job-source-application-package.json')!==$input['package_sha256'])exit(2);
$package=json_decode(file_get_contents($input['installation_directory'].'/job-source-application-package.json'),true,512,JSON_THROW_ON_ERROR);
if(hash_file('sha256',dirname(PILOT_ROOT).'/private_html/coreflux.thunderhawk-pilot.php')!==$package['feature_sha256'])exit(2);
foreach($package['files']as$f=>$e)if(hash_file('sha256',PILOT_ROOT.'/'.$f)!==$e['sha256'])exit(2);
foreach($package['dependencies']as$f=>$h)if(hash('sha256',str_replace("\r\n","\n",file_get_contents(PILOT_ROOT.'/'.$f)))!==$h)exit(2);
require_once PILOT_ROOT.'/modules/staffing/lib/jobs.php';
require_once PILOT_ROOT.'/modules/staffing/lib/job_write.php';
require_once PILOT_ROOT.'/core/memberships.php';
require_once PILOT_ROOT.'/core/rbac/permissions.php';
require_once PILOT_ROOT.'/core/integrations/identity.php';
$pdo=getDB();
if(!$pdo||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||!COREFLUX_STAGING
 ||integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf'||COREFLUX_INTEGRATION_DELIVERY_TENANTS!=='2002'
 ||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=2002')->fetchColumn()!==1)exit(2);
$mask=umask(0077);$lock=fopen(PILOT_PRIVATE.'/contact-install.lock','c');umask($mask);
if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)||file_exists(__DIR__.'/result.json'))exit(2);
$report=['status'=>'running','pid'=>getmypid(),'started_at'=>gmdate('c'),'candidate'=>$installed['candidate'],
 'checks'=>[],'requests'=>[],'actors'=>[],'passed'=>false,'browser_test'=>false,'provider_requests'=>0,
 'production_changed'=>false,'arabella_changed'=>false,'real_payroll_payments_postings'=>false];
$save=static function()use(&$report):void{
 $mask=umask(0077);try{$s=fopen(__DIR__.'/result.next','wb');if(!$s)throw new RuntimeException('Receipt unavailable');
 $b=json_encode($report,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT);if(fwrite($s,$b)!==strlen($b)||!fflush($s)||!fsync($s))throw new RuntimeException('Receipt persistence failed');fclose($s);
 if(!rename(__DIR__.'/result.next',__DIR__.'/result.json'))throw new RuntimeException('Receipt activation failed');}finally{umask($mask);}
};
$save();$tenant=null;$actors=[];$cookies=[];$modules=['people','placements','staffing','integrations'];
$expect=static function(bool $ok,string $label)use(&$report,$save):void{if(!$ok)throw new RuntimeException($label);$report['checks'][]=$label;$save();};
$insert=static function(string $table,array $row)use($pdo):int{
 $pdo->prepare('INSERT INTO '.$table.' (`'.implode('`,`',array_keys($row)).'`) VALUES ('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row));return(int)$pdo->lastInsertId();
};
$one=static function(string $sql,array $args=[])use($pdo):array{$q=$pdo->prepare($sql);$q->execute($args);return$q->fetch(PDO::FETCH_ASSOC)?:[];};
$snapshot=static function()use($pdo):array{
 $result=[];foreach($pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN)as$t){
 if(!preg_match('/^[A-Za-z0-9_]+$/D',$t))throw new RuntimeException('Unexpected table');$hashes=[];
 foreach($pdo->query('SELECT * FROM `'.$t.'`')as$row)$hashes[]=hash('sha256',serialize($row));sort($hashes);$result[$t]=array_count_values($hashes);
 }ksort($result);return$result;
};
$before=$snapshot();$mask=umask(0077);try{file_put_contents(__DIR__.'/baseline.private.json',json_encode($before,JSON_THROW_ON_ERROR));}finally{umask($mask);}
$request=static function(string $actor,string $method,string $path,?array $body=null,?int $pin=null,bool $form=false)use(&$cookies,&$report,&$tenant,$save):array{
 $c=curl_init('https://phpstack-1516771-6707602.cloudwaysapps.com'.$path);$headers=['Accept: application/json','X-CoreFlux-Tenant-Id: '.($pin??$tenant)];
 $options=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>40,
 CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2];
 if($body!==null){$options[CURLOPT_POSTFIELDS]=$form?http_build_query($body):json_encode((object)$body,JSON_THROW_ON_ERROR);$headers[]='Content-Type: '.($form?'application/x-www-form-urlencoded':'application/json');}
 if(isset($cookies[$actor])){$options[CURLOPT_COOKIEFILE]=$cookies[$actor];$options[CURLOPT_COOKIEJAR]=$cookies[$actor];}
 $options[CURLOPT_HTTPHEADER]=$headers;curl_setopt_array($c,$options);$raw=curl_exec($c);$status=(int)curl_getinfo($c,CURLINFO_HTTP_CODE);$location=(string)curl_getinfo($c,CURLINFO_REDIRECT_URL);curl_close($c);
 $report['requests'][]=['actor'=>$actor,'method'=>$method,'path'=>$path,'status'=>$status,'tenant'=>$pin??$tenant];$save();
 if($raw!==false&&$status>=400){$mask=umask(0077);try{file_put_contents(__DIR__.'/response-'.count($report['requests']).'.private.txt',$raw);}finally{umask($mask);}}
 if($raw===false)throw new RuntimeException('HTTP outcome unconfirmed; inspect receipt without repeating');
 return[$status,json_decode($raw,true),$location];
};
try{
 $marker='Synthetic job source review '.bin2hex(random_bytes(6));$report['marker']=$marker;$save();
 $pdo->beginTransaction();
 try{
  $tenant=$insert('tenants',['name'=>$marker,'parent_id'=>2002,'tenant_type'=>'sub','is_active'=>1,'is_simulation'=>1]);$report['tenant_id']=$tenant;
  foreach(['companies',...$modules]as$module)$insert('tenant_module_scope',['tenant_id'=>$tenant,'module_key'=>$module,'scope_mode'=>'isolated']);
  setRequestTenantId($tenant);setRequestModuleScope('staffing');$secrets=[];
  foreach(['editor','viewer']as$name){$email='job-'.$name.'-'.bin2hex(random_bytes(8)).'@example.invalid';$password=bin2hex(random_bytes(32));$hash=password_hash($password,PASSWORD_DEFAULT);
   $id=$insert('users',['tenant_id'=>$tenant,'name'=>$marker.' '.$name,'email'=>$email,'password'=>$hash,'password_hash'=>$hash,'role'=>'tenant_admin','is_active'=>1,'is_global_admin'=>0]);
   $mid=(int)provisionMembership($id,$tenant,'tenant_admin',['is_primary'=>true,'status'=>'active'])['membership_id'];
   foreach($modules as$module)RBACResolver::grantModule($mid,$module,$name==='editor'?'write':'read',[$tenant],$id);
   $actors[$name]=['user_id'=>$id,'membership_id'=>$mid];$secrets[$name]=[$email,$password];
  }
  $report['actors']=$actors;$save();$editor=$actors['editor']['user_id'];$pdo->commit();
 }catch(Throwable$e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}
 foreach($secrets as$name=>[$email,$password]){$cookies[$name]=tempnam(__DIR__,'session-');chmod($cookies[$name],0600);
  [$status,,$location]=$request($name,'POST','/login.php',['username'=>$email,'password'=>$password,'redirect'=>'spa'],null,true);
  $expect($status===302&&!str_contains($location,'error='),'Native sign-in succeeds for '.$name);
 }unset($secrets,$password,$hash);
 $url='/modules/staffing/api/jobs.php?action=';
 $get=static function(int $id,string $actor='editor')use($request,$expect,$url):array{[$status,$detail]=$request($actor,'GET',$url.'get&id='.$id);$expect($status===200,'Native job detail is readable by '.$actor);return$detail;};
 $read=static fn(int $id):array=>$one('SELECT * FROM staffing_jobs WHERE id=? AND tenant_id=?',[$id,$tenant]);
 [$status,$manual]=$request('editor','POST',$url.'create',['title'=>'Synthetic manual role','status'=>'open','location_country'=>'US']);
 $expect($status===200&&(int)($manual['job']['id']??0)>0,'Native API creates a synthetic manual job');$manualId=(int)$manual['job']['id'];$report['manual_job_id']=$manualId;$save();
 $detail=$get($manualId);$oldRevision=$detail['job']['edit_revision'];
 $expect($request('editor','POST',$url.'update',['id'=>$manualId,'title'=>'Missing version'])[0]===409,'Native edit refuses a missing displayed version');
 $expect($request('editor','POST',$url.'close',['id'=>$manualId,'edit_revision'=>$oldRevision])[0]===200&&$read($manualId)['status']==='closed','Native closure records the reviewed status');
 $expect($request('editor','POST',$url.'update',['id'=>$manualId,'title'=>'Stale version','edit_revision'=>$oldRevision])[0]===409,'Native edit refuses the version preceding closure');

 $external='synthetic-job-source-'.$tenant;
 $source=staffingJobEnsureFromJobDivaPayload($tenant,$external,['title'=>'Original source role','status'=>'active','city'=>'Hamilton','country'=>'United States'],$editor);$job=(int)$source['id'];
 mappingBindStable($tenant,'jobdiva','staffing_job',$external,$job,['title'=>'Original source role'],'pull',$editor);
 $report['source_job_id']=$job;$report['synthetic_provider_id']=$external;$save();
 $detail=$get($job);$expect($detail['job']['location_country']==='US'&&$detail['job']['external_id']===$external&&!array_key_exists('coreflux_source_state',$detail['job']),'Country normalization and exact provider number survive without leaking private ownership');
 $revision=$detail['job']['edit_revision'];
 $expect($request('editor','POST',$url.'update',['id'=>$job,'title'=>'Core reviewed role','status'=>'closed','location_city'=>'','edit_revision'=>$revision])[0]===200,'Native edit claims title, closed status and intentional city clear');
 $incoming=['title'=>'Incoming source role','status'=>'active','city'=>'Princeton','department'=>'Engineering'];
 staffingJobEnsureFromJobDivaPayload($tenant,$external,$incoming,$editor);$detail=$get($job);$review=$detail['source_review'];
 $expect($detail['job']['title']==='Core reviewed role'&&$detail['job']['status']==='closed'&&$detail['job']['location_city']===null&&$detail['job']['department']==='Engineering','Source refresh preserves Core edits and clear while updating an unowned field');
 $expect($review['proposed']['title']==='Incoming source role'&&$review['proposed']['status']==='active'&&$review['proposed']['location_city']==='Princeton','Incoming values remain available for explicit review');
 $decision=['id'=>$job,'fields'=>['title'],'mode'=>'use_once','review_token'=>$review['review_token'],'confirm_source'=>true];
 $viewer=$get($job,'viewer');$expect($viewer['can_manage']===false&&$viewer['source_review']===null,'Read-only user receives no source decision payload');
 $expect($request('viewer','POST',$url.'source_decide',$decision)[0]===403,'Read-only user cannot apply a source decision');
 $expect($request('anonymous','POST',$url.'source_decide',$decision)[0]===401,'Anonymous source decision is refused');
 $expect($request('editor','GET',$url.'get&id='.$job,null,2002)[0]===403,'Synthetic editor cannot change to the existing parent company');
 $expect($request('editor','GET',$url.'source_decide')[0]===405,'Source decision requires POST');
 $expect($request('editor','POST',$url.'source_decide',array_replace($decision,['confirm_source'=>false]))[0]===422,'Source decision requires explicit confirmation');
 $expect($request('editor','POST',$url.'source_decide',array_replace($decision,['review_token'=>str_repeat('0',64)]))[0]===409,'Stale source review cannot be applied');
 [$status,$accepted]=$request('editor','POST',$url.'source_decide',$decision);
 $expect($status===200&&$accepted['replayed']===false&&$read($job)['title']==='Incoming source role','Use once applies exactly the reviewed source title');
 [$status,$replayed]=$request('editor','POST',$url.'source_decide',$decision);
 $expect($status===200&&$replayed['replayed']===true,'Exact source decision retry returns its original receipt');
 $expect($request('editor','POST',$url.'source_decide',array_replace($decision,['mode'=>'follow_source']))[0]===409,'Prior review cannot authorize a different ownership mode');
 $audit=$one('SELECT meta_json FROM audit_log WHERE tenant_id=? AND target_id=? AND event="staffing.job.source_reviewed" ORDER BY id DESC LIMIT 1',[$tenant,$job]);$meta=json_decode($audit['meta_json'],true,512,JSON_THROW_ON_ERROR);
 $expect($meta['previous_values']===['title'=>'Core reviewed role']&&$meta['observed_values']===['title'=>'Incoming source role']&&$meta['source']['external_id']===$external,'Durable receipt retains original and selected source values with exact provider identity');
 staffingJobEnsureFromJobDivaPayload($tenant,$external,['title'=>'Next source role'],$editor);$detail=$get($job);
 $expect($detail['job']['title']==='Incoming source role'&&$detail['job']['status']==='closed'&&$detail['job']['location_country']==='US','Use-once ownership and omitted source fields preserve current values');
 $decision=['id'=>$job,'fields'=>['title'],'mode'=>'follow_source','review_token'=>$detail['source_review']['review_token'],'confirm_source'=>true];
 $expect($request('editor','POST',$url.'source_decide',$decision)[0]===200,'Reviewer can explicitly follow future title updates');
 staffingJobEnsureFromJobDivaPayload($tenant,$external,['title'=>'Following source role'],$editor);$detail=$get($job);
 $expect($detail['job']['title']==='Following source role','A later source update follows the selected ownership');
 $expect($request('editor','POST',$url.'update',['id'=>$job,'title'=>'Final Core role','edit_revision'=>$detail['job']['edit_revision']])[0]===200,'Native edit takes ownership again');
 staffingJobEnsureFromJobDivaPayload($tenant,$external,['title'=>'Final held source role'],$editor);$detail=$get($job);
 $expect($detail['job']['title']==='Final Core role'&&$detail['source_review']['proposed']['title']==='Final held source role','Later source changes are held after Core reclaims title');
 $decision=['id'=>$job,'fields'=>['location_city'],'mode'=>'keep_core','review_token'=>$detail['source_review']['review_token'],'confirm_source'=>true];
 $expect($request('editor','POST',$url.'source_decide',$decision)[0]===200&&$read($job)['location_city']===null,'Keep Core explicitly retains an intentional empty city');
 $replayBefore=$read($job);staffingJobEnsureFromJobDivaPayload($tenant,$external,['title'=>'Final held source role'],$editor);
 $expect($read($job)===$replayBefore,'Repeated source observation changes no job values or ownership revision');

 $legacyExternal='synthetic-legacy-job-'.$tenant;
 $legacy=$insert('staffing_jobs',['tenant_id'=>$tenant,'title'=>'Original legacy job','status'=>'closed','source_system'=>'jobdiva','external_id'=>$legacyExternal,'location_country'=>'CA']);
 mappingBindStable($tenant,'jobdiva','staffing_job',$legacyExternal,$legacy,['title'=>'Original legacy job'],'pull',$editor);$report['legacy_job_id']=$legacy;$save();
 $detail=$get($legacy);$expect($detail['job']['external_id']===$legacyExternal&&$detail['source_review']['source']===null,'Legacy exact mapped source number is visible without granting source ownership');
 staffingJobEnsureFromJobDivaPayload($tenant,$legacyExternal,['title'=>'New legacy source proposal','status'=>'active','country'=>'United States'],$editor);$detail=$get($legacy);
 $expect($detail['job']['title']==='Original legacy job'&&$detail['job']['status']==='closed'&&$detail['job']['location_country']==='CA','First legacy refresh preserves all Core values and offers proposals');
 $report['passed']=true;
}catch(Throwable$e){$report['failure_class']=get_class($e);$report['failure']=$e->getMessage();$report['failure_line']=$e->getLine();}
finally{
 if($pdo->inTransaction())$pdo->rollBack();
 foreach($cookies as$name=>$cookie){try{$request($name,'GET','/logout.php');}catch(Throwable$e){$report['logout_errors'][]=$name;}$report['session_removed'][$name]=is_file($cookie)?unlink($cookie):true;}
 if($tenant!==null){try{$pdo->beginTransaction();foreach($actors as$name=>$actor){foreach($modules as$module)RBACResolver::revokeModule($actor['membership_id'],$module,$actor['user_id']);
   deactivateMembership($actor['user_id'],$tenant);$pdo->prepare('UPDATE users SET is_active=0,password=NULL,password_hash=NULL WHERE id=? AND tenant_id=?')->execute([$actor['user_id'],$tenant]);}
  $pdo->prepare('UPDATE tenants SET is_active=0 WHERE id=? AND parent_id=2002 AND is_simulation=1')->execute([$tenant]);$pdo->commit();$report['fixture_retired']=true;
 }catch(Throwable$e){if($pdo->inTransaction())$pdo->rollBack();$report['fixture_retired']=false;$report['passed']=false;$report['retirement_failure']=$e->getMessage();}}
}
try{
 $after=$snapshot();$expect(array_keys($before)===array_keys($after),'Acceptance changes no schema');
 foreach($before as$t=>$hashes)foreach($hashes as$h=>$n)if(($after[$t][$h]??0)<$n)throw new RuntimeException('Pre-existing record changed in '.$t);
 $expect(true,'All pre-existing rows are preserved');
 foreach(['accounting_events','accounting_journal_entries','accounting_journal_entry_lines','ap_payments','billing_payments']as$t)$expect($before[$t]===$after[$t],'No financial execution in '.$t);
 foreach($actors as$name=>$actor){$a=$one('SELECT is_active,password,password_hash FROM users WHERE id=? AND tenant_id=?',[$actor['user_id'],$tenant]);
  $m=$one('SELECT status FROM tenant_memberships WHERE id=?',[$actor['membership_id']]);$q=$pdo->prepare('SELECT COUNT(*) FROM membership_module_access WHERE membership_id=?');$q->execute([$actor['membership_id']]);
  $expect($a&&(int)$a['is_active']===0&&$a['password']===null&&$a['password_hash']===null&&$m['status']==='revoked'&&(int)$q->fetchColumn()===0,'Synthetic access and credentials retired for '.$name);}
}catch(Throwable$e){$report['passed']=false;$report['preservation_failure']=$e->getMessage();}
$report['status']='finished';$report['completed_at']=gmdate('c');$save();
echo json_encode($report,JSON_THROW_ON_ERROR)."\n";exit($report['passed']?0:1);
