<?php
/** Real saved-source review/replacement, confined to a rolled-back disposable database. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('COREFLUX_ENV')!=='coreaccounting'||getenv('COREFLUX_STANDALONE_DATABASE')!=='coreaccounting_ci'
    ||!in_array('--confirm-disposable-fixture',$argv,true))exit(2);
require_once __DIR__.'/../modules/placements/lib/source_rule_review.php';
$pdo=getDB();if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='coreaccounting_ci')exit(2);
$tenant=(int)$pdo->query('SELECT MIN(id) FROM tenants')->fetchColumn();if(!$tenant)exit(2);
setRequestTenantId($tenant);$_SESSION['tenant_id']=$tenant;
$n=0;$check=static function(bool $ok,string $label)use(&$n):void{if(!$ok)throw new RuntimeException($label);$n++;echo "PASS $label\n";};
$refused=static function(callable $f,int $code):bool{try{$f();return false;}catch(InvalidArgumentException|RuntimeException $e){return $e->getCode()===$code;}};
$read=static fn(string $table):array=>getDB()->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$financial=[$read('accounting_events'),$read('accounting_journal_entries'),$read('accounting_journal_entry_lines'),$read('time_entries')];
JobdivaStoredReviewTransaction::run(static function()use($pdo,$tenant,$check,$refused,$read,$financial):void{
    $user=$pdo->query('SELECT id,email,name FROM users WHERE is_active=1 ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);$uid=(int)$user['id'];
    $pdo->prepare('INSERT INTO user_tenants(user_id,tenant_id,role,status) VALUES(?,?,"admin","active") ON DUPLICATE KEY UPDATE status="active"')->execute([$uid,$tenant]);
    $pdo->prepare('INSERT INTO tenant_memberships(tenant_id,user_id,persona_label,persona_type,is_primary,status) VALUES(?,?,"CI reviewed rules","tenant_admin",1,"active") ON DUPLICATE KEY UPDATE status="active"')->execute([$tenant,$uid]);
    $person=scopedInsert('people',['first_name'=>'CI Source','last_name'=>'Review','classification'=>'w2','external_id'=>'jd:99190002','email_primary'=>'source-rule-review@example.invalid']);
    $bill=companiesUpsertByName($tenant,'CI Source Review Billing',[],['client']);$client=staffingClientEnsureForCompany($tenant,$bill,'CI Source Review Billing')['client_id'];
    $msp=companiesUpsertByName($tenant,'CI Source Review MSP',[],['msp']);
    companiesUpsertByName($tenant,'CI Source Review Referrer',[],['referrer','vendor']);
    $pid=scopedInsert('placements',['person_id'=>$person,'title'=>'CI reviewed source rules','status'=>'on_hold','engagement_type'=>'w2','start_date'=>'2026-10-01',
        'external_id'=>'jd:99190001','coreflux_overridden_fields'=>'["notes","start_date"]','client_id'=>$client,'end_client_company_id'=>$bill,'end_client_name'=>'CI Source Review Billing',
        'client_bill_cycle'=>'weekly','client_payment_terms_override'=>'NET30','vendor_pay_cycle'=>'biweekly','vendor_payment_terms_override'=>'NET30','vendor_pwp_enabled'=>0,'worksite_country'=>'US']);
    scopedInsert('placement_rates',['placement_id'=>$pid,'effective_from'=>'2026-10-01','bill_rate'=>100,'pay_rate'=>50,'approved_at'=>'2026-10-01 12:00:00']);
    $payload=['startId'=>'99190001','candidateId'=>'99190002','jobId'=>'99190003','startDate'=>'2026-10-01','status'=>'on_hold','title'=>'CI source review',
        'endClientName'=>'CI Source Review Billing','employmentType'=>'W2','worksiteCountry'=>'US','mspName'=>'CI Source Review MSP','mspFeePct'=>2,
        'recruiterEmail'=>$user['email'],'recruiterCommissionPct'=>10,'referralVendor'=>'CI Source Review Referrer','referralFeeAmount'=>10,'referralFeeBasis'=>'one_time',
        '_jd_contract'=>['start_id'=>'99190001','engagement_type'=>'w2','placement_status'=>'on_hold','start_date'=>'2026-10-01','end_date'=>'2026-12-31',
            'client_company_name'=>'CI Source Review Billing','end_client_name'=>'CI Source Review Billing','client_bill_cycle'=>'weekly','vendor_pay_cycle'=>'biweekly',
            'client_payment_terms'=>'NET30','vendor_payment_terms'=>'NET30','paid_when_paid'=>false,'worksite_country'=>'US','worksite_city'=>'Hamilton',
            'bill_rate'=>100,'pay_rate'=>50,'bill_rate_unit'=>'hour','pay_rate_unit'=>'hour','currency'=>'USD']];
    mappingUpsert($tenant,'jobdiva','jobdiva_assignment','99190001',$pid,$payload);
    $chain=jobdivaSyncUpsertPlacementChain($tenant,$pid,$bill,'CI Source Review Billing',$payload)['rows'];
    $commission=jobdivaSyncUpsertPlacementCommissions($tenant,$pid,'2026-10-01',$payload)['rows']['recruiter'];
    $referral=jobdivaSyncUpsertPlacementReferral($tenant,$pid,'2026-10-01',$payload);
    placementEconomicsReconcile($tenant,$pid);
    $edit=static fn(string $kind,string $op,int $id,array $body):array=>placementSourceRuleEdit($tenant,$pid,$kind,$op,$id,$body,$user);
    $private="CI-FAKE-BINARY\0\xff";
    $pdo->prepare('UPDATE placement_client_chain SET portal_credentials_ct=?,kms_key_version="ci-v1" WHERE id=?')->execute([$private,$chain['msp']]);
    $edit('chain','update',$chain['msp'],['position'=>4,'portal_fee_pct'=>'0.0300']);
    $extra=$edit('chain','create',0,['request_id'=>'99190001-0000-4000-8000-000000000001','position'=>5,'party_role'=>'sub_vendor','party_name'=>'CI Local Only Rule'])['id'];
    $edit('commission','update',$commission,['role'=>'other','effective_from'=>'2026-10-02','notes'=>'Core reviewed commission','split_pct'=>'0.2000']);
    $edit('referral','update',$referral,['notes'=>'Core reviewed referral','fee_flat'=>'12.00']);
    $preview=static fn(string $kind):array=>placementSourceRuleReview($tenant,$pid,$kind);
    $body=static function(array $plan,array $matches,string $suffix='000000000002'):array{return ['confirm'=>'REPLACE_REVIEWED_SOURCE_RULES','request_id'=>'99190001-0000-4000-8000-'.$suffix,'review_token'=>$plan['review_token'],'matches'=>$matches];};
    $apply=static fn(string $kind,array $data):array=>placementSourceRuleReplace($tenant,$pid,$kind,$data,$user);
    $match=static function(array $plan):array{$out=[];foreach($plan['proposed']as$p){$out[$p['key']]=0;foreach($plan['current']as$c)if($c['identity']===$p['identity']){$out[$p['key']]=$c['id'];break;}}return $out;};
    $state=static fn():array=>array_map($read,['placements','placement_client_chain','placement_commissions','placement_referrals','placement_economic_parties','placement_rates','companies','people','audit_log']);
    $baseline=$state();$rates=$read('placement_rates');$people=$read('people');
    $plan=$preview('chain');
    $check($plan['selectable']&&count($plan['proposed'])===2,'Complete saved source proposes the client and MSP: '.implode('; ',$plan['issues']));
    $check($state()===$baseline,'Review rolls back all temporary source rows, company usage, ownership and audit');
    $check($plan['review_token']===$preview('chain')['review_token'],'Repeated read-only review produces the same seal despite temporary row allocations');
    $public=json_encode($plan,JSON_THROW_ON_ERROR);
    $check(!str_contains($public,'CI-FAKE-BINARY')&&!str_contains($public,'portal_credentials_ct')&&!str_contains($public,'__payload'),'Public review has no credential bytes or raw source payload');
    $matches=$match($plan);$data=$body($plan,$matches);
    $check($refused(fn()=>$apply('chain',$body($plan,[])),422)&&$state()===$baseline,'Missing per-rule choices cause no writes');
    $wrong=$matches;foreach($plan['proposed']as$p)if($p['identity']==='company:'.$msp)$wrong[$p['key']]=$chain['end_client'];
    $check($refused(fn()=>$apply('chain',$body($plan,$wrong)),409)&&$state()===$baseline,'A same-name or position guess cannot reuse another company rule');
    $newOnly=array_fill_keys(array_keys($matches),0);
    $check($refused(fn()=>$apply('chain',$body($plan,$newOnly)),409),'A replacement cannot discard encrypted credentials');
    $check($refused(fn()=>placementSourceRuleReview($tenant+999999,$pid,'chain'),404),'Foreign placement workspace is refused');
    foreach(['credential','source','person','mapping']as$change)JobdivaStoredReviewTransaction::run(static function()use($change,$pdo,$tenant,$pid,$person,$private,$chain,$payload,$data,$apply,$check,$refused):void{
        if($change==='credential')$pdo->prepare('UPDATE placement_client_chain SET portal_credentials_ct=? WHERE id=?')->execute([$private.'-changed',$chain['msp']]);
        if($change==='source')mappingUpsert($tenant,'jobdiva','jobdiva_assignment','99190001',$pid,array_replace($payload,['mspFeePct'=>4]));
        if($change==='person')$pdo->prepare('UPDATE people SET external_id="jd:DIFFERENT" WHERE id=?')->execute([$person]);
        if($change==='mapping')$pdo->prepare('INSERT INTO tenant_integration_field_map(tenant_id,integration,entity_type,internal_field,external_field,source_path,target_module,target_table,target_column,linked_entity,transform,enabled) VALUES(?,"jobdiva","placement","ci_review_submittal","sourceSubmittal","sourceSubmittal","placements","placement_client_chain","submittal_id","placement_chain_msp","none",1)')->execute([$tenant]);
        $check($refused(fn()=>$apply('chain',$data),409),'Changed '.$change.' evidence invalidates the saved review');
    },true);
    $check($state()===$baseline,'Rejected stale reviews preserve all unrelated placement state');
    JobdivaStoredReviewTransaction::run(static function()use($pdo,$tenant,$pid,$payload,$preview,$apply,$body,$match,$read,$check):void{
        foreach([['placement_client_chain','portal_fee_pct','placement_chain_msp'],['placement_referrals','fee_flat','placement_referral']]as[$table,$column,$linked])
            $pdo->prepare('INSERT INTO tenant_integration_field_map(tenant_id,integration,entity_type,internal_field,external_field,source_path,target_module,target_table,target_column,linked_entity,transform,enabled) VALUES(?,"jobdiva","placement",?,"reviewValue","reviewValue","placements",?,?,?,"none",1)')->execute([$tenant,'ci_review_'.$column,$table,$column,$linked]);
        mappingUpsert($tenant,'jobdiva','jobdiva_assignment','99190001',$pid,array_replace($payload,['reviewValue'=>0.04]));
        $before=$read('placement_referrals');$p=$preview('chain');$mspProposal=array_values(array_filter($p['proposed'],static fn($r)=>$r['values']['party_role']==='msp'))[0];
        $check($p['selectable']&&(float)$mspProposal['values']['portal_fee_pct']===0.04,'Review includes configured source mappings rather than only default fields');
        $apply('chain',$body($p,$match($p)));
        $check($read('placement_referrals')===$before,'Selected-table mapping replay leaves another rule set unchanged');
    },true);
    JobdivaStoredReviewTransaction::run(static function()use($pdo,$tenant,$pid,$chain,$preview,$match,$body,$apply,$check,$refused):void{
        $pdo->prepare('UPDATE placement_economic_parties SET payment_terms="NET10",payment_terms_overridden=1 WHERE tenant_id=? AND placement_id=? AND source_ref=?')->execute([$tenant,$pid,'chain:'.$chain['msp']]);
        $p=$preview('chain');$m=$match($p);$new=$m;foreach($p['proposed']as$r)if($m[$r['key']]===$chain['msp'])$new[$r['key']]=0;
        $check($refused(fn()=>$apply('chain',$body($p,$new)),409),'Independently reviewed participant settings cannot be retired');
        $apply('chain',$body($p,$m));
        $q=$pdo->prepare('SELECT payment_terms,payment_terms_overridden FROM placement_economic_parties WHERE tenant_id=? AND placement_id=? AND source_ref=?');$q->execute([$tenant,$pid,'chain:'.$chain['msp']]);$party=$q->fetch(PDO::FETCH_ASSOC);
        $check($party['payment_terms']==='NET10'&&(int)$party['payment_terms_overridden']===1,'Reused source participant retains its separately reviewed terms');
    },true);
    $pdo->exec('CREATE TEMPORARY TABLE audit_log(id BIGINT,tenant_id BIGINT,target_id BIGINT,event VARCHAR(100),meta_json JSON)');
    try{$before=array_slice($state(),0,-1);$check($refused(fn()=>$apply('chain',$data),503)&&array_slice($state(),0,-1)===$before,'Audit failure rolls back IDs, private links, source ownership and graph changes');}
    finally{$pdo->exec('DROP TEMPORARY TABLE audit_log');}
    $otherBefore=[$read('placement_commissions'),$read('placement_referrals')];
    $result=$apply('chain',$data);$saved=$state();
    $check($result['retired']===[$extra]&&array_values($result['applied'])===array_values($matches),'Reviewed replacement preserves matched IDs and retires only the unmatched rule');
    $q=$pdo->prepare('SELECT position,portal_fee_pct,portal_credentials_ct FROM placement_client_chain WHERE id=?');$q->execute([$chain['msp']]);$mspRow=$q->fetch(PDO::FETCH_ASSOC);
    $check((int)$mspRow['position']===1&&(float)$mspRow['portal_fee_pct']===0.02&&$mspRow['portal_credentials_ct']===$private,'Source values replace the mutable match fields while encrypted bytes stay exact');
    $owned=placementOwnershipFields(placementAuditRow($pid)['coreflux_overridden_fields']);
    $check(!in_array('chain_rules',$owned,true)&&in_array('referral_rules',$owned,true)&&in_array('commission_rules',$owned,true)&&in_array('notes',$owned,true),'Only the selected rule-set hold is released');
    $check([$read('placement_commissions'),$read('placement_referrals')]===$otherBefore,'Replacing chain rules does not touch referrals or commissions');
    $retry=$apply('chain',$data);$check(!empty($retry['replayed'])&&$state()===$saved,'Lost-response retry returns the original receipt without duplicate writes');
    JobdivaStoredReviewTransaction::run(static function()use($edit,$chain,$apply,$data,$check):void{
        $edit('chain','update',$chain['msp'],['portal_fee_pct'=>'0.0500']);$retry=$apply('chain',$data);
        $check(!empty($retry['replayed'])&&$retry['currently_follows_source']===false,'Historical receipt replay does not claim a later Core-owned edit still follows the source');
    },true);
    $different=$data;$different['matches']=array_fill_keys(array_keys($matches),0);
    $check($refused(fn()=>$apply('chain',$different),409),'Request ID cannot be reused for a different replacement');
    $q=$pdo->prepare('SELECT meta_json FROM audit_log WHERE event="placement.source_rules.replaced" AND target_id=? ORDER BY id DESC LIMIT 1');$q->execute([$pid]);$audit=(string)$q->fetchColumn();
    $check(str_contains($audit,'request_fingerprint')&&!str_contains($audit,'CI-FAKE-BINARY'),'Applied replacement records review and replay receipt without private bytes');
    $chainBefore=$read('placement_client_chain');jobdivaSyncUpsertPlacementChain($tenant,$pid,$bill,'CI Source Review Billing',$payload);
    $check($read('placement_client_chain')===$chainBefore,'A normal source refresh converges on the retained row IDs');
    foreach(['commission'=>$commission,'referral'=>$referral]as$kind=>$id){
        $p=$preview($kind);$check($p['selectable']&&count($p['proposed'])===1,$kind.' source is reviewable: '.implode('; ',$p['issues']));
        $r=$apply($kind,$body($p,$match($p),$kind==='commission'?'000000000003':'000000000004'));
        $check(array_values($r['applied'])===[$id],$kind.' replacement preserves the edited rule ID through source replay');
    }
    // No source referral is a deliberate, visible removal, not an unreviewed clear.
    JobdivaStoredReviewTransaction::run(static function()use($tenant,$pid,$payload,$edit,$referral,$preview,$apply,$body,$check,$pdo):void{
        $edit('referral','update',$referral,['notes'=>'Hold empty-source review']);$empty=$payload;unset($empty['referralFeeAmount']);
        mappingUpsert($tenant,'jobdiva','jobdiva_assignment','99190001',$pid,$empty);$p=$preview('referral');
        $check($p['selectable']&&$p['proposed']===[],'No source rules is an explicit empty proposal');
        $result=$apply('referral',$body($p,[],'000000000005'));
        $q=$pdo->prepare('SELECT active FROM placement_economic_parties WHERE tenant_id=? AND placement_id=? AND source_ref=?');$q->execute([$tenant,$pid,'referral:'.$referral]);
        $check($result['retired']===[$referral]&&(int)$q->fetchColumn()===0,'Reviewed empty replacement retires the current graph participant');
    },true);
    JobdivaStoredReviewTransaction::run(static function()use($tenant,$pid,$payload,$edit,$commission,$preview,$check):void{
        $edit('commission','update',$commission,['notes'=>'Hold currency review']);$flat=$payload;unset($flat['recruiterCommissionPct']);$flat['recruiterCommissionFlat']=7500;$flat['recruiterCommissionCurrency']='INR';$flat['recruiterCommissionFrequency']='one_time';
        mappingUpsert($tenant,'jobdiva','jobdiva_assignment','99190001',$pid,$flat);$p=$preview('commission');
        $check(!$p['selectable']&&str_contains(implode(' ',$p['issues']),'currency and payment schedule'),'Foreign-currency one-time source commission remains for review without dropping its terms');
    },true);
    $check($read('placement_rates')===$rates&&$read('people')===$people,'All reviews and replacements preserve people and approved rate snapshots');
    $check([$read('accounting_events'),$read('accounting_journal_entries'),$read('accounting_journal_entry_lines'),$read('time_entries')]===$financial,'Source-rule replacement creates no financial postings or time changes');
},true);
setRequestTenantId(null);
echo "$n source rule replacement database checks passed.\n";
