<?php
/** Canonical disposable rate ownership, approval and rollback checks. No provider calls. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('COREFLUX_ENV')!=='coreaccounting'||getenv('COREFLUX_STANDALONE_DATABASE')!=='coreaccounting_ci'||!in_array('--confirm-disposable-fixture',$argv,true))exit(2);
require_once __DIR__.'/../core/jobdiva/mapping_alignment.php';
require_once __DIR__.'/../modules/placements/lib/rate_approve.php';
$pdo=getDB();if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='coreaccounting_ci')exit(2);
$owner=(int)$pdo->query('SELECT MIN(id) FROM tenants')->fetchColumn();$workspace=990705;$checks=0;$trigger=false;
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";};
$failure=static function(callable $f):string{try{$f();return '';}catch(Throwable $e){return $e->getMessage();}};
$insert=static function(string $table,array $row)use($pdo):int{$pdo->prepare('INSERT INTO '.$table.' (`'.implode('`,`',array_keys($row)).'`) VALUES('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row));return(int)$pdo->lastInsertId();};
$all=static function(string $table)use($pdo):array{$rows=array_map(static fn($r)=>json_encode($r,JSON_THROW_ON_ERROR),$pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC));sort($rows);return$rows;};
$state=static fn():array=>array_map($all,['people','placements','placement_rates','placement_economic_parties','staffing_operating_cycles','external_entity_mappings','entity_sync_history','core_record_numbers','audit_log']);
$read=static function(string $table,int $id)use($pdo):array{$q=$pdo->prepare('SELECT * FROM '.$table.' WHERE id=?');$q->execute([$id]);return$q->fetch(PDO::FETCH_ASSOC)?:[];};
$financial=array_map($all,['accounting_events','accounting_journal_entries','accounting_journal_entry_lines','time_entries','payroll_runs','billing_payments','ap_payments']);
try{
    if($pdo->query('SELECT id FROM tenants WHERE id=990705')->fetchColumn())throw new RuntimeException('Source rate fixture already exists');
    $pdo->exec("CREATE TRIGGER fixture_source_rate_audit BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.event=COALESCE(@fixture_source_rate_fail,'') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic required rate audit failure'; END IF; END");$trigger=true;
    JobdivaStoredReviewTransaction::run(static function()use($pdo,$owner,$workspace,$check,$failure,$insert,$all,$state,$read,$financial):void{
        $insert('tenants',['id'=>$workspace,'parent_id'=>$owner,'tenant_type'=>'sub','name'=>'Synthetic source rates','is_active'=>1,'is_simulation'=>1]);
        foreach(['people','placements','companies','staffing','accounting']as$module)$insert('tenant_module_scope',['tenant_id'=>$workspace,'module_key'=>$module,'scope_mode'=>'shared']);
        setRequestTenantId($workspace);$_SESSION['tenant_id']=$workspace;setRequestModuleScope('placements');
        $actor=$insert('users',['tenant_id'=>$owner,'name'=>'Synthetic rate reviewer','email'=>'source-rate-review@example.invalid','password'=>'fixture-disabled','password_hash'=>'fixture-disabled','role'=>'admin','is_active'=>1]);$user=['id'=>$actor];
        $person=$insert('people',['tenant_id'=>$owner,'first_name'=>'Synthetic','last_name'=>'Source rate','email_primary'=>'source-rate-worker@example.invalid','classification'=>'w2','status'=>'active','external_id'=>'retained-worker-number']);
        mappingUpsert($workspace,'jobdiva','person','jd:99196002',$person,['candidateId'=>'99196002']);
        $placement=$insert('placements',['tenant_id'=>$owner,'person_id'=>$person,'title'=>'Retained rate assignment','status'=>'draft','engagement_type'=>'w2','start_date'=>'2026-10-01','external_id'=>'retained-placement-number','worksite_country'=>'US']);
        $payload=['startId'=>'99196001','candidateId'=>'99196002','jobId'=>'99196003','startDate'=>'2026-10-01','status'=>'Active','_jd_contract'=>['start_id'=>'99196001','candidate_id'=>'99196002','job_id'=>'99196003','engagement_type'=>'w2','bill_rate'=>100,'pay_rate'=>50,'bill_rate_unit'=>'hour','pay_rate_unit'=>'hour','currency'=>'USD']];
        $mapping=mappingUpsert($workspace,'jobdiva','placement','jd:99196001',$placement,$payload);$mappingId=(int)$mapping['id'];
        $repair=static fn():array=>jobdivaRepairPlacementSourceRate($workspace,$placement,$actor,$mappingId);
        foreach(['wrong_start','wrong_candidate','duplicate_source','companies','people','placements','policy','invalid_json']as$case)JobdivaStoredReviewTransaction::run(static function()use($pdo,$workspace,$owner,$placement,$payload,$mappingId,$repair,$state,$failure,$check,$case):void{
            if($case==='wrong_start'||$case==='wrong_candidate'){$bad=$payload;$bad[$case==='wrong_start'?'startId':'candidateId']='99196999';$pdo->prepare('UPDATE external_entity_mappings SET payload_snapshot=? WHERE id=?')->execute([json_encode($bad),$mappingId]);}
            if($case==='duplicate_source')mappingUpsert($owner,'jobdiva','placement','99196998',$placement,$payload);
            if(in_array($case,['companies','people','placements'],true))$pdo->prepare('UPDATE tenant_module_scope SET scope_mode="isolated" WHERE tenant_id=? AND module_key=?')->execute([$workspace,$case]);
            if($case==='policy')$pdo->prepare('UPDATE tenants SET is_active=0 WHERE id=?')->execute([$workspace]);
            if($case==='invalid_json')$pdo->prepare('UPDATE external_entity_mappings SET payload_snapshot="[]" WHERE id=?')->execute([$mappingId]);
            $before=$state();$check($failure($repair)!==''&&$state()===$before,'Rate repair rejects '.$case.' without writes');
        },true);
        $before=$state();$pdo->exec("SET @fixture_source_rate_fail='placement.rate.auto_drafted_from_source'");$error=$failure($repair);$pdo->exec('SET @fixture_source_rate_fail=NULL');
        $check(str_contains($error,'Synthetic required rate audit failure')&&$state()===$before,'Late source audit failure rolls back the new draft');
        $beforeMapping=$read('external_entity_mappings',$mappingId);$beforePlacement=$read('placements',$placement);$r=$repair();
        $q=$pdo->prepare('SELECT * FROM placement_rates WHERE tenant_id=? AND placement_id=?');$q->execute([$owner,$placement]);$rate=$q->fetch(PDO::FETCH_ASSOC);$rateId=(int)$rate['id'];
        $check($r['status']==='drafted'&&(int)$rate['tenant_id']===$owner&&(float)$rate['bill_rate']===100.0&&(float)$rate['pay_rate']===50.0&&$rate['approved_at']===null,'Exact saved assignment creates an unapproved rate in the shared owner');
        $check($read('external_entity_mappings',$mappingId)===$beforeMapping&&$read('placements',$placement)===$beforePlacement,'Source repair preserves provider bindings, permanent display numbers and placement fields');
        $q=$pdo->prepare('SELECT tenant_id,actor_user_id,meta_json FROM audit_log WHERE event="placement.rate.auto_drafted_from_source" AND target_id=? ORDER BY id DESC LIMIT 1');$q->execute([$placement]);$audit=$q->fetch(PDO::FETCH_ASSOC);$meta=json_decode($audit['meta_json'],true,512,JSON_THROW_ON_ERROR);
        $check((int)$audit['tenant_id']===$workspace&&(int)$audit['actor_user_id']===$actor&&$meta['directory_owner_id']===$owner&&$meta['source_external_id']==='jd:99196001','Source audit records actor, original workspace, current owner and retained source identity');
        $before=$state();$check($repair()['status']==='skipped'&&$state()===$before,'Repeated repair preserves the existing valid draft and audit');
        JobdivaStoredReviewTransaction::run(static function()use($pdo,$workspace,$owner,$placement,$mappingId,$payload,$rateId,$repair,$state,$read,$check):void{
            $pdo->prepare('UPDATE placement_rates SET pay_rate=bill_rate WHERE id=?')->execute([$rateId]);$unsafe=$read('placement_rates',$rateId);
            $bad=$payload;$bad['_jd_contract']['pay_rate']=0;$pdo->prepare('UPDATE external_entity_mappings SET payload_snapshot=? WHERE id=?')->execute([json_encode($bad),$mappingId]);$before=$state();
            $check($repair()['reason']==='positive_source_rates_required'&&$state()===$before,'Missing source pay does not delete or replace an unsafe draft during repair');
            $pdo->prepare('UPDATE external_entity_mappings SET payload_snapshot=? WHERE id=?')->execute([json_encode($payload),$mappingId]);
            $check($repair()['status']==='drafted'&&(float)$read('placement_rates',$rateId)['pay_rate']===50.0,'Exact positive source pay repairs the retained unsafe auto-draft');
        },true);
        JobdivaStoredReviewTransaction::run(static function()use($pdo,$workspace,$owner,$placement,$mappingId,$payload,$rateId,$repair,$insert,$check):void{
            $pdo->prepare('DELETE FROM placement_rates WHERE id=?')->execute([$rateId]);$pdo->prepare('UPDATE external_entity_mappings SET payload_snapshot=NULL WHERE id=?')->execute([$mappingId]);
            $check($repair()['reason']==='exact_assignment_evidence_required','An absent snapshot cannot be manufactured from display IDs');
            mappingUpsert($workspace,'jobdiva','jobdiva_assignment','jd:99196001',$placement,$payload);
            $check($repair()['status']==='drafted','Exact prefixed assignment mirror supports a missing snapshot');
        },true);
        foreach(['rates','bill_rate','currency']as$field)JobdivaStoredReviewTransaction::run(static function()use($pdo,$owner,$workspace,$placement,$payload,$rateId,$repair,$state,$check,$field):void{
            $pdo->prepare('UPDATE placement_rates SET pay_rate=bill_rate WHERE id=?')->execute([$rateId]);$pdo->prepare('UPDATE placements SET coreflux_overridden_fields=? WHERE id=?')->execute([json_encode([$field]),$placement]);$before=$state();
            $check($repair()['reason']==='core_owned_rates'&&$state()===$before,'Repair preserves native '.$field.' ownership');
            $check(!jobdivaSyncUpsertPlacementRates($owner,$placement,'2026-10-01',$payload,false,$workspace)&&$state()===$before,'Ordinary source sync also preserves native '.$field.' ownership');
        },true);
        $native=['effective_from'=>'2026-10-01','effective_to'=>null,'bill_rate'=>110,'pay_rate'=>60,'bill_rate_unit'=>'hour','pay_rate_unit'=>'hour','currency'=>'USD'];
        $before=$state();$pdo->exec("SET @fixture_source_rate_fail='placement.rate.draft_updated'");$error=$failure(fn()=>placementRateDraftNative($workspace,$placement,$native,$actor,$rateId));$pdo->exec('SET @fixture_source_rate_fail=NULL');
        $check(str_contains($error,'Synthetic required rate audit failure')&&$state()===$before,'Native edit audit failure rolls back draft values and ownership');
        $edit=placementRateDraftNative($workspace,$placement,$native,$actor,$rateId);$edited=$read('placement_rates',$rateId);
        $check($edit['updated']&&(int)$edited['created_by_user_id']===$actor&&(float)$edited['bill_rate']===110.0&&in_array('rates',json_decode($read('placements',$placement)['coreflux_overridden_fields'],true),true),'Editing a source draft records native ownership and the editor');
        $before=$state();$check(!jobdivaSyncUpsertPlacementRates($owner,$placement,'2026-10-01',$payload,false,$workspace)&&$state()===$before,'Later source refresh cannot overwrite the edited draft or its timestamps');
        $check($repair()['reason']==='native_draft_requires_review'&&$state()===$before,'Repair preserves the manually reviewed draft');
        require_once __DIR__.'/../core/integrations/field_map_apply.php';
        $mapInsert=$pdo->prepare('INSERT INTO tenant_integration_field_map(tenant_id,integration,entity_type,external_field,internal_field,source_path,target_module,target_table,target_column,linked_entity) VALUES(?,"jobdiva","placement",?,?,?,"placements","placement_rates",?,"placement_rates")');
        foreach(['bill_rate','pay_rate','bill_rate_unit','pay_rate_unit','currency']as$field)$mapInsert->execute([$workspace,$field,$field,$field,$field]);
        tenantIntegrationFieldMapFlushCache();$mappedPayload=['employmentType'=>'W2','bill_rate'=>80,'pay_rate'=>40,'bill_rate_unit'=>'hour','pay_rate_unit'=>'hour','currency'=>'USD'];$before=$state();
        $mapped=integrationFieldMapApplyAll($workspace,'jobdiva','placement',$mappedPayload,['placement'=>$placement,'placement_rates'=>$rateId],['source_owned_context'=>true,'reconcile'=>false]);
        $check($mapped['written']===0&&$mapped['errors']===[]&&$state()===$before,'Configured field mappings cannot bypass native rate ownership');
        $held=jobdivaContractOwnedFields(['placement'=>$read('placements',$placement),'rates'=>[$read('placement_rates',$rateId)]]);
        $check(in_array('bill_rate',$held,true)&&in_array('pay_rate',$held,true)&&in_array('currency',$held,true),'Stored contract reviews recognize the complete native rate ownership decision');
        $pdo->exec("SET @fixture_source_rate_fail='placement.rate.approved'");$error=$failure(fn()=>placementsRateApproveOne($rateId,$user,false,null));$pdo->exec('SET @fixture_source_rate_fail=NULL');
        $check(str_contains($error,'Synthetic required rate audit failure')&&$state()===$before,'Approval audit failure rolls back approval and rebuilt economics together');
        $approved=placementsRateApproveOne($rateId,$user,false,null);$stored=$read('placement_rates',$rateId);
        $check($stored['approved_at']!==null&&(int)$stored['approved_by_user_id']===$actor&&(int)$stored['tenant_id']===$owner&&$stored['economics_snapshot_json']!==null,'Shared-view approval stores the actor and locked economics on the owning row');
        $before=$state();$check(str_contains($failure(fn()=>placementsRateApproveOne($rateId,$user,false,null)),'already approved')&&$state()===$before,'Repeated approval cannot restamp the immutable snapshot');
        $check(str_contains($failure(fn()=>placementRateDraftNative($workspace,$placement,$native,$actor,$rateId)),'locked')&&$state()===$before,'Native edit cannot overwrite an approved row');
        $check(!jobdivaSyncUpsertPlacementRates($owner,$placement,'2026-10-01',$payload,false,$workspace)&&$state()===$before,'Native rate ownership remains effective after approval');
        JobdivaStoredReviewTransaction::run(static function()use($pdo,$workspace,$placement,$rateId,$mappedPayload,$state,$check):void{
            $pdo->prepare('UPDATE placements SET coreflux_overridden_fields=NULL WHERE id=?')->execute([$placement]);$before=$state();
            $mapped=integrationFieldMapApplyAll($workspace,'jobdiva','placement',$mappedPayload,['placement'=>$placement,'placement_rates'=>$rateId],['source_owned_context'=>true,'reconcile'=>false]);
            $check($mapped['written']===0&&$mapped['errors']===[]&&$state()===$before,'Even without a native hold, field mappings cannot overwrite an approved snapshot');
        },true);
        $next=placementRateDraftNative($workspace,$placement,array_replace($native,['effective_from'=>'2026-11-01','bill_rate'=>120]),$actor);$before=$state();
        $pdo->exec("SET @fixture_source_rate_fail='placement.rate.superseded'");$error=$failure(fn()=>placementsRateApproveOne($next['id'],$user,false,null));$pdo->exec('SET @fixture_source_rate_fail=NULL');
        $check(str_contains($error,'Synthetic required rate audit failure')&&$state()===$before,'Late supersede audit failure restores both old history and new approval');
        $r=placementsRateApproveOne($next['id'],$user,false,null);$old=$read('placement_rates',$rateId);$new=$read('placement_rates',$next['id']);
        $check($r['superseded_count']===1&&$r['auto_correction']&&$old['effective_to']==='2026-10-31'&&(int)$old['superseded_by']===$next['id']&&(int)$new['is_correction']===1,'Shared approval closes prior coverage and records a correction from locked history');
        $check($old['economics_snapshot_json']===$stored['economics_snapshot_json']&&$old['approved_at']===$stored['approved_at'],'Superseding preserves the previous approval snapshot and original approval stamp');
        $before=$state();$error=$failure(fn()=>companyRelationshipAtomic($pdo,static function()use($workspace,$placement,$native,$actor):void{placementRateDraftNative($workspace,$placement,array_replace($native,['effective_from'=>'2026-12-01']),$actor);throw new RuntimeException('Synthetic outer activation failure');}));
        $check(str_contains($error,'Synthetic outer activation failure')&&$state()===$before,'Nested native rate changes remain subject to outer activation rollback');
        $check($financial===array_map($all,['accounting_events','accounting_journal_entries','accounting_journal_entry_lines','time_entries','payroll_runs','billing_payments','ap_payments']),'Rate work creates no time, payroll, ledger posting or payment');
    },true);
    $check(!$pdo->inTransaction()&&!JobdivaStoredReviewTransaction::inScope(),'All synthetic rate fixture records roll back');
}finally{if($pdo->inTransaction())$pdo->rollBack();if($trigger)$pdo->exec('DROP TRIGGER fixture_source_rate_audit');setRequestTenantId(null);clearRequestModuleScope();}
echo "$checks source rate ownership and approval checks passed.\n";
