<?php
/** Native/source corporate identity handoff; synthetic records, outer rollback only. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'coreaccounting'
    || getenv('COREFLUX_STANDALONE_DATABASE') !== 'coreaccounting_ci'
    || !in_array('--confirm-disposable-fixture',$argv,true)) exit(2);
require_once __DIR__ . '/../core/jobdiva/sync.php';
require_once __DIR__ . '/../modules/placements/lib/economics_participants.php';
require_once __DIR__ . '/../modules/placements/lib/commercial_ownership.php';
$pdo=getDB();if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='coreaccounting_ci')exit(2);
$tenant=(int)$pdo->query('SELECT MIN(id) FROM tenants')->fetchColumn();if($tenant<=0)exit(2);
setRequestTenantId($tenant);$_SESSION['tenant_id']=$tenant;
$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";};
$refused=static function(callable $fn,int $code):bool{try{$fn();return false;}catch(RuntimeException|InvalidArgumentException $e){return $e->getCode()===$code;}};
$failure=static function(callable $fn):string{try{$fn();return '';}catch(Throwable $e){return $e->getMessage();}};
$read=static function(string $table):array{
    $rows=getDB()->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
    usort($rows,static fn($a,$b)=>strcmp(json_encode($a,JSON_THROW_ON_ERROR),json_encode($b,JSON_THROW_ON_ERROR)));return $rows;
};
$tables=['placements','placement_rates','placement_corp_details','placement_economic_parties','companies','company_roles','external_entity_mappings','ap_vendors_index','staffing_operating_cycles','audit_log'];
$snapshot=static fn():array=>array_map($read,$tables);
$financial=[$read('accounting_events'),$read('accounting_journal_entries'),$read('accounting_journal_entry_lines')];
try {
JobdivaStoredReviewTransaction::run(static function()use($pdo,$tenant,$check,$refused,$failure,$read,$snapshot,$financial):void{
    $person=scopedInsert('people',['first_name'=>'CI Corporate','last_name'=>'Owner','classification'=>'c2c','email_primary'=>'corporate-owner@example.invalid','external_id'=>'jd:99183002']);
    $billing=companiesUpsertByName($tenant,'CI Corporate Billing',[],['client']);
    $client=staffingClientEnsureForCompany($tenant,$billing,'CI Corporate Billing')['client_id'];
    $sourceCompany=companiesUpsertByName($tenant,'CI Source Corporation',[],['vendor']);
    $coreCompany=companiesUpsertByName($tenant,'CI Core Corporation',[],['vendor']);
    mappingUpsert($tenant,'jobdiva','company','99183101',$sourceCompany);
    $placement=scopedInsert('placements',['person_id'=>$person,'title'=>'CI corporate ownership','status'=>'on_hold','engagement_type'=>'c2c',
        'start_date'=>'2026-10-01','end_date'=>'2026-12-31','external_id'=>'jd:99183001','client_id'=>$client,'end_client_company_id'=>$billing,
        'end_client_name'=>'CI Corporate Billing','client_bill_cycle'=>'monthly','client_payment_terms_override'=>'NET30',
        'vendor_pay_cycle'=>'biweekly','vendor_payment_terms_override'=>'NET30','vendor_pwp_enabled'=>0,'worksite_country'=>'US']);
    scopedInsert('placement_rates',['placement_id'=>$placement,'effective_from'=>'2026-10-01','bill_rate'=>100,'pay_rate'=>50,'c2c_overhead_pct'=>0,'approved_at'=>'2026-10-01 12:00:00']);
    $payload=['startId'=>'99183001','candidateId'=>'99183002','jobId'=>'99183003','startDate'=>'2026-10-01','title'=>'CI corporate ownership',
        'status'=>'on_hold','endClientName'=>'CI Corporate Billing','worksiteCountry'=>'United States',
        '_jd_contract'=>['start_id'=>'99183001','engagement_type'=>'c2c','placement_status'=>'on_hold','start_date'=>'2026-10-01','end_date'=>'2026-12-31',
            'client_company_name'=>'CI Corporate Billing','end_client_name'=>'CI Corporate Billing','client_bill_cycle'=>'monthly','vendor_pay_cycle'=>'biweekly',
            'client_payment_terms'=>'NET30','vendor_payment_terms'=>'NET30','paid_when_paid'=>false,'worksite_country'=>'United States',
            'bill_rate'=>100,'pay_rate'=>50,'bill_rate_unit'=>'hour','pay_rate_unit'=>'hour','currency'=>'USD','corporation_id'=>'99183101','corporation_name'=>'CI Source Corporation']];
    $save=static fn(array $p)=>mappingUpsert($tenant,'jobdiva','jobdiva_assignment','99183001',$placement,$p);
    $preview=static function()use($tenant):array{
        $plan=jobdivaStoredAssignmentProjectionPlan($tenant,20,[],false);
        $plan['target']=array_values(array_filter($plan['rows'],static fn($row)=>$row['start_id']==='99183001'))[0]??null;
        if(!$plan['target'])throw new RuntimeException('Synthetic assignment missing from review');return $plan;
    };
    $apply=static fn(string $token):array=>jobdivaApplyStoredAssignmentProjection($tenant,null,['99183001'],$token,20);
    $sync=static fn(array $p):int=>jobdivaSyncUpsertPlacement($tenant,$person,$billing,$p+['__cf_force_source_contract'=>true],'99183001');
    $corp=static function()use($pdo,$tenant,$placement):array{$q=$pdo->prepare('SELECT * FROM placement_corp_details WHERE tenant_id=? AND placement_id=?');$q->execute([$tenant,$placement]);return $q->fetch(PDO::FETCH_ASSOC)?:[];};
    $party=static function()use($pdo,$tenant,$placement):array{$q=$pdo->prepare('SELECT * FROM placement_economic_parties WHERE tenant_id=? AND placement_id=? AND source_ref=?');$q->execute([$tenant,$placement,'corp:'.$placement]);return $q->fetch(PDO::FETCH_ASSOC)?:[];};
    $review=static fn():array=>placementCommercialOwnershipReview($tenant,$placement);
    $release=static fn(array $groups,string $token):array=>placementReleaseCommercialOwnership($tenant,$placement,$groups,$token);
    $nativeBody=['role'=>'c2c_vendor','company_id'=>$coreCompany,'display_name'=>'CI Core Corporation','settlement_channel'=>'ap','payment_terms'=>'NET15','pwp_enabled'=>false,'cadence'=>'weekly'];
    $native=static fn():array=>placementEconomicsCreateNativeParty($tenant,$placement,$nativeBody,[]);
    $save($payload);$ready=$preview();$apply($ready['dry_run_token']);$initial=$party();$partyId=(int)$initial['id'];
    $check($partyId>0&&(int)$initial['source_managed']===1&&(int)$corp()['company_id']===$sourceCompany,'Source creates one corporate participant with its mapped company');
    $pdo->prepare('UPDATE placement_corp_details SET corp_address_line1=? WHERE tenant_id=? AND placement_id=?')->execute(['Source address',$tenant,$placement]);
    $native();$manual=$party();$owned=placementOwnershipFields(placementAuditRow($placement)['coreflux_overridden_fields']);
    $check((int)$manual['id']===$partyId&&!(int)$manual['source_managed']&&in_array('corp_identity',$owned,true),'Core choice reuses the canonical participant and records identity ownership');
    $check((int)$manual['company_id']===$coreCompany&&(int)$corp()['company_id']===$coreCompany&&(int)$corp()['ap_vendor_id']===(int)$manual['ap_vendor_id'],'Core corporate source, participant and AP vendor agree');
    $check($corp()['corp_address_line1']===null,'Changing payee does not retain the former company address');
    $mapping=mappingFindInternal($tenant,'jobdiva','company','99183101');
    $check((int)$mapping['internal_entity_id']===$sourceCompany,'Core choice leaves the JobDiva company mapping unchanged');
    $payload['title']='Fresh JobDiva title';$payload['_jd_contract']['vendor_payment_terms']='NET60';
    $payload['_jd_contract']['vendor_pay_cycle']='monthly';$payload['_jd_contract']['paid_when_paid']=true;
    $sync($payload);$row=placementAuditRow($placement);
    $check($row['title']==='Fresh JobDiva title'&&(int)$corp()['company_id']===$coreCompany&&(int)$party()['company_id']===$coreCompany,'Source refresh changes unrelated fields while preserving Core payee in both records');
    $check($row['vendor_payment_terms_override']==='NET15'&&$corp()['payment_terms_override']==='NET15'&&$party()['payment_terms']==='NET15'&&$row['vendor_pay_cycle']==='weekly','Core terms and schedule remain aligned across placement, source and participant');
    $model=$payload;$model['_jd_contract']['engagement_type']='w2';$model['_jd_contract']['bill_rate']=200;$model['_jd_contract']['pay_rate']=100;
    $rates=$read('placement_rates');$sync($model);
    $check(placementAuditRow($placement)['engagement_type']==='c2c'&&$read('placement_rates')===$rates&&(int)$corp()['company_id']===$coreCompany,'Core corporate identity also retains the C2C model and its dependent rates');
    $missing=$payload;$missing['_jd_contract']['corporation_id']='99183199';unset($missing['_jd_contract']['corporation_name']);$save($missing);
    $stable=$snapshot();$ready=$preview();
    $check($ready['target']['selectable']&&$ready['target']['contract']['subcontract_company']['source']==='core_owned'&&$snapshot()===$stable,'Stored preview uses Core identity without fetching or creating a missing provider company');
    $check(in_array('corp_identity',array_column($ready['target']['contract']['ownership_conflicts'],'field'),true),'Review explicitly displays the retained corporate identity conflict');
    $applied=$apply($ready['dry_run_token']);
    $check($applied['projected']===1&&(int)$corp()['company_id']===$coreCompany&&mappingFindInternal($tenant,'jobdiva','company','99183199')===null,'Reviewed apply retains Core identity without inventing a provider mapping');
    $ready=$preview();$pdo->prepare('UPDATE companies SET name=? WHERE tenant_id=? AND id=?')->execute(['CI Core Corporation Renamed',$tenant,$coreCompany]);$changed=$snapshot();
    $check(str_contains($failure(fn()=>$apply($ready['dry_run_token'])),'changed after preview')&&$snapshot()===$changed,'A Core directory rename invalidates old source review before writes');
    $pdo->prepare('UPDATE companies SET name=? WHERE tenant_id=? AND id=?')->execute(['CI Core Corporation',$tenant,$coreCompany]);
    $ownership=$review();$groups=array_column($ownership['groups'],null,'key');
    $check($groups['corp_identity']['selectable']&&!$groups['vendor_terms']['selectable'],'Identity can be reviewed independently while manual terms remain protected');
    $pdo->prepare('UPDATE placement_economic_parties SET display_name=? WHERE tenant_id=? AND id=?')->execute(['Changed after review',$tenant,$partyId]);$changed=$snapshot();
    $check($refused(fn()=>$release(['corp_identity'],$ownership['review_token']),409)&&$snapshot()===$changed,'A changed corporate participant invalidates ownership release');
    $pdo->prepare('UPDATE placement_economic_parties SET display_name=? WHERE tenant_id=? AND id=?')->execute(['CI Core Corporation',$tenant,$partyId]);
    $ownership=$review();$beforeGraph=jobdivaPlacementProjectionAuditSnapshot($tenant,$placement);
    $pdo->exec('CREATE TEMPORARY TABLE audit_log (id BIGINT,tenant_id BIGINT,target_id BIGINT,event VARCHAR(100),meta_json JSON)');
    try{$check($refused(fn()=>$release(['corp_identity'],$ownership['review_token']),503)&&jobdivaPlacementProjectionAuditSnapshot($tenant,$placement)===$beforeGraph,'Failed release audit restores both parent ownership and manual participant control');}
    finally{$pdo->exec('DROP TEMPORARY TABLE audit_log');}
    $check($refused(fn()=>placementCommercialOwnershipReview($tenant+1000000,$placement),404),'Another tenant cannot review the corporate identity');
    $private=$pdo->prepare('UPDATE placement_corp_details SET corp_ein_ct=? WHERE tenant_id=? AND placement_id=?');
    $private->execute(['synthetic-presence-only',$tenant,$placement]);$stable=$snapshot();$ownership=$review();$groups=array_column($ownership['groups'],null,'key');
    $check(!$groups['corp_identity']['selectable']&&$refused(fn()=>$release(['corp_identity'],$ownership['review_token']),409)&&$snapshot()===$stable,'Tax records block identity release without exposing or detaching them');
    $check($refused(fn()=>placementEconomicsRemoveNativeParty($tenant,$partyId),409)&&$snapshot()===$stable,'Native removal preserves attached corporate tax records');
    $check($refused(fn()=>placementEconomicsCreateNativeParty($tenant,$placement,array_replace($nativeBody,['company_id'=>$sourceCompany]),[]),409)&&$snapshot()===$stable,'A native company change cannot reassign existing private records');
    $private->execute([null,$tenant,$placement]);$ownership=$review();$beforeCorp=$corp();$release(['corp_identity'],$ownership['review_token']);
    $check($corp()===$beforeCorp&&(int)$party()['source_managed']===1&&(int)$party()['active']===1&&!in_array('corp_identity',placementOwnershipFields(placementAuditRow($placement)['coreflux_overridden_fields']),true),'Reviewed release preserves current identity and values until a later source apply');
    $check((int)$party()['payment_terms_overridden']===1&&(int)$party()['cycle_overridden']===1,'Corporate identity release retains separate term and schedule decisions');
    $save($payload);$ready=$preview();$apply($ready['dry_run_token']);
    $check((int)$corp()['company_id']===$sourceCompany&&(int)$party()['company_id']===$sourceCompany&&(int)$party()['id']===$partyId,'Later reviewed source apply changes the payee using the same participant ID');
    $check($corp()['payment_terms_override']==='NET15'&&$party()['payment_terms']==='NET15'&&placementAuditRow($placement)['vendor_payment_terms_override']==='NET15','Released identity does not release Core payment terms');
    $ownership=$review();$release(['vendor_terms'],$ownership['review_token']);$ready=$preview();$apply($ready['dry_run_token']);
    $check($corp()['payment_terms_override']==='NET60'&&$party()['payment_terms']==='NET60'&&placementAuditRow($placement)['vendor_payment_terms_override']==='NET60','A separately reviewed term release updates all three term records');
    $private->execute(['synthetic-presence-only',$tenant,$placement]);
    $replacement=$payload;$replacement['_jd_contract']['corporation_id']='99183102';$replacement['_jd_contract']['corporation_name']='CI Different Source Corporation';$save($replacement);
    $stable=$snapshot();$ready=$preview();
    $check(!$ready['target']['selectable']&&$snapshot()===$stable,'Preview blocks source payee replacement when private corporate records exist');
    $check($refused(fn()=>$sync($replacement),409)&&$snapshot()===$stable,'Direct source replacement rolls back rather than attaching private records to a different company');
    $save($model);$stable=$snapshot();$ready=$preview();
    $check(!$ready['target']['selectable']&&$refused(fn()=>$sync($model),409)&&$snapshot()===$stable,'Source employment-model change cannot discard a private corporate record');
    $private->execute([null,$tenant,$placement]);$save($payload);$native();placementEconomicsRemoveNativeParty($tenant,$partyId);
    $check(!$corp()&&!(int)$party()['active'],'Core removal leaves the canonical participant as an inactive historical record');
    $sync($payload);
    $check(!$corp()&&!(int)$party()['active'],'A later source refresh cannot resurrect a removed Core payee');
    $ready=$preview();$check(!$ready['target']['selectable'],'Stored review explains that the removed Core payee needs restoration or ownership release');
    $ownership=$review();$groups=array_column($ownership['groups'],null,'key');
    $check($groups['corp_identity']['selectable']&&!(int)$groups['corp_identity']['participants'][0]['values']['active'],'Review can explicitly return an inactive corporate identity to JobDiva');
    $release(['corp_identity'],$ownership['review_token']);$ready=$preview();$apply($ready['dry_run_token']);
    $check((int)$party()['id']===$partyId&&(int)$party()['active']===1&&(int)$corp()['company_id']===$sourceCompany,'Reviewed restoration reuses the original participant without duplication');
    $check($corp()['payment_terms_override']==='NET15'&&placementAuditRow($placement)['vendor_payment_terms_override']==='NET15'&&$party()['payment_terms']==='NET15','Restoration seeds retained Core terms into the recreated corporate record');
    $graph=jobdivaPlacementProjectionAuditSnapshot($tenant,$placement);$ready=$preview();$apply($ready['dry_run_token']);
    $check(jobdivaPlacementProjectionAuditSnapshot($tenant,$placement)===$graph,'Fresh reviewed replay leaves the business graph unchanged');
    $row=placementAuditRow($placement);$mapping=mappingFindInternal($tenant,'jobdiva','company','99183101');
    $check((int)$row['person_id']===$person&&$row['external_id']==='jd:99183001'&&(int)$mapping['internal_entity_id']===$sourceCompany,'All handoffs preserve worker, assignment and provider company identities');
    $check([$read('accounting_events'),$read('accounting_journal_entries'),$read('accounting_journal_entry_lines')]===$financial,'Corporate identity round trip creates no financial postings');
},true);
    $check(!$pdo->inTransaction()&&!JobdivaStoredReviewTransaction::active(),'All invented records roll back and the review scope closes');
}finally{if($pdo->inTransaction())$pdo->rollBack();setRequestTenantId(null);}
echo "$checks corporate identity ownership checks passed.\n";
