<?php
/** Exact invented source -> separate Core review -> Quanta status; no ledger dispatch. */
declare(strict_types=1);
const ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const PRIVATE_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030';
const SOURCE='5e72afa5-07b9-4681-bcc0-7e46379a205c';
if(PHP_SAPI!=='cli'||getcwd()!==ROOT||posix_geteuid()!==1003||getenv('COREFLUX_ENV')!=='staging'
 ||realpath(__DIR__)!==__DIR__||dirname(__DIR__)!==PRIVATE_ROOT||($argv[1]??'')!=='--synthetic-native-review')exit(2);
$input=json_decode(file_get_contents(__DIR__.'/manifest.json'),true,512,JSON_THROW_ON_ERROR);
if(hash_file('sha256',__DIR__.'/manifest.json')!==($argv[2]??'')||hash_file('sha256',__FILE__)!==$input['verifier_sha256']
 ||!str_starts_with($input['installation_directory'],PRIVATE_ROOT.'/supplemental-time-')||realpath($input['installation_directory'])!==$input['installation_directory'])exit(2);
$op=$input['installation_directory'];$installed=json_decode(file_get_contents($op.'/installation-state.json'),true,512,JSON_THROW_ON_ERROR);
$packagePath=$op.'/quanta-supplement-application-package.json';
if($installed['phase']!=='completed'||$installed['candidate']!=='329d9f8a97e0f28657a0ba27d1c29844b99a35fa'
 ||$installed['package_sha256']!==$input['package_sha256']||hash_file('sha256',$packagePath)!==$input['package_sha256'])exit(2);
$package=json_decode(file_get_contents($packagePath),true,512,JSON_THROW_ON_ERROR);
if(hash_file('sha256',dirname(ROOT).'/private_html/coreflux.thunderhawk-pilot.php')!==$package['feature_sha256'])exit(2);
foreach($package['files']as$f=>$e)if(hash_file('sha256',ROOT.'/'.$f)!==$e['sha256'])exit(2);
foreach($package['dependencies']as$f=>$h)if(hash('sha256',str_replace("\r\n","\n",file_get_contents(ROOT.'/'.$f)))!==$h)exit(2);
require ROOT.'/core/db.php';require_once ROOT.'/core/tenant_scope.php';require_once ROOT.'/core/memberships.php';
require_once ROOT.'/core/rbac/permissions.php';require_once ROOT.'/core/integrations/identity.php';require_once ROOT.'/core/quanta/sync.php';
$pdo=getDB();
if(!COREFLUX_STAGING||DB_NAME!=='muzqvdvqbx'||integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf'
 ||COREFLUX_INTEGRATION_DELIVERY_TENANTS!=='2002'||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=2002')->fetchColumn()!==1)exit(2);
setRequestTenantId(2002);setRequestModuleScope('time');
$mask=umask(0077);$lock=fopen(PRIVATE_ROOT.'/contact-install.lock','c');umask($mask);
if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)||file_exists(__DIR__.'/result.json'))exit(2);
$report=['status'=>'running','pid'=>getmypid(),'started_at'=>gmdate('c'),'candidate'=>$installed['candidate'],'source_entry_id'=>SOURCE,
 'checks'=>[],'requests'=>[],'actors'=>[],'passed'=>false,'browser_test'=>false,'import_completed'=>false,'approval_completed'=>false,'quanta_status_delivered'=>false,
 'production_changed'=>false,'arabella_changed'=>false,'financial_execution'=>false];
