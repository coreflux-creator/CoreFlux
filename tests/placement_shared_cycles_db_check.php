<?php
/** Real shared schedule writes, under an outer rollback, without provider or financial execution. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('COREFLUX_ENV')!=='coreaccounting'||getenv('COREFLUX_STANDALONE_DATABASE')!=='coreaccounting_ci'||!in_array('--confirm-disposable-fixture',$argv,true))exit(2);
require_once __DIR__.'/../modules/placements/lib/cycle_edits.php';
require_once __DIR__.'/../modules/placements/lib/economics_edit.php';
require_once __DIR__.'/../core/jobdiva/sync.php';
require_once __DIR__.'/../core/integrations/purepay_assignment_contract.php';
$pdo=getDB();if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='coreaccounting_ci')exit(2);
$tenant=(int)$pdo->query('SELECT MIN(id) FROM tenants')->fetchColumn();if($tenant<=0)exit(2);
setRequestTenantId($tenant);$_SESSION['tenant_id']=$tenant;
$checks=0;$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";};
$refused=static function(callable $fn,int $code):bool{try{$fn();return false;}catch(RuntimeException|InvalidArgumentException $e){return $e->getCode()===$code;}};
$read=static function(string $table):array{$rows=getDB()->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);usort($rows,static fn($a,$b)=>strcmp(serialize($a),serialize($b)));return $rows;};
$tables=['tenants','people','placements','placement_rates','placement_economic_parties','placement_corp_details','placement_economic_obligations','companies','company_roles','ap_vendors_index','staffing_operating_cycles','payroll_pay_cycles','payroll_pay_schedules','payroll_pay_periods','payroll_profiles','audit_log'];
$snapshot=static fn():array=>array_map($read,$tables);
$financialTables=['accounting_events','accounting_journal_entries','accounting_journal_entry_lines','payroll_runs','ap_payments','billing_payments'];
$financial=array_map($read,$financialTables);$original=$snapshot();$pdo->beginTransaction();
try{
    $person=scopedInsert('people',['first_name'=>'CI Shared','last_name'=>'Schedule','classification'=>'1099','email_primary'=>'shared-cycle@example.invalid']);
    $company=companiesUpsertByName($tenant,'CI Shared Schedule Client',[],['client']);$client=staffingClientEnsureForCompany($tenant,$company,'CI Shared Schedule Client')['client_id'];
    $seed=static function(string $model,string $start)use($tenant,$person,$client):int{
        $id=scopedInsert('placements',['person_id'=>$person,'client_id'=>$client,'title'=>'CI shared schedule','status'=>'on_hold','engagement_type'=>$model,'start_date'=>'2026-10-01','external_id'=>'jd:'.$start]);
        placementEconomicsReconcile($tenant,$id);return $id;
    };
    $first=$seed('1099','99187001');$second=$seed('1099','99187011');$employee=$seed('w2','99187021');
    $party=static function(int $id)use($tenant,$person):array{$q=getDB()->prepare('SELECT * FROM placement_economic_parties WHERE tenant_id=? AND placement_id=? AND source_ref=?');$q->execute([$tenant,$id,'worker:'.$person]);return $q->fetch(PDO::FETCH_ASSOC);};
    $cycle=placementCycleCreate($tenant,['name'=>'CI Shared AP','purpose'=>'ap','cadence'=>'biweekly','anchor_date'=>'2026-10-05','default_payment_terms'=>'NET30']);$id=$cycle['id'];
    placementCycleAssign($tenant,$first,['ap_operating_cycle_id'=>$id],placementCycleReview($tenant,$first)['review_token']);
    $review=placementCycleSharedReview($tenant,$id);$stable=$snapshot();
    $check(count($review['placements'])===1&&count($review['participants'])===1&&strlen($review['review_token'])===64&&$snapshot()===$stable,'Shared review identifies the actual consumers without changing business or audit rows');
    $check($refused(fn()=>placementCycleSharedReview($tenant+1000000,$id),404)&&$snapshot()===$stable,'Shared review enforces the schedule workspace');
    $edit=static fn(array $input,bool $payroll=false):array=>placementCycleSharedEdit($tenant,$id,$input,$payroll);
    $body=['cadence'=>'weekly','anchor_date'=>'2026-11-02','default_payment_terms'=>'NET45','settlement_offset_days'=>4,'review_token'=>$review['review_token'],'confirm'=>'UPDATE_SHARED_SCHEDULE'];
    $check($refused(fn()=>$edit(array_replace($body,['confirm'=>''])),422)&&$snapshot()===$stable,'A shared change requires an explicit impact confirmation');
    placementCycleAssign($tenant,$second,['ap_operating_cycle_id'=>$id],placementCycleReview($tenant,$second)['review_token']);$stable=$snapshot();
    $check($refused(fn()=>$edit($body),409)&&$snapshot()===$stable,'A newly linked placement invalidates the old shared review');
    placementEconomicsApplyNativePartyEdit($tenant,(int)$party($second)['id'],['payment_terms'=>'NET15']);
    scopedInsert('placement_rates',['placement_id'=>$first,'effective_from'=>'2026-10-01','bill_rate'=>100,'pay_rate'=>50,'approved_at'=>'2026-10-01 12:00:00','economics_snapshot_json'=>json_encode(['cycles'=>[$cycle['cycle']],'fixture'=>'Historical approved contract'])]);$rates=$read('placement_rates');
    $review=placementCycleSharedReview($tenant,$id);$body['review_token']=$review['review_token'];$stable=$snapshot();
    foreach([['anchor_date'=>'2026-02-30'],['active'=>null],['active'=>'false'],['purpose'=>'billing'],['name'=>[]],['default_payment_terms'=>'bad'],['settlement_offset_days'=>32768]] as $bad){
        $check($refused(fn()=>$edit(array_replace($body,$bad)),422)&&$snapshot()===$stable,'Invalid shared definition is rejected before any linked record changes');
    }
    $check($refused(fn()=>$edit(['active'=>0,'review_token'=>$review['review_token'],'confirm'=>'UPDATE_SHARED_SCHEDULE']),409)&&$snapshot()===$stable,'A schedule with linked placements cannot be disabled underneath them');
    $pdo->exec('CREATE TEMPORARY TABLE audit_log (id BIGINT,tenant_id BIGINT,target_id BIGINT,event VARCHAR(100),meta_json JSON)');
    try{$before=array_map($read,array_slice($tables,0,-1));$check($refused(fn()=>$edit($body),503)&&array_map($read,array_slice($tables,0,-1))===$before&&$pdo->inTransaction(),'Audit failure rolls back the definition and every linked placement in the caller transaction');}
    finally{$pdo->exec('DROP TEMPORARY TABLE audit_log');}
    $check($snapshot()===$stable,'Failed shared save preserves all original audit and business rows');
    $saved=$edit($body);$p1=placementAuditRow($first);$p2=placementAuditRow($second);
    $check($saved['changed']&&$saved['affected_placement_ids']===[$first,$second]&&$p1['vendor_pay_cycle']==='weekly'&&$p2['vendor_pay_cycle']==='weekly'&&$p1['vendor_pay_cycle_anchor']==='2026-11-02'&&$p2['vendor_pay_cycle_anchor']==='2026-11-02','One shared edit updates all reviewed placement cadences and anchors atomically');
    $check((int)$p1['ap_operating_cycle_id']===$id&&(int)$p2['ap_operating_cycle_id']===$id&&(int)$party($first)['operating_cycle_id']===$id&&(int)$party($second)['operating_cycle_id']===$id,'Shared editing retains every selected operating schedule and participant identity');
    $check($party($first)['payment_terms']==='NET45'&&$party($second)['payment_terms']==='NET15','New AP defaults reach inheriting payees while explicit participant terms stay owned');
    $check($read('placement_rates')===$rates,'Approved economic snapshots keep their historical schedule and terms unchanged');
    $export=purepayAssignmentCycle($saved['cycle'],true);
    $check($export['id']===$id&&$export['cadence']==='weekly'&&$export['anchor_date']==='2026-11-02'&&$export['source_system']==='coreflux','PurePay current commercial schedule serialization carries the changed definition with its original Core identity');
    $stable=$snapshot();$again=$edit($body);
    $check(!$again['changed']&&$snapshot()===$stable,'An exact shared edit retry confirms success without duplicate audit or downstream changes');
    $check($refused(fn()=>$edit(array_replace($body,['name'=>'CI stale rename'])),409)&&$snapshot()===$stable,'A consumed shared review cannot apply a different change');
    jobdivaSyncUpsertPlacement($tenant,$person,null,['startId'=>'99187001','candidateId'=>'99187002','jobId'=>'99187003','startDate'=>'2026-10-01','status'=>'on_hold','title'=>'Source after shared schedule','__cf_force_source_contract'=>true,
        '_jd_contract'=>['start_id'=>'99187001','engagement_type'=>'1099','placement_status'=>'on_hold','start_date'=>'2026-10-01','vendor_pay_cycle'=>'monthly']],'99187001');
    $after=placementAuditRow($first);$check($after['vendor_pay_cycle']==='weekly'&&$after['vendor_pay_cycle_anchor']==='2026-11-02'&&(int)$after['ap_operating_cycle_id']===$id&&$after['title']==='Source after shared schedule','JobDiva refresh preserves a shared Core schedule while updating unrelated fields');
    $standard=placementEconomicsEnsureStandardCycle($tenant,'billing','monthly');$standardReview=placementCycleSharedReview($tenant,$standard);$stable=$snapshot();
    $check($refused(fn()=>placementCycleSharedEdit($tenant,$standard,['name'=>'Do not rename source frequency','review_token'=>$standardReview['review_token'],'confirm'=>'UPDATE_SHARED_SCHEDULE']),409)&&$snapshot()===$stable,'Shared editing cannot repurpose a standard or source-managed frequency');
    $payroll=placementCycleCreate($tenant,['name'=>'CI Shared Payroll','purpose'=>'payroll','cadence'=>'biweekly','anchor_date'=>'2026-10-05','settlement_offset_days'=>5]);$payId=$payroll['id'];
    placementCycleAssign($tenant,$employee,['payroll_operating_cycle_id'=>$payId],placementCycleReview($tenant,$employee)['review_token']);
    $payReview=placementCycleSharedReview($tenant,$payId);$payBody=['anchor_date'=>'2026-10-12','settlement_offset_days'=>7,'review_token'=>$payReview['review_token'],'confirm'=>'UPDATE_SHARED_SCHEDULE'];$stable=$snapshot();
    $check($refused(fn()=>placementCycleSharedEdit($tenant,$payId,$payBody),403)&&$snapshot()===$stable,'Placement management alone cannot change an existing payroll bridge');
    $paySaved=placementCycleSharedEdit($tenant,$payId,$payBody,true);$emp=placementAuditRow($employee);
    $q=$pdo->prepare('SELECT pc.*,ps.frequency,ps.period_start_anchor,ps.pay_date_offset_days FROM payroll_pay_cycles pc JOIN payroll_pay_schedules ps ON ps.tenant_id=pc.tenant_id AND ps.id=pc.schedule_id WHERE pc.tenant_id=? AND pc.id=?');$q->execute([$tenant,$payroll['payroll_pay_cycle_id']]);$bridge=$q->fetch(PDO::FETCH_ASSOC);
    $check($paySaved['changed']&&(int)$emp['payroll_cycle_id']===(int)$payroll['payroll_pay_cycle_id']&&$emp['vendor_pay_cycle_anchor']==='2026-10-12'&&$bridge['anchor_date_override']==='2026-10-12'&&$bridge['period_start_anchor']==='2026-10-12'&&(int)$bridge['pay_date_offset_days_override']===7&&(int)$bridge['pay_date_offset_days']===7&&(int)$bridge['next_period_number']===1,'A permitted payroll edit aligns both bridge layers without advancing or replacing their identities');
    $pdo->prepare('UPDATE payroll_pay_cycles SET pay_date_offset_days_override=9 WHERE tenant_id=? AND id=?')->execute([$tenant,$bridge['id']]);$stable=$snapshot();
    $check($refused(fn()=>placementCycleSharedEdit($tenant,$payId,$payBody,true),409)&&$snapshot()===$stable,'An unchanged edit retry cannot report success for a payroll bridge that has drifted');
    $pdo->prepare('UPDATE payroll_pay_cycles SET pay_date_offset_days_override=7 WHERE tenant_id=? AND id=?')->execute([$tenant,$bridge['id']]);
    scopedInsert('payroll_pay_periods',['schedule_id'=>$bridge['schedule_id'],'cycle_id'=>$bridge['id'],'period_number'=>1,'period_start'=>'2026-10-12','period_end'=>'2026-10-25','pay_date'=>'2026-11-01','status'=>'draft']);$periods=$read('payroll_pay_periods');
    $payReview=placementCycleSharedReview($tenant,$payId);$stable=$snapshot();
    $check($payReview['payroll']['generated_period_count']===1&&$refused(fn()=>placementCycleSharedEdit($tenant,$payId,array_replace($payBody,['review_token'=>$payReview['review_token'],'anchor_date'=>'2026-10-19']),true),409)&&$snapshot()===$stable,'Generated periods require a replacement calendar rather than in-place reanchoring');
    $renamed=placementCycleSharedEdit($tenant,$payId,['name'=>'CI Shared Payroll Renamed','review_token'=>$payReview['review_token'],'confirm'=>'UPDATE_SHARED_SCHEDULE'],true);
    $check($renamed['changed']&&$read('payroll_pay_periods')===$periods,'Renaming a used payroll schedule preserves all generated period values');
    $extra=scopedInsert('payroll_pay_cycles',['name'=>'CI Separate Cohort','schedule_id'=>$bridge['schedule_id'],'active'=>1]);$payReview=placementCycleSharedReview($tenant,$payId);$stable=$snapshot();
    $check($payReview['payroll']['other_cohort_count']===1&&$refused(fn()=>placementCycleSharedEdit($tenant,$payId,['name'=>'Do not rename other cohort calendar','review_token'=>$payReview['review_token'],'confirm'=>'UPDATE_SHARED_SCHEDULE'],true),409)&&$snapshot()===$stable,'Another cohort cannot be changed through an operating schedule that did not review its consumers');
    $unused=placementCycleCreate($tenant,['name'=>'CI Unused Shared Schedule','purpose'=>'billing','cadence'=>'weekly','anchor_date'=>'2026-11-02']);$unusedReview=placementCycleSharedReview($tenant,$unused['id']);
    $disabled=placementCycleSharedEdit($tenant,$unused['id'],['active'=>0,'review_token'=>$unusedReview['review_token'],'confirm'=>'UPDATE_SHARED_SCHEDULE']);
    $check($disabled['changed']&&$disabled['cycle']['active']===0,'An unused native schedule can be disabled with an audit');
    $unusedReview=placementCycleSharedReview($tenant,$unused['id']);$enabled=placementCycleSharedEdit($tenant,$unused['id'],['active'=>1,'review_token'=>$unusedReview['review_token'],'confirm'=>'UPDATE_SHARED_SCHEDULE']);
    $check($enabled['changed']&&$enabled['cycle']['active']===1,'An unused native schedule can be reactivated through a fresh review');
    $review=placementCycleSharedReview($tenant,$id);$body['review_token']=$review['review_token'];$body['name']='CI Shared After Review';
    $main=$pdo;$secondDb=new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);$secondDb->exec('SET SESSION innodb_lock_wait_timeout=1');$pdo=$secondDb;
    try{try{$edit($body);$check(false,'Competing shared writer must wait');}catch(PDOException $e){$check((int)($e->errorInfo[1]??0)===1205&&!$secondDb->inTransaction(),'Competing shared edits wait on placement locks and clean up after timeout');}}
    finally{$pdo=$main;$secondDb=null;}
    $check(array_map($read,$financialTables)===$financial,'Shared schedule checks execute no payroll runs payments or financial postings');
}finally{if($pdo->inTransaction())$pdo->rollBack();setRequestTenantId(null);}
$check($snapshot()===$original,'Outer rollback restores every original schedule placement and history row');
echo "$checks shared schedule editing checks passed.\n";
