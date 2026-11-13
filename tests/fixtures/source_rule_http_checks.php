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

// A reviewed source link is authoritative even when the retained placement
// number came from a spreadsheet. Exercise the actual authenticated endpoint.
mappingUpsert($tenant,'jobdiva','placement',$ruleStart,$rulePlacement,$rulePayload);
$pdo->prepare('UPDATE placements SET external_id="http-retained-placement-number" WHERE tenant_id=? AND id=?')->execute([$tenant,$rulePlacement]);
[$status,$legacyReview]=$request('GET',$rulePaths['chain']);
$expect($status===200&&$legacyReview['selectable']&&$legacyReview['source_start_id']===$ruleStart,'Native handback reviews a mapped placement with a retained legacy external number');
$legacyMatches=[];foreach($legacyReview['proposed']as$p){$same=array_values(array_filter($legacyReview['current'],static fn($c)=>$c['identity']===$p['identity']));$legacyMatches[$p['key']]=$same[0]['id'];}
$legacyBody=['confirm'=>'REPLACE_REVIEWED_SOURCE_RULES','request_id'=>'99190100-0000-4000-8000-000000000010','review_token'=>$legacyReview['review_token'],'matches'=>$legacyMatches];
[$status,$legacySaved]=$request('POST',$rulePaths['chain'],$legacyBody);
$q=$pdo->prepare('SELECT external_id FROM placements WHERE tenant_id=? AND id=?');$q->execute([$tenant,$rulePlacement]);
$expect($status===200&&$legacySaved['applied']===$legacyMatches&&$q->fetchColumn()==='http-retained-placement-number','Native handback preserves both the legacy number and explicitly matched rule IDs');
$q=$pdo->prepare('SELECT COUNT(*) FROM placement_economic_parties WHERE tenant_id=? AND placement_id=? AND active=1 AND (source_system<>"jobdiva" OR source_external_id<>?)');$q->execute([$tenant,$rulePlacement,$ruleStart]);
$expect((int)$q->fetchColumn()===0,'Native legacy-number handback retains JobDiva economic provenance');
$before=$snapshot($ruleTables);[$status,$legacyAgain]=$request('POST',$rulePaths['chain'],$legacyBody);
$expect($status===200&&!empty($legacyAgain['replayed'])&&$snapshot($ruleTables)===$before,'Native legacy-number handback retries return the existing receipt');
$expect($snapshot($financialTables)===$financialBefore,'Native legacy-number handback changes no accounting, payroll, payment or time operation');

