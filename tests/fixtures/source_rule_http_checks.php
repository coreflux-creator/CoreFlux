<?php
/** Native sessions, RBAC and saved-source replacement; no live provider or financial execution. */
require_once $root.'/modules/placements/lib/source_rule_review.php';
setRequestTenantId($tenant);$_SESSION['tenant_id']=$tenant;
$ruleStart='99190100';$ruleTitle='Synthetic HTTP reviewed source rules';
$ruleMsp=jobdivaUpsertCompanyMapped($tenant,'99190104','Synthetic HTTP Source MSP',[],['id'=>'99190104'],null,['msp']);
$ruleReferrer=jobdivaUpsertCompanyMapped($tenant,'99190105','Synthetic HTTP Source Referrer',[],['id'=>'99190105'],null,['referrer','vendor']);
$rulePlacement=scopedInsert('placements',['person_id'=>$own['person'],'title'=>$ruleTitle,'status'=>'on_hold',
    'engagement_type'=>'1099','start_date'=>'2026-10-01','end_date'=>'2026-12-31','external_id'=>'jd:'.$ruleStart,
    'coreflux_overridden_fields'=>'["notes"]','client_id'=>$own['client'],'end_client_company_id'=>$own['company'],
    'end_client_name'=>'Synthetic HTTP Billing '.$tenant,'client_bill_cycle'=>'monthly','client_payment_terms_override'=>'NET30',
    'vendor_pay_cycle'=>'biweekly','vendor_payment_terms_override'=>'NET30','vendor_pwp_enabled'=>0,'worksite_country'=>'US']);
scopedInsert('placement_rates',['placement_id'=>$rulePlacement,'effective_from'=>'2026-10-01','bill_rate'=>100,'pay_rate'=>50,'approved_at'=>'2026-10-01 12:00:00']);
$rulePayload=['startId'=>$ruleStart,'candidateId'=>$own['start'].'2','jobId'=>$ruleStart.'3','startDate'=>'2026-10-01',
    'status'=>'on_hold','title'=>$ruleTitle,'endClientName'=>'Synthetic HTTP Billing '.$tenant,'employmentType'=>'1099',
    'worksiteCountry'=>'US','mspName'=>'Synthetic HTTP Source MSP','mspCompanyId'=>'99190104','referralVendorCompanyId'=>'99190105','mspFeePct'=>2,
    'recruiterEmail'=>$email,'recruiterCommissionPct'=>10,'referralVendor'=>'Synthetic HTTP Source Referrer','referralFeeAmount'=>10,'referralFeeBasis'=>'one_time',
    '_jd_contract'=>['start_id'=>$ruleStart,'engagement_type'=>'1099','placement_status'=>'on_hold','start_date'=>'2026-10-01','end_date'=>'2026-12-31',
        'client_company_name'=>'Synthetic HTTP Billing '.$tenant,'end_client_name'=>'Synthetic HTTP Billing '.$tenant,
        'client_bill_cycle'=>'monthly','vendor_pay_cycle'=>'biweekly','client_payment_terms'=>'NET30','vendor_payment_terms'=>'NET30',
        'paid_when_paid'=>false,'worksite_country'=>'US','worksite_city'=>'Hamilton',
        'bill_rate'=>100,'pay_rate'=>50,'bill_rate_unit'=>'hour','pay_rate_unit'=>'hour','currency'=>'USD']];
