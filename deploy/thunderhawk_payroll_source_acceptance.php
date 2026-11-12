<?php
/** Installed native consumers, temporary tables only, with full original-row preservation. */
declare(strict_types=1);
const PS_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const PS_PRIVATE='/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030';
if(PHP_SAPI!=='cli'||getcwd()!==PS_ROOT||posix_geteuid()!==1003||getenv('COREFLUX_ENV')!=='staging'
 ||!preg_match('/^[a-f0-9]{64}$/D',$argv[1]??'')||!hash_equals($argv[1],hash_file('sha256',__DIR__.'/payroll-source-pins.json')))exit(2);
require_once PS_ROOT.'/modules/payroll/lib/payroll.php';
require_once PS_ROOT.'/core/integrations/identity.php';
$pdo=getDB();
if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||!COREFLUX_STAGING
 ||integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf'||COREFLUX_INTEGRATION_DELIVERY_TENANTS!=='2002'
 ||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=2002')->fetchColumn()!==1)exit(2);
$pins=json_decode(file_get_contents(__DIR__.'/payroll-source-pins.json'),true,64,JSON_THROW_ON_ERROR);
foreach($pins as$file=>$sha){if(!is_string($file)||str_contains($file,'..')||is_link(PS_ROOT.'/'.$file)||hash('sha256',str_replace("\r\n","\n",file_get_contents(PS_ROOT.'/'.$file)))!==$sha)throw new RuntimeException('Runtime drift: '.$file);}
function psSnapshot(PDO $pdo):array{
 $all=[];foreach($pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN)as$t){
  if(!preg_match('/^[a-zA-Z0-9_]+$/D',$t))throw new RuntimeException('Unexpected table');$hashes=[];
  foreach($pdo->query('SELECT * FROM `'.$t.'`')as$row)$hashes[]=hash('sha256',serialize($row));sort($hashes);
  $all[$t]=['rows'=>count($hashes),'sha256'=>hash('sha256',implode("\n",$hashes))];
 }ksort($all);return $all;
}
$report=['checked_at'=>gmdate('c'),'passed'=>false,'checks'=>[],'temporary_tables_only'=>true,'real_financial_execution'=>false,'provider_changed'=>false];
$check=static function(bool$ok,string$label)use(&$report){if(!$ok)throw new RuntimeException($label);$report['checks'][]=$label;};
$fails=static function(callable$fn,string$msg):bool{try{$fn();return false;}catch(Throwable$e){if(!str_contains($e->getMessage(),$msg))throw$e;return true;}};
$tables=['people','people_employees','people_employment_links','payroll_profiles','time_entries','placement_rates','placement_economic_parties','placement_economic_obligations'];
$created=[];$before=psSnapshot($pdo);
file_put_contents(PS_PRIVATE.'/payroll-source-before.private.json',json_encode($before,JSON_THROW_ON_ERROR));chmod(PS_PRIVATE.'/payroll-source-before.private.json',0600);
try{
 setRequestTenantId(2002);setRequestModuleScope('payroll');
 if(effectiveTenantIdForModule('people',2002)!==2002||effectiveTenantIdForModule('placements',2002)!==2002)throw new RuntimeException('Unexpected fixture directory ownership');
 foreach($tables as$table){$pdo->exec('CREATE TEMPORARY TABLE `'.$table.'` LIKE `'.$table.'`');$created[]=$table;}
 $pdo->exec("INSERT INTO people(id,tenant_id,first_name,last_name,email_primary,classification,status) VALUES(1,2002,'Synthetic','Source','reused@example.invalid','w2','active'),(2,2002,'Unlinked','Source','reused@example.invalid','w2','active')");
 $pdo->exec("INSERT INTO people_employees(id,tenant_id,employee_number,legal_first_name,legal_last_name,work_email,status) VALUES(20,2002,'TEST-20','Synthetic','Source','old@example.invalid','active'),(21,2002,'TEST-21','Different','Source','reused@example.invalid','active')");
 $pdo->prepare('INSERT INTO people_employment_links(tenant_id,person_id,employee_id,review_id,actor_user_id,origin,reason,review_ct) VALUES(2002,1,20,?,1,"reviewed","Temporary native-reader fixture",?)')->execute([str_repeat('a',64),encryptField('{"synthetic":true}')]);
 $pdo->exec('INSERT INTO payroll_profiles(id,tenant_id,employee_id,enabled) VALUES(1,2002,20,1),(2,2002,21,1)');
 $pdo->exec("INSERT INTO placement_rates(id,tenant_id,placement_id,effective_from,pay_rate,bill_rate,currency,approved_at) VALUES(1,2002,1,'2026-11-01',50,100,'USD',NOW())");
 $pdo->exec("INSERT INTO time_entries(id,tenant_id,placement_id,person_id,period_id,work_date,category,hours,status,rate_snapshot_id,payroll_extracted_ref,payroll_extracted_at) VALUES(1,2002,1,1,1,'2026-11-09','regular_billable',2,'approved',1,'payroll:run#999991',NOW()),(2,2002,1,1,1,'2026-11-10','OT_billable',1,'approved',1,'payroll:run#999991',NOW())");
 $pdo->exec("INSERT INTO placement_economic_parties(id,tenant_id,placement_id,source_ref,source_type,role,display_name,person_id,money_flow,settlement_channel) VALUES(1,2002,1,'manual:test','manual','recruiter','Synthetic source',1,'payable','payroll')");
 $pdo->exec("INSERT INTO placement_economic_obligations(tenant_id,placement_id,economic_party_id,source_type,source_ref_id,amount,currency,status,payroll_ref_id) VALUES(2002,1,1,'manual',1,12.34,'USD','payroll',999991),(2002,1,1,'manual',2,0.01,'USD','payroll',999991)");
 $hours=[20=>['hours_regular'=>2.0,'hours_overtime'=>1.0]];
 $check(payrollRunExtractedHours(999991)===$hours,'native time keeps reviewed identity and canonical overtime categories');
 $check(payrollRunEconomicEarnings(999991)===[20=>1235],'native earnings sum exact USD cents once');
 $pdo->exec("UPDATE people SET email_primary='changed@example.invalid' WHERE id=1");
 $check(payrollRunExtractedHours(999991)===$hours&&payrollRunEconomicEarnings(999991)===[20=>1235],'contact edits cannot redirect sources');
 $pdo->exec('UPDATE time_entries SET person_id=2 WHERE id=1');
 $check($fails(fn()=>payrollRunExtractedHours(999991),'reviewed person/employment link'),'unlinked time cannot disappear or use email');
 $pdo->exec('UPDATE time_entries SET person_id=1 WHERE id=1');
 $pdo->exec("UPDATE placement_rates SET currency='INR' WHERE id=1");
 $check($fails(fn()=>payrollRunExtractedHours(999991),'explicit USD currency'),'foreign rate is not relabelled as USD');
 $pdo->exec("UPDATE placement_rates SET currency='USD',approved_at=NULL WHERE id=1");
 $check($fails(fn()=>payrollRunExtractedHours(999991),'approved time and rate'),'unapproved rate is refused');
 $pdo->exec('UPDATE placement_rates SET approved_at=NOW(),placement_id=2 WHERE id=1');
 $check($fails(fn()=>payrollRunExtractedHours(999991),'approved time and rate'),'another placement rate is refused');
 $pdo->exec('UPDATE placement_rates SET placement_id=1 WHERE id=1');
 $pdo->exec("UPDATE placement_economic_obligations SET currency='INR' WHERE source_ref_id=1");
 $check($fails(fn()=>payrollRunEconomicEarnings(999991),'explicit USD currency'),'foreign commission is not added to USD earnings');
 $pdo->exec("UPDATE placement_economic_obligations SET currency='USD' WHERE source_ref_id=1");
 $pdo->exec('UPDATE payroll_profiles SET enabled=0 WHERE id=1');
 $check($fails(fn()=>payrollRunExtractedHours(999991),'reviewed person/employment link')&&$fails(fn()=>payrollRunEconomicEarnings(999991),'unambiguous payroll recipient'),'disabled profile blocks both source consumers');
 $pdo->exec('UPDATE payroll_profiles SET enabled=1 WHERE id=1');
 $pdo->exec('UPDATE people_employees SET user_id=99');$pdo->exec('UPDATE placement_economic_parties SET person_id=NULL,user_id=99');
 $check($fails(fn()=>payrollRunEconomicEarnings(999991),'unambiguous payroll recipient'),'ambiguous account-only payee is refused');
 $pdo->exec('UPDATE placement_economic_parties SET person_id=1,user_id=NULL,placement_id=2');
 $check($fails(fn()=>payrollRunEconomicEarnings(999991),'unambiguous payroll recipient'),'cross-placement economic party cannot receive payroll');
 $pdo->exec('UPDATE placement_economic_parties SET placement_id=1');
 $roster=payrollRunSourceEmployees([],$hours,[20=>1235]);
 $check(count($roster['employees'])===1&&(int)$roster['employees'][0]['employee_id']===20&&$roster['economic_only']===[],'explicitly routed hours remain on the compute roster');
 $check(payrollRunSourceEmployees([],[],[20=>1235])['economic_only']===[20=>true],'commission-only source suppresses regular earnings');
 $check(payrollRunExtractedHours(999992)===[]&&payrollRunEconomicEarnings(999992)===[],'other run is isolated');
 $pdo->exec('UPDATE time_entries SET tenant_id=999999');$pdo->exec('UPDATE placement_economic_obligations SET tenant_id=999999');
 $check(payrollRunExtractedHours(999991)===[]&&payrollRunEconomicEarnings(999991)===[],'other company sources are isolated');
}catch(Throwable$e){$report['failure']=$e->getMessage();}
finally{foreach(array_reverse($created)as$table)$pdo->exec('DROP TEMPORARY TABLE `'.$table.'`');}
$after=psSnapshot($pdo);
$report['original_rows_preserved']=$before===$after;
$report['tables_verified']=count($before);$report['rows_preserved']=array_sum(array_column($before,'rows'));$report['runtime_pins_verified']=count($pins);
$report['passed']=!isset($report['failure'])&&$report['original_rows_preserved']&&count($report['checks'])===15;
file_put_contents(PS_PRIVATE.'/payroll-source-acceptance-result.json',json_encode($report,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
echo json_encode($report,JSON_THROW_ON_ERROR);exit($report['passed']?0:1);