// The native route must retain the acting source workspace when business rows
// are shared. A parent catalog does not grant use of a child's connection.
foreach(['people','placements','companies','staffing','accounting']as$module)$pdo->prepare('INSERT INTO tenant_module_scope(tenant_id,module_key,scope_mode) VALUES(?,?,"shared") ON DUPLICATE KEY UPDATE scope_mode="shared"')->execute([$childTenant,$module]);
$sharedRuleStart='99190120';
$sharedRulePerson=scopedInsert('people',['first_name'=>'Synthetic','last_name'=>'Shared rule worker','classification'=>'1099','external_id'=>'http-shared-worker-legacy']);
mappingUpsert($childTenant,'jobdiva','person','99190122',$sharedRulePerson,['candidateId'=>'99190122']);
$sharedRuleBill=jobdivaUpsertCompanyMapped($childTenant,'99190126','Synthetic shared rule debtor',[],['id'=>'99190126'],null,['client']);
$sharedRuleMsp=jobdivaUpsertCompanyMapped($childTenant,'99190124','Synthetic shared rule MSP',[],['id'=>'99190124'],null,['msp']);
$sharedRuleClient=staffingClientEnsureForCompany($childTenant,$sharedRuleBill,'Synthetic shared rule debtor',['source_system'=>'jobdiva','source_external_id'=>'99190126'])['client_id'];
$sharedRulePlacement=scopedInsert('placements',['person_id'=>$sharedRulePerson,'title'=>'Synthetic shared rule handback','status'=>'on_hold','engagement_type'=>'1099','start_date'=>'2026-10-01','end_date'=>'2026-12-31','external_id'=>'http-shared-placement-legacy','client_id'=>$sharedRuleClient,'end_client_company_id'=>$sharedRuleBill,'end_client_name'=>'Synthetic shared rule debtor','client_bill_cycle'=>'monthly','client_payment_terms_override'=>'NET30','vendor_pay_cycle'=>'biweekly','vendor_payment_terms_override'=>'NET30','vendor_pwp_enabled'=>0,'worksite_country'=>'US']);
scopedInsert('placement_rates',['placement_id'=>$sharedRulePlacement,'effective_from'=>'2026-10-01','bill_rate'=>100,'pay_rate'=>50,'approved_at'=>'2026-10-01 12:00:00']);
$sharedRulePayload=array_replace($rulePayload,['startId'=>$sharedRuleStart,'candidateId'=>'99190122','jobId'=>'99190123','endClientName'=>'Synthetic shared rule debtor','mspCompanyId'=>'99190124','mspName'=>'Synthetic shared rule MSP']);
foreach(['recruiterEmail','recruiterCommissionPct','referralVendorCompanyId','referralVendor','referralFeeAmount','referralFeeBasis']as$field)unset($sharedRulePayload[$field]);
$sharedRulePayload['_jd_contract']=array_replace($rulePayload['_jd_contract'],['start_id'=>$sharedRuleStart,'client_company_name'=>'Synthetic shared rule debtor','end_client_name'=>'Synthetic shared rule debtor']);
foreach(['placement','jobdiva_assignment']as$type)mappingUpsert($childTenant,'jobdiva',$type,$sharedRuleStart,$sharedRulePlacement,$sharedRulePayload);
$sharedRuleRows=jobdivaSyncUpsertPlacementChain($tenant,$sharedRulePlacement,$sharedRuleBill,'Synthetic shared rule debtor',$sharedRulePayload,null,$childTenant)['rows'];
placementSourceRuleEdit($tenant,$sharedRulePlacement,'chain','update',$sharedRuleRows['msp'],['portal_fee_pct'=>'0.0300'],$ruleActor);
$sharedRulePath='/modules/placements/api/source_rules.php?kind=chain&placement_id='.$sharedRulePlacement;
$before=$snapshot($ruleTables);[$status,$review]=$request('GET',$sharedRulePath,null,true,$childTenant);
$expect($status===200&&$review['selectable']&&$review['source_workspace_id']===$childTenant&&$review['source_start_id']===$sharedRuleStart&&$snapshot($ruleTables)===$before,'Child native source review resolves its own connection and shared business owner without writes');
$expect($request('GET',$sharedRulePath)[0]===409&&$snapshot($ruleTables)===$before,'Parent native viewer cannot borrow a child JobDiva connection through the shared catalog');
$matches=[];foreach($review['proposed']as$p){$same=array_values(array_filter($review['current'],static fn($c)=>$c['identity']===$p['identity']));$matches[$p['key']]=$same[0]['id'];}
$sharedRuleBody=['confirm'=>'REPLACE_REVIEWED_SOURCE_RULES','request_id'=>'99190120-0000-4000-8000-000000000001','review_token'=>$review['review_token'],'matches'=>$matches];
RBACResolver::grantModule($childMembership,'placements','read',[$childTenant],$actor);
$expect($request('POST',$sharedRulePath,$sharedRuleBody,true,$childTenant)[0]===403&&$snapshot($ruleTables)===$before,'Child native read access cannot replace shared source rules');
RBACResolver::grantModule($childMembership,'placements','admin',[$childTenant],$actor);
$pdo->prepare('UPDATE tenant_module_scope SET scope_mode="isolated" WHERE tenant_id=? AND module_key="placements"')->execute([$childTenant]);
$expect($request('POST',$sharedRulePath,$sharedRuleBody,true,$childTenant)[0]===404&&$snapshot($ruleTables)===$before,'Native sharing change removes access to the old review before any mutation');
$pdo->prepare('UPDATE tenant_module_scope SET scope_mode="shared" WHERE tenant_id=? AND module_key="placements"')->execute([$childTenant]);
[$status,$review]=$request('GET',$sharedRulePath,null,true,$childTenant);$sharedRuleBody['review_token']=$review['review_token'];
$retained=$snapshot(['people','placement_rates','external_entity_mappings','entity_sync_history','core_record_numbers']);
[$status,$saved]=$request('POST',$sharedRulePath,$sharedRuleBody,true,$childTenant);
$expect($status===200&&$saved['source_workspace_id']===$childTenant&&$saved['applied']===$matches,'Child native replacement preserves matched IDs while writing the shared rule rows');
$q=$pdo->prepare('SELECT portal_fee_pct,tenant_id FROM placement_client_chain WHERE id=?');$q->execute([$sharedRuleRows['msp']]);$sharedRuleRow=$q->fetch(PDO::FETCH_ASSOC);
$expect((float)$sharedRuleRow['portal_fee_pct']===0.02&&(int)$sharedRuleRow['tenant_id']===$tenant&&$retained===$snapshot(['people','placement_rates','external_entity_mappings','entity_sync_history','core_record_numbers']),'Shared native handback preserves provider identities, legacy person numbers, history and approved rates');
$q=$pdo->prepare('SELECT tenant_id,actor_user_id FROM audit_log WHERE event="placement.source_rules.replaced" AND target_id=?');$q->execute([$sharedRulePlacement]);$sharedRuleAudits=$q->fetchAll(PDO::FETCH_ASSOC);
$expect(count($sharedRuleAudits)===1&&(int)$sharedRuleAudits[0]['tenant_id']===$childTenant&&(int)$sharedRuleAudits[0]['actor_user_id']===$actor,'Shared native receipt records the authenticated actor in the original source workspace');
$before=$snapshot($ruleTables);[$status,$again]=$request('POST',$sharedRulePath,$sharedRuleBody,true,$childTenant);
$expect($status===200&&!empty($again['replayed'])&&$again['currently_follows_source']&&$snapshot($ruleTables)===$before,'Shared native retry returns its original receipt without repeating writes');
$expect($snapshot($financialTables)===$financialBefore,'Shared native source review and replacement execute no financial operations');

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