mappingUpsert($tenant,'jobdiva','jobdiva_assignment',$ruleStart,$rulePlacement,$rulePayload);
$ruleChain=jobdivaSyncUpsertPlacementChain($tenant,$rulePlacement,$own['company'],'Synthetic HTTP Billing '.$tenant,$rulePayload)['rows'];
$ruleCommission=jobdivaSyncUpsertPlacementCommissions($tenant,$rulePlacement,'2026-10-01',$rulePayload)['rows']['recruiter'];
$ruleReferral=jobdivaSyncUpsertPlacementReferral($tenant,$rulePlacement,'2026-10-01',$rulePayload);
placementEconomicsReconcile($tenant,$rulePlacement);
$ruleActor=['id'=>$actor,'email'=>$email,'name'=>'Synthetic contract reviewer'];
$ruleEdit=static fn(string $kind,int $id,array $body):array=>placementSourceRuleEdit($tenant,$rulePlacement,$kind,'update',$id,$body,$ruleActor);
$ruleEdit('chain',$ruleChain['msp'],['portal_fee_pct'=>'0.0300']);
$ruleEdit('commission',$ruleCommission,['split_pct'=>'0.2000']);
$ruleEdit('referral',$ruleReferral,['fee_flat'=>'12.00']);
$ruleTables=array_merge($graphTables,['audit_log']);$ruleBefore=$snapshot($ruleTables);
$rulePaths=[];$ruleReviews=[];$ruleBodies=[];
RBACResolver::grantModule($membership,'placements','read',[$tenant],$actor);
foreach(['chain','referral','commission'] as $index=>$kind){
    $path='/modules/placements/api/source_rules.php?kind='.$kind.'&placement_id='.$rulePlacement;$rulePaths[$kind]=$path;
    [$status,$review]=$request('GET',$path);
    $expect($status===200&&$review['selectable']&&!$review['can_apply'],'Native read grant reviews '.$kind.' without applying source ownership');
    $expect(!isset($review['__raw_current'],$review['__source'],$review['__parties'])&&!str_contains(json_encode($review,JSON_THROW_ON_ERROR),'portal_credentials_ct'),'Native '.$kind.' review omits raw payload and private linked data');
    $matches=[];foreach($review['proposed'] as $proposal){
        $same=array_values(array_filter($review['current'],static fn($current)=>$current['identity']===$proposal['identity']));
        $expect(count($same)===1,'Native '.$kind.' proposal has exactly one synthetic recipient match');
        $matches[$proposal['key']]=$same[0]['id'];
    }
    $body=['confirm'=>'REPLACE_REVIEWED_SOURCE_RULES','request_id'=>'99190100-0000-4000-8000-'.sprintf('%012d',$index+1),
        'review_token'=>$review['review_token'],'matches'=>$matches];$ruleBodies[$kind]=$body;$ruleReviews[$kind]=$review;
    $expect($request('POST',$path,$body)[0]===403,'Read-only actor cannot replace '.$kind.' rules');
    $expect($request('GET',$path,null,false)[0]===401&&$request('POST',$path,$body,false)[0]===401,'Anonymous source '.$kind.' review and save require native login');
    $expect($request('GET',$path,null,true,$foreignTenant)[0]===403&&$request('POST',$path,$body,true,$foreignTenant)[0]===403,'Source '.$kind.' refuses foreign workspace pin');
    $foreignPath='/modules/placements/api/source_rules.php?kind='.$kind.'&placement_id='.$foreign['placement'];
    $expect($request('GET',$foreignPath)[0]===404,'Source '.$kind.' does not disclose a foreign placement');
    $operations[]=['GET',$path,null];$operations[]=['POST',$path,$body];
}
$expect($snapshot($ruleTables)===$ruleBefore,'All authenticated rule reviews and denied saves leave business rows and rule audits unchanged');
RBACResolver::grantModule($membership,'placements','write',[$tenant],$actor);
foreach($rulePaths as $kind=>$path){
    [$status,$review]=$request('GET',$path);
    $expect($status===200&&$review['can_apply']===($kind!=='commission'),'Native write grant reports the distinct '.$kind.' apply permission');
}
$expect($request('POST',$rulePaths['commission'],$ruleBodies['commission'])[0]===403,'Ordinary placement writer cannot replace recruiter commission rules');
$expect($snapshot($ruleTables)===$ruleBefore,'Permission changes and denied commission save do not mutate contract rules');
// Referrals use placement write; commissions require placement admin.
[$status,$ruleReferralSaved]=$request('POST',$rulePaths['referral'],$ruleBodies['referral']);
$expect($status===200&&array_values($ruleReferralSaved['applied'])===[$ruleReferral],'Authorized placement writer restores the reviewed referral and retains its ID');
RBACResolver::grantModule($membership,'placements','admin',[$tenant],$actor);
[$status,$review]=$request('GET',$rulePaths['commission']);
$expect($status===200&&$review['can_apply'],'Native admin grant enables reviewed commission replacement');
// Another rule set changing invalidates the parent-based review, so explicitly reload both remaining sets.
foreach(['chain','commission'] as $kind){
    [$status,$review]=$request('GET',$rulePaths[$kind]);$expect($status===200&&$review['selectable'],'Fresh '.$kind.' review follows the earlier referral replacement');
    $ruleBodies[$kind]['review_token']=$review['review_token'];
}
$ruleBefore=$snapshot($ruleTables);$ruleBad=$ruleBodies['chain'];$ruleBad['matches']=[];
$expect($request('POST',$rulePaths['chain'],$ruleBad)[0]===422&&$snapshot($ruleTables)===$ruleBefore,'Missing explicit native matches refuse the entire replacement');
$ruleBad=$ruleBodies['chain'];foreach($ruleReviews['chain']['proposed'] as $p)if($p['identity']==='company:'.$ruleMsp)$ruleBad['matches'][$p['key']]=$ruleChain['end_client'];
$expect($request('POST',$rulePaths['chain'],$ruleBad)[0]===409&&$snapshot($ruleTables)===$ruleBefore,'Native source save refuses another recipient identity without mutation');
mappingUpsert($tenant,'jobdiva','jobdiva_assignment',$ruleStart,$rulePlacement,array_replace($rulePayload,['mspFeePct'=>4]));
$ruleBefore=$snapshot($ruleTables);
$expect($request('POST',$rulePaths['chain'],$ruleBodies['chain'])[0]===409&&$snapshot($ruleTables)===$ruleBefore,'Changed saved source invalidates the authenticated review before any writes');
mappingUpsert($tenant,'jobdiva','jobdiva_assignment',$ruleStart,$rulePlacement,$rulePayload);
[$status,$review]=$request('GET',$rulePaths['chain']);$ruleBodies['chain']['review_token']=$review['review_token'];
$ruleBefore=$snapshot($ruleTables);
$pdo->exec("CREATE TRIGGER cf_http_rule_audit_failure BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.event='placement.source_rules.replaced' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic HTTP source-rule audit failure'; END IF; END");
try{$expect($request('POST',$rulePaths['chain'],$ruleBodies['chain'])[0]===503&&$snapshot($ruleTables)===$ruleBefore,'Failed native source audit rolls back rule values, graph, IDs and ownership');}
finally{$pdo->exec('DROP TRIGGER cf_http_rule_audit_failure');}
foreach(['chain','commission'] as $kind){
    [$status,$review]=$request('GET',$rulePaths[$kind]);$expect($status===200&&$review['selectable'],'Native '.$kind.' reload remains applicable');
    $ruleBodies[$kind]['review_token']=$review['review_token'];
    [$status,$saved]=$request('POST',$rulePaths[$kind],$ruleBodies[$kind]);
    $expect($status===200&&$saved['source_control_released']&&$saved['applied']===$ruleBodies[$kind]['matches'],'Native '.$kind.' save restores source rules while preserving explicitly matched IDs');
}
foreach($rulePaths as $kind=>$path){
    $before=$snapshot($ruleTables);[$status,$again]=$request('POST',$path,$ruleBodies[$kind]);
    $expect($status===200&&$again['replayed']&&$again['currently_follows_source']&&$snapshot($ruleTables)===$before,'Exact native '.$kind.' save retry adds no rules, graph change or audit');
    $changed=$ruleBodies[$kind];$changed['matches']=array_fill_keys(array_keys($changed['matches']),0);
    $expect($request('POST',$path,$changed)[0]===409&&$snapshot($ruleTables)===$before,'Reused '.$kind.' request cannot apply different match choices');
}
$ruleEdit('chain',$ruleChain['msp'],['portal_fee_pct'=>'0.0500']);$before=$snapshot($ruleTables);
[$status,$again]=$request('POST',$rulePaths['chain'],$ruleBodies['chain']);
$expect($status===200&&$again['replayed']&&!$again['currently_follows_source']&&$snapshot($ruleTables)===$before,'Historical native receipt cannot overwrite a later Core-owned edit');
$q=$pdo->prepare("SELECT actor_user_id,meta_json FROM audit_log WHERE tenant_id=? AND target_id=? AND event='placement.source_rules.replaced'");$q->execute([$tenant,$rulePlacement]);$ruleAudits=$q->fetchAll(PDO::FETCH_ASSOC);
$expect(count($ruleAudits)===3&&count(array_filter($ruleAudits,static fn($r)=>(int)$r['actor_user_id']===$actor))===3,'Each native rule-set replacement audits the authenticated actor exactly once');
$expect($snapshot($financialTables)===$financialBefore,'Native rule review and replacement do not execute accounting, payroll or payment operations');

