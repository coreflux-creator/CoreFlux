<?php
/** No provider requests; canonical disposable database, rollback-only invented fixtures. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('COREFLUX_ENV')!=='coreaccounting'||getenv('COREFLUX_STANDALONE_DATABASE')!=='coreaccounting_ci'||!in_array('--confirm-disposable-fixture',$argv,true))exit(2);
require_once __DIR__.'/../core/jobdiva/active_status_review.php';
$pdo=getDB();if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='coreaccounting_ci')exit(2);
$owner=(int)$pdo->query('SELECT MIN(id) FROM tenants')->fetchColumn();$workspace=990709;$checks=0;$trigger='fixture_active_restore_audit';
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";};
$failure=static function(callable $f):string{try{$f();return '';}catch(Throwable $e){return $e->getMessage();}};
$insert=static function(string $table,array $row)use($pdo):int{$pdo->prepare('INSERT INTO '.$table.' (`'.implode('`,`',array_keys($row)).'`) VALUES('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row));return(int)$pdo->lastInsertId();};
$all=static function(string $table)use($pdo):array{$rows=array_map(static fn($r)=>json_encode($r,JSON_THROW_ON_ERROR),$pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC));sort($rows);return$rows;};
$protected=['people','people_employees','people_employment_links','payroll_profiles','placement_rates','placement_economic_parties','staffing_operating_cycles','external_entity_mappings','entity_sync_history','core_record_numbers','accounting_events','accounting_journal_entries','accounting_journal_entry_lines','time_entries','payroll_runs','ap_payments','billing_payments'];
$state=static fn():array=>array_map($all,[...$protected,'placements','audit_log']);
$read=static function(string $table,int $id)use($pdo):array{$q=$pdo->prepare('SELECT * FROM '.$table.' WHERE id=?');$q->execute([$id]);return$q->fetch(PDO::FETCH_ASSOC)?:[];};
try{
    if($pdo->query('SELECT id FROM tenants WHERE id=990709')->fetchColumn())throw new RuntimeException('Restore fixture already exists');
    $pdo->exec("CREATE TRIGGER {$trigger} BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF BINARY NEW.event=BINARY 'jobdiva.active_status_restored' AND @fixture_active_restore_audit=1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic restore receipt failure'; END IF; END");
    JobdivaStoredReviewTransaction::run(static function()use($pdo,$owner,$workspace,$check,$failure,$insert,$all,$state,$read,$protected):void{
        $insert('tenants',['id'=>$workspace,'parent_id'=>$owner,'tenant_type'=>'sub','name'=>'Synthetic active restoration','is_active'=>1,'is_simulation'=>1]);
        foreach(['people','placements','companies','staffing','accounting']as$module)$insert('tenant_module_scope',['tenant_id'=>$workspace,'module_key'=>$module,'scope_mode'=>'shared']);
        $actor=$insert('users',['tenant_id'=>$owner,'name'=>'Synthetic restore reviewer','email'=>'restore-review@example.invalid','password'=>'fixture-disabled','password_hash'=>'fixture-disabled','role'=>'admin','is_active'=>1]);
        $membership=$insert('tenant_memberships',['user_id'=>$actor,'tenant_id'=>$workspace,'persona_type'=>'admin','status'=>'active']);
        $grant=$insert('membership_module_access',['membership_id'=>$membership,'module_key'=>'placements','access_level'=>'write']);
        $insert('jobdiva_connections',['tenant_id'=>$workspace,'client_id'=>'synthetic','username'=>'synthetic','password_enc'=>'unused','status'=>'connected','last_sync_at'=>date('Y-m-d H:i:s')]);
        $company=companiesUpsertByName($owner,'Synthetic restore client',[],['client']);$client=staffingClientEnsureForCompany($owner,$company,'Synthetic restore client')['client_id'];
        $person=$insert('people',['tenant_id'=>$owner,'first_name'=>'Synthetic','last_name'=>'Restoration','email_primary'=>'restore-worker@example.invalid','classification'=>'w2','status'=>'active','external_id'=>'retained-worker-number']);
        $employee=$insert('people_employees',['tenant_id'=>$owner,'employee_number'=>'SYNTHETIC-RESTORE','legal_first_name'=>'Synthetic','legal_last_name'=>'Restore','status'=>'active']);
        $insert('people_employment_links',['tenant_id'=>$owner,'person_id'=>$person,'employee_id'=>$employee,'review_id'=>str_repeat('b',64),'actor_user_id'=>$actor,'origin'=>'review_existing','reason'=>'Synthetic existing employment','review_ct'=>'synthetic-private-receipt']);
        $profile=$insert('payroll_profiles',['tenant_id'=>$owner,'employee_id'=>$employee,'enabled'=>1]);
        mappingUpsert($workspace,'jobdiva','person','jd:99320002',$person,['candidateId'=>'99320002']);
        $make=static function(string $status,string $external)use($pdo,$owner,$workspace,$person,$client,$company,$actor,$insert):array{
            $placement=$insert('placements',['tenant_id'=>$owner,'person_id'=>$person,'client_id'=>$client,'end_client_company_id'=>$company,'title'=>'Synthetic retained assignment','status'=>$status,'engagement_type'=>'w2','start_date'=>'2026-10-01','external_id'=>'retained-'.$external,'client_bill_cycle'=>'monthly','vendor_pay_cycle'=>'biweekly','client_payment_terms_override'=>'NET30','worksite_country'=>'US']);
            $root=['startId'=>$external,'candidateId'=>'99320002','jobId'=>'99320003','startDate'=>'2026-10-01','status'=>'Active'];
            $detail=[['Start ID'=>$external,'Candidate ID'=>'99320002','Job ID'=>'99320003','Employment Category'=>'Hourly Employee','Bill Rate'=>100,'Pay Rate'=>50,'Start Date'=>'2026-10-01','Currency'=>'USD',
                'BILLING'=>[['Approved'=>true,'Actual Start'=>true,'Actual End'=>false,'Closed'=>false]],'SALARY'=>[['Approved'=>true,'Closed'=>false]]]];
            $payload=$root+['_jd_assignment_detail'=>$detail,'_jd_contract'=>jobdivaAssignmentContractBuild($detail,$root,$external)];
            $mapping=mappingUpsert($workspace,'jobdiva','placement','jd:'.$external,$placement,$payload);
            $rate=$insert('placement_rates',['tenant_id'=>$owner,'placement_id'=>$placement,'effective_from'=>'2026-10-01','bill_rate'=>100,'pay_rate'=>50,'approved_at'=>'2026-10-01 12:00:00','approved_by_user_id'=>$actor]);
            placementEconomicsReconcile($owner,$placement);
            return [$placement,(int)$mapping['id'],$rate,$payload];
        };
        [$pending,$mapping,$rate,$payload]=$make('pending_start','99320001');[$ended,$endedMapping]=$make('ended','99320004');
        $ids=[$pending,$ended];$preview=static fn(array $selected=[]):array=>jobdivaActiveRestoreReview($workspace,$actor,$selected?:$ids);
        $body=static fn(array $r,array $selected=[]):array=>['confirm'=>'RESTORE_REVIEWED_ACTIVE_STATUS','placement_ids'=>$selected?:$ids,'review_token'=>$r['review_token'],'request_id'=>bin2hex(random_bytes(4)).'-0000-4000-8000-'.bin2hex(random_bytes(6)),'expected_restore_count'=>$r['selected_count'],'expected_active_total'=>$r['expected_active_total_after'],'reason'=>'Reviewed exact current JobDiva assignment'];
        $before=$state();$r=$preview();
        $check($r['can_apply']&&$r['eligible_count']===2&&$r['placement_owner_id']===$owner,'Shared owner preview accepts both evidenced pending and ended assignments: '.json_encode($r['rows']));
        $check($before===$state(),'Preview changes no business row, identity, rate or audit');
        $check(!str_contains(json_encode($r),'example.invalid')&&!str_contains(json_encode($r),'password_enc'),'Review exposes decisions without private source or credentials');
        $request=$body($r);
        foreach(['pending_no_detail','fabricated_contract','stale_source','disconnected','native_status','inactive_worker','missing_rate','overlap_rate','disabled_payroll','dates','archived','actual_end','wrong_person','wrong_raw_candidate','policy','revoked_actor','revoked_membership','revoked_grant','active_count','missing_client','retired_company','disabled_cycle','duplicate_source','contradictory_mirror','mirror_closed_financials','missing_number']as$case)JobdivaStoredReviewTransaction::run(static function()use($pdo,$workspace,$owner,$actor,$membership,$grant,$profile,$pending,$person,$mapping,$rate,$payload,$ids,$preview,$request,$state,$failure,$check,$insert,$case):void{
            if(in_array($case,['pending_no_detail','fabricated_contract','wrong_raw_candidate'],true)){
                $bad=$payload;if($case==='pending_no_detail')unset($bad['_jd_assignment_detail']);
                if($case==='fabricated_contract')$bad['_jd_contract']['salary_approved']=false;
                if($case==='wrong_raw_candidate')$bad['_jd_assignment_detail'][0]['Candidate ID']='99329999';
                $pdo->prepare('UPDATE external_entity_mappings SET payload_snapshot=? WHERE id=?')->execute([json_encode($bad),$mapping]);
            }
            if($case==='missing_client')$pdo->prepare('UPDATE placements SET client_id=NULL WHERE id=?')->execute([$pending]);
            if($case==='retired_company')$pdo->prepare('UPDATE companies c JOIN placements p ON p.end_client_company_id=c.id SET c.deleted_at=NOW() WHERE p.id=?')->execute([$pending]);
            if($case==='disabled_cycle')$pdo->prepare('UPDATE staffing_operating_cycles c JOIN placement_economic_parties ep ON ep.operating_cycle_id=c.id SET c.active=0 WHERE ep.placement_id=?')->execute([$pending]);
            // The normal stable mapper deliberately retains the old pair. Construct a legacy duplicate directly.
            if($case==='duplicate_source')$insert('external_entity_mappings',['tenant_id'=>$workspace,'source_system'=>'jobdiva','internal_entity_type'=>'placement','external_id'=>'99320001','internal_entity_id'=>$pending,'payload_snapshot'=>json_encode($payload),'content_hash'=>mappingHash($payload),'sync_status'=>'ok']);
            if($case==='contradictory_mirror')mappingUpsert($workspace,'jobdiva','jobdiva_assignment','99320001',99320001,['startId'=>'99320001','candidateId'=>'99320002','jobId'=>'99320003','startDate'=>'2026-10-01','status'=>'Ended']);
            if($case==='mirror_closed_financials'){
                $mirror=$payload;$mirror['_jd_assignment_detail'][0]['BILLING'][0]['Actual End']=true;
                mappingUpsert($workspace,'jobdiva','jobdiva_assignment','99320001',99320001,$mirror);
            }
            if($case==='missing_number')$pdo->prepare('DELETE FROM core_record_numbers WHERE tenant_id=? AND entity_type="placement" AND record_id=?')->execute([$owner,$pending]);
            if($case==='stale_source')$pdo->prepare('UPDATE external_entity_mappings SET last_seen_at=DATE_SUB(NOW(),INTERVAL 3 DAY) WHERE id=?')->execute([$mapping]);
            if($case==='disconnected')$pdo->prepare('UPDATE jobdiva_connections SET status="disconnected" WHERE tenant_id=?')->execute([$workspace]);
            if($case==='native_status')$pdo->prepare('UPDATE placements SET coreflux_overridden_fields=\'["status"]\' WHERE id=?')->execute([$pending]);
            if($case==='inactive_worker')$pdo->prepare('UPDATE people SET status="inactive" WHERE id=?')->execute([$person]);
            if($case==='missing_rate')$pdo->prepare('UPDATE placement_rates SET approved_at=NULL WHERE id=?')->execute([$rate]);
            if($case==='overlap_rate')$insert('placement_rates',['tenant_id'=>$owner,'placement_id'=>$pending,'effective_from'=>'2026-10-01','bill_rate'=>110,'pay_rate'=>55,'approved_at'=>'2026-10-01 13:00:00']);
            if($case==='disabled_payroll')$pdo->prepare('UPDATE payroll_profiles SET enabled=0 WHERE id=?')->execute([$profile]);
            if($case==='dates')$pdo->prepare('UPDATE placements SET start_date="2026-10-02" WHERE id=?')->execute([$pending]);
            if($case==='archived')$pdo->prepare('UPDATE placements SET deleted_at=NOW() WHERE id=?')->execute([$pending]);
            if($case==='actual_end')$pdo->prepare('UPDATE placements SET actual_end_date="2026-10-31" WHERE id=?')->execute([$pending]);
            if($case==='wrong_person')$pdo->prepare('UPDATE external_entity_mappings SET internal_entity_id=0 WHERE tenant_id=? AND internal_entity_type="person"')->execute([$workspace]);
            if($case==='policy')$pdo->prepare('UPDATE tenant_module_scope SET scope_mode="isolated" WHERE tenant_id=? AND module_key="people"')->execute([$workspace]);
            if($case==='revoked_actor')$pdo->prepare('UPDATE users SET is_active=0 WHERE id=?')->execute([$actor]);
            if($case==='revoked_membership')$pdo->prepare('UPDATE tenant_memberships SET status="revoked" WHERE id=?')->execute([$membership]);
            if($case==='revoked_grant')$pdo->prepare('UPDATE membership_module_access SET access_level="read" WHERE id=?')->execute([$grant]);
            if($case==='active_count')$insert('placements',['tenant_id'=>$owner,'person_id'=>$person,'title'=>'Synthetic intervening activation','status'=>'active','engagement_type'=>'w2','start_date'=>'2026-10-01','worksite_country'=>'US']);
            $before=$state();$error=$failure(fn()=>jobdivaActiveRestoreApply($workspace,$actor,$request));
            $check($error!==''&&$before===$state(),'Changed '.$case.' rejects the reviewed batch atomically');
            if($case!=='active_count'){
                $reviewError=$failure(static function()use($preview):void{if($preview()['can_apply'])throw new LogicException('UNSAFE fresh eligibility');});
                $check($reviewError!=='UNSAFE fresh eligibility'&&$before===$state(),'Fresh review also refuses unsafe '.$case);
            }
        },true);
        foreach([[],[$pending,$pending],['1junk'],[true],['9999999999999999999999999']]as$bad)$check($failure(fn()=>jobdivaActiveRestoreIds($bad))!=='','Malformed or duplicate selection is rejected');
        $check($failure(fn()=>jobdivaActiveRestoreReview($owner,$actor,$ids))!==''&&$before===$state(),'A different source workspace cannot borrow child links');
        $bad=$request;$bad['expected_active_total']++;
        $check($failure(fn()=>jobdivaActiveRestoreApply($workspace,$actor,$bad))!==''&&$before===$state(),'Wrong final count cannot partially restore the batch');
        $pdo->exec('SET @fixture_active_restore_audit=1');$error=$failure(fn()=>jobdivaActiveRestoreApply($workspace,$actor,$request));$pdo->exec('SET @fixture_active_restore_audit=0');
        $check(str_contains($error,'Synthetic restore receipt failure')&&$before===$state(),'Late receipt failure rolls back both statuses and their per-row audits');
        $protectedBefore=array_map($all,$protected);$pBefore=$read('placements',$pending);
        $saved=jobdivaActiveRestoreApply($workspace,$actor,$request);$pAfter=$read('placements',$pending);
        $check($saved['restored']===2&&$pAfter['status']==='active'&&$read('placements',$ended)['status']==='active','The complete reviewed batch restores both source-owned statuses');
        unset($pBefore['status'],$pBefore['updated_at'],$pAfter['status'],$pAfter['updated_at']);
        $check($pBefore===$pAfter&&$protectedBefore===array_map($all,$protected),'All non-status fields, provider pairs, rate approvals, time, payroll, journals and payments remain byte-for-byte unchanged');
        $q=$pdo->prepare('SELECT COUNT(*) FROM audit_log WHERE tenant_id=? AND actor_user_id=? AND event IN("jobdiva.active_status_restored","placement.active_status_restored")');$q->execute([$workspace,$actor]);
        $check((int)$q->fetchColumn()===3,'Two placement decisions and the batch receipt retain the actual reviewer');
        $pdo->prepare('UPDATE placements SET status="on_hold",coreflux_overridden_fields=\'["status"]\' WHERE id=?')->execute([$pending]);$after=$state();
        $replay=jobdivaActiveRestoreApply($workspace,$actor,$request);
        $check($replay['replayed']&&$after===$state(),'Lost-response retry returns its receipt without reversing a later native hold');
        $different=$request;$different['reason']='Changed retry reason';
        $check($failure(fn()=>jobdivaActiveRestoreApply($workspace,$actor,$different))!==''&&$after===$state(),'A request ID cannot be reused for different instructions');
        $pdo->prepare('UPDATE membership_module_access SET access_level="none" WHERE id=?')->execute([$grant]);
        $check($failure(fn()=>jobdivaActiveRestoreApply($workspace,$actor,$request))!==''&&$after===$state(),'Receipt recovery still requires current placement permission');
    },true);
    $check(!$pdo->inTransaction()&&!JobdivaStoredReviewTransaction::inScope(),'All synthetic restoration records roll back');
}finally{if($pdo->inTransaction())$pdo->rollBack();$pdo->exec('DROP TRIGGER IF EXISTS '.$trigger);RBACResolver::resetCache();}
echo "$checks active restoration checks passed.\n";
