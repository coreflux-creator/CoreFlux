<?php
/** Reviewed commercial ownership release, synthetic records and outer rollback only. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'coreaccounting'
    || getenv('COREFLUX_STANDALONE_DATABASE') !== 'coreaccounting_ci'
    || !in_array('--confirm-disposable-fixture', $argv, true)) exit(2);
require_once __DIR__ . '/../modules/placements/lib/commercial_ownership.php';
require_once __DIR__ . '/../modules/placements/lib/economics_edit.php';
require_once __DIR__ . '/../core/jobdiva/sync.php';
$pdo=getDB();if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='coreaccounting_ci')exit(2);
$tenant=(int)$pdo->query('SELECT MIN(id) FROM tenants')->fetchColumn();if($tenant<=0)exit(2);
setRequestTenantId($tenant);$_SESSION['tenant_id']=$tenant;
$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";};
$refused=static function(callable $fn,int $code):bool{try{$fn();return false;}catch(RuntimeException|InvalidArgumentException $e){return $e->getCode()===$code;}};
$read=static fn(string $table):array=>getDB()->query('SELECT * FROM '.$table.' ORDER BY '.($table==='placement_corp_details'?'placement_id':'id'))->fetchAll(PDO::FETCH_ASSOC);
$financial=[$read('accounting_events'),$read('accounting_journal_entries'),$read('accounting_journal_entry_lines')];
$pdo->beginTransaction();
try{
    $person=scopedInsert('people',['first_name'=>'CI Release','last_name'=>'Worker','classification'=>'1099','email_primary'=>'ci-commercial-release@example.invalid']);
    $company=companiesUpsertByName($tenant,'CI Release Billing',[],['client']);
    $client=staffingClientEnsureForCompany($tenant,$company,'CI Release Billing')['client_id'];
    $placement=scopedInsert('placements',['person_id'=>$person,'title'=>'CI ownership release','status'=>'on_hold','engagement_type'=>'1099','start_date'=>'2026-10-01','end_date'=>'2026-12-31',
        'external_id'=>'jd:99170001','coreflux_overridden_fields'=>'["notes"]','notes'=>'Keep this note','client_id'=>$client,'end_client_company_id'=>$company,'end_client_name'=>'CI Release Billing',
        'client_bill_cycle'=>'monthly','client_payment_terms_override'=>'NET30','vendor_pay_cycle'=>'biweekly','vendor_payment_terms_override'=>'NET30','vendor_pwp_enabled'=>0]);
    scopedInsert('placement_rates',['placement_id'=>$placement,'effective_from'=>'2026-10-01','bill_rate'=>100,'pay_rate'=>50,'approved_at'=>'2026-10-01 12:00:00']);
    placementEconomicsReconcile($tenant,$placement);
    $party=static function(int $pid,string $ref)use($tenant):array{$q=getDB()->prepare('SELECT * FROM placement_economic_parties WHERE tenant_id=? AND placement_id=? AND source_ref=?');$q->execute([$tenant,$pid,$ref]);return $q->fetch(PDO::FETCH_ASSOC);};
    $ar=$party($placement,'placement:bill_to');$ap=$party($placement,'worker:'.$person);
    placementEconomicsApplyNativePartyEdit($tenant,(int)$ar['id'],['payment_terms'=>'NET10','cadence'=>'weekly']);
    placementEconomicsApplyNativePartyEdit($tenant,(int)$ap['id'],['payment_terms'=>'PWP_NET15','pwp_enabled'=>true,'cadence'=>'weekly']);
    $preview=static fn():array=>placementCommercialOwnershipReview($tenant,$placement);
    $apply=static fn(array $groups,string $token):array=>placementReleaseCommercialOwnership($tenant,$placement,$groups,$token);
    $snapshot=static fn():array=>jobdivaPlacementProjectionAuditSnapshot($tenant,$placement);
    $before=$snapshot();$audit=$read('audit_log');$review=$preview();$groups=array_column($review['groups'],null,'key');
    $check(count($groups)===4&&!in_array(false,array_column($groups,'selectable'),true),'Review identifies all four linked commercial ownership groups');
    $check($groups['client_terms']['placement_values']['client_payment_terms_override']==='NET10'&&$groups['vendor_terms']['participants'][0]['values']['payment_terms']==='PWP_NET15','Review shows current placement and participant values');
    $check($snapshot()===$before&&$read('audit_log')===$audit&&$preview()['review_token']===$review['review_token'],'Ownership preview is read-only and stable');
    foreach([[[],str_repeat('0',64)],[['unknown'],$review['review_token']],[['client_terms','client_terms'],$review['review_token']]] as [$selected,$token])
        $check($refused(fn()=>$apply($selected,$token),422)&&$snapshot()===$before,'Malformed ownership selection writes nothing');
    $check($refused(fn()=>$apply(['client_terms'],str_repeat('0',64)),409)&&$snapshot()===$before,'A wrong review token cannot release ownership');
    $pdo->prepare('UPDATE placement_economic_parties SET payment_terms=? WHERE tenant_id=? AND id=?')->execute(['NET20',$tenant,$ar['id']]);
    $changed=$snapshot();$check($refused(fn()=>$apply(['client_terms'],$review['review_token']),409)&&$snapshot()===$changed,'A changed participant value invalidates the review');
    $pdo->prepare('UPDATE placement_economic_parties SET payment_terms=? WHERE tenant_id=? AND id=?')->execute(['NET10',$tenant,$ar['id']]);
    $review=$preview();scopedUpdate('placements',$placement,['notes'=>'New reviewed note']);$changed=$snapshot();
    $check($refused(fn()=>$apply(['client_terms'],$review['review_token']),409)&&$snapshot()===$changed,'A changed placement invalidates the review');
    $review=$preview();$cycle=(int)$groups['client_cycle']['participants'][0]['values']['operating_cycle_id'];
    $pdo->prepare('UPDATE staffing_operating_cycles SET anchor_date=? WHERE tenant_id=? AND id=?')->execute(['2026-11-09',$tenant,$cycle]);
    $check($refused(fn()=>$apply(['client_cycle'],$review['review_token']),409),'A changed linked cycle invalidates the review');
    $review=$preview();$result=$apply(['client_terms'],$review['review_token']);$arAfter=$party($placement,'placement:bill_to');$row=placementAuditRow($placement);
    $check($result['changed']&&!$result['values_applied']&&(int)$arAfter['payment_terms_overridden']===0&&!in_array('client_payment_terms_override',placementOwnershipFields($row['coreflux_overridden_fields']),true),'Client term release clears participant and placement ownership together');
    $check($arAfter['payment_terms']==='NET10'&&$row['client_payment_terms_override']==='NET10'&&(int)$arAfter['cycle_overridden']===1&&(int)$party($placement,'worker:'.$person)['pwp_overridden']===1,'Release retains current values and unrelated ownership');
    $audit=$read('audit_log');$stable=$snapshot();
    $check($refused(fn()=>$apply(['client_terms'],$review['review_token']),409)&&$snapshot()===$stable&&$read('audit_log')===$audit,'Replaying a consumed review makes no further change');
    $review=$preview();$apply(['vendor_terms'],$review['review_token']);$apAfter=$party($placement,'worker:'.$person);$row=placementAuditRow($placement);
    $check((int)$apAfter['payment_terms_overridden']===0&&(int)$apAfter['pwp_overridden']===0&&!array_intersect(['vendor_payment_terms_override','vendor_pwp_enabled'],placementOwnershipFields($row['coreflux_overridden_fields'])),'Vendor terms and PWP release as one reviewed group');
    $check($apAfter['payment_terms']==='PWP_NET15'&&(int)$apAfter['pwp_enabled']===1,'Vendor release does not replace the current commercial agreement');
    $review=$preview();$apply(['client_cycle','vendor_cycle'],$review['review_token']);$row=placementAuditRow($placement);
    $check(placementOwnershipFields($row['coreflux_overridden_fields'])===['notes']&&(int)$party($placement,'placement:bill_to')['cycle_overridden']===0&&(int)$party($placement,'worker:'.$person)['cycle_overridden']===0,'Schedule release clears linked cycle cadence and anchor ownership while retaining unrelated fields');
    $check($preview()['groups']===[],'Fresh review after release contains no remaining commercial groups');
    $payload=['startId'=>'99170001','candidateId'=>'99170002','jobId'=>'99170003','startDate'=>'2026-10-01','title'=>'Fresh JobDiva title','status'=>'on_hold','endClientName'=>'CI Release Billing','worksiteCountry'=>'United States','__cf_force_source_contract'=>true,
        '_jd_contract'=>['start_id'=>'99170001','engagement_type'=>'1099','placement_status'=>'on_hold','start_date'=>'2026-10-01','end_date'=>'2026-12-31','client_company_name'=>'CI Release Billing','end_client_name'=>'CI Release Billing','client_bill_cycle'=>'monthly','vendor_pay_cycle'=>'monthly','client_payment_terms'=>'NET60','vendor_payment_terms'=>'NET45','paid_when_paid'=>false,'worksite_country'=>'United States']];
    jobdivaSyncUpsertPlacement($tenant,$person,$company,$payload,'99170001');$row=placementAuditRow($placement);
    $check($row['client_payment_terms_override']==='NET60'&&$row['vendor_payment_terms_override']==='NET45'&&(int)$row['vendor_pwp_enabled']===0&&$row['client_bill_cycle']==='monthly'&&$row['vendor_pay_cycle']==='monthly','A later JobDiva refresh can update the released placement fields');
    $check($party($placement,'placement:bill_to')['payment_terms']==='NET60'&&$party($placement,'worker:'.$person)['payment_terms']==='NET45'&&$row['notes']==='New reviewed note','Participant values follow the source again and unrelated Core notes remain protected');
    placementEconomicsApplyNativePartyEdit($tenant,(int)$ar['id'],['payment_terms'=>'NET20']);$review=$preview();$before=$snapshot();
    $pdo->exec('CREATE TEMPORARY TABLE audit_log (id BIGINT,tenant_id BIGINT,target_id BIGINT,event VARCHAR(100),meta_json JSON)');
    try{$check($refused(fn()=>$apply(['client_terms'],$review['review_token']),503)&&$snapshot()===$before&&$pdo->inTransaction(),'Audit failure rolls back all linked ownership changes and preserves the caller transaction');}
    finally{$pdo->exec('DROP TEMPORARY TABLE audit_log');}
    $pdo->prepare('UPDATE placement_economic_parties SET source_managed=0 WHERE tenant_id=? AND id=?')->execute([$tenant,$ar['id']]);$review=$preview();
    $check(!$review['groups'][0]['selectable']&&$refused(fn()=>$apply(['client_terms'],$review['review_token']),409),'Manual participant ownership requires identity review before release');
    $pdo->prepare('UPDATE placement_economic_parties SET source_managed=1,source_external_id=? WHERE tenant_id=? AND id=?')->execute(['99179999',$tenant,$ar['id']]);$review=$preview();
    $check(!$review['groups'][0]['selectable'],'A different source assignment cannot receive ownership');
    $pdo->prepare('UPDATE placement_economic_parties SET source_external_id=? WHERE tenant_id=? AND id=?')->execute(['99170001',$tenant,$ar['id']]);
    $legacy=placementEconomicsUpsertParty($tenant,$placement,['source_ref'=>'placement:end_client','source_type'=>'placement','role'=>'end_client','display_name'=>'CI legacy participant','company_id'=>$company,'money_flow'=>'receivable','settlement_channel'=>'ar','source_system'=>'jobdiva','source_external_id'=>'99170001']);
    placementEconomicsUpdateParty($tenant,$legacy,['payment_terms'=>'NET25']);$review=$preview();
    $check(!$review['groups'][0]['selectable']&&count($review['groups'][0]['participants'])===2,'Duplicate legacy ownership is visible and blocks a partial release');
    $pdo->prepare('DELETE FROM placement_economic_parties WHERE tenant_id=? AND id=?')->execute([$tenant,$legacy]);
    $check($refused(fn()=>placementCommercialOwnershipReview($tenant+1000000,$placement),404),'Another tenant cannot inspect the ownership review');
    scopedUpdate('placements',$placement,['coreflux_overridden_fields'=>'{}']);
    $check($refused($preview,422),'Malformed placement ownership blocks preview');
    scopedUpdate('placements',$placement,['coreflux_overridden_fields'=>'["client_payment_terms_override"]']);
    $review=$preview();$main=$pdo;$second=new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $second->exec('SET SESSION innodb_lock_wait_timeout=1');$pdo=$second;
    try{$apply(['client_terms'],$review['review_token']);$check(false,'Competing release must wait');}
    catch(PDOException $e){$check((int)($e->errorInfo[1]??0)===1205&&!$second->inTransaction(),'Competing release waits on the placement lock and rolls back on timeout');}
    finally{$pdo=$main;$second=null;}
    $corpCompany=companiesUpsertByName($tenant,'CI Release Corporation',[],['vendor']);
    $corpPlacement=scopedInsert('placements',['person_id'=>$person,'title'=>'CI corp release','status'=>'on_hold','engagement_type'=>'c2c','start_date'=>'2026-10-01','external_id'=>'jd:99170011']);
    $pdo->prepare('INSERT INTO placement_corp_details (tenant_id,placement_id,company_id,corp_legal_name,payment_terms_override,pwp_enabled) VALUES (?,?,?,?,?,?)')->execute([$tenant,$corpPlacement,$corpCompany,'CI Release Corporation','NET30',0]);
    placementEconomicsReconcile($tenant,$corpPlacement);$corpParty=$party($corpPlacement,'corp:'.$corpPlacement);
    placementEconomicsApplyNativePartyEdit($tenant,(int)$corpParty['id'],['payment_terms'=>'PWP_NET10','pwp_enabled'=>true]);
    $corpReview=placementCommercialOwnershipReview($tenant,$corpPlacement);$corpBefore=$read('placement_corp_details');
    $check($corpReview['groups'][0]['corporation_values']['payment_terms_override']==='PWP_NET10','Corporate source values are included in the review');
    placementReleaseCommercialOwnership($tenant,$corpPlacement,['vendor_terms'],$corpReview['review_token']);
    $check($read('placement_corp_details')===$corpBefore&&(int)$party($corpPlacement,'corp:'.$corpPlacement)['payment_terms_overridden']===0,'Corporate release preserves corporation agreement values while releasing participant ownership');
    $check($pdo->inTransaction()&&(int)placementAuditRow($placement)['person_id']===$person&&placementAuditRow($placement)['external_id']==='jd:99170001','Ownership release preserves the caller transaction and source identities');
    $check([$read('accounting_events'),$read('accounting_journal_entries'),$read('accounting_journal_entry_lines')]===$financial,'Ownership release and source refresh create no financial posting');
}finally{if($pdo->inTransaction())$pdo->rollBack();setRequestTenantId(null);}
echo "$checks commercial ownership release checks passed.\n";
