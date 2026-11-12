<?php
/** Synthetic source-rule operator and invented placement in the isolated pilot. */
declare(strict_types=1);
const FR_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const FR_PRIVATE='/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030';
const FR_STATE=FR_PRIVATE.'/source-rule-browser-20261112.json';
const FR_CREDENTIAL=FR_PRIVATE.'/source-rule-browser-credential.private.json';
const FR_BEFORE=FR_PRIVATE.'/source-rule-browser-before.private.json';
const FR_EMAIL='core-source-rule-reviewer-20261112@example.invalid';
const FR_MODULES=['people','placements'];
if(PHP_SAPI!=='cli'||getenv('COREFLUX_ENV')!=='staging'||getcwd()!==FR_ROOT||posix_geteuid()!==1003
 ||realpath(FR_PRIVATE)!==FR_PRIVATE||(fileperms(FR_PRIVATE)&0777)!==0700||!in_array($argv[1]??'',['prepare','inspect','finish','retire'],true))exit(2);
require_once FR_ROOT.'/core/db.php';require_once FR_ROOT.'/core/integrations/identity.php';
require_once FR_ROOT.'/core/tenant_scope.php';
require_once FR_ROOT.'/core/memberships.php';require_once FR_ROOT.'/core/rbac/permissions.php';require_once FR_ROOT.'/core/RBAC.php';
$pdo=getDB();
if(!COREFLUX_STAGING||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf'
 ||COREFLUX_INTEGRATION_DELIVERY_TENANTS!=='2002'||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=2002')->fetchColumn()!==1)exit(2);
setRequestTenantId(2002);
$pins=json_decode(file_get_contents(__DIR__.'/source-rule-runtime-pins.json'),true,64,JSON_THROW_ON_ERROR);
foreach($pins as$file=>$hash)if(hash('sha256',str_replace("\r\n","\n",file_get_contents(FR_ROOT.'/'.$file)))!==$hash)throw new RuntimeException('Runtime changed: '.$file);
$lock=fopen(FR_PRIVATE.'/contact-install.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit(2);
function frSave(string $path,array $value,bool $new=false):void{
 if(is_link($path)||realpath(dirname($path))!==FR_PRIVATE)throw new RuntimeException('Invalid private file');
 $old=umask(0077);try{$f=fopen($path,$new?'x':'w');if(!$f)throw new RuntimeException('Cannot save checkpoint');
  $bytes=json_encode($value,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT);if(fwrite($f,$bytes)!==strlen($bytes)||!fflush($f)||!fsync($f))throw new RuntimeException('Incomplete checkpoint');fclose($f);
 }finally{umask($old);}
}
function frSnapshot(PDO $pdo):array{
 $out=[];foreach($pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN)as$t){
  if(!preg_match('/^[A-Za-z0-9_]+$/D',$t))throw new RuntimeException('Unexpected table');$rows=[];
  foreach($pdo->query('SELECT * FROM `'.$t.'`')->fetchAll(PDO::FETCH_ASSOC)as$r)$rows[]=hash('sha256',serialize($r));
  sort($rows,SORT_STRING);$out[$t]=$rows;
 }ksort($out);return$out;
}
if($argv[1]==='prepare'){
 if(file_exists(FR_STATE)||file_exists(FR_CREDENTIAL)||file_exists(FR_BEFORE))throw new RuntimeException('Existing browser fixture must be inspected, never recreated');
 $s=$pdo->prepare('SELECT COUNT(*) FROM users WHERE email=?');$s->execute([FR_EMAIL]);if((int)$s->fetchColumn())throw new RuntimeException('Reserved operator exists');
 frSave(FR_BEFORE,frSnapshot($pdo),true);
 $report=['checked_at'=>gmdate('c'),'status'=>'preparing','passed'=>false,'tenant_id'=>2002,
  'production_changed'=>false,'arabella_changed'=>false,'real_payroll_payments_postings'=>false,
  'feature_sha256'=>hash_file('sha256',dirname(FR_ROOT).'/private_html/coreflux.thunderhawk-pilot.php')];
 frSave(FR_STATE,$report,true);$password=bin2hex(random_bytes(32));$hash=password_hash($password,PASSWORD_DEFAULT);
 $pdo->beginTransaction();try{
  $pdo->prepare('INSERT INTO users(tenant_id,name,email,password,password_hash,role,is_active,is_global_admin) VALUES(2002,"Synthetic source rule reviewer",?,?,?,"admin",1,0)')->execute([FR_EMAIL,$hash,$hash]);
  $actor=(int)$pdo->lastInsertId();
  $membership=(int)provisionMembership($actor,2002,'admin',['is_primary'=>true,'persona_label'=>'Synthetic source rule reviewer','status'=>'active'])['membership_id'];
  $pdo->commit();
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}
 $report['actor_id']=$actor;$report['membership_id']=$membership;$report['status']='operator_created';frSave(FR_STATE,$report);
 foreach(FR_MODULES as$module){
  RBACResolver::grantModule($membership,$module,$module==='placements'?'admin':'read',[2002],4);
  if(!RBACResolver::can($actor,2002,$module,'read',2002)||RBACResolver::can($actor,2002,$module,'write',2002)!==($module==='placements')||RBACResolver::can($actor,2002,$module,'admin',2002)!==($module==='placements'))throw new RuntimeException('Reader access differs');
 }
 foreach(['integrations','treasury','pii']as$module)if(RBACResolver::can($actor,2002,$module,'read'))throw new RuntimeException('Unexpected extra module access');
 foreach(['tenant.manage','ai.enable_auto_execute']as$permission)if(RBAC::hasPermission(['role'=>'admin'],$permission))throw new RuntimeException('Unexpected legacy platform authority');
 require_once FR_ROOT.'/modules/placements/lib/source_rule_review.php';
 $seeded=JobdivaStoredReviewTransaction::run(static function()use($pdo,$actor):array{
 $tenant=2002;$email=FR_EMAIL;$_SESSION['tenant_id']=2002;
 if((int)$pdo->query("SELECT COUNT(*) FROM placements WHERE tenant_id=2002 AND external_id='jd:99190200'")->fetchColumn())throw new RuntimeException('Synthetic source placement already exists');
 $person=scopedInsert('people',['first_name'=>'Synthetic Browser','last_name'=>'Rule Review','classification'=>'1099','external_id'=>'jd:991902002','email_primary'=>'source-rule-browser@example.invalid']);
 $bill=companiesUpsertByName(2002,'Synthetic Browser Billing 2002',[],['client']);
 $client=staffingClientEnsureForCompany(2002,$bill,'Synthetic Browser Billing 2002')['client_id'];
 $own=['person'=>$person,'company'=>$bill,'client'=>$client,'start'=>'99190200'];
$ruleStart='99190200';$ruleTitle='Synthetic Browser reviewed source rules';
$ruleMsp=companiesUpsertByName($tenant,'Synthetic Browser Source MSP',[],['msp']);
companiesUpsertByName($tenant,'Synthetic Browser Source Referrer',[],['referrer','vendor']);
$rulePlacement=scopedInsert('placements',['person_id'=>$own['person'],'title'=>$ruleTitle,'status'=>'on_hold',
    'engagement_type'=>'1099','start_date'=>'2026-10-01','end_date'=>'2026-12-31','external_id'=>'jd:'.$ruleStart,
    'coreflux_overridden_fields'=>'["notes"]','client_id'=>$own['client'],'end_client_company_id'=>$own['company'],
    'end_client_name'=>'Synthetic Browser Billing '.$tenant,'client_bill_cycle'=>'monthly','client_payment_terms_override'=>'NET30',
    'vendor_pay_cycle'=>'biweekly','vendor_payment_terms_override'=>'NET30','vendor_pwp_enabled'=>0,'worksite_country'=>'US']);
scopedInsert('placement_rates',['placement_id'=>$rulePlacement,'effective_from'=>'2026-10-01','bill_rate'=>100,'pay_rate'=>50,'approved_at'=>'2026-10-01 12:00:00']);
$rulePayload=['startId'=>$ruleStart,'candidateId'=>$own['start'].'2','jobId'=>$ruleStart.'3','startDate'=>'2026-10-01',
    'status'=>'on_hold','title'=>$ruleTitle,'endClientName'=>'Synthetic Browser Billing '.$tenant,'employmentType'=>'1099',
    'worksiteCountry'=>'US','mspName'=>'Synthetic Browser Source MSP','mspFeePct'=>2,
    'recruiterEmail'=>$email,'recruiterCommissionPct'=>10,'referralVendor'=>'Synthetic Browser Source Referrer','referralFeeAmount'=>10,'referralFeeBasis'=>'one_time',
    '_jd_contract'=>['start_id'=>$ruleStart,'engagement_type'=>'1099','placement_status'=>'on_hold','start_date'=>'2026-10-01','end_date'=>'2026-12-31',
        'client_company_name'=>'Synthetic Browser Billing '.$tenant,'end_client_name'=>'Synthetic Browser Billing '.$tenant,
        'client_bill_cycle'=>'monthly','vendor_pay_cycle'=>'biweekly','client_payment_terms'=>'NET30','vendor_payment_terms'=>'NET30',
        'paid_when_paid'=>false,'worksite_country'=>'US','worksite_city'=>'Hamilton',
        'bill_rate'=>100,'pay_rate'=>50,'bill_rate_unit'=>'hour','pay_rate_unit'=>'hour','currency'=>'USD']];
mappingUpsert($tenant,'jobdiva','jobdiva_assignment',$ruleStart,$rulePlacement,$rulePayload);
$ruleChain=jobdivaSyncUpsertPlacementChain($tenant,$rulePlacement,$own['company'],'Synthetic Browser Billing '.$tenant,$rulePayload)['rows'];
$ruleCommission=jobdivaSyncUpsertPlacementCommissions($tenant,$rulePlacement,'2026-10-01',$rulePayload)['rows']['recruiter'];
$ruleReferral=jobdivaSyncUpsertPlacementReferral($tenant,$rulePlacement,'2026-10-01',$rulePayload);
placementEconomicsReconcile($tenant,$rulePlacement);
$ruleActor=['id'=>$actor,'email'=>$email,'name'=>'Synthetic contract reviewer'];
$ruleEdit=static fn(string $kind,int $id,array $body):array=>placementSourceRuleEdit($tenant,$rulePlacement,$kind,'update',$id,$body,$ruleActor);
$ruleEdit('chain',$ruleChain['msp'],['portal_fee_pct'=>'0.0300']);
$ruleEdit('commission',$ruleCommission,['split_pct'=>'0.2000']);
$ruleEdit('referral',$ruleReferral,['fee_flat'=>'12.00']);

 $q=$pdo->prepare('SELECT id FROM companies WHERE tenant_id=2002 AND name IN (?,?,?)');$q->execute(['Synthetic Browser Billing 2002','Synthetic Browser Source MSP','Synthetic Browser Source Referrer']);
 return ['person'=>$person,'placement'=>$rulePlacement,'chain'=>$ruleChain,'commission'=>$ruleCommission,'referral'=>$ruleReferral,'companies'=>array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN))];
 });
 $report['fixture']=$seeded;frSave(FR_STATE,$report);
 frSave(FR_CREDENTIAL,['username'=>FR_EMAIL,'password'=>$password],true);unset($password,$hash);
 $report['status']='browser_ready';$report['grants']=['people'=>'read','placements'=>'admin'];frSave(FR_STATE,$report);
 echo json_encode(['status'=>'browser_ready','actor_id'=>$actor,'membership_id'=>$membership,'grants'=>$report['grants'],'credential_printed'=>false,'fixture'=>$seeded]);exit;
}
$report=json_decode(file_get_contents(FR_STATE),true,64,JSON_THROW_ON_ERROR);
if($argv[1]==='inspect'){echo json_encode($report,JSON_THROW_ON_ERROR);exit;}
if(($report['status']??null)!=='browser_ready')throw new RuntimeException('Inspect fixture before cleanup');
$actor=(int)$report['actor_id'];$membership=(int)$report['membership_id'];
try{
 $fixture=$report['fixture'];$pid=(int)$fixture['placement'];
 require_once FR_ROOT.'/modules/placements/lib/source_rule_review.php';
 $q=$pdo->prepare("SELECT actor_user_id,meta_json FROM audit_log WHERE tenant_id=2002 AND target_id=? AND event='placement.source_rules.replaced'");$q->execute([$pid]);$audits=$q->fetchAll(PDO::FETCH_ASSOC);
 $report['replacement_receipts']=array_map(static fn($r)=>['actor'=>(int)$r['actor_user_id'],'result'=>json_decode($r['meta_json'],true,512,JSON_THROW_ON_ERROR)['result']],$audits);
 if($argv[1]==='finish'){
  if(count($audits)!==3||count(array_filter($audits,static fn($r)=>(int)$r['actor_user_id']===$actor))!==3)throw new RuntimeException('Three authenticated replacement receipts required');
  $owned=placementOwnershipFields(placementAuditRow($pid)['coreflux_overridden_fields']);
  if(array_intersect(['chain_rules','commission_rules','referral_rules'],$owned)||!in_array('notes',$owned,true))throw new RuntimeException('Source ownership differs');
  foreach(['chain'=>array_values($fixture['chain']),'commission'=>[$fixture['commission']],'referral'=>[$fixture['referral']]]as$kind=>$expected){
   $receipts=array_values(array_filter($report['replacement_receipts'],static fn($r)=>$r['result']['kind']===$kind));
   $ids=array_values($receipts[0]['result']['applied']??[]);sort($ids);sort($expected);
   if(count($receipts)!==1||$ids!==$expected||$receipts[0]['result']['retired'])throw new RuntimeException('Matched rule identity differs');
  }
  $q=$pdo->prepare('SELECT portal_fee_pct FROM placement_client_chain WHERE tenant_id=2002 AND id=?');$q->execute([$fixture['chain']['msp']]);if((float)$q->fetchColumn()!==0.02)throw new RuntimeException('MSP fee differs');
  $q=$pdo->prepare('SELECT split_pct FROM placement_commissions WHERE tenant_id=2002 AND id=?');$q->execute([$fixture['commission']]);if((float)$q->fetchColumn()!==0.1)throw new RuntimeException('Commission differs');
  $q=$pdo->prepare('SELECT fee_flat FROM placement_referrals WHERE tenant_id=2002 AND id=?');$q->execute([$fixture['referral']]);if((float)$q->fetchColumn()!==10.0)throw new RuntimeException('Referral differs');
  $report['all_three_source_rules_verified']=true;
 }
 $pdo->beginTransaction();try{
  $pdo->prepare('UPDATE placements SET deleted_at=NOW(),status="ended" WHERE tenant_id=2002 AND id=? AND person_id=?')->execute([$pid,$fixture['person']]);
  $pdo->prepare('UPDATE people SET deleted_at=NOW() WHERE tenant_id=2002 AND id=?')->execute([$fixture['person']]);
  foreach($fixture['companies']as$id)$pdo->prepare('UPDATE companies SET deleted_at=NOW() WHERE tenant_id=2002 AND id=?')->execute([$id]);
  $pdo->commit();
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}
 $report['synthetic_fixture_retired']=true;
 foreach(FR_MODULES as$module)RBACResolver::revokeModule($membership,$module,4);
 deactivateMembership($actor,2002);
 $pdo->prepare('UPDATE users SET is_active=0,password=NULL,password_hash=NULL WHERE tenant_id=2002 AND id=? AND email=?')->execute([$actor,FR_EMAIL]);
 $s=$pdo->prepare('SELECT COUNT(*) FROM membership_module_access WHERE membership_id=?');$s->execute([$membership]);if((int)$s->fetchColumn())throw new RuntimeException('Temporary grants remain');
 $s=$pdo->prepare('SELECT COUNT(*) FROM users WHERE id=? AND tenant_id=2002 AND email=? AND is_active=0 AND password IS NULL AND password_hash IS NULL');$s->execute([$actor,FR_EMAIL]);if((int)$s->fetchColumn()!==1)throw new RuntimeException('Actor retirement differs');
 if(!is_file(FR_CREDENTIAL)||is_link(FR_CREDENTIAL)||!unlink(FR_CREDENTIAL))throw new RuntimeException('Temporary credential cleanup failed');
 $before=json_decode(file_get_contents(FR_BEFORE),true,64,JSON_THROW_ON_ERROR);$after=frSnapshot($pdo);
 if(array_keys($before)!==array_keys($after))throw new RuntimeException('Schema changed');
 $allowed=['users','tenant_memberships','user_tenants','membership_audit','audit_log','rbac_bridge_audit','people','companies','company_roles','ap_vendors_index','staffing_clients','placements','placement_rates','placement_client_chain','placement_referrals','placement_commissions','placement_economic_parties','staffing_operating_cycles','external_entity_mappings'];$added=[];
 foreach($before as$table=>$rows){
  if($rows===$after[$table])continue;if(!in_array($table,$allowed,true))throw new RuntimeException('Business rows changed: '.$table);
  $counts=array_count_values($after[$table]);foreach(array_count_values($rows)as$hash=>$n)if(($counts[$hash]??0)<$n)throw new RuntimeException('Original row changed: '.$table);
  if($table==='rbac_bridge_audit')foreach($pdo->query('SELECT * FROM rbac_bridge_audit')->fetchAll(PDO::FETCH_ASSOC)as$row)
   if(!in_array(hash('sha256',serialize($row)),$rows,true)&&((int)$row['tenant_id']!==2002||(int)$row['user_id']!==$actor))throw new RuntimeException('Unrelated permission audit added');
  if(in_array($table,['people','companies','company_roles','ap_vendors_index','staffing_clients','placements','placement_rates','placement_client_chain','placement_referrals','placement_commissions','placement_economic_parties','staffing_operating_cycles','external_entity_mappings'],true))foreach($pdo->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC)as$row){
   if(in_array(hash('sha256',serialize($row)),$rows,true))continue;
   if((int)$row['tenant_id']!==2002)throw new RuntimeException('Unexpected synthetic tenant');
   if(isset($row['placement_id'])&&(int)$row['placement_id']!==$pid)throw new RuntimeException('Unexpected synthetic placement');
   if($table==='people'&&((int)$row['id']!==(int)$fixture['person']||$row['deleted_at']===null))throw new RuntimeException('Synthetic person not retired');
   if($table==='placements'&&((int)$row['id']!==$pid||$row['deleted_at']===null))throw new RuntimeException('Synthetic placement not retired');
   if($table==='companies'&&(!in_array((int)$row['id'],$fixture['companies'],true)||$row['deleted_at']===null))throw new RuntimeException('Synthetic company not retired');
  }
  $added[$table]=count($after[$table])-count($rows);
 }
 if(hash_file('sha256',dirname(FR_ROOT).'/private_html/coreflux.thunderhawk-pilot.php')!==$report['feature_sha256'])throw new RuntimeException('Operational settings changed');
 $report['tables_verified']=count($after);$report['original_rows_preserved']=array_sum(array_map('count',$before));$report['added_rows']=$added;
 $report['operator_retired']=true;$report['original_business_rows_unchanged']=true;$report['passed']=true;$report['status']='complete';
}catch(Throwable $e){$report['failure_class']=get_class($e);$report['failure_line']=$e->getLine();$report['failure_message']=$e->getMessage();$report['status']='needs_inspection';}
$report['finished_at']=gmdate('c');frSave(FR_STATE,$report);echo json_encode($report,JSON_THROW_ON_ERROR);exit($report['passed']?0:1);
