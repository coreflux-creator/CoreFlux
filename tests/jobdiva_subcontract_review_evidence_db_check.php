<?php
/** Saved subcontract-company evidence: real graph, all invented rows rolled back. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'coreaccounting'
    || getenv('COREFLUX_STANDALONE_DATABASE') !== 'coreaccounting_ci'
    || !in_array('--confirm-disposable-fixture',$argv,true)) exit(2);
require_once __DIR__ . '/../core/jobdiva/sync.php';
$pdo=getDB();if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='coreaccounting_ci')exit(2);
$tenant=(int)$pdo->query('SELECT MIN(id) FROM tenants')->fetchColumn();if($tenant<=0)exit(2);
setRequestTenantId($tenant);$_SESSION['tenant_id']=$tenant;
$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";};
$failure=static function(callable $fn):string{try{$fn();return '';}catch(Throwable $e){return $e->getMessage();}};
$read=static function(string $table):array{
    $rows=getDB()->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
    usort($rows,static fn($a,$b)=>strcmp(json_encode($a,JSON_THROW_ON_ERROR),json_encode($b,JSON_THROW_ON_ERROR)));
    return $rows;
};
$tables=['companies','company_roles','external_entity_mappings','placements','placement_rates','placement_corp_details','placement_economic_parties','staffing_operating_cycles','ap_vendors_index'];
$snapshot=static fn():array=>array_map($read,$tables);
$financial=[$read('accounting_events'),$read('accounting_journal_entries'),$read('accounting_journal_entry_lines')];
try {
JobdivaStoredReviewTransaction::run(static function()use($pdo,$tenant,$check,$failure,$read,$snapshot,$financial):void{
    $person=scopedInsert('people',['first_name'=>'CI Saved','last_name'=>'Subcontract','email_primary'=>'saved-subcontract@example.invalid','classification'=>'c2c','external_id'=>'jd:99181002']);
    $billing=companiesUpsertByName($tenant,'CI Saved Evidence Billing',[],['client']);
    $client=staffingClientEnsureForCompany($tenant,$billing,'CI Saved Evidence Billing')['client_id'];
    $placement=scopedInsert('placements',['person_id'=>$person,'title'=>'CI saved company review','status'=>'on_hold','engagement_type'=>'c2c',
        'start_date'=>'2026-10-01','end_date'=>'2026-12-31','external_id'=>'jd:99181001','client_id'=>$client,'end_client_company_id'=>$billing,
        'end_client_name'=>'CI Saved Evidence Billing','client_bill_cycle'=>'monthly','client_payment_terms_override'=>'NET30','vendor_pay_cycle'=>'biweekly',
        'vendor_payment_terms_override'=>'NET30','vendor_pwp_enabled'=>0,'worksite_country'=>'US']);
    scopedInsert('placement_rates',['placement_id'=>$placement,'effective_from'=>'2026-10-01','bill_rate'=>100,'pay_rate'=>50,'c2c_overhead_pct'=>0,'approved_at'=>'2026-10-01 12:00:00']);
    $payload=['startId'=>'99181001','candidateId'=>'99181002','jobId'=>'99181003','startDate'=>'2026-10-01','title'=>'CI saved company review',
        'status'=>'on_hold','endClientName'=>'CI Saved Evidence Billing','worksiteCountry'=>'United States',
        '_jd_contract'=>['start_id'=>'99181001','engagement_type'=>'c2c','placement_status'=>'on_hold','start_date'=>'2026-10-01','end_date'=>'2026-12-31',
            'client_company_name'=>'CI Saved Evidence Billing','end_client_name'=>'CI Saved Evidence Billing','client_bill_cycle'=>'monthly','vendor_pay_cycle'=>'biweekly',
            'client_payment_terms'=>'NET30','vendor_payment_terms'=>'NET30','paid_when_paid'=>false,'worksite_country'=>'United States',
            'bill_rate'=>100,'pay_rate'=>50,'bill_rate_unit'=>'hour','pay_rate_unit'=>'hour','currency'=>'USD','corporation_id'=>'99181101']];
    mappingUpsert($tenant,'jobdiva','jobdiva_assignment','99181001',$placement,$payload);
    $preview=static function()use($tenant):array{
        $plan=jobdivaStoredAssignmentProjectionPlan($tenant,20,[],false);
        $plan['target']=array_values(array_filter($plan['rows'],static fn($row)=>$row['start_id']==='99181001'))[0]??null;
        if (!$plan['target']) throw new RuntimeException('Synthetic assignment missing from review page');
        return $plan;
    };
    $apply=static fn(string $token):array=>jobdivaApplyStoredAssignmentProjection($tenant,null,['99181001'],$token,20);
    $evidence=static fn(array $contract=[]):array=>jobdivaStoredSubcontractCompanyEvidence($tenant,'99181101',$contract);
    $stable=$snapshot();$missing=$preview();
    $check(!$missing['target']['selectable']&&$missing['summary']['missing_subcontract_company']>=1,'Missing subcontract company is blocked before apply');
    $check(!$missing['target']['contract']['complete']&&str_contains(implode(' ',$missing['target']['errors']),'then refresh this preview'),'Contract completeness and visible guidance explain the missing saved evidence');
    $check($snapshot()===$stable,'Preview creates no company, vendor, role, mapping or placement record');
    $check(str_contains($failure(fn()=>$apply($missing['dry_run_token'])),'blocked')&&$snapshot()===$stable,'A blocked review cannot be applied and makes no changes');
    $check(!$evidence(['corporation_id'=>'different-id','corporation_name'=>'CI Unrelated Company'])['available'],'A different assignment company ID cannot supply the missing name');
    $exact=$evidence(['corporation_id'=>'99181101','corporation_name'=>'CI Exact Named Company']);
    $check($exact['available']&&$exact['source']==='assignment_contract'&&$snapshot()===$stable,'Exact assignment name is accepted as read-only evidence');
    $check(!$evidence(['corporation_id'=>'99181101','corporation_name'=>'JobDiva Company 99181101'])['available'],'Placeholder assignment names do not unblock a company');
    $pdo->exec('INSERT INTO tenants(name,is_active,is_simulation,tenant_type) VALUES("CI Foreign Subcontract Evidence",1,1,"master")');
    $foreign=(int)$pdo->lastInsertId();
    $foreignCompany=companiesUpsertByName($foreign,'CI Foreign Subcontractor',[],['vendor']);
    mappingUpsert($foreign,'jobdiva','jobdiva_company','99181101',99181101,['id'=>'99181101','name'=>'CI Foreign Subcontractor']);
    $check(!$evidence()['available'],'Another tenant company mirror does not satisfy this assignment');
    mappingUpsert($tenant,'jobdiva','company','99181101',$foreignCompany);
    $check(!$evidence()['available'],'A mapping to another tenant company does not supply a valid company identity');
    $q=$pdo->prepare('SELECT * FROM companies WHERE tenant_id=? AND id=?');$q->execute([$foreign,$foreignCompany]);$foreignBefore=$q->fetch(PDO::FETCH_ASSOC);
    mappingUpsert($tenant,'jobdiva','jobdiva_company','99181101',99181101,['id'=>'different-id','name'=>'CI Wrong Identity']);
    $check(!$evidence()['available']&&!$preview()['target']['selectable'],'Mismatched saved payload identity cannot unblock its mapping key');
    mappingUpsert($tenant,'jobdiva','jobdiva_company','99181101',99181101,['id'=>'99181101','name'=>'JobDiva Company 99181101']);
    $check(!$evidence()['available'],'Saved placeholder names remain blocked');
    $mirror=['id'=>'99181101','name'=>'CI Saved Subcontractor','phone'=>'555-0101'];
    mappingUpsert($tenant,'jobdiva','jobdiva_company','99181101',99181101,$mirror);
    $q=$pdo->prepare('UPDATE external_entity_mappings SET sync_status="error" WHERE tenant_id=? AND internal_entity_type="jobdiva_company" AND external_id="99181101"');$q->execute([$tenant]);
    $check(!$evidence()['available'],'A failed saved company mirror is not treated as verified evidence');
    mappingUpsert($tenant,'jobdiva','jobdiva_company','99181101',99181101,$mirror);
    $ready=$preview();$row=$ready['target'];
    $check($row['selectable']&&$row['contract']['subcontract_company']['source']==='saved_company'&&$row['contract']['complete'],'A verified saved company unblocks the exact assignment');
    $check(!array_key_exists('payload',$row['contract']['subcontract_company']),'Public preview includes provenance without exposing the saved company payload');
    $mirror['phone']='555-0102';mappingUpsert($tenant,'jobdiva','jobdiva_company','99181101',99181101,$mirror);
    $changed=$preview();$afterSourceChange=$snapshot();
    $check($changed['dry_run_token']!==$ready['dry_run_token'],'A company payload change invalidates the prior assignment review');
    $check(str_contains($failure(fn()=>$apply($ready['dry_run_token'])),'changed after preview')&&$snapshot()===$afterSourceChange,'Stale company evidence is rejected before any business write');
    $result=$apply($changed['dry_run_token']);$binding=mappingFindInternal($tenant,'jobdiva','company','99181101');$companyId=(int)($binding['internal_entity_id']??0);
    $check($result['projected']===1&&$companyId>0,'Reviewed saved company evidence projects through the canonical writer');
    $q=$pdo->prepare('SELECT company_id,corp_legal_name FROM placement_corp_details WHERE tenant_id=? AND placement_id=?');$q->execute([$tenant,$placement]);$corp=$q->fetch(PDO::FETCH_ASSOC);
    $check((int)$corp['company_id']===$companyId&&$corp['corp_legal_name']==='CI Saved Subcontractor','Applied corporate payee matches the reviewed provider company');
    $current=placementAuditRow($placement);
    $check((int)$current['person_id']===$person&&$current['external_id']==='jd:99181001'&&$binding['external_id']==='99181101','Person, assignment and provider company identities are preserved');
    $q=$pdo->prepare('SELECT * FROM companies WHERE tenant_id=? AND id=?');$q->execute([$foreign,$foreignCompany]);
    $check($companyId!==$foreignCompany&&$q->fetch(PDO::FETCH_ASSOC)===$foreignBefore,'Resolving this tenant company leaves the foreign company unchanged');
    $stable=$snapshot();$mapped=$evidence();
    $check($mapped['source']==='existing_company'&&$mapped['company_id']===$companyId&&$snapshot()===$stable,'Reading a mapped company does not add roles or change records');
    $ready=$preview();$pdo->prepare('UPDATE companies SET name=? WHERE tenant_id=? AND id=?')->execute(['CI Renamed Subcontractor',$tenant,$companyId]);
    $afterRename=$snapshot();
    $check(str_contains($failure(fn()=>$apply($ready['dry_run_token'])),'changed after preview')&&$snapshot()===$afterRename,'A canonical company rename invalidates an older assignment review');
    $ready=$preview();$apply($ready['dry_run_token']);$binding=mappingFindInternal($tenant,'jobdiva','company','99181101');
    $check((int)$binding['internal_entity_id']===$companyId,'Fresh review reuses the existing Core company ID');
    $graph=jobdivaPlacementProjectionAuditSnapshot($tenant,$placement);$ready=$preview();$apply($ready['dry_run_token']);
    $check(jobdivaPlacementProjectionAuditSnapshot($tenant,$placement)===$graph,'Freshly reviewed replay preserves the canonical business graph');
    $check([$read('accounting_events'),$read('accounting_journal_entries'),$read('accounting_journal_entry_lines')]===$financial,'Saved-company review creates no financial postings');
},true);
    $check(!$pdo->inTransaction()&&!JobdivaStoredReviewTransaction::active(),'The fixture rolls back all synthetic business records and clears its scope');
} finally {if($pdo->inTransaction())$pdo->rollBack();setRequestTenantId(null);}
echo "$checks subcontract company evidence checks passed.\n";
