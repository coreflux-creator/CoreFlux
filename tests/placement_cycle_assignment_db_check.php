<?php
/** Reviewed native schedules against real disposable tables; every invented row is rolled back. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('COREFLUX_ENV')!=='coreaccounting'||getenv('COREFLUX_STANDALONE_DATABASE')!=='coreaccounting_ci'||!in_array('--confirm-disposable-fixture',$argv,true))exit(2);
require_once __DIR__.'/../modules/placements/lib/cycle_assignments.php';
require_once __DIR__.'/../modules/placements/lib/economics_edit.php';
require_once __DIR__.'/../core/jobdiva/sync.php';
$pdo=getDB();if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='coreaccounting_ci')exit(2);
$tenant=(int)$pdo->query('SELECT MIN(id) FROM tenants')->fetchColumn();if($tenant<=0)exit(2);
setRequestTenantId($tenant);$_SESSION['tenant_id']=$tenant;
$checks=0;$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";};
$refused=static function(callable $fn,int $code):bool{try{$fn();return false;}catch(RuntimeException|InvalidArgumentException $e){return $e->getCode()===$code;}};
$read=static function(string $table):array{$rows=getDB()->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);usort($rows,static fn($a,$b)=>strcmp(serialize($a),serialize($b)));return $rows;};
$tables=['people','placements','placement_rates','placement_economic_parties','placement_economic_items','placement_economic_obligations','companies','company_roles','ap_vendors_index','staffing_operating_cycles','payroll_pay_cycles','payroll_pay_schedules','audit_log'];
$snapshot=static fn():array=>array_map($read,$tables);
$financialTables=['accounting_events','accounting_journal_entries','accounting_journal_entry_lines','payroll_runs','ap_payments','billing_payments'];
$financial=array_map($read,$financialTables);$original=$snapshot();
$pdo->beginTransaction();
try{
    $person=scopedInsert('people',['first_name'=>'CI Schedule','last_name'=>'Owner','classification'=>'1099','email_primary'=>'native-cycle@example.invalid']);
    $company=companiesUpsertByName($tenant,'CI Schedule Billing',[],['client']);
    $client=staffingClientEnsureForCompany($tenant,$company,'CI Schedule Billing')['client_id'];
    $seed=static function(string $model,string $external)use($tenant,$person,$client):int{
        $id=scopedInsert('placements',['person_id'=>$person,'client_id'=>$client,'title'=>'CI native schedule','status'=>'on_hold','engagement_type'=>$model,'start_date'=>'2026-10-01','external_id'=>'jd:'.$external,'vendor_pay_cycle'=>'biweekly','client_bill_cycle'=>'monthly']);
        placementEconomicsReconcile($tenant,$id);return $id;
    };
    $placement=$seed('1099','99186001');$employee=$seed('w2','99186011');
    $party=static function(int $id,string $ref)use($tenant):array{$q=getDB()->prepare('SELECT * FROM placement_economic_parties WHERE tenant_id=? AND placement_id=? AND source_ref=?');$q->execute([$tenant,$id,$ref]);return $q->fetch(PDO::FETCH_ASSOC);};
    $make=static fn(array $overrides=[]):array=>placementCycleCreate($tenant,array_replace(['name'=>'CI Custom Billing','purpose'=>'billing','cadence'=>'weekly','anchor_date'=>'2026-11-02','settlement_offset_days'=>4],$overrides));
    $billing=$make();$ap=$make(['name'=>'CI Custom AP','purpose'=>'ap','default_payment_terms'=>'NET45']);
    $payroll=$make(['name'=>'CI Custom Payroll','purpose'=>'payroll','cadence'=>'biweekly','anchor_date'=>'2026-10-05','settlement_offset_days'=>5]);
    $stable=$snapshot();$review=placementCycleReview($tenant,$placement);
    $check(strlen($review['review_token'])===64&&$snapshot()===$stable,'Schedule review provides a token without changing any business or audit row');
    $check($refused(fn()=>placementCycleReview($tenant+1000000,$placement),404)&&$snapshot()===$stable,'Schedule review cannot disclose another workspace placement');
    $assign=static fn(array $ids,string $token):array=>placementCycleAssign($tenant,$placement,$ids,$token);
    foreach([['billing_operating_cycle_id'=>null],['billing_operating_cycle_id'=>true],['billing_operating_cycle_id'=>[]],['billing_operating_cycle_id'=>$ap['id']],['unknown'=>$billing['id']],[]] as $bad){
        $check($refused(fn()=>$assign($bad,$review['review_token']),422)&&$snapshot()===$stable,'Invalid assignment is rejected without changes');
    }
    $check($refused(fn()=>$assign(['billing_operating_cycle_id'=>$billing['id']],''),409)&&$snapshot()===$stable,'Changed schedule selection requires the reviewed state');
    $saved=$assign(['billing_operating_cycle_id'=>$billing['id'],'ap_operating_cycle_id'=>$ap['id']],$review['review_token']);
    $parent=placementAuditRow($placement);$ar=$party($placement,'placement:bill_to');$worker=$party($placement,'worker:'.$person);
    $check($saved['changed']&&$parent['client_bill_cycle']==='weekly'&&$parent['client_bill_cycle_anchor']==='2026-11-02'&&(int)$parent['billing_operating_cycle_id']===$billing['id']&&(int)$ar['operating_cycle_id']===$billing['id']&&(int)$ar['cycle_overridden']===1,'Billing choice aligns placement cadence anchor and bill-to ownership');
    $check($parent['vendor_pay_cycle']==='weekly'&&$parent['vendor_pay_cycle_anchor']==='2026-11-02'&&(int)$worker['operating_cycle_id']===$ap['id']&&(int)$worker['cycle_overridden']===1&&$worker['payment_terms']==='NET45','AP schedule aligns the primary payee and supplies unowned default terms');
    $stable=$snapshot();$again=$assign(['billing_operating_cycle_id'=>$billing['id'],'ap_operating_cycle_id'=>$ap['id']],$review['review_token']);
    $check(!$again['changed']&&$snapshot()===$stable,'An exact retry using its old review confirms the original assignment without another audit');
    placementEconomicsApplyNativePartyEdit($tenant,(int)$ar['id'],['cadence'=>'weekly']);
    $check($snapshot()===$stable,'Choosing the same frequency retains the exact custom schedule and anchor');
    $newBilling=$make(['name'=>'CI Other Weekly Billing','anchor_date'=>'2026-11-09']);$stable=$snapshot();
    $check($refused(fn()=>$assign(['billing_operating_cycle_id'=>$newBilling['id']],$review['review_token']),409)&&$snapshot()===$stable,'An old review cannot replace the selected schedule');
    $review=placementCycleReview($tenant,$employee);
    $assigned=placementCycleAssign($tenant,$employee,['ap_operating_cycle_id'=>$ap['id']],$review['review_token']);$emp=placementAuditRow($employee);
    $check($assigned['changed']&&$emp['vendor_pay_cycle']==='biweekly'&&!in_array('vendor_pay_cycle',placementOwnershipFields($emp['coreflux_overridden_fields']),true),'Secondary AP choice on W2 does not claim or alter payroll frequency');
    $owned=jobdivaContractOwnedFields(['placement'=>$emp,'economic_parties'=>[$party($employee,'worker:'.$person)]]);
    $check(!in_array('vendor_pay_cycle',$owned,true),'JobDiva ownership projection keeps secondary AP separate from primary payroll');
    jobdivaSyncUpsertPlacement($tenant,$person,null,['startId'=>'99186011','candidateId'=>'99186012','jobId'=>'99186013','startDate'=>'2026-10-01','status'=>'on_hold','title'=>'Refreshed employee title','__cf_force_source_contract'=>true,
        '_jd_contract'=>['start_id'=>'99186011','engagement_type'=>'w2','placement_status'=>'on_hold','start_date'=>'2026-10-01','vendor_pay_cycle'=>'monthly']],'99186011');
    $emp=placementAuditRow($employee);
    $check($emp['vendor_pay_cycle']==='monthly'&&(int)$emp['ap_operating_cycle_id']===$ap['id'],'Unowned payroll frequency refreshes independently of the Core secondary AP schedule');
    $review=placementCycleReview($tenant,$employee);
    placementCycleAssign($tenant,$employee,['payroll_operating_cycle_id'=>$payroll['id']],$review['review_token']);$emp=placementAuditRow($employee);
    $check($emp['vendor_pay_cycle']==='biweekly'&&$emp['vendor_pay_cycle_anchor']==='2026-10-05'&&(int)$emp['payroll_cycle_id']===(int)$payroll['payroll_pay_cycle_id']&&(int)$party($employee,'worker:'.$person)['operating_cycle_id']===$payroll['id'],'Payroll choice aligns staffing summary payee and native payroll bridge');
    $owned=placementOwnershipFields($emp['coreflux_overridden_fields']);
    $check(in_array('payroll_operating_cycle_id',$owned,true)&&!in_array('payroll_cycle_id',$owned,true),'The explicit operating schedule is owned while its compatibility pointer remains derived');
    $pdo->prepare('UPDATE payroll_pay_cycles SET pay_date_offset_days_override=9 WHERE tenant_id=? AND id=?')->execute([$tenant,$payroll['payroll_pay_cycle_id']]);$stable=$snapshot();
    $check($refused(fn()=>placementCycleAssign($tenant,$employee,['payroll_operating_cycle_id'=>$payroll['id']],placementCycleReview($tenant,$employee)['review_token']),409)&&$snapshot()===$stable,'A conflicting payroll bridge is refused even on an otherwise identical retry');
    $pdo->prepare('UPDATE payroll_pay_cycles SET pay_date_offset_days_override=5 WHERE tenant_id=? AND id=?')->execute([$tenant,$payroll['payroll_pay_cycle_id']]);
    $standard=placementEconomicsEnsureStandardCycle($tenant,'payroll','monthly');
    placementCycleAssign($tenant,$employee,['payroll_operating_cycle_id'=>$standard],placementCycleReview($tenant,$employee)['review_token']);
    $check(placementAuditRow($employee)['payroll_cycle_id']===null,'A schedule without a bridge clears the previous compatibility pointer');
    $stable=$snapshot();$same=$make();
    $check(!$same['changed']&&$same['id']===$billing['id']&&$snapshot()===$stable,'Repeating native schedule creation preserves its identity without another receipt');
    $check($refused(fn()=>$make(['anchor_date'=>'2026-11-09']),409)&&$snapshot()===$stable,'An existing name cannot silently change or adopt different schedule settings');
    foreach([['anchor_date'=>'2026-02-30'],['anchor_date'=>''],['name'=>''],['purpose'=>'unknown'],['cadence'=>'daily'],['purpose'=>'payroll','cadence'=>'adhoc'],['purpose'=>'payroll','settlement_offset_days'=>128],['settlement_offset_days'=>true],['default_payment_terms'=>'NET30'],['purpose'=>'ap','default_payment_terms'=>'bad'],['placement_id'=>0],['name'=>[]]] as $bad){
        $check($refused(fn()=>$make($bad),422)&&$snapshot()===$stable,'Invalid schedule definition creates no operating or payroll records');
    }
    $body=['name'=>'CI Atomic Payroll','purpose'=>'payroll','cadence'=>'biweekly','anchor_date'=>'2026-10-05','settlement_offset_days'=>5,'placement_id'=>$employee,'review_token'=>str_repeat('0',64)];
    $check($refused(fn()=>placementCycleCreate($tenant,$body),409)&&$snapshot()===$stable,'A stale combined create-and-assign rolls back both payroll bridge records and the new schedule');
    $body['review_token']=placementCycleReview($tenant,$employee)['review_token'];
    $pdo->exec('CREATE TEMPORARY TABLE audit_log (id BIGINT,tenant_id BIGINT,target_id BIGINT,event VARCHAR(100),meta_json JSON)');
    try{$before=array_map($read,array_slice($tables,0,-1));$check($refused(fn()=>placementCycleCreate($tenant,$body),503)&&array_map($read,array_slice($tables,0,-1))===$before&&$pdo->inTransaction(),'Unavailable creation audit rolls back all new schedules within the caller transaction');}
    finally{$pdo->exec('DROP TEMPORARY TABLE audit_log');}
    $check($snapshot()===$stable,'Audit failure leaves every original graph payroll schedule and audit row unchanged');
    $created=placementCycleCreate($tenant,$body);$stable=$snapshot();$again=placementCycleCreate($tenant,$body);
    $check($created['created']&&$created['assignment']['changed']&&!$again['changed']&&$again['id']===$created['id']&&$snapshot()===$stable,'Combined creation and assignment commit once and exact retry retains all original IDs');
    $pdo->exec('CREATE TEMPORARY TABLE audit_log (id BIGINT,tenant_id BIGINT,target_id BIGINT,event VARCHAR(100),meta_json JSON)');
    try{$before=array_map($read,array_slice($tables,0,-1));$check($refused(fn()=>$assign(['billing_operating_cycle_id'=>$newBilling['id']],placementCycleReview($tenant,$placement)['review_token']),503)&&array_map($read,array_slice($tables,0,-1))===$before,'Assignment audit failure restores parent payee and ownership together');}
    finally{$pdo->exec('DROP TEMPORARY TABLE audit_log');}
    $before=placementAuditRow($placement);
    jobdivaSyncUpsertPlacement($tenant,$person,null,['startId'=>'99186001','candidateId'=>'99186002','jobId'=>'99186003','startDate'=>'2026-10-01','status'=>'on_hold','title'=>'Refreshed contractor title','__cf_force_source_contract'=>true,
        '_jd_contract'=>['start_id'=>'99186001','engagement_type'=>'1099','placement_status'=>'on_hold','start_date'=>'2026-10-01','client_bill_cycle'=>'monthly','vendor_pay_cycle'=>'monthly']],'99186001');
    $after=placementAuditRow($placement);
    $check($after['title']==='Refreshed contractor title'&&$after['billing_operating_cycle_id']===$before['billing_operating_cycle_id']&&$after['ap_operating_cycle_id']===$before['ap_operating_cycle_id']&&$after['client_bill_cycle_anchor']===$before['client_bill_cycle_anchor']&&$after['vendor_pay_cycle_anchor']===$before['vendor_pay_cycle_anchor'],'JobDiva refresh retains Core custom schedules and stable person placement IDs while unrelated fields update');
    $pdo->prepare('UPDATE staffing_operating_cycles SET active=0 WHERE tenant_id=? AND id=?')->execute([$tenant,$billing['id']]);$stable=$snapshot();
    $reconciled=placementEconomicsReconcile($tenant,$placement);
    $check(!empty($reconciled['errors'])&&$snapshot()===$stable,'An unavailable Core-owned schedule cannot silently fall back during graph reconciliation');
    $check($refused(fn()=>$assign(['billing_operating_cycle_id'=>$billing['id']],placementCycleReview($tenant,$placement)['review_token']),422)&&$snapshot()===$stable,'Inactive schedules cannot be assigned');
    $pdo->prepare('UPDATE staffing_operating_cycles SET active=1 WHERE tenant_id=? AND id=?')->execute([$tenant,$billing['id']]);
    $custom=scopedInsert('staffing_operating_cycles',['name'=>'CI Custom Adhoc','purpose'=>'billing','cadence'=>'adhoc','source_system'=>'coreflux']);
    $resolved=placementEconomicsEnsureStandardCycle($tenant,'billing','adhoc');
    $check($resolved!==$custom,'Selecting a standard frequency does not adopt an unrelated custom schedule of the same frequency');
    $foreign=scopedInsert('staffing_operating_cycles',['name'=>'CI Foreign Cycle','purpose'=>'billing','cadence'=>'weekly']);
    $pdo->prepare('INSERT INTO tenants(id,name,is_active,is_simulation,tenant_type) VALUES(?,?,1,1,"master")')->execute([$tenant+1000000,'CI foreign schedule workspace']);
    $pdo->prepare('UPDATE staffing_operating_cycles SET tenant_id=? WHERE id=?')->execute([$tenant+1000000,$foreign]);$stable=$snapshot();
    $check($refused(fn()=>$assign(['billing_operating_cycle_id'=>$foreign],placementCycleReview($tenant,$placement)['review_token']),422)&&$snapshot()===$stable,'A schedule from another workspace is rejected without touching either graph');
    $main=$pdo;$secondDb=new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);$secondDb->exec('SET SESSION innodb_lock_wait_timeout=1');$pdo=$secondDb;
    try{try{$assign(['billing_operating_cycle_id'=>$newBilling['id']],str_repeat('0',64));$check(false,'Competing assignment must wait');}catch(PDOException $e){$check((int)($e->errorInfo[1]??0)===1205&&!$secondDb->inTransaction(),'Competing schedule assignment waits on the parent and rolls back its timed-out transaction');}}
    finally{$pdo=$main;$secondDb=null;}
    scopedUpdate('placements',$placement,['deleted_at'=>date('Y-m-d H:i:s')]);$stable=$snapshot();
    $check($refused(fn()=>placementCycleReview($tenant,$placement),404)&&$refused(fn()=>$assign(['billing_operating_cycle_id'=>$newBilling['id']],''),404)&&$snapshot()===$stable,'Deleted placements refuse both schedule review and assignment');
    $check(array_map($read,$financialTables)===$financial,'Schedule tests create no payroll runs payments or financial postings');
}finally{if($pdo->inTransaction())$pdo->rollBack();setRequestTenantId(null);}
$check($snapshot()===$original,'Outer rollback restores every original schedule graph and audit row');
echo "$checks native schedule assignment checks passed.\n";
