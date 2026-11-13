<?php
/** Original-workspace rule handback against shared canonical records. No providers. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('COREFLUX_ENV')!=='coreaccounting'||getenv('COREFLUX_STANDALONE_DATABASE')!=='coreaccounting_ci'
    ||!in_array('--confirm-disposable-fixture',$argv,true))exit(2);
require_once __DIR__.'/../modules/placements/lib/source_rule_review.php';
$pdo=getDB();if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='coreaccounting_ci')exit(2);
$owner=(int)$pdo->query('SELECT MIN(id) FROM tenants')->fetchColumn();$workspace=990700;$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";};
$refused=static function(callable $f,int $code=409):bool{try{$f();return false;}catch(RuntimeException|InvalidArgumentException $e){return $e->getCode()===$code;}};
$all=static fn(string $table):array=>getDB()->query('SELECT * FROM '.$table.' ORDER BY '.match($table){'core_record_numbers'=>'tenant_id,entity_type,record_id','placement_corp_details'=>'placement_id',default=>'id'})->fetchAll(PDO::FETCH_ASSOC);
$state=static fn():array=>array_map($all,['placements','placement_client_chain','placement_referrals','placement_commissions','placement_economic_parties','placement_rates','people','companies','external_entity_mappings','entity_sync_history','core_record_numbers','audit_log']);
$insert=static function(string $table,array $row)use($pdo):int{$pdo->prepare('INSERT INTO '.$table.' (`'.implode('`,`',array_keys($row)).'`) VALUES('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row));return(int)$pdo->lastInsertId();};
$financial=array_map($all,['accounting_events','accounting_journal_entries','accounting_journal_entry_lines','time_entries','payroll_runs','billing_payments','ap_payments']);
try{JobdivaStoredReviewTransaction::run(static function()use($pdo,$owner,$workspace,$check,$refused,$all,$state,$insert,$financial):void{
    if($pdo->query('SELECT id FROM tenants WHERE id=990700')->fetchColumn())throw new RuntimeException('Rule-directory fixture already exists');
    $insert('tenants',['id'=>$workspace,'name'=>'Synthetic rule source workspace','parent_id'=>$owner,'tenant_type'=>'sub','is_active'=>1,'is_simulation'=>1]);
    foreach(['people','companies','placements','staffing','accounting']as$module)$insert('tenant_module_scope',['tenant_id'=>$workspace,'module_key'=>$module,'scope_mode'=>'shared']);
    setRequestTenantId($workspace);$_SESSION['tenant_id']=$workspace;setRequestModuleScope('placements');
    $user=$pdo->query('SELECT id,name,email FROM users WHERE is_active=1 ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    foreach([$workspace,$owner]as$t){
        $pdo->prepare('INSERT INTO user_tenants(user_id,tenant_id,role,status) VALUES(?,?,"admin","active") ON DUPLICATE KEY UPDATE status="active"')->execute([$user['id'],$t]);
        $pdo->prepare('INSERT INTO tenant_memberships(tenant_id,user_id,persona_label,persona_type,is_primary,status) VALUES(?,?,"Synthetic rule review","tenant_admin",1,"active") ON DUPLICATE KEY UPDATE status="active"')->execute([$t,$user['id']]);
    }
    $person=$insert('people',['tenant_id'=>$owner,'first_name'=>'Synthetic','last_name'=>'Legacy rule worker','classification'=>'w2','external_id'=>'person-import-legacy','email_primary'=>'source-directory@example.invalid']);
    mappingUpsert($workspace,'jobdiva','person','99191002',$person,['candidateId'=>'99191002']);
    $bill=jobdivaUpsertCompanyMapped($workspace,'99191006','Synthetic shared source debtor',[],['id'=>'99191006'],null,['client']);
    $client=staffingClientEnsureForCompany($workspace,$bill,'Synthetic shared source debtor',['source_system'=>'jobdiva','source_external_id'=>'99191006'])['client_id'];
    $msp=jobdivaUpsertCompanyMapped($workspace,'99191004','Synthetic shared source MSP',[],['id'=>'99191004'],null,['msp']);
    jobdivaUpsertCompanyMapped($workspace,'99191005','Synthetic shared source referrer',[],['id'=>'99191005'],null,['referrer','vendor']);
    $placement=$insert('placements',['tenant_id'=>$owner,'person_id'=>$person,'title'=>'Synthetic shared source rules','status'=>'on_hold','engagement_type'=>'w2','start_date'=>'2026-10-01','external_id'=>'placement-import-legacy','coreflux_overridden_fields'=>'["notes"]','client_id'=>$client,'end_client_company_id'=>$bill,'end_client_name'=>'Synthetic shared source debtor','client_bill_cycle'=>'weekly','client_payment_terms_override'=>'NET30','vendor_pay_cycle'=>'biweekly','vendor_payment_terms_override'=>'NET30','vendor_pwp_enabled'=>0,'worksite_country'=>'US']);
    $insert('placement_rates',['tenant_id'=>$owner,'placement_id'=>$placement,'effective_from'=>'2026-10-01','bill_rate'=>100,'pay_rate'=>50,'approved_at'=>'2026-10-01 12:00:00']);
    $payload=['startId'=>'99191001','candidateId'=>'99191002','jobId'=>'99191003','startDate'=>'2026-10-01','status'=>'on_hold','employmentType'=>'W2','worksiteCountry'=>'US','endClientName'=>'Synthetic shared source debtor','mspName'=>'Synthetic shared source MSP','mspCompanyId'=>'99191004','mspFeePct'=>2,'recruiterEmail'=>$user['email'],'recruiterCommissionPct'=>10,'referralVendorCompanyId'=>'99191005','referralVendor'=>'Synthetic shared source referrer','referralFeeAmount'=>10,'referralFeeBasis'=>'one_time','sourceReviewValue'=>0.04,'ownerReviewValue'=>0.8,
        '_jd_contract'=>['start_id'=>'99191001','engagement_type'=>'w2','placement_status'=>'on_hold','start_date'=>'2026-10-01','end_date'=>'2026-12-31','client_company_name'=>'Synthetic shared source debtor','end_client_name'=>'Synthetic shared source debtor','client_bill_cycle'=>'weekly','vendor_pay_cycle'=>'biweekly','client_payment_terms'=>'NET30','vendor_payment_terms'=>'NET30','paid_when_paid'=>false,'worksite_country'=>'US','worksite_city'=>'Hamilton','bill_rate'=>100,'pay_rate'=>50,'bill_rate_unit'=>'hour','pay_rate_unit'=>'hour','currency'=>'USD']];
    mappingUpsert($workspace,'jobdiva','placement','99191001',$placement,$payload);
    mappingUpsert($workspace,'jobdiva','jobdiva_assignment','99191001',$placement,$payload);
    $chain=jobdivaSyncUpsertPlacementChain($owner,$placement,$bill,'Synthetic shared source debtor',$payload,null,$workspace)['rows'];
    $commission=jobdivaSyncUpsertPlacementCommissions($owner,$placement,'2026-10-01',$payload,$workspace)['rows']['recruiter'];
    $referral=jobdivaSyncUpsertPlacementReferral($owner,$placement,'2026-10-01',$payload,null,$workspace);
    $check(placementEconomicsReconcile($owner,$placement)['available'],'Shared legacy placement builds its economic graph from the saved source link');
    $edit=static fn(string $kind,int $id,array $patch):array=>placementSourceRuleEdit($owner,$placement,$kind,'update',$id,$patch,$user);
    $edit('chain',$chain['msp'],['portal_fee_pct'=>'0.0300']);$edit('commission',$commission,['split_pct'=>'0.2000']);$edit('referral',$referral,['fee_flat'=>'12.00']);
    foreach([$workspace=>'sourceReviewValue',$owner=>'ownerReviewValue']as$t=>$field)$insert('tenant_integration_field_map',['tenant_id'=>$t,'integration'=>'jobdiva','entity_type'=>'placement','external_field'=>$field,'internal_field'=>'directory_review_pct','source_path'=>$field,'target_module'=>'placements','target_table'=>'placement_client_chain','target_column'=>'portal_fee_pct','linked_entity'=>'placement_chain_msp']);
    $preview=static fn(string $kind='chain'):array=>placementSourceRuleReview($workspace,$placement,$kind);
    $body=static function(array $review,int $n):array{$matches=[];foreach($review['proposed']as$p){$same=array_values(array_filter($review['current'],static fn($c)=>$c['identity']===$p['identity']));$matches[$p['key']]=$same[0]['id']??0;}return['confirm'=>'REPLACE_REVIEWED_SOURCE_RULES','request_id'=>'99191001-0000-4000-8000-'.sprintf('%012d',$n),'review_token'=>$review['review_token'],'matches'=>$matches];};
    $apply=static fn(string $kind,array $data):array=>placementSourceRuleReplace($workspace,$placement,$kind,$data,$user);
    $before=$state();$review=$preview();$data=$body($review,1);
    $check($review['selectable']&&$review['source_workspace_id']===$workspace&&$review['source_start_id']==='99191001','Shared rule review uses the original source workspace and mapped Start despite a legacy display number');
    $proposal=array_values(array_filter($review['proposed'],static fn($p)=>$p['identity']==='company:'.$msp))[0];
    $check((float)$proposal['values']['portal_fee_pct']===0.04,'Configured handback fields come from the source workspace rather than the graph owner');
    $check($state()===$before&&$preview()['review_token']===$review['review_token'],'Shared preview is read-only and its token is stable');
    $check(!str_contains(json_encode($review,JSON_THROW_ON_ERROR),'__directory_context'),'Public source review omits its internal policy and payload context');
    foreach(['companies','placements','mapping','source','person','display','unlinked','competing']as$change)JobdivaStoredReviewTransaction::run(static function()use($change,$pdo,$owner,$workspace,$placement,$person,$payload,$data,$apply,$state,$check,$refused):void{
        if(in_array($change,['companies','placements'],true))$pdo->prepare('UPDATE tenant_module_scope SET scope_mode="isolated" WHERE tenant_id=? AND module_key=?')->execute([$workspace,$change]);
        if($change==='mapping')$pdo->prepare('UPDATE tenant_integration_field_map SET source_path="ownerReviewValue" WHERE tenant_id=? AND internal_field="directory_review_pct"')->execute([$workspace]);
        if($change==='source')mappingUpsert($workspace,'jobdiva','jobdiva_assignment','99191001',$placement,array_replace($payload,['sourceReviewValue'=>0.06]));
        if($change==='person')$pdo->prepare('UPDATE people SET external_id="jd:another-person" WHERE id=?')->execute([$person]);
        if($change==='display')$pdo->prepare('UPDATE placements SET external_id="different-retained-number" WHERE id=?')->execute([$placement]);
        if($change==='unlinked')$pdo->prepare('DELETE FROM external_entity_mappings WHERE tenant_id=? AND source_system="jobdiva" AND internal_entity_type="placement" AND external_id="99191001"')->execute([$workspace]);
        if($change==='competing')mappingUpsert($owner,'jobdiva','placement','99191999',$placement,$payload);
        $before=$state();$check($refused(fn()=>$apply('chain',$data))&&$state()===$before,'Changed '.$change.' evidence rejects shared handback atomically');
    },true);
    setRequestTenantId($owner);$_SESSION['tenant_id']=$owner;
    $check($refused(fn()=>placementSourceRuleReview($owner,$placement,'chain')),'Parent viewer cannot borrow the child workspace source link');
    setRequestTenantId($workspace);$_SESSION['tenant_id']=$workspace;
    $immutable=array_map($all,['people','placement_rates','external_entity_mappings','entity_sync_history','core_record_numbers']);
    foreach(['chain'=>1,'commission'=>2,'referral'=>3]as$kind=>$n){
        $r=$preview($kind);$check($r['selectable'],'Shared '.$kind.' source remains selectable');$data=$body($r,$n);
        $saved=$apply($kind,$data);$check($saved['applied']===$data['matches']&&$saved['source_control_released'],'Shared '.$kind.' handback preserves explicitly matched rule IDs');
        $before=$state();$again=$apply($kind,$data);$check(!empty($again['replayed'])&&$state()===$before,'Shared '.$kind.' retry returns its receipt without new writes');
    }
    $q=$pdo->prepare('SELECT portal_fee_pct,tenant_id FROM placement_client_chain WHERE id=?');$q->execute([$chain['msp']]);$saved=$q->fetch(PDO::FETCH_ASSOC);
    $check((float)$saved['portal_fee_pct']===0.04&&(int)$saved['tenant_id']===$owner,'Configured source value commits to the business owner only');
    $q=$pdo->prepare('SELECT tenant_id,meta_json FROM audit_log WHERE event="placement.source_rules.replaced" AND target_id=? ORDER BY id');$q->execute([$placement]);$audits=$q->fetchAll(PDO::FETCH_ASSOC);
    $check(count($audits)===3&&array_unique(array_map('intval',array_column($audits,'tenant_id')))===[$workspace],'Required receipts remain in the original acting workspace');
    $check($immutable===array_map($all,['people','placement_rates','external_entity_mappings','entity_sync_history','core_record_numbers']),'Handback preserves source IDs, person identity, numbers, history and approved rate snapshots');
    $q=$pdo->prepare('SELECT external_id FROM placements WHERE id=?');$q->execute([$placement]);
    $check($q->fetchColumn()==='placement-import-legacy','Handback leaves the retained placement external number unchanged');
    $q=$pdo->prepare('SELECT source_system,source_external_id FROM placement_economic_parties WHERE placement_id=? AND active=1');$q->execute([$placement]);$parties=$q->fetchAll(PDO::FETCH_ASSOC);
    $check($parties&&count(array_filter($parties,static fn($p)=>$p['source_system']==='jobdiva'&&$p['source_external_id']==='99191001'))===count($parties),'Derived participants keep JobDiva provenance after legacy-number handback');
    $check($financial===array_map($all,['accounting_events','accounting_journal_entries','accounting_journal_entry_lines','time_entries','payroll_runs','billing_payments','ap_payments']),'Shared source handback creates no financial execution or time changes');
},true);}finally{setRequestTenantId(null);clearRequestModuleScope();}
echo "$checks source rule directory checks passed.\n";
