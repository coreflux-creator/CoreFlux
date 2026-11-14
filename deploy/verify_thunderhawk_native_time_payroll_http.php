<?php
/** Fresh isolated company, native login, time review and payroll review; no execution. */
declare(strict_types=1);
const PILOT_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const PILOT_PRIVATE='/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030';
if(PHP_SAPI!=='cli'||getcwd()!==PILOT_ROOT||posix_geteuid()!==1003||getenv('COREFLUX_ENV')!=='staging'
 ||realpath(__DIR__)!==__DIR__||dirname(__DIR__)!==PILOT_PRIVATE||($argv[1]??'')!=='--synthetic-native-review')exit(2);
$input=json_decode(file_get_contents(__DIR__.'/manifest.json'),true,512,JSON_THROW_ON_ERROR);
if(hash_file('sha256',__DIR__.'/manifest.json')!==($argv[2]??'')||hash_file('sha256',__FILE__)!==$input['verifier_sha256']
 ||!str_starts_with($input['installation_directory'],PILOT_PRIVATE.'/time-payroll-')
 ||realpath($input['installation_directory'])!==$input['installation_directory'])exit(2);
$installed=json_decode(file_get_contents($input['installation_directory'].'/installation-state.json'),true,512,JSON_THROW_ON_ERROR);
if($installed['phase']!=='completed'||$installed['candidate']!=='846e86204d188c6ec10d8a5b4537c6a79150f1ce'
 ||$installed['package_sha256']!==$input['package_sha256']
 ||hash_file('sha256',$input['installation_directory'].'/time-payroll-application-package.json')!==$input['package_sha256'])exit(2);
