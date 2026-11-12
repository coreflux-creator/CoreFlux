<?php
/** Guarded native-browser fixture for the existing P-387 / PL-902 operational journey. */
declare(strict_types=1);
const EX_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const EX_PRIVATE='/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030';
const EX_STATE=EX_PRIVATE.'/operational-exchange-browser-20261112.json';
const EX_BEFORE=EX_PRIVATE.'/operational-exchange-before-20261112.private.json';
const EX_CREDENTIAL=EX_PRIVATE.'/operational-exchange-credential-20261112.private.json';
if(PHP_SAPI!=='cli'||getenv('COREFLUX_ENV')!=='staging'||getcwd()!==EX_ROOT||posix_geteuid()!==1003
 ||realpath(EX_PRIVATE)!==EX_PRIVATE||(fileperms(EX_PRIVATE)&0777)!==0700
 ||!in_array($argv[1]??'',['prepare','inspect','verify','finish','retire'],true))exit(2);
require_once EX_ROOT.'/core/db.php';
require_once EX_ROOT.'/core/tenant_scope.php';
require_once EX_ROOT.'/core/memberships.php';
require_once EX_ROOT.'/core/rbac/permissions.php';
require_once EX_ROOT.'/core/integrations/identity.php';
require_once EX_ROOT.'/core/integrations/purepay_core.php';
$pdo=getDB();
if(!COREFLUX_STAGING||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'
 ||integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf'||COREFLUX_INTEGRATION_DELIVERY_TENANTS!=='2002'
 ||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=2002')->fetchColumn()!==1)exit(2);
setRequestTenantId(2002);setRequestModuleScope('people');
$pins=json_decode(file_get_contents(__DIR__.'/exchange-runtime-pins.json'),true,512,JSON_THROW_ON_ERROR);
foreach($pins as$file=>$hash)if(hash('sha256',str_replace("\r\n","\n",file_get_contents(EX_ROOT.'/'.$file)))!==$hash)throw new RuntimeException('Runtime changed: '.$file);
function exExpect(bool$yes,string$message):void{if(!$yes)throw new RuntimeException($message);}
function exSave(string$path,array$data,bool$new=false):void{
 exExpect(!is_link($path)&&realpath(dirname($path))===EX_PRIVATE,'Invalid checkpoint');
 $old=umask(0077);try{$f=fopen($path,$new?'x':'w');exExpect((bool)$f,'Existing or unavailable checkpoint');
 $s=json_encode($data,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT);exExpect(fwrite($f,$s)===strlen($s)&&fflush($f)&&fsync($f),'Incomplete checkpoint');fclose($f);}finally{umask($old);}
}
function exSnapshot():array{
 global$pdo;$out=[];foreach($pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN)as$table){
  exExpect((bool)preg_match('/^[A-Za-z0-9_]+$/D',$table),'Invalid table');$rows=[];
  foreach($pdo->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC)as$row)$rows[]=hash('sha256',serialize($row));
  sort($rows,SORT_STRING);$out[$table]=$rows;
 }ksort($out);return$out;
}
function exDeliveries():array{global$pdo;return$pdo->query("SELECT id,operation,entity_type,entity_id,state,remote_receipt_id,created_at FROM integration_deliveries WHERE tenant_id=2002 AND provider='purepayroll' AND entity_id IN (387,902) ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);}
$action=$argv[1];
if($action==='prepare'){
 exExpect(!file_exists(EX_STATE)&&!file_exists(EX_BEFORE)&&!file_exists(EX_CREDENTIAL),'Inspect existing checkpoint; never repeat preparation');
 $p=$pdo->query('SELECT * FROM placements WHERE tenant_id=2002 AND id=902')->fetch(PDO::FETCH_ASSOC);
 exExpect($p&&(int)$p['person_id']===387&&$p['status']==='active'&&$p['workers_comp_class']==='SYNTHETIC TEST ONLY','Unexpected placement');
 $t=$pdo->query('SELECT * FROM time_entries WHERE tenant_id=2002 AND id=17')->fetch(PDO::FETCH_ASSOC);
 exExpect($t&&$t['status']==='approved'&&(float)$t['hours']===2.0&&(int)$t['placement_id']===902&&(int)$t['rate_snapshot_id']===23,'Unexpected approved time');
 exExpect(exDeliveries()===[],'Provider exchange already started');
 $review=purepayCoreReview($pdo,2002,integrationInstanceId());
 exExpect($review['verified']&&$review['organization']['id']==='org_0369a63ff2cb','Pilot company binding changed');
 exSave(EX_BEFORE,['tables'=>exSnapshot()],true);
 $report=['started_at'=>gmdate('c'),'phase'=>'preparing','passed'=>false,
  'feature_sha256'=>hash_file('sha256',dirname(EX_ROOT).'/private_html/coreflux.thunderhawk-pilot.php'),
  'tenant_id'=>2002,'person_number'=>'P-387','placement_number'=>'PL-902','production_changed'=>false,'arabella_changed'=>false,'financial_execution'=>false];
 exSave(EX_STATE,$report,true);
 $email='core-operational-exchange-20261112@example.invalid';
 $s=$pdo->prepare('SELECT COUNT(*) FROM users WHERE email=?');$s->execute([$email]);exExpect(!(int)$s->fetchColumn(),'Reserved actor exists');
 $password=bin2hex(random_bytes(32));$hash=password_hash($password,PASSWORD_DEFAULT);
 $pdo->beginTransaction();try{
  $pdo->prepare('INSERT INTO users(tenant_id,name,email,password,password_hash,role,is_active,is_global_admin) VALUES(2002,?,?,?, ?,"admin",1,0)')->execute(['Synthetic operational exchange',$email,$hash,$hash]);$id=(int)$pdo->lastInsertId();
  $membership=(int)provisionMembership($id,2002,'admin',['is_primary'=>true,'persona_label'=>'Synthetic operational exchange','status'=>'active'])['membership_id'];$pdo->commit();
 }catch(Throwable$e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}
 $grants=['accounting'=>'admin','treasury'=>'read','people'=>'read','placements'=>'read','companies'=>'read','ap'=>'read','payroll'=>'write','time'=>'read'];
 $report['actor']=['id'=>$id,'membership_id'=>$membership,'email'=>$email,'grants'=>$grants];exSave(EX_STATE,$report);
 foreach($grants as$module=>$level)RBACResolver::grantModule($membership,$module,$level,[2002],4);
 exExpect(!RBACResolver::can($id,2002,'treasury','admin'),'Unexpected payment execution access');
 exSave(EX_CREDENTIAL,['username'=>$email,'password'=>$password],true);unset($password,$hash);
 $report['phase']='browser_ready';exSave(EX_STATE,$report);
 echo json_encode(['phase'=>$report['phase'],'actor'=>$report['actor'],'runtime_pins_verified'=>count($pins),'credential_printed'=>false]);exit;
}
exExpect(is_file(EX_STATE)&&is_file(EX_BEFORE),'No exchange checkpoint');
$report=json_decode(file_get_contents(EX_STATE),true,512,JSON_THROW_ON_ERROR);$before=json_decode(file_get_contents(EX_BEFORE),true,512,JSON_THROW_ON_ERROR);
$deliveries=exDeliveries();
if($action==='inspect'){echo json_encode(['phase'=>$report['phase'],'actor'=>$report['actor'],'deliveries'=>$deliveries],JSON_THROW_ON_ERROR);exit;}
if($action==='verify'){
 require_once EX_ROOT.'/core/integrations/purepay_payroll_results.php';
 $matches=array_values(array_filter($deliveries,static fn($d)=>$d['operation']==='payroll.inputs'&&$d['state']==='delivered'));
 exExpect(count($matches)===1,'One delivered payroll input required');
 $id=(int)$matches[0]['id'];$delivery=integrationDeliveryGet($pdo,2002,$id);$actor=(int)$report['actor']['id'];
 exExpect((int)$pdo->query('SELECT is_active FROM users WHERE id='.$actor)->fetchColumn()===1,'Reviewer retired');
 $owners=['time'=>2002,'people'=>2002,'placements'=>2002];$instance=integrationInstanceId();
 $authority='cpa_4a0bfee5f022202d92f0b09a87d9423e859c337d65c2e514cc9a3fcf09fc27dc';
 $initial=exSnapshot();$checks=[];
 $result=purepayPayrollResultRefresh($pdo,2002,$actor,$instance,$owners,$id,$delivery['review_hash'],$authority);
 exExpect($result['replayed']===true&&$result['result']['execution_hold']===true&&$result['result']['status']==='draft','Original held result must replay');
 exExpect(count($result['result']['lines'])===1&&count($result['result']['lines'][0]['components'])===1,'Unexpected result lines');
 $line=$result['result']['lines'][0];$component=$line['components'][0];
 exExpect($line['amounts']['gross']==='100.00'&&$component['gross']==='100.00'&&(int)$component['input_component']['source']['time_entry_id']===17,'Corrected source or amount mismatch');
 $checks[]='exact_result_reuses_saved_observation';$checks[]='two_hours_times_fifty_reconciles_to_one_hundred';
 [$original,$payload]=purepayPayrollInputEnvelope($pdo,2002,$id,$delivery['review_hash'],$instance,$owners);
 $authorityRow=purepayPayrollResultAuthority($pdo,2002,$instance,$owners,$original,$payload,$authority);
 $bad=$result['result'];$bad['lines'][0]['components'][0]['gross']='101.00';$refused=false;
 try{purepayPayrollResultValidate($bad,purepayPayrollAuthorityBody($authorityRow),$payload['body']);}catch(RuntimeException$e){$refused=true;}
 exExpect($refused,'Changed result accepted');$checks[]='altered_component_amount_refused';
 $refused=false;
 try{purepayPayrollResultHistory($pdo,2013,$instance,['time'=>2013,'people'=>2013,'placements'=>2013],$id,$delivery['review_hash']);}catch(InvalidArgumentException|RuntimeException$e){$refused=true;}
 exExpect($refused,'Foreign company read accepted');$checks[]='foreign_company_delivery_refused';
 exExpect(!RBACResolver::can($actor,2013,'payroll','read'),'Cross-company payroll grant present');$checks[]='reviewer_has_no_foreign_company_payroll_access';
 exExpect(exSnapshot()===$initial,'Verification changed original Core rows');
 $result=['passed'=>true,'checks'=>$checks,'saved_observation_id'=>$result['id'],'input_delivery_id'=>$id,'authority_id'=>$authority,
  'core_rows_preserved'=>array_sum(array_map('count',$initial)),'service_boundary_checks'=>true,'financial_execution'=>false];
 exSave(EX_PRIVATE.'/operational-exchange-core-verification-20261112.json',$result);echo json_encode($result,JSON_THROW_ON_ERROR);exit;
}
exExpect(!in_array($report['phase'],['complete','retired'],true),'Checkpoint already terminal');
if($action==='finish'){
 foreach(['people.inputs','assignments.inputs','payroll.inputs']as$operation)exExpect(count(array_filter($deliveries,static fn($r)=>$r['operation']===$operation&&$r['state']==='delivered'))===1,'Exactly one delivered '.$operation.' required');
 $verification=json_decode(file_get_contents(EX_PRIVATE.'/operational-exchange-core-verification-20261112.json'),true,512,JSON_THROW_ON_ERROR);
 exExpect(($verification['passed']??false)===true&&($verification['financial_execution']??null)===false,'Completed result and isolation verification required');
 exExpect(in_array('reviewer_has_no_foreign_company_payroll_access',$verification['checks']??[],true),'Missing native company isolation evidence');
 $report['verification']=$verification;$report['scope']='Core operational exchange and actor retirement; provider preservation audited separately';
 $report['deliveries']=$deliveries;
}
$actor=$report['actor'];foreach($actor['grants']as$module=>$level)RBACResolver::revokeModule($actor['membership_id'],$module,4);
deactivateMembership($actor['id'],2002);
$pdo->prepare('UPDATE users SET is_active=0,password=NULL,password_hash=NULL WHERE tenant_id=2002 AND id=? AND email=?')->execute([$actor['id'],$actor['email']]);
$s=$pdo->prepare('SELECT COUNT(*) FROM membership_module_access WHERE membership_id=?');$s->execute([$actor['membership_id']]);exExpect(!(int)$s->fetchColumn(),'Actor grants remain');
if(is_file(EX_CREDENTIAL))exExpect(!is_link(EX_CREDENTIAL)&&unlink(EX_CREDENTIAL),'Credential removal failed');
$report['actor_retired']=true;exSave(EX_STATE,$report);
$after=exSnapshot();exExpect(array_keys($before['tables'])===array_keys($after),'Schema changed');$changed=[];
$allowed=['integration_deliveries','integration_delivery_subjects','integration_links','payroll_source_authorities','payroll_source_claims','payroll_provider_results','users','tenant_memberships','user_tenants','membership_audit','audit_log','rbac_bridge_audit'];
foreach($before['tables']as$table=>$hashes){if($hashes===$after[$table])continue;exExpect(in_array($table,$allowed,true),'Unexpected table change: '.$table);
 $counts=array_count_values($after[$table]);foreach(array_count_values($hashes)as$hash=>$n)exExpect(($counts[$hash]??0)>=$n,'Original record changed: '.$table);
 $changed[$table]=count($after[$table])-count($hashes);
}
exExpect(hash_file('sha256',dirname(EX_ROOT).'/private_html/coreflux.thunderhawk-pilot.php')===$report['feature_sha256'],'Operational switches changed');
$report['tables_verified']=count($after);$report['original_rows_checked']=array_sum(array_map('count',$before['tables']));$report['added_rows']=$changed;
$report['finished_at']=gmdate('c');$report['passed']=$action==='finish';$report['phase']=$action==='finish'?'complete':'retired';exSave(EX_STATE,$report);echo json_encode($report,JSON_THROW_ON_ERROR);