$save=static function()use(&$report):void{$mask=umask(0077);try{
 $f=fopen(__DIR__.'/result.next','wb');$b=json_encode($report,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT);
 if(!$f||fwrite($f,$b)!==strlen($b)||!fflush($f)||!fsync($f))throw new RuntimeException('Receipt failed');fclose($f);
 if(!rename(__DIR__.'/result.next',__DIR__.'/result.json'))throw new RuntimeException('Receipt activation failed');
}finally{umask($mask);}};
$expect=static function(bool$ok,string$label)use(&$report,$save):void{if(!$ok)throw new RuntimeException($label);$report['checks'][]=$label;$save();};
$rows=static function(string$sql,array$args=[])use($pdo):array{$q=$pdo->prepare($sql);$q->execute($args);return$q->fetchAll(PDO::FETCH_ASSOC);};
$one=static fn(string$sql,array$args=[])=>$rows($sql,$args)[0]??[];
$snapshot=static function()use($pdo):array{$out=[];foreach($pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN)as$t){
 if(!preg_match('/^[A-Za-z0-9_]+$/D',$t))throw new RuntimeException('Invalid table');$hashes=[];
 foreach($pdo->query('SELECT * FROM `'.$t.'`')->fetchAll(PDO::FETCH_ASSOC)as$r){
  // Only this existing connection's successful-import telemetry may advance.
  if($t==='quanta_connections'&&(int)$r['tenant_id']===2002)foreach(['last_import_at','last_import_error','updated_at']as$c)unset($r[$c]);
  $hashes[]=hash('sha256',serialize($r));
 }sort($hashes);$out[$t]=array_count_values($hashes);
}ksort($out);return$out;};
$before=$snapshot();$actors=[];$cookies=[];$modules=['people'=>'read','placements'=>'read','time'=>'admin','staffing'=>'admin','integrations'=>'admin','accounting'=>'read'];
$mask=umask(0077);try{file_put_contents(__DIR__.'/baseline.private.json',json_encode($before,JSON_THROW_ON_ERROR));}finally{umask($mask);}$save();
$request=static function(string$actor,string$method,string$path,?array$body=null,int$tenant=2002,bool$form=false)use(&$cookies,&$report,$save):array{
 $route=parse_url($path,PHP_URL_PATH);if(!in_array($route,['/login.php','/logout.php','/api/quanta.php','/modules/time/api/entries.php','/modules/staffing/api/timesheets.php'],true)
  ||!in_array($method,['GET','POST'],true)||!in_array($tenant,[2002,2],true))throw new RuntimeException('Unreviewed request');
 $c=curl_init('https://phpstack-1516771-6707602.cloudwaysapps.com'.$path);$h=['Accept: application/json','X-CoreFlux-Tenant-Id: '.$tenant];
 $opts=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>40,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2];
 if($body!==null){$opts[CURLOPT_POSTFIELDS]=$form?http_build_query($body):json_encode((object)$body,JSON_THROW_ON_ERROR);$h[]='Content-Type: '.($form?'application/x-www-form-urlencoded':'application/json');}
 if(isset($cookies[$actor])){$opts[CURLOPT_COOKIEFILE]=$cookies[$actor];$opts[CURLOPT_COOKIEJAR]=$cookies[$actor];}$opts[CURLOPT_HTTPHEADER]=$h;curl_setopt_array($c,$opts);
 $raw=curl_exec($c);$status=(int)curl_getinfo($c,CURLINFO_HTTP_CODE);$location=(string)curl_getinfo($c,CURLINFO_REDIRECT_URL);curl_close($c);
 $report['requests'][]=['actor'=>$actor,'method'=>$method,'path'=>$path,'status'=>$status,'tenant'=>$tenant];$save();
 if($raw===false)throw new RuntimeException('Outcome uncertain; inspect the same operation');
 $mask=umask(0077);try{if($route!=='/login.php')file_put_contents(__DIR__.'/response-'.count($report['requests']).'.private.txt',$raw);}finally{umask($mask);}
 return[$status,json_decode($raw,true),$location];
};
try{
 $primary=$one('SELECT * FROM staffing_timesheets WHERE tenant_id=2002 AND id=8');
 $oldEntry=$one('SELECT * FROM time_entries WHERE tenant_id=2002 AND id=17');
 $expect($primary['status']==='approved'&&(float)$primary['total_hours']===2.0&&(int)$primary['person_id']===387&&$oldEntry['status']==='approved','Original two-hour approval remains the source baseline');
 $expect((int)$one('SELECT COUNT(*) n FROM quanta_time_imports WHERE tenant_id=2002 AND quanta_entry_id=?',[SOURCE])['n']===0,'Exact invented source remains unimported');
 $pdo->beginTransaction();$secrets=[];
 try{foreach(['maker','reviewer']as$name){$email='supplement-'.$name.'-'.bin2hex(random_bytes(8)).'@example.invalid';$password=bin2hex(random_bytes(32));$hash=password_hash($password,PASSWORD_DEFAULT);
  $pdo->prepare('INSERT INTO users(tenant_id,name,email,password,password_hash,role,is_active,is_global_admin) VALUES(2002,?,?,?,? ,"tenant_admin",1,0)')->execute(['Synthetic supplement '.$name,$email,$hash,$hash]);$id=(int)$pdo->lastInsertId();
  $mid=(int)provisionMembership($id,2002,'tenant_admin',['is_primary'=>true,'status'=>'active'])['membership_id'];
  foreach($modules as$m=>$level)RBACResolver::grantModule($mid,$m,$level,[2002],4);
  $actors[$name]=['user_id'=>$id,'membership_id'=>$mid];$secrets[$name]=[$email,$password];
 }$pdo->commit();}catch(Throwable$e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}
 $report['actors']=$actors;$save();
 foreach($secrets as$name=>[$email,$password]){$cookies[$name]=tempnam(__DIR__,'session-');chmod($cookies[$name],0600);
  [$status,,$location]=$request($name,'POST','/login.php',['username'=>$email,'password'=>$password,'redirect'=>'spa'],2002,true);
  $expect($status===302&&!str_contains($location,'error='),'Native isolated sign-in succeeds for '.$name);
 }unset($secrets,$password,$hash);
 $window=['changed_since'=>'2026-11-14','source_status'=>'approved'];
 $expect($request('maker','POST','/api/quanta.php?action=preview',$window,2)[0]===403,'Real Thunderhawk workspace is inaccessible to the synthetic operator');
 [$status,$preview]=$request('maker','POST','/api/quanta.php?action=preview',$window);
 $found=array_values(array_filter($preview['rows']??[],static fn($r)=>($r['id']??null)===SOURCE));$row=$found[0]??[];
 $expect($status===200&&count($found)===1&&($row['person_number']??null)==='P-387'&&($row['placement_number']??null)==='PL-902'&&(float)($row['hours']??0)===1.25,'Native source preview retains worker, placement and exactly 75 invented minutes');
 $d=$row['destination']??[];
 $expect(($row['status']??null)==='supplement'&&($d['period_start']??null)==='2026-11-09'&&($d['period_end']??null)==='2026-11-15'
  &&($d['supplement_of_id']??null)===8&&strlen($row['destination_review_hash']??'')===64,'Preview identifies the preserved approved parent and actual Core week');
 $selection=$window+['entry_ids'=>[SOURCE]];$businessBefore=$snapshot();
 $expect($request('maker','POST','/api/quanta.php?action=import',$selection)[0]===409,'Unconfirmed supplemental import is refused');
 $expect($request('maker','POST','/api/quanta.php?action=import',$selection+['confirm_supplements'=>true,'destination_reviews'=>[SOURCE=>str_repeat('0',64)]])[0]===409,'Stale supplemental destination is refused');
 $afterRefused=$snapshot();foreach(['time_entries','staffing_timesheets','quanta_time_imports','time_approval_rates','time_accounting_handoffs']as$t)$expect($businessBefore[$t]===$afterRefused[$t],'Refused intake leaves '.$t.' unchanged');
 $confirmed=$selection+['confirm_supplements'=>true,'destination_reviews'=>[SOURCE=>$row['destination_review_hash']]];
 [$status,$imported]=$request('maker','POST','/api/quanta.php?action=import',$confirmed);
 $report['import_completed']=$status===200;$save();
 $expect($status===200&&($imported['inserted']??null)===1&&count($imported['timesheets']??[])===1,'Confirmed native import creates exactly one new time row and review header');
 $sheet=(int)$imported['timesheets'][0]['id'];$header=$one('SELECT * FROM staffing_timesheets WHERE tenant_id=2002 AND id=?',[$sheet]);
 $import=$one('SELECT * FROM quanta_time_imports WHERE tenant_id=2002 AND quanta_entry_id=? AND is_current=1',[SOURCE]);$entryId=(int)$import['time_entry_id'];
 $entry=$one('SELECT * FROM time_entries WHERE tenant_id=2002 AND id=?',[$entryId]);$report['timesheet_id']=$sheet;$report['time_entry_id']=$entryId;$save();
 $expect($sheet!==8&&(int)$header['supplement_of_id']===8&&(int)$header['supplement_sequence']===1&&$header['status']==='submitted'&&(float)$header['total_hours']===1.25,'New supplement contains only the additional hours and requires review');
 $expect($entry['status']==='pending_review'&&$entry['approved_by_user_id']===null&&$entry['rate_snapshot_id']===null&&(int)$entry['person_id']===387&&(int)$entry['placement_id']===902,'New source inherits no original approval or rate');
 $expect($import['source_workspace_id']==='4f93d4e7-af74-4765-b812-3464bb6d974b'&&$import['worker_id']==='cfw_84c68922408ef4edb348e7d1a00c5254e9c019936e192ce5fd9f517199ab52d6','Imported source retains original provider identities');
 $replayBefore=$snapshot();[$status,$retry]=$request('maker','POST','/api/quanta.php?action=import',$confirmed);$replayAfter=$snapshot();
 $expect($status===200&&($retry['inserted']??null)===0&&($retry['already_imported']??null)===1,'Exact import replay recognizes the original source');
 foreach(['time_entries','staffing_timesheets','quanta_time_imports','time_accounting_handoffs']as$t)$expect($replayBefore[$t]===$replayAfter[$t],'Import replay adds no duplicate in '.$t);
 $decision=['ids'=>[$entryId],'decision'=>'approve'];$time='/modules/time/api/entries.php';
 $expect($request('maker','POST',$time.'?action=decision_review',$decision)[0]===403,'Importer cannot review their own time for approval');
 [$status,$weekReview]=$request('reviewer','POST','/modules/staffing/api/timesheets.php?action=decision_review',['ids'=>[$sheet],'decision'=>'approve']);
 $expect($status===200&&($weekReview['entry_count']??null)===1&&(float)($weekReview['total_hours']??0)===1.25,'Whole-supplement review contains no original approved entries');
 // The entry decision reconciles its complete one-entry header and queues
 // accounting intent. Weekly commit also dispatches journals, so it is not used.
 [$status,$review]=$request('reviewer','POST',$time.'?action=decision_review',$decision);
 $expect($status===200&&strlen($review['revision']??'')===64&&($review['entries'][0]['person_number']??null)==='P-387','Independent reviewer receives the current source decision');
 $expect($request('reviewer','POST',$time.'?action=decision_commit',$decision+['review_revision'=>str_repeat('0',64)])[0]===409,'Stale approval review is refused');
 [$status,$approved]=$request('reviewer','POST',$time.'?action=decision_commit',$decision+['review_revision'=>$review['revision']]);
 $report['approval_completed']=$status===200;$save();$expect($status===200,'Independent native entry approval completes');
 $entry=$one('SELECT * FROM time_entries WHERE tenant_id=2002 AND id=?',[$entryId]);$header=$one('SELECT * FROM staffing_timesheets WHERE tenant_id=2002 AND id=?',[$sheet]);
 $expect($entry['status']==='approved'&&(int)$entry['approved_by_user_id']===$actors['reviewer']['user_id']&&(int)$entry['rate_snapshot_id']===23&&$header['status']==='approved','Native source and supplemental header retain independent approval');
 $handoff=$one('SELECT * FROM time_accounting_handoffs WHERE tenant_id=2002 AND timesheet_id=? AND is_current=1',[$sheet]);
 $events=json_decode($handoff['events_json']??'null',true);$report['accounting_handoff_id']=$handoff['id']??null;
 $expect($handoff&&$handoff['status']==='pending'&&(int)$handoff['attempts']===0&&count($events)===1&&(float)$events[0]['payload']['hours']===1.25
  &&(float)$events[0]['payload']['wage_cost']===62.5&&(int)$events[0]['payload']['timesheet_id']===$sheet,'Only the new 75 minutes at the retained 50 rate reach pending accounting intent');
 $expect($request('reviewer','POST',$time.'?action=decision_commit',$decision+['review_revision'=>$review['revision']])[0]===409,'Repeated approval cannot create a second decision');
 [$status,$publication]=$request('reviewer','POST','/api/quanta.php?action=time_review_preview',['source_entry_id'=>SOURCE]);
 $expect($status===200&&($publication['status']??null)==='preview'&&($publication['state']??null)==='accepted'&&($publication['delivery_enabled']??false)===true,'Quanta status preview contains the independent Core approval');
 $delivery=$publication['delivery'];$send=['delivery_id'=>(int)$delivery['id'],'review_hash'=>$delivery['review_hash'],'confirm_status'=>true];$report['status_delivery_id']=$send['delivery_id'];$save();
 [$status,$sent]=$request('reviewer','POST','/api/quanta.php?action=time_review_send',$send);
 $report['quanta_status_delivered']=$status===200&&($sent['state']??null)==='delivered';$save();
 $expect($report['quanta_status_delivered'],'Quanta accepts the exact Core review status');
 [$status,$sentAgain]=$request('reviewer','POST','/api/quanta.php?action=time_review_send',$send);
 $expect($status===200&&($sentAgain['state']??null)==='delivered'&&$sentAgain['id']===$sent['id'],'Status delivery replay retains one receipt');
 [$status,$current]=$request('reviewer','POST','/api/quanta.php?action=time_review_preview',['source_entry_id'=>SOURCE]);
 $expect($status===200&&($current['status']??null)==='unchanged'&&($current['state']??null)==='accepted','Fresh provider readback matches the shared Core approval');
 $expect($one('SELECT * FROM staffing_timesheets WHERE tenant_id=2002 AND id=8')===$primary&&$one('SELECT * FROM time_entries WHERE tenant_id=2002 AND id=17')===$oldEntry,'Original approved header, entry and legacy evidence remain byte-for-byte unchanged');
 $report['passed']=true;
}catch(Throwable$e){$report['failure_class']=get_class($e);$report['failure']=$e->getMessage();$report['failure_line']=$e->getLine();}
finally{
 if($pdo->inTransaction())$pdo->rollBack();
 foreach($cookies as$name=>$cookie){try{$request($name,'GET','/logout.php');}catch(Throwable$e){$report['logout_failure'][]=$name;}$report['cookies_removed'][$name]=is_file($cookie)?unlink($cookie):true;}
 foreach($actors as$name=>$actor){try{$pdo->beginTransaction();foreach($modules as$m=>$level)RBACResolver::revokeModule($actor['membership_id'],$m,4);deactivateMembership($actor['user_id'],2002);
  $pdo->prepare('UPDATE users SET is_active=0,password=NULL,password_hash=NULL WHERE tenant_id=2002 AND id=?')->execute([$actor['user_id']]);$pdo->commit();$report['actors_retired'][$name]=true;
 }catch(Throwable$e){if($pdo->inTransaction())$pdo->rollBack();$report['passed']=false;$report['retirement_failure']=$e->getMessage();}}
}
try{
 $after=$snapshot();$expect(array_keys($before)===array_keys($after),'No schema changed during native acceptance');
 foreach($before as$t=>$hashes)foreach($hashes as$h=>$n)if(($after[$t][$h]??0)<$n)throw new RuntimeException('Original row changed in '.$t);
 $expect(true,'Every pre-existing Core business row and identity is preserved; only import telemetry may advance');
 foreach(['payroll_source_claims','payroll_source_authorities','payroll_provider_results','payroll_runs','payroll_line_items','accounting_events','accounting_journal_entries','accounting_journal_entry_lines','ap_payments','billing_payments']as$t)$expect($before[$t]===$after[$t],'No change in '.$t);
 foreach($actors as$name=>$a){$user=$one('SELECT is_active,password,password_hash FROM users WHERE tenant_id=2002 AND id=?',[$a['user_id']]);
  $count=$one('SELECT COUNT(*) n FROM membership_module_access WHERE membership_id=?',[$a['membership_id']]);
  $expect((int)$user['is_active']===0&&$user['password']===null&&$user['password_hash']===null&&(int)$count['n']===0,'Temporary '.$name.' credentials and grants are retired');
 }
}catch(Throwable$e){$report['passed']=false;$report['preservation_failure']=$e->getMessage();}
$report['status']='finished';$report['completed_at']=gmdate('c');$save();echo json_encode($report,JSON_THROW_ON_ERROR)."\n";exit($report['passed']?0:1);