$package=json_decode(file_get_contents($input['installation_directory'].'/time-payroll-application-package.json'),true,512,JSON_THROW_ON_ERROR);
if(hash_file('sha256',dirname(PILOT_ROOT).'/private_html/coreflux.thunderhawk-pilot.php')!==$package['feature_sha256'])exit(2);
foreach($package['files']as$f=>$e)if(hash_file('sha256',PILOT_ROOT.'/'.$f)!==$e['sha256'])exit(2);
foreach($package['dependencies']as$f=>$h)if(hash('sha256',str_replace("\r\n","\n",file_get_contents(PILOT_ROOT.'/'.$f)))!==$h)exit(2);
require_once PILOT_ROOT.'/modules/payroll/lib/run_compute.php';
require_once PILOT_ROOT.'/modules/payroll/lib/approval_settings.php';
require_once PILOT_ROOT.'/modules/payroll/lib/accounting_posting.php';
require_once PILOT_ROOT.'/modules/people/lib/employment_links.php';
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
$save();$tenant=null;$actors=[];$cookies=[];$modules=['people','placements','time','staffing','payroll','accounting'];
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
 $marker='Synthetic native time/payroll '.bin2hex(random_bytes(6));$report['marker']=$marker;$save();
 $pdo->beginTransaction();
 try{
  $tenant=$insert('tenants',['name'=>$marker,'parent_id'=>2002,'tenant_type'=>'sub','is_active'=>1,'is_simulation'=>1]);$report['tenant_id']=$tenant;
  foreach(['companies',...$modules]as$module)$insert('tenant_module_scope',['tenant_id'=>$tenant,'module_key'=>$module,'scope_mode'=>'isolated']);
  setRequestTenantId($tenant);setRequestModuleScope('people');
  $secrets=[];
  foreach(['maker','reviewer']as$name){$email='native-'.$name.'-'.bin2hex(random_bytes(8)).'@example.invalid';$password=bin2hex(random_bytes(32));$hash=password_hash($password,PASSWORD_DEFAULT);
   $id=$insert('users',['tenant_id'=>$tenant,'name'=>$marker.' '.$name,'email'=>$email,'password'=>$hash,'password_hash'=>$hash,'role'=>'tenant_admin','is_active'=>1,'is_global_admin'=>0]);
   $mid=(int)provisionMembership($id,$tenant,'tenant_admin',['is_primary'=>true,'status'=>'active'])['membership_id'];
   foreach($modules as$module)RBACResolver::grantModule($mid,$module,'admin',[$tenant],$id);
   $actors[$name]=['user_id'=>$id,'membership_id'=>$mid];$secrets[$name]=[$email,$password];
  }
  $report['actors']=$actors;$save();$maker=$actors['maker']['user_id'];$reviewer=$actors['reviewer']['user_id'];
  $person=$insert('people',['tenant_id'=>$tenant,'first_name'=>'Synthetic review','last_name'=>'Worker','email_primary'=>'native-worker-'.$tenant.'@example.invalid','classification'=>'w2','status'=>'active']);
  $employee=(int)peopleEmploymentForNewPerson($person,$maker)['employee_id'];$pdo->prepare('UPDATE people_employees SET status="active" WHERE id=? AND tenant_id=?')->execute([$employee,$tenant]);
  $company=$insert('companies',['tenant_id'=>$tenant,'name'=>$marker.' Client']);
  $client=$insert('staffing_clients',['tenant_id'=>$tenant,'company_id'=>$company,'name'=>$marker.' Client']);
  $entity=$insert('accounting_entities',['tenant_id'=>$tenant,'code'=>'NATIVE','legal_name'=>$marker,'country'=>'US','base_currency'=>'USD','active'=>1]);
  $placement=$insert('placements',['tenant_id'=>$tenant,'person_id'=>$person,'title'=>$marker,'status'=>'active','engagement_type'=>'w2','start_date'=>'2026-10-01','worksite_country'=>'US','worksite_state'=>'NJ','workers_comp_class'=>'synthetic-class','client_id'=>$client,'accounting_entity_id'=>$entity]);
  $rate=$insert('placement_rates',['tenant_id'=>$tenant,'placement_id'=>$placement,'effective_from'=>'2026-10-01','bill_rate'=>100,'pay_rate'=>60,'approved_at'=>'2026-10-01 12:00:00']);
  $schedule=$insert('payroll_pay_schedules',['tenant_id'=>$tenant,'name'=>$marker,'frequency'=>'weekly','period_start_anchor'=>'2026-10-05','active'=>1]);
  $period=$insert('payroll_pay_periods',['tenant_id'=>$tenant,'schedule_id'=>$schedule,'period_number'=>1,'period_start'=>'2026-10-05','period_end'=>'2026-10-11','pay_date'=>'2026-10-16','status'=>'open']);
  $insert('payroll_settings',['tenant_id'=>$tenant,'legal_name'=>$marker,'primary_state'=>'NJ','auto_post_to_ledger'=>0,'ai_run_summary_enabled'=>0]);
  $insert('payroll_profiles',['tenant_id'=>$tenant,'employee_id'=>$employee,'schedule_id'=>$schedule,'work_state'=>'NJ','payment_method'=>'check','default_hours_per_period'=>40,'enabled'=>1]);
  $insert('people_compensation',['tenant_id'=>$tenant,'employee_id'=>$employee,'pay_type'=>'hourly','pay_rate_cents'=>3000,'pay_frequency'=>'weekly','effective_from'=>'2026-01-01','currency'=>'USD']);
  $insert('people_tax_federal',['tenant_id'=>$tenant,'employee_id'=>$employee,'filing_status'=>'single','effective_date'=>'2026-01-01']);
  payrollRunApprovalSettingsSave($tenant,[$reviewer],$maker);
  $report['fixture']=['person_id'=>$person,'placement_id'=>$placement,'rate_id'=>$rate,'pay_period_id'=>$period];$save();$pdo->commit();
 }catch(Throwable$e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}
 $expect((int)payrollAccountingPostingSettings($tenant)['auto_post_to_ledger']===0,'Synthetic payroll accounting execution is disabled');
 foreach($secrets as$name=>[$email,$password]){$cookies[$name]=tempnam(__DIR__,'session-');chmod($cookies[$name],0600);
  [$status,,$location]=$request($name,'POST','/login.php',['username'=>$email,'password'=>$password,'redirect'=>'spa'],null,true);
  $expect($status===302&&!str_contains($location,'error='),'Native sign-in succeeds for '.$name);
 }unset($secrets,$password,$hash);
 $time='/modules/time/api/entries.php';$pay='/modules/payroll/api/runs.php';
 [$status,$created]=$request('maker','POST',$time.'?action=bulk_create',['entries'=>[
  ['placement_id'=>$placement,'work_date'=>'2026-10-05','category'=>'regular_billable','hours'=>2],
  ['placement_id'=>$placement,'work_date'=>'2026-10-06','category'=>'regular_billable','hours'=>2]]]);
 $expect($status===201&&($created['created']??null)===2,'Native API creates two synthetic draft entries');$ids=$created['entry_ids'];$report['entry_ids']=$ids;$save();
 [$status,$submitted]=$request('maker','POST',$time.'?action=bulk_submit',['ids'=>$ids]);
 $expect($status===200&&($submitted['submitted']??null)===2,'Preparer submits the complete time selection');
 $expect($request('maker','POST',$time.'?action=bulk_submit',['ids'=>$ids])[0]===409,'Repeated submission cannot duplicate receipts');
 [$status,$review]=$request('reviewer','POST',$time.'?action=decision_review',['ids'=>$ids,'decision'=>'approve']);
 $expect($status===200&&strlen($review['revision']??'')===64,'Independent reviewer receives the current time revision');
 $decision=['ids'=>$ids,'decision'=>'approve','review_revision'=>$review['revision']];
 $expect($request('maker','POST',$time.'?action=decision_commit',$decision)[0]===403,'Preparer cannot approve their own time');
 $expect($request('reviewer','POST',$time.'?action=decision_commit',array_replace($decision,['review_revision'=>str_repeat('0',64)]))[0]===409,'Stale time review is refused');
 [$status,$approved]=$request('reviewer','POST',$time.'?action=decision_commit',$decision);
 $expect($status===200,'Independent time decision succeeds');
 foreach($ids as$id){$r=$one('SELECT status,approved_by_user_id,rate_snapshot_id FROM time_entries WHERE tenant_id=? AND id=?',[$tenant,$id]);
  $expect($r['status']==='approved'&&(int)$r['approved_by_user_id']===$reviewer&&(int)$r['rate_snapshot_id']===$rate,'Approved entry retains reviewer and source rate: '.$id);}
 $expect($request('reviewer','POST',$time.'?action=decision_commit',$decision)[0]===409,'Repeated time approval cannot add a second decision');
 [$status,$run]=$request('maker','POST',$pay,['pay_period_id'=>$period,'run_type'=>'off_cycle']);
 $expect($status===201&&($run['id']??0)>0,'Preparer creates a synthetic payroll draft');$runId=(int)$run['id'];$report['run_id']=$runId;$save();
 [$status]=$request('maker','POST','/modules/time/api/settlement.php?action=extract',['entry_ids'=>$ids,'target'=>'payroll','target_ref'=>$runId]);
 $expect($status===200,'Approved time is reserved through the native payroll handoff');
 [$status,$computed]=$request('maker','POST',$pay,['action'=>'compute','run_id'=>$runId,'expected_computation_revision'=>0]);
 $expect($status===200&&(int)($computed['run']['gross_total_cents']??0)===24000,'Payroll retains approved rate 60 and four hours instead of compensation rate 30');
 $r=$computed['run'];$seal=['action'=>'approve','run_id'=>$runId,'expected_computation_revision'=>(int)$r['computation_revision'],'expected_computation_sha256'=>$r['computation_sha256']];
 $expect($request('maker','POST',$pay,$seal)[0]===403,'Payroll preparer cannot approve their calculation');
 $expect($request('reviewer','POST',$pay,array_replace($seal,['expected_computation_sha256'=>str_repeat('0',64)]))[0]===409,'Payroll rejects a different displayed calculation');
 $expect($request('reviewer','GET',$pay.'?id='.$runId,null,2002)[0]===403,'Synthetic reviewer has no access through the parent company pin');
 [$status,$final]=$request('reviewer','POST',$pay,$seal);
 $expect($status===200&&($final['status']??null)==='approved'&&($final['workflow_status']??null)==='approved','Independent reviewer approves the exact native calculation');
 $saved=$one('SELECT * FROM payroll_runs WHERE tenant_id=? AND id=?',[$tenant,$runId]);
 $expect((int)$saved['approved_by']===$reviewer&&$saved['approved_computation_sha256']===$r['computation_sha256'],'Saved payroll approval matches its reviewer and fingerprint');
 $expect($request('reviewer','POST',$pay,$seal)[0]===409,'Repeated payroll approval is refused');
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