// The same native PATCH used by the company chooser resolves an unlinked source row.
$choicePlacement=scopedInsert('placements',['person_id'=>$own['person'],'title'=>'Synthetic unresolved company choice','status'=>'on_hold','engagement_type'=>'1099','start_date'=>'2026-10-01']);
$choiceChain=jobdivaSyncUpsertPlacementChainRow($tenant,$choicePlacement,1,'msp','Unresolved source MSP',null,['portal_fee_pct'=>0.02]);
$choiceReferral=jobdivaSyncUpsertPlacementReferral($tenant,$choicePlacement,'2026-10-01',['employmentType'=>'1099','referralVendor'=>'Unresolved source referrer','referralFeeAmount'=>11]);
foreach([['chain','chain',$choiceChain,'company_id',$ruleMsp,'placement_client_chain'],['referral','referrals',$choiceReferral,'referrer_company_id',$ruleReferrer,'placement_referrals']]as[$kind,$endpoint,$id,$field,$company,$table]){
    $path='/modules/placements/api/'.$endpoint.'.php?id='.$id;
    RBACResolver::grantModule($membership,'placements','read',[$tenant],$actor);$before=$snapshot($ruleTables);
    $expect($request('PATCH',$path,[$field=>$company])[0]===403&&$snapshot($ruleTables)===$before,'Read-only staff cannot choose the '.$kind.' company');
    RBACResolver::grantModule($membership,'placements','write',[$tenant],$actor);
    [$status,$saved]=$request('PATCH',$path,[$field=>$company]);
    $q=$pdo->prepare('SELECT '.$field.' FROM '.$table.' WHERE tenant_id=? AND placement_id=? AND id=?');$q->execute([$tenant,$choicePlacement,$id]);
    $expect($status===200&&(int)$q->fetchColumn()===$company,'Native chooser resolves the existing '.$kind.' row to the selected company');
    $before=$snapshot($ruleTables);$expect($request('PATCH',$path,[$field=>$company])[0]===200&&$snapshot($ruleTables)===$before,'Repeated '.$kind.' company choice preserves rows and audit');
}
$expect(jobdivaSyncUpsertPlacementChainRow($tenant,$choicePlacement,1,'msp','Different source')===0&&jobdivaSyncUpsertPlacementReferral($tenant,$choicePlacement,'2026-10-01',['employmentType'=>'1099','referralVendor'=>'Different source','referralFeeAmount'=>99])===0,'Native company selection holds both rule sets against later source changes');
$expect($snapshot($financialTables)===$financialBefore,'Native company choice creates no accounting, payroll or payment operation');
