<?php
/** Stored source review and apply with real graph writes, enclosed in a disposable rollback. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('COREFLUX_ENV')!=='coreaccounting'
    ||getenv('COREFLUX_STANDALONE_DATABASE')!=='coreaccounting_ci'
    ||!in_array('--confirm-disposable-fixture',$argv,true))exit(2);
require_once __DIR__.'/../core/jobdiva/sync.php';
$pdo=getDB();if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='coreaccounting_ci')exit(2);
$tenant=(int)$pdo->query('SELECT MIN(id) FROM tenants')->fetchColumn();if($tenant<=0)exit(2);
setRequestTenantId($tenant);$_SESSION['tenant_id']=$tenant;
$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;echo "PASS $message\n";};
$failure=static function(callable $fn):string{try{$fn();return '';}catch(Throwable $e){return $e->getMessage();}};
$read=static fn(string $table):array=>getDB()->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$financial=[$read('accounting_journal_entries'),$read('accounting_journal_entry_lines'),$read('accounting_events')];
$pdo->beginTransaction();
try{
    $person=scopedInsert('people',['first_name'=>'CI Review','last_name'=>'Worker','email_primary'=>'ci-review-worker@example.invalid','classification'=>'w2','external_id'=>'jd:99150002']);
    $billCompany=companiesUpsertByName($tenant,'CI Review Billing',[],['client']);
    $client=staffingClientEnsureForCompany($tenant,$billCompany,'CI Review Billing')['client_id'];
    $owned=['start_date','end_date','client_bill_cycle','client_payment_terms_override'];
    $placement=scopedInsert('placements',['person_id'=>$person,'title'=>'CI Core review title','status'=>'on_hold','start_date'=>'2026-10-01','end_date'=>'2026-12-31','engagement_type'=>'w2','external_id'=>'jd:99150001','coreflux_overridden_fields'=>json_encode($owned),
        'client_id'=>$client,'end_client_company_id'=>$billCompany,'end_client_name'=>'CI Review Billing','client_bill_cycle'=>'monthly','client_payment_terms_override'=>'NET10','vendor_pay_cycle'=>'biweekly','vendor_payment_terms_override'=>'NET30','vendor_pwp_enabled'=>0,'worksite_city'=>'Newark','worksite_country'=>'US']);
    scopedInsert('placement_rates',['placement_id'=>$placement,'effective_from'=>'2026-10-01','bill_rate'=>100,'pay_rate'=>50,'approved_at'=>'2026-10-01 12:00:00']);
    placementEconomicsReconcile($tenant,$placement);
    $payload=['startId'=>'99150001','candidateId'=>'99150002','jobId'=>'99150003','startDate'=>'2026-10-10','title'=>'CI Source review title','status'=>'on_hold','endClientName'=>'CI Review Billing','worksiteCountry'=>'United States',
        '_jd_contract'=>['start_id'=>'99150001','engagement_type'=>'w2','placement_status'=>'on_hold','start_date'=>'2026-10-10','end_date'=>'2026-11-30','client_company_name'=>'CI Review Billing','end_client_name'=>'CI Review Billing','client_bill_cycle'=>'weekly','vendor_pay_cycle'=>'biweekly','client_payment_terms'=>'NET60','vendor_payment_terms'=>'NET30','paid_when_paid'=>false,'worksite_country'=>'United States','worksite_city'=>'Hamilton','bill_rate'=>100,'pay_rate'=>50,'bill_rate_unit'=>'hour','pay_rate_unit'=>'hour','currency'=>'USD']];
    mappingUpsert($tenant,'jobdiva','jobdiva_assignment','99150001',$placement,$payload);
    $preview=static fn():array=>jobdivaStoredAssignmentProjectionPlan($tenant,20,[],false);
    $reviewRow=static function(array $plan):array{foreach($plan['public_rows'] as $row)if($row['start_id']==='99150001')return $row;throw new RuntimeException('Synthetic source review missing');};
    $before=jobdivaPlacementProjectionAuditSnapshot($tenant,$placement);$plan=$preview();$row=$reviewRow($plan);
    $check($row['selectable']===true,'Complete synthetic source contract is selectable: '.implode('; ',$row['errors']));
    $fields=array_column($row['contract']['fields'],null,'field');
    $check($fields['start_date']['handling']==='retain_core'&&$fields['client_payment_terms']['handling']==='retain_core','Preview distinguishes retained Core dates and terms');
    $check($fields['worksite_city']['handling']==='source_proposal','Unowned differing source value remains a proposal');
    $check(count($row['contract']['ownership_conflicts'])===4&&$plan['summary']['retained_core_fields']>=4,'Compact public preview retains ownership decisions and their count');
    $check(jobdivaPlacementProjectionAuditSnapshot($tenant,$placement)===$before,'Source review does not change placement or commercial records');
    scopedUpdate('placements',$placement,['coreflux_overridden_fields'=>json_encode([...$owned,'worksite_city'])]);
    $changed=$preview();
    $check($changed['dry_run_token']!==$plan['dry_run_token'],'Changing only field ownership invalidates the review token');
    $beforeStale=jobdivaPlacementProjectionAuditSnapshot($tenant,$placement);
    $message=$failure(fn()=>jobdivaApplyStoredAssignmentProjection($tenant,null,['99150001'],$plan['dry_run_token'],20));
    $check(str_contains($message,'changed after preview')&&jobdivaPlacementProjectionAuditSnapshot($tenant,$placement)===$beforeStale,'Stale ownership review is rejected without placement changes');
    $pdo->prepare("UPDATE placement_economic_parties SET payment_terms_overridden=1 WHERE tenant_id=? AND placement_id=? AND source_ref='placement:bill_to'")->execute([$tenant,$placement]);
    $partyPlan=$preview();
    $check($partyPlan['dry_run_token']!==$changed['dry_run_token'],'Participant ownership changes invalidate the source review too');
    scopedUpdate('placements',$placement,['coreflux_overridden_fields'=>'{}']);
    $bad=$reviewRow($preview());
    $check(!$bad['selectable']&&in_array('review_required',array_column($bad['contract']['fields'],'handling'),true),'Malformed ownership blocks that row and exposes the review requirement');
    scopedUpdate('placements',$placement,['coreflux_overridden_fields'=>json_encode($owned)]);
    $ready=$preview();$result=jobdivaApplyStoredAssignmentProjection($tenant,null,['99150001'],$ready['dry_run_token'],20);
    $check($result['projected']===1&&$result['retained_core_fields']===4,'Stored apply reports the actual retained-field count');
    $retained=$result['rows'][0]['ownership_conflicts'];
    $check(count($retained)===4&&array_column($retained,'handling')===array_fill(0,4,'retain_core'),'Apply returns retained field values for operator review');
    $after=placementAuditRow($placement);
    $check($after['start_date']==='2026-10-01'&&$after['end_date']==='2026-12-31'&&$after['client_payment_terms_override']==='NET10'&&$after['worksite_city']==='Hamilton','Apply retains reviewed terms while refreshing an unowned field');
    $check((int)$after['person_id']===$person&&$after['external_id']==='jd:99150001','Stored review preserves the canonical person and assignment identities');
    $stable=jobdivaPlacementProjectionAuditSnapshot($tenant,$placement);$fresh=$preview();
    $again=jobdivaApplyStoredAssignmentProjection($tenant,null,['99150001'],$fresh['dry_run_token'],20);
    $check($again['retained_core_fields']===4&&jobdivaPlacementProjectionAuditSnapshot($tenant,$placement)===$stable,'Freshly reviewed replay preserves the commercial graph and retained decisions');
    $check($pdo->inTransaction(),'Stored apply does not commit its caller transaction');
    $check([$read('accounting_journal_entries'),$read('accounting_journal_entry_lines'),$read('accounting_events')]===$financial,'Review and reconciliation create no financial postings');
}finally{if($pdo->inTransaction())$pdo->rollBack();setRequestTenantId(null);}
echo "$checks JobDiva contract review checks passed.\n";
