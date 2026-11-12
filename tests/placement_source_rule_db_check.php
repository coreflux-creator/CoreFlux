<?php
/** Synthetic business records and real native/source writers, in a disposable DB only. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('COREFLUX_ENV')!=='coreaccounting'||getenv('COREFLUX_STANDALONE_DATABASE')!=='coreaccounting_ci'
    ||!in_array('--confirm-disposable-fixture',$argv,true))exit(2);
require_once __DIR__.'/../modules/placements/lib/source_rule_edit.php';
require_once __DIR__.'/../core/jobdiva/sync.php';
require_once __DIR__.'/../core/integrations/field_map_apply.php';
$pdo=getDB();if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='coreaccounting_ci')exit(2);
$tenant=(int)$pdo->query('SELECT MIN(id) FROM tenants')->fetchColumn();if(!$tenant)exit(2);
setRequestTenantId($tenant);$_SESSION['tenant_id']=$tenant;
$n=0;$check=static function(bool $ok,string $label)use(&$n):void{if(!$ok)throw new RuntimeException($label);$n++;echo "PASS $label\n";};
$refused=static function(callable $f,int $code):bool{try{$f();return false;}catch(InvalidArgumentException|RuntimeException $e){return $e->getCode()===$code;}};
$read=static fn(string $table):array=>getDB()->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$financial=[$read('accounting_events'),$read('accounting_journal_entries'),$read('accounting_journal_entry_lines'),$read('time_entries')];
$pdo->beginTransaction();
try {
    $uid=(int)$pdo->query('SELECT MIN(id) FROM users WHERE is_active=1')->fetchColumn();
    $pdo->prepare('INSERT INTO tenant_memberships(tenant_id,user_id,persona_label,persona_type,is_primary,status) VALUES(?,?,"CI rules","tenant_admin",1,"active") ON DUPLICATE KEY UPDATE status="active"')->execute([$tenant,$uid]);
    $user=['id'=>$uid];
    $person=scopedInsert('people',['first_name'=>'CI Rule','last_name'=>'Worker','classification'=>'w2','email_primary'=>'ci-rule-owner@example.invalid']);
    $pid=scopedInsert('placements',['person_id'=>$person,'title'=>'CI legacy rules','status'=>'on_hold','engagement_type'=>'w2','start_date'=>'2026-11-01','external_id'=>'jd:99180001','coreflux_overridden_fields'=>'["notes"]']);
    scopedInsert('placement_rates',['placement_id'=>$pid,'effective_from'=>'2026-11-01','bill_rate'=>100,'pay_rate'=>50,'approved_at'=>'2026-11-01 12:00:00']);
    $rates=$read('placement_rates');$people=$read('people');
    $edit=static fn(string $kind,string $op,int $id,array $body):array=>placementSourceRuleEdit($tenant,$pid,$kind,$op,$id,$body,$user);
    $readRow=static function(string $table,int $id)use($pdo):array{$q=$pdo->prepare('SELECT * FROM '.$table.' WHERE id=?');$q->execute([$id]);return $q->fetch(PDO::FETCH_ASSOC)?:[];};
    $chain=jobdivaSyncUpsertPlacementChainRow($tenant,$pid,1,'msp','CI source MSP',null,['portal_fee_pct'=>0.02]);
    $commission=jobdivaSyncUpsertPlacementCommissionRow($tenant,$pid,'recruiter',$uid,0.1,null,'net_margin','2026-11-01',null,'recruiter');
    $referral=jobdivaSyncUpsertPlacementReferral($tenant,$pid,'2026-11-01',['employmentType'=>'W2','referralVendor'=>'CI Source Referral','referralFeeAmount'=>10,'referralFeeBasis'=>'one_time']);
    $check($chain>0&&$commission>0&&$referral>0,'Unowned JobDiva rule sets still import normally');
    placementEconomicsReconcile($tenant,$pid);
    $pdo->prepare('UPDATE placement_client_chain SET portal_credentials_ct=?,kms_key_version="ci-v1" WHERE tenant_id=? AND id=?')->execute(['CI-NOT-A-REAL-CREDENTIAL',$tenant,$chain]);
    $edit('chain','update',$chain,['position'=>4,'portal_fee_pct'=>'0.03','submittal_id'=>'SUB-12','vms_job_id'=>'VMS-34']);
    $chainRow=$readRow('placement_client_chain',$chain);
    $check($chainRow['submittal_id']==='SUB-12'&&$chainRow['vms_job_id']==='VMS-34'&&$chainRow['portal_credentials_ct']==='CI-NOT-A-REAL-CREDENTIAL','Native chain edit preserves source references and unrelated encrypted credentials');
    $q=$pdo->prepare('SELECT meta_json FROM audit_log WHERE tenant_id=? AND target_id=? AND event="placement.chain.updated" ORDER BY id DESC LIMIT 1');$q->execute([$tenant,$pid]);
    $check(!str_contains((string)$q->fetchColumn(),'CI-NOT-A-REAL-CREDENTIAL'),'Commercial audit excludes encrypted portal credential content');
    $check($refused(fn()=>$edit('chain','update',$chain,['company_id'=>999999991]),422)&&$readRow('placement_client_chain',$chain)===$chainRow,'Chain company selection rejects an unavailable directory identity');
    $edit('commission','update',$commission,['role'=>'other','effective_from'=>'2026-11-02','notes'=>'Reviewed core note','split_pct'=>0.2]);
    $edit('referral','update',$referral,['referrer_type'=>'person','referrer_person_id'=>$person,'fee_flat'=>12,'notes'=>'Reviewed referral']);
    $snap=[$read('placement_client_chain'),$read('placement_commissions'),$read('placement_referrals')];$companies=$read('companies');$audit=$read('audit_log');
    $check(count(array_intersect(['chain_rules','commission_rules','referral_rules'],placementOwnershipFields(placementAuditRow($pid)['coreflux_overridden_fields'])))===3,'Native edits claim their whole rule sets while preserving prior ownership');
    $edited=$readRow('placement_referrals',$referral);
    $check($edited['referrer_company_id']===null&&$edited['referrer_vendor_name']===null&&$edited['referrer_user_id']===null&&(int)$edited['referrer_person_id']===$person,'Changing recipient type clears incompatible hidden identities');
    $repeat=$edit('commission','update',$commission,['role'=>'other','effective_from'=>'2026-11-02','notes'=>'Reviewed core note','split_pct'=>'0.2000']);
    $check(!$repeat['changed']&&$read('audit_log')===$audit,'An exact repeated edit is a no-op');
    $check(jobdivaSyncUpsertPlacementChainRow($tenant,$pid,1,'msp','CI Must Not Exist')===0,'Direct chain source writer respects Core ownership before company side effects');
    $check(jobdivaSyncUpsertPlacementCommissionRow($tenant,$pid,'recruiter',$uid,0.8,null,'net_margin','2026-11-01',null,'recruiter')===0,'Commission source does not recreate the original role/date/notes match');
    $check(jobdivaSyncUpsertPlacementReferral($tenant,$pid,'2026-11-01',['employmentType'=>'W2','referralVendor'=>'CI Must Not Exist','referralFeeAmount'=>90])===0,'Referral source does not recreate a changed note or payee');
    $check(!empty(jobdivaSyncUpsertPlacementChain($tenant,$pid,null,'CI Must Not Exist',[])['held_by_core'])&&!empty(jobdivaSyncUpsertPlacementCommissions($tenant,$pid,'2026-11-01',[])['held_by_core']),'Set-level source writers report the Core hold');
    $check([$read('placement_client_chain'),$read('placement_commissions'),$read('placement_referrals')]===$snap&&$read('companies')===$companies,'Held source writes preserve all rule rows and create no companies');
    foreach([['placement_client_chain','portal_fee_pct','placement_chain_msp'],['placement_commissions','split_pct','placement_commission_recruiter'],['placement_referrals','fee_flat','placement_referral']]as[$table,$column,$linked]){
        $pdo->prepare('INSERT INTO tenant_integration_field_map(tenant_id,integration,entity_type,internal_field,external_field,source_path,target_module,target_table,target_column,linked_entity,transform,enabled) VALUES(?,"jobdiva","placement",?,"ruleValue","ruleValue","placements",?,?,?,"none",1)')->execute([$tenant,'ci_'.$column,$table,$column,$linked]);
    }
    $mapped=integrationFieldMapApplyAll($tenant,'jobdiva','placement',['employmentType'=>'W2','ruleValue'=>0.7],['placement'=>$pid,'self'=>$pid]);
    $check($mapped['written']===0&&count(array_filter($mapped['skipped_reasons'],static fn($v)=>str_contains($v,'core_owned_rule_set')))===3,'Custom field mappings retain all three Core rule sets before resolving or creating rows');
    foreach(['chain'=>$chain,'commission'=>$commission,'referral'=>$referral]as$kind=>$id)$edit($kind,'delete',$id,[]);
    $deleted=[$read('placement_client_chain'),$read('placement_commissions'),$read('placement_referrals')];
    jobdivaSyncUpsertPlacementChainRow($tenant,$pid,1,'msp','CI Must Not Exist');
    jobdivaSyncUpsertPlacementCommissionRow($tenant,$pid,'recruiter',$uid,0.1,null,'net_margin','2026-11-01',null,'recruiter');
    jobdivaSyncUpsertPlacementReferral($tenant,$pid,'2026-11-01',['employmentType'=>'W2','referralVendor'=>'CI Must Not Exist','referralFeeAmount'=>90]);
    $check([$read('placement_client_chain'),$read('placement_commissions'),$read('placement_referrals')]===$deleted,'Deleted native decisions are not resurrected on source replay');
    $q=$pdo->prepare('SELECT COUNT(*) FROM placement_economic_parties WHERE tenant_id=? AND placement_id=? AND source_type IN ("chain","commission","referral") AND active=1');$q->execute([$tenant,$pid]);
    $check((int)$q->fetchColumn()===0,'Deleting legacy rules retires their current graph participants');
    $input=['request_id'=>'99180001-0000-4000-8000-000000000001','referrer_type'=>'person','referrer_person_id'=>$person,'fee_basis'=>'one_time','fee_flat'=>'15','start_date'=>'2026-11-01'];
    $new=$edit('referral','create',0,$input);$audit=$read('audit_log');$graph=$read('placement_economic_parties');
    $retry=$edit('referral','create',0,$input);
    $check($retry['id']===$new['id']&&!$retry['changed']&&$read('audit_log')===$audit&&$read('placement_economic_parties')===$graph,'Lost-response retry returns the existing rule without duplicate fees or audit');
    $check($refused(fn()=>$edit('referral','create',0,array_replace($input,['fee_flat'=>20])),409),'Reusing a save request for different economics is refused');
    $check($refused(fn()=>$edit('referral','update',$new['id'],['referrer_type'=>'user','referrer_user_id'=>$uid+1000000]),422),'Foreign or inactive recipient is refused');
    $check($refused(fn()=>placementSourceRuleEdit($tenant+1000000,$pid,'referral','delete',$new['id'],[],$user),404),'A foreign tenant cannot remove the rule');
    $before=placementAuditRow($pid);$audit=$read('audit_log');$rows=$read('placement_referrals');
    $pdo->prepare('UPDATE placements SET coreflux_overridden_fields="{}" WHERE tenant_id=? AND id=?')->execute([$tenant,$pid]);
    $check($refused(fn()=>$edit('referral','update',$new['id'],['fee_flat'=>20]),422)&&$read('placement_referrals')===$rows&&$read('audit_log')===$audit,'Malformed ownership refuses the edit before side effects');
    $pdo->prepare('UPDATE placements SET coreflux_overridden_fields=? WHERE tenant_id=? AND id=?')->execute([$before['coreflux_overridden_fields'],$tenant,$pid]);
    $pdo->prepare('UPDATE placement_economic_parties SET payment_terms_overridden=1 WHERE tenant_id=? AND placement_id=? AND source_ref=?')->execute([$tenant,$pid,'referral:'.$new['id']]);
    $check($refused(fn()=>$edit('referral','update',$new['id'],['payment_terms_override'=>'NET10']),409)&&$read('placement_referrals')===$rows,'A separately reviewed participant setting cannot silently swallow a rule edit');
    $pdo->prepare('UPDATE placement_economic_parties SET payment_terms_overridden=0 WHERE tenant_id=? AND placement_id=? AND source_ref=?')->execute([$tenant,$pid,'referral:'.$new['id']]);
    $companyBefore=$read('companies');$before=placementAuditRow($pid);$rules=$read('placement_client_chain');$graphBefore=$read('placement_economic_parties');
    $pdo->exec('CREATE TEMPORARY TABLE audit_log(id BIGINT,tenant_id BIGINT,target_id BIGINT,event VARCHAR(100),meta_json JSON)');
    try{$check($refused(fn()=>$edit('chain','create',0,['request_id'=>'99180001-0000-4000-8000-000000000002','position'=>2,'party_role'=>'msp','party_name'=>'CI Rollback Company']),503)
        &&$read('companies')===$companyBefore&&$read('placement_client_chain')===$rules&&$read('placement_economic_parties')===$graphBefore&&placementAuditRow($pid)===$before&&$pdo->inTransaction(),'Audit failure rolls back the new company, rule, graph and ownership together');}
    finally{$pdo->exec('DROP TEMPORARY TABLE audit_log');}
    $main=$pdo;$second=new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $second->exec('SET SESSION innodb_lock_wait_timeout=1');$pdo=$second;
    try{jobdivaSyncUpsertPlacementChainRow($tenant,$pid,1,'msp','CI competing source');$check(false,'Competing source waits for parent');}
    catch(PDOException $e){$check((int)($e->errorInfo[1]??0)===1205&&!$second->inTransaction(),'Competing source waits for the native parent lock instead of overwriting stale ownership');}
    finally{$pdo=$main;$second=null;}
    $check($read('people')===$people&&$read('placement_rates')===$rates&&placementAuditRow($pid)['external_id']==='jd:99180001','Edits preserve people, source placement IDs and frozen rates');
    $check([$read('accounting_events'),$read('accounting_journal_entries'),$read('accounting_journal_entry_lines'),$read('time_entries')]===$financial,'Rule edits produce no financial postings or time changes');
}finally{if($pdo->inTransaction())$pdo->rollBack();setRequestTenantId(null);}
echo "$n source rule database checks passed.\n";
