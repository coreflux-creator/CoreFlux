<?php
/** Installed native consumers, temporary tables only, with full original-row preservation. */
declare(strict_types=1);
const PS_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const PS_PRIVATE='/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030';
if(PHP_SAPI!=='cli'||getcwd()!==PS_ROOT||posix_geteuid()!==1003||getenv('COREFLUX_ENV')!=='staging'
 ||!preg_match('/^[a-f0-9]{64}$/D',$argv[1]??'')||!hash_equals($argv[1],hash_file('sha256',__DIR__.'/payroll-receipts-pins.json')))exit(2);
require_once PS_ROOT.'/modules/payroll/lib/payroll.php';
require_once PS_ROOT.'/modules/people/lib/employment_links.php';
require_once PS_ROOT.'/core/integrations/identity.php';
require_once PS_ROOT.'/modules/placements/lib/economics.php';
$pdo=getDB();
if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||!COREFLUX_STAGING
 ||integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf'||COREFLUX_INTEGRATION_DELIVERY_TENANTS!=='2002'
 ||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=2002')->fetchColumn()!==1)exit(2);
$pins=json_decode(file_get_contents(__DIR__.'/payroll-receipts-pins.json'),true,64,JSON_THROW_ON_ERROR);
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

$tables=['placements','people_employees','payroll_profiles','payroll_runs','placement_economic_parties','placement_economic_obligations','placement_payroll_recipient_receipts'];
$created=[];$before=psSnapshot($pdo);
file_put_contents(PS_PRIVATE.'/payroll-receipts-before.private.json',json_encode($before,JSON_THROW_ON_ERROR));chmod(PS_PRIVATE.'/payroll-receipts-before.private.json',0600);
try{
 setRequestTenantId(2002);setRequestModuleScope('payroll');
 if(effectiveTenantIdForModule('people',2002)!==2002||effectiveTenantIdForModule('placements',2002)!==2002)throw new RuntimeException('Unexpected fixture ownership');
 foreach($tables as$table){
  $definition=$pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
  if(!str_starts_with($definition,'CREATE TABLE `'.$table.'` (')||str_contains($definition,'FOREIGN KEY'))throw new RuntimeException('Temporary fixture schema requires review');
  $pdo->exec(preg_replace('/^CREATE TABLE /','CREATE TEMPORARY TABLE ',$definition,1));$created[]=$table;
 }
 $pdo->exec("INSERT INTO placements(id,tenant_id,person_id,title,status,engagement_type,start_date) VALUES(1,2002,999999,'Temporary recipient test','on_hold','w2','2026-11-01')");
 $pdo->exec("INSERT INTO people_employees(id,tenant_id,employee_number,user_id,legal_first_name,legal_last_name,status) VALUES(20,2002,'TEST-20',999999,'Original','Recipient','active'),(21,2002,'TEST-21',NULL,'Later','Recipient','active')");
 $pdo->exec('INSERT INTO payroll_profiles(id,tenant_id,employee_id,enabled) VALUES(1,2002,20,1),(2,2002,21,1)');
 $pdo->exec("INSERT INTO payroll_runs(id,tenant_id,pay_period_id,status) VALUES(999991,2002,999991,'draft'),(999992,2002,999992,'draft')");
 $pdo->exec("INSERT INTO placement_economic_parties(id,tenant_id,placement_id,source_ref,source_type,role,display_name,user_id,money_flow,settlement_channel) VALUES(1,2002,1,'manual:recipient-test','manual','commission_recipient','Original recipient',999999,'payable','payroll')");
 $values=['amount'=>'12.34','quantity'=>1,'currency'=>'USD','status'=>'payroll','payroll_ref_id'=>999991,'period_start'=>'2026-11-09','period_end'=>'2026-11-09'];
 $write=static fn(int$id,array$v=[])=>placementEconomicsWriteObligation(2002,1,1,'manual',$id,array_replace($values,$v));
 $pdo->exec('ALTER TABLE placement_payroll_recipient_receipts MODIFY receipt_json VARCHAR(1) NOT NULL');
 $check($fails(fn()=>$write(1),'Data too long')&&(int)$pdo->query('SELECT COUNT(*) FROM placement_economic_obligations')->fetchColumn()===0,'receipt storage failure rolls back the new earning');
 $pdo->exec('ALTER TABLE placement_payroll_recipient_receipts MODIFY receipt_json LONGTEXT NOT NULL');
 $id=$write(1);$receipt=placementPayrollReceipt($pdo,2002,$id);
 $check($id>0&&(int)$receipt['employee_id']===20&&(int)$receipt['payroll_profile_id']===1,'final writer saves the original employment and company payroll profile');
 $check(payrollRunEconomicEarnings(999991)===[20=>1234],'installed native reader uses exact original recipient and cents');
 $check($write(1)===$id&&placementPayrollReceipt($pdo,2002,$id)===$receipt,'exact retry retains one unchanged receipt');
 foreach([['amount'=>'25.00'],['payroll_ref_id'=>999992],['status'=>'billed']]as$change){
  $check($fails(fn()=>$write(1,$change),'saved payroll earning'),'conflicting retry cannot rewrite an existing earning: '.array_key_first($change));
 }
 $pdo->exec('UPDATE people_employees SET user_id=NULL WHERE id=20');$pdo->exec('UPDATE people_employees SET user_id=999999 WHERE id=21');
 $check(payrollRunEconomicEarnings(999991)===[20=>1234],'account reuse cannot move original earnings to a new employee');
 $pdo->exec('UPDATE placement_economic_parties SET active=0,user_id=888888 WHERE id=1');
 $check(payrollRunEconomicEarnings(999991)===[20=>1234],'later participant edits and retirement cannot redirect the recorded earning');
 $pdo->exec('UPDATE placement_economic_parties SET active=1,user_id=999999 WHERE id=1');
 $pdo->exec('UPDATE payroll_profiles SET enabled=0 WHERE id=1');
 $check($fails(fn()=>payrollRunEconomicEarnings(999991),'no longer enabled'),'disabled original profile requires review instead of selecting a replacement');
 $pdo->exec('UPDATE payroll_profiles SET enabled=1 WHERE id=1');
 $check($fails(fn()=>$write(2,['payroll_ref_id'=>999990]),'draft payroll run'),'new earning requires a draft run in this company');
 $check($fails(fn()=>$write(2,['payroll_source_party'=>['user_id'=>888888,'settlement_channel'=>'payroll']]),'approved payroll recipient changed'),'final writer rejects a stale approved recipient');
 $check($fails(fn()=>placementEconomicsWriteObligation(999999,1,1,'manual',2,$values),'Placement not found'),'another tenant cannot claim the placement');
 $pdo->exec('UPDATE placement_economic_obligations SET amount=99 WHERE id='.$id);
 $check($fails(fn()=>payrollRunEconomicEarnings(999991),'earning evidence differs'),'changed source amount cannot pass saved evidence validation');
 $pdo->exec('UPDATE placement_economic_obligations SET amount=12.34 WHERE id='.$id);
 $pdo->prepare('UPDATE placement_payroll_recipient_receipts SET receipt_sha256=? WHERE tenant_id=2002 AND obligation_id=?')->execute([str_repeat('0',64),$id]);
 $check($fails(fn()=>payrollRunEconomicEarnings(999991),'earning evidence differs'),'changed receipt fingerprint is refused');
 $pdo->prepare('UPDATE placement_payroll_recipient_receipts SET receipt_sha256=? WHERE tenant_id=2002 AND obligation_id=?')->execute([$receipt['receipt_sha256'],$id]);
 $pdo->exec('UPDATE placement_economic_obligations SET status="paid" WHERE id='.$id);
 $check($write(1)===$id&&$pdo->query('SELECT status FROM placement_economic_obligations WHERE id='.$id)->fetchColumn()==='paid','exact retry cannot regress the final source status');
 $pdo->exec("INSERT INTO placement_economic_obligations(tenant_id,placement_id,economic_party_id,source_type,source_ref_id,amount,currency,status,payroll_ref_id) VALUES(2002,1,1,'manual',99,25,'USD','payroll',999992)");
 $check($fails(fn()=>payrollRunEconomicEarnings(999992),'no saved payroll recipient'),'unreceipted history is not adopted from current identity');
 $check((int)$pdo->query('SELECT COUNT(*) FROM placement_payroll_recipient_receipts')->fetchColumn()===1&&payrollRunEconomicEarnings(999991)===[20=>1234],'all failed writes leave one original earning receipt and exact readback');
}catch(Throwable$e){$report['failure']=$e->getMessage();}
finally{foreach(array_reverse($created)as$table)$pdo->exec('DROP TEMPORARY TABLE `'.$table.'`');}
$after=psSnapshot($pdo);$report['original_rows_preserved']=$before===$after;
$report['tables_verified']=count($before);$report['rows_preserved']=array_sum(array_column($before,'rows'));$report['runtime_pins_verified']=count($pins);
$report['passed']=!isset($report['failure'])&&$report['original_rows_preserved']&&count($report['checks'])===18;
file_put_contents(PS_PRIVATE.'/payroll-receipts-acceptance-result.json',json_encode($report,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
echo json_encode($report,JSON_THROW_ON_ERROR);exit($report['passed']?0:1);
