<?php
/** Installed native consumers, temporary tables only, with full original-row preservation. */
declare(strict_types=1);
const PS_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const PS_PRIVATE='/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030';
if(PHP_SAPI!=='cli'||getcwd()!==PS_ROOT||posix_geteuid()!==1003||getenv('COREFLUX_ENV')!=='staging'
 ||!preg_match('/^[a-f0-9]{64}$/D',$argv[1]??'')||!hash_equals($argv[1],hash_file('sha256',__DIR__.'/commission-terms-pins.json')))exit(2);
require_once PS_ROOT.'/modules/placements/lib/economics.php';
require_once PS_ROOT.'/core/integrations/identity.php';
$pdo=getDB();
if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||!COREFLUX_STAGING
 ||integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf'||COREFLUX_INTEGRATION_DELIVERY_TENANTS!=='2002'
 ||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=2002')->fetchColumn()!==1)exit(2);
$pins=json_decode(file_get_contents(__DIR__.'/commission-terms-pins.json'),true,64,JSON_THROW_ON_ERROR);
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
$tables=['placements','placement_commissions','placement_economic_parties','placement_economic_obligations'];
$created=[];$before=psSnapshot($pdo);
file_put_contents(PS_PRIVATE.'/commission-terms-before.private.json',json_encode($before,JSON_THROW_ON_ERROR));chmod(PS_PRIVATE.'/commission-terms-before.private.json',0600);
try{
 setRequestTenantId(2002);setRequestModuleScope('placements');
 if(effectiveTenantIdForModule('people',2002)!==2002||effectiveTenantIdForModule('placements',2002)!==2002)throw new RuntimeException('Unexpected fixture directory ownership');
 foreach($tables as$table){
  $definition=$pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
  if(!str_starts_with($definition,'CREATE TABLE `'.$table.'` (')||str_contains($definition,'FOREIGN KEY'))throw new RuntimeException('Temporary fixture schema requires review');
  $pdo->exec(preg_replace('/^CREATE TABLE /','CREATE TEMPORARY TABLE ',$definition,1));$created[]=$table;
 }

 $pdo->exec("INSERT INTO placements(id,tenant_id,person_id,title,status,engagement_type,start_date) VALUES(1,2002,999999,'Temporary commission fixture','on_hold','w2','2026-05-18')");
 $pdo->exec("INSERT INTO placement_commissions(id,tenant_id,placement_id,role,user_id,basis,flat_amount,currency,payment_frequency,due_month,effective_from) VALUES(1,2002,1,'recruiter',999999,'flat',75,'USD','one_time','2026-08','2026-05-18'),(2,2002,1,'other',999999,'flat',20,'USD','per_pay_period',NULL,'2026-05-18')");
 $pdo->exec("INSERT INTO placement_economic_parties(id,tenant_id,placement_id,source_ref,source_type,source_id,role,display_name,user_id,money_flow,settlement_channel,fee_basis,fee_flat,currency,payment_frequency,due_month,effective_from) VALUES(1,2002,1,'commission:1','commission',1,'commission_recruiter','Synthetic one-time',999999,'payable','payroll','flat',75,'USD','one_time','2026-08','2026-05-18'),(2,2002,1,'commission:2','commission',2,'commission_other','Synthetic recurring',999999,'payable','payroll','flat',20,'USD','per_pay_period',NULL,'2026-05-18')");
 $rows=$pdo->query('SELECT * FROM placement_commissions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
 $check($rows[0]['currency']==='USD'&&$rows[0]['due_month']==='2026-08'&&$rows[1]['payment_frequency']==='per_pay_period','installed schema stores currency, frequency and month without backfill');
 $parties=$pdo->query('SELECT * FROM placement_economic_parties ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);$once=$parties[0];$rec=$parties[1];
 $foreign=$once;$foreign['currency']='INR';$foreign['fee_flat']='7500.00';
 $model=placementEconomicsModelForRate(2002,1,['currency'=>'USD','bill_rate'=>98,'pay_rate'=>65],[$once,$foreign],null,'w2');
 $check($model['fixed_obligations']===75.0&&$model['fixed_obligations_by_currency']===['INR'=>7500.0,'USD'=>75.0],'installed model separates INR and USD totals');
 $claim=static fn(array$t,int$s,int$r,string$d='2026-08-20'):int=>placementEconomicsRecordObligation(2002,1,(int)$t['id'],'time_bundle',$s,['commission_terms'=>$t,'period_start'=>$d,'period_end'=>$d,'quantity'=>1,'amount'=>$t['fee_flat'],'currency'=>$t['currency'],'status'=>'payroll','payroll_ref_id'=>$r]);
 $check($fails(fn()=>$claim($once,1,999991,'2026-07-31'),'due month has not arrived'),'future due month cannot be claimed');
 $check($claim($once,1,999991)>0,'native final writer claims a synthetic one-time commission');
 $check($claim($once,2,999992)===0,'another batch and run cannot duplicate a one-time commission');
 $changed=$once;$changed['fee_flat']='90.00';
 $check($fails(fn()=>$claim($changed,3,999993),'different commission amount'),'conflicting amount cannot replace the original claim');
 $check($fails(fn()=>$claim($foreign,4,999994),'settlement terms'),'INR cannot enter native USD payroll');
 $check($fails(fn()=>placementCommissionProtectConsumed($pdo,2002,1,1,['user_id'=>999998]),'financial workflow'),'consumed recipient identity is retained');
 $check(placementCommissionTermsIssue(['basis'=>'flat'])!==null,'unknown legacy currency and frequency require review');
 $check($claim($rec,5,999995)>0&&$claim($rec,6,999995)===0&&$claim($rec,7,999996)>0,'recurring fixed amounts are claimed once per recipient run');
 $stale=$rec;$stale['user_id']=999998;
 $check($fails(fn()=>$claim($stale,8,999997),'recipient changed'),'stale approved recipient cannot redirect a commission');
 $check($fails(fn()=>placementCommissionClaim(999998,1,1,'time_bundle',9,['commission_terms'=>$once]),'Placement not found'),'another tenant cannot claim the placement');
 $pdo->exec('UPDATE placement_economic_parties SET active=0 WHERE id=2');
 $check($fails(fn()=>$claim($rec,10,999998),'participant needs review'),'retired participant cannot acquire new claims');
 $terms=placementCommissionTermsInput(['currency'=>'inr','payment_frequency'=>'one_time','due_month'=>'2026-08']);
 $check($terms===['currency'=>'INR','payment_frequency'=>'one_time','due_month'=>'2026-08'],'terms preserve month precision without inventing a due date');
 $check((int)$pdo->query('SELECT COUNT(*) FROM placement_economic_obligations')->fetchColumn()===3&&(float)$pdo->query('SELECT SUM(amount) FROM placement_economic_obligations')->fetchColumn()===115.0,'exact synthetic claim count and currency total reconcile');
}catch(Throwable$e){$report['failure']=$e->getMessage();}
finally{foreach(array_reverse($created)as$table)$pdo->exec('DROP TEMPORARY TABLE `'.$table.'`');}
$after=psSnapshot($pdo);
$report['original_rows_preserved']=$before===$after;
$report['tables_verified']=count($before);$report['rows_preserved']=array_sum(array_column($before,'rows'));$report['runtime_pins_verified']=count($pins);
$report['passed']=!isset($report['failure'])&&$report['original_rows_preserved']&&count($report['checks'])===15;
file_put_contents(PS_PRIVATE.'/commission-terms-acceptance-result.json',json_encode($report,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
echo json_encode($report,JSON_THROW_ON_ERROR);exit($report['passed']?0:1);
