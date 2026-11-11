<?php
/** Actual contract writer and graph ownership; disposable database, outer rollback. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('COREFLUX_ENV')!=='coreaccounting'
    ||getenv('COREFLUX_STANDALONE_DATABASE')!=='coreaccounting_ci'
    ||!in_array('--confirm-disposable-fixture',$argv,true))exit(2);
require_once __DIR__.'/../core/jobdiva/sync.php';
require_once __DIR__.'/../modules/placements/lib/ownership.php';
$pdo=getDB();if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='coreaccounting_ci')exit(2);
$tenant=(int)$pdo->query('SELECT MIN(id) FROM tenants')->fetchColumn();if($tenant<=0)exit(2);
setRequestTenantId($tenant);$_SESSION['tenant_id']=$tenant;
$checks=0;$failures=[];
$check=static function(bool $ok,string $message)use(&$checks,&$failures):void{
    if($ok){$checks++;echo "PASS $message\n";}else{$failures[]=$message;echo "FAIL $message\n";}
};
$read=static fn(string $table):array=>getDB()->query('SELECT * FROM '.$table.' ORDER BY '.($table==='placement_corp_details'?'placement_id':'id'))->fetchAll(PDO::FETCH_ASSOC);
$financial=[$read('accounting_journal_entries'),$read('accounting_journal_entry_lines'),$read('accounting_events')];
$pdo->beginTransaction();
try{
    $person=scopedInsert('people',['first_name'=>'CI Contract','last_name'=>'Owner','email_primary'=>'ci-contract-owner@example.invalid','classification'=>'1099']);
    $coreEnd=companiesUpsertByName($tenant,'CI Core End Client',[],['client']);
    $sourceEnd=companiesUpsertByName($tenant,'CI Source End Client',[],['client']);
    $billCompany=companiesUpsertByName($tenant,'CI Core Billing',[],['client']);
    $billClient=staffingClientEnsureForCompany($tenant,$billCompany,'CI Core Billing')['client_id'];
    $owned=['engagement_type','start_date','end_date','client_id','end_client_name','end_client_company_id','client_bill_cycle','client_bill_cycle_anchor','client_payment_terms_override','vendor_pay_cycle','vendor_pay_cycle_anchor','vendor_payment_terms_override','vendor_pwp_enabled'];
    $placement=scopedInsert('placements',['person_id'=>$person,'status'=>'on_hold','start_date'=>'2026-10-01','end_date'=>'2026-12-31','engagement_type'=>'1099','title'=>'Core initial title','external_id'=>'jd:99140001','coreflux_overridden_fields'=>json_encode($owned),
        'client_id'=>$billClient,'end_client_company_id'=>$coreEnd,'end_client_name'=>'CI Core End Client','client_bill_cycle'=>'monthly','client_bill_cycle_anchor'=>'2026-10-01','client_payment_terms_override'=>'NET10','vendor_pay_cycle'=>'biweekly','vendor_pay_cycle_anchor'=>'2026-10-05','vendor_payment_terms_override'=>'NET15','vendor_pwp_enabled'=>0]);
    scopedInsert('placement_rates',['placement_id'=>$placement,'effective_from'=>'2026-10-01','bill_rate'=>100,'pay_rate'=>50,'approved_at'=>'2026-10-01 12:00:00']);
    $people=$read('people');$rates=$read('placement_rates');
    placementEconomicsReconcile($tenant,$placement);
    $before=placementAuditRow($placement);
    $payload=['startId'=>'99140001','candidateId'=>'99140002','jobId'=>'99140003','startDate'=>'2026-11-01','title'=>'Fresh source title','status'=>'on_hold','endClientName'=>'CI Source End Client','worksiteCountry'=>'United States','__cf_force_source_contract'=>true,
        '_jd_contract'=>['start_id'=>'99140001','engagement_type'=>'1099','placement_status'=>'on_hold','start_date'=>'2026-11-01','end_date'=>'2026-11-30','client_company_name'=>'CI Source Billing','end_client_name'=>'CI Source End Client','client_bill_cycle'=>'weekly','vendor_pay_cycle'=>'monthly','client_payment_terms'=>'NET60','vendor_payment_terms'=>'NET60','paid_when_paid'=>true,'worksite_country'=>'United States']];
    $same=jobdivaSyncUpsertPlacement($tenant,$person,$sourceEnd,$payload,'99140001');$row=placementAuditRow($placement);
    foreach($owned as $field)$check($row[$field]===$before[$field],'Contract reconciliation preserves Core field '.$field);
    $check($same===$placement&&json_decode($row['coreflux_overridden_fields'],true)===$owned&&$row['external_id']==='jd:99140001'&&(int)$row['person_id']===$person,'Contract reconciliation preserves ownership flags and stable identities');
    $check($row['title']==='Fresh source title','Unrelated source-owned fields still refresh');
    $worker=$pdo->prepare("SELECT * FROM placement_economic_parties WHERE tenant_id=? AND placement_id=? AND role='worker' AND active=1");
    $worker->execute([$tenant,$placement]);$party=$worker->fetch(PDO::FETCH_ASSOC);
    $check($party['payment_terms']==='NET15'&&(int)$party['pwp_enabled']===0&&$party['effective_from']==='2026-10-01'&&$party['effective_to']==='2026-12-31','Derived labor graph uses Core terms, PWP and date boundaries');
    $receivable=$pdo->prepare("SELECT * FROM placement_economic_parties WHERE tenant_id=? AND placement_id=? AND role='bill_to_client' AND active=1");$receivable->execute([$tenant,$placement]);$ar=$receivable->fetch(PDO::FETCH_ASSOC);
    $check((int)$ar['company_id']===$billCompany&&$ar['payment_terms']==='NET10','Derived receivable retains the Core billing identity and terms');
    $snapshot=jobdivaPlacementProjectionAuditSnapshot($tenant,$placement);
    $conflicts=jobdivaProjectorCoreOwnershipConflicts($payload,$snapshot,'99140001');$conflictFields=array_column($conflicts,'field');
    $check(in_array('vendor_payment_terms',$conflictFields,true)&&in_array('client_payment_terms',$conflictFields,true)&&in_array('paid_when_paid',$conflictFields,true)&&in_array('billing_client_name',$conflictFields,true),'Source review reports canonical ownership using the correct display aliases');
    $drift=array_column(jobdivaProjectorSourceContractDrift($payload,$snapshot,'99140001'),'field');
    $check(!array_intersect($drift,['vendor_payment_terms','client_payment_terms','paid_when_paid','billing_client_name','start_date','end_date','end_client_name','client_bill_cycle','vendor_pay_cycle']),'Retained Core fields are not retried as failed source writes');
    // Party-specific review survives even when no placement-level flag exists.
    placementClearOverrides($placement,$owned);
    $cycle=placementEconomicsEnsureStandardCycle($tenant,'ap','weekly');
    placementEconomicsUpdateParty($tenant,(int)$party['id'],['payment_terms'=>'NET20','pwp_enabled'=>false,'operating_cycle_id'=>$cycle]);
    scopedUpdate('placements',$placement,['vendor_payment_terms_override'=>'NET20','vendor_pwp_enabled'=>0,'vendor_pay_cycle'=>'weekly','ap_operating_cycle_id'=>$cycle]);
    $payload['_jd_contract']['start_date']='2026-10-01';$payload['startDate']='2026-10-01';
    jobdivaSyncUpsertPlacement($tenant,$person,$sourceEnd,$payload,'99140001');$row=placementAuditRow($placement);
    $worker->execute([$tenant,$placement]);$reviewed=$worker->fetch(PDO::FETCH_ASSOC);
    $check($reviewed['payment_terms']==='NET20'&&(int)$reviewed['pwp_enabled']===0&&(int)$reviewed['operating_cycle_id']===$cycle
        &&(int)$reviewed['payment_terms_overridden']===1&&(int)$reviewed['pwp_overridden']===1&&(int)$reviewed['cycle_overridden']===1,'Forced reconciliation retains participant-specific values and ownership flags');
    $check($row['vendor_payment_terms_override']==='NET20'&&(int)$row['vendor_pwp_enabled']===0&&$row['vendor_pay_cycle']==='weekly'&&(int)$row['ap_operating_cycle_id']===$cycle,'Participant ownership also protects the canonical placement summary');
    $check($row['end_date']==='2026-11-30'&&$row['client_bill_cycle']==='weekly'&&$row['client_payment_terms_override']==='NET60','Explicit field release lets source-owned contract fields refresh normally');
    $snapshot=jobdivaPlacementProjectionAuditSnapshot($tenant,$placement);
    $check((int)$snapshot['economic_parties'][0]['payment_terms_overridden']>=0&&array_key_exists('cycle_overridden',$snapshot['economic_parties'][0]),'Projection snapshots retain participant field ownership evidence');
    $stableParty=$reviewed;$stableSnapshot=$snapshot;
    jobdivaSyncUpsertPlacement($tenant,$person,$sourceEnd,$payload,'99140001');$worker->execute([$tenant,$placement]);
    $check($worker->fetch(PDO::FETCH_ASSOC)===$stableParty&&jobdivaPlacementProjectionAuditSnapshot($tenant,$placement)===$stableSnapshot,'Contract replay preserves participant identities and reviewed state');
    // Conflicting classification must not leak its rate/payee model into Core.
    scopedUpdate('placements',$placement,['engagement_type'=>'1099','coreflux_overridden_fields'=>'["engagement_type"]']);
    $model=$payload;$model['_jd_contract']['engagement_type']='w2';$model['_jd_contract']['bill_rate']=200;$model['_jd_contract']['pay_rate']=100;
    $model['_jd_contract']['bill_rate_unit']='hour';$model['_jd_contract']['pay_rate_unit']='hour';$model['_jd_contract']['currency']='USD';
    $model['title']='Source title with model conflict';
    $rateBefore=$read('placement_rates');$corpBefore=$read('placement_corp_details');
    jobdivaSyncUpsertPlacement($tenant,$person,$sourceEnd,$model,'99140001');$row=placementAuditRow($placement);
    $check($row['engagement_type']==='1099'&&$row['title']==='Source title with model conflict'&&$read('placement_rates')===$rateBefore&&$read('placement_corp_details')===$corpBefore,'A Core employment-model conflict holds dependent rates and payees while unrelated fields refresh');
    $drift=array_column(jobdivaProjectorSourceContractDrift($model,jobdivaPlacementProjectionAuditSnapshot($tenant,$placement),'99140001'),'field');
    $check(!array_intersect($drift,['engagement_type','bill_rate','pay_rate','vendor_pay_cycle']),'Employment-model conflicts are retained review decisions, not retryable source drift');
    $check($read('people')===$people&&$read('placement_rates')===$rates,'Contract reconciliation preserves people and approved rate history');
    $check([$read('accounting_journal_entries'),$read('accounting_journal_entry_lines'),$read('accounting_events')]===$financial,'Ownership-aware contract refresh creates no financial postings');
}finally{if($pdo->inTransaction())$pdo->rollBack();setRequestTenantId(null);}
if($failures)throw new RuntimeException(implode('; ',$failures));
echo "$checks JobDiva contract ownership checks passed.\n";
