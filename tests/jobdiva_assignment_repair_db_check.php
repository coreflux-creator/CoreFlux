<?php
/** Exact assignment repair against canonical disposable schemas; no network calls. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('COREFLUX_ENV')!=='coreaccounting'||getenv('COREFLUX_STANDALONE_DATABASE')!=='coreaccounting_ci'||!in_array('--confirm-disposable-fixture',$argv,true))exit(2);
require_once __DIR__.'/../core/jobdiva/mapping_alignment.php';
$pdo=getDB();if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='coreaccounting_ci')exit(2);
$owner=(int)$pdo->query('SELECT MIN(id) FROM tenants')->fetchColumn();$workspace=990703;$checks=0;$trigger=false;
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";};
$failure=static function(callable $f):string{try{$f();return '';}catch(Throwable $e){return $e->getMessage();}};
$insert=static function(string $table,array $row)use($pdo):int{$pdo->prepare('INSERT INTO '.$table.' (`'.implode('`,`',array_keys($row)).'`) VALUES('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row));return(int)$pdo->lastInsertId();};
$all=static fn(string $table):array=>$pdo->query('SELECT * FROM '.$table.' ORDER BY '.($table==='core_record_numbers'?'tenant_id,entity_type,record_id':'id'))->fetchAll(PDO::FETCH_ASSOC);
$state=static fn():array=>array_map($all,['people','placements','placement_rates','placement_economic_parties','external_entity_mappings','entity_sync_history','core_record_numbers','audit_log']);
$read=static function(string $table,int $id)use($pdo):array{$q=$pdo->prepare('SELECT * FROM '.$table.' WHERE id=?');$q->execute([$id]);return$q->fetch(PDO::FETCH_ASSOC)?:[];};
$financial=array_map($all,['accounting_events','accounting_journal_entries','accounting_journal_entry_lines','time_entries','payroll_runs','billing_payments','ap_payments']);
try{
    if($pdo->query('SELECT id FROM tenants WHERE id=990703')->fetchColumn())throw new RuntimeException('Assignment repair fixture already exists');
    $pdo->exec("CREATE TRIGGER fixture_assignment_repair_audit BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF COALESCE(@fixture_assignment_repair_fail,0)=1 AND NEW.event='placement.assignment_source_repaired' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic required assignment repair audit failure'; END IF; END");$trigger=true;
    JobdivaStoredReviewTransaction::run(static function()use($pdo,$owner,$workspace,$check,$failure,$insert,$all,$state,$read,$financial):void{
        $insert('tenants',['id'=>$workspace,'parent_id'=>$owner,'tenant_type'=>'sub','name'=>'Synthetic assignment repair workspace','is_active'=>1,'is_simulation'=>1]);
        foreach(['people','placements','companies','staffing','accounting']as$module)$insert('tenant_module_scope',['tenant_id'=>$workspace,'module_key'=>$module,'scope_mode'=>'shared']);
        setRequestTenantId($workspace);$_SESSION['tenant_id']=$workspace;setRequestModuleScope('placements');
        $person=$insert('people',['tenant_id'=>$owner,'first_name'=>'Synthetic','last_name'=>'Source repair','email_primary'=>'source-repair@example.invalid','classification'=>'w2','status'=>'active','external_id'=>'legacy-worker-number']);
        mappingUpsert($workspace,'jobdiva','person','jd:99194002',$person,['candidateId'=>'99194002']);
        $placement=$insert('placements',['tenant_id'=>$owner,'person_id'=>$person,'title'=>'Retained title','status'=>'active','engagement_type'=>'w2','start_date'=>'2026-10-01','end_date'=>'2099-12-31','external_id'=>'legacy-placement-number','worksite_country'=>'US','notes'=>'Retained note']);
        $insert('placement_rates',['tenant_id'=>$owner,'placement_id'=>$placement,'effective_from'=>'2026-10-01','bill_rate'=>100,'pay_rate'=>50,'approved_at'=>'2026-10-01 12:00:00']);
        $payload=['startId'=>'99194001','candidateId'=>'99194002','jobId'=>'99194003','startDate'=>'2026-10-01','endDate'=>'2099-12-31','status'=>'Active','title'=>'Source title','_jd_contract'=>['start_id'=>'99194001','candidate_id'=>'99194002','job_id'=>'99194003','bill_rate'=>100,'pay_rate'=>50],'_jd_job'=>['id'=>'99194003','title'=>'Cached job']];
        $mapping=mappingUpsert($workspace,'jobdiva','placement','jd:99194001',$placement,$payload);
        $mappingId=(int)$mapping['id'];
        $mirror=mappingUpsert($workspace,'jobdiva','jobdiva_assignment','99194001',$placement,$payload);
        $remote=jobdivaAssignmentMarkVerified(array_diff_key($payload,array_flip(['_jd_contract','_jd_job'])),'99194001','searchStart:criteria_exact');
        $exact=['status'=>'verified','row'=>$remote,'identity'=>['channel'=>'searchStart:criteria_exact'],'seen_ids'=>['99194001']];
        $preview=static fn():array=>JobdivaStoredReviewTransaction::run(static fn()=>jobdivaAssignmentRepairSnapshot($workspace,$mappingId),true);
        $apply=static fn(array $s,?array $r=null):array=>jobdivaAssignmentRepairApply($s,$r??$exact);
        $before=$state();$expected=$preview();
        $check($expected['context']['placement_owner']===$owner&&$expected['mapping']['external_id']==='jd:99194001'&&$state()===$before,'Repair preview is read-only and resolves the original source into its shared directory');
        $check($failure(fn()=>JobdivaStoredReviewTransaction::run(static fn()=>jobdivaAssignmentRepairSnapshot($owner,$mappingId),true))!==''&&$state()===$before,'Parent workspace cannot borrow a child source link');
        $check(str_contains($failure(fn()=>jobdivaMappingRepairAssignmentSources($workspace)),'outside an existing transaction'),'Remote lookup orchestration refuses a transaction before any provider call');
        foreach(['companies','placements','people','source','mirror','ownership','candidate','display','competing','policy']as$change)JobdivaStoredReviewTransaction::run(static function()use($pdo,$workspace,$owner,$person,$placement,$mappingId,$mirror,$payload,$expected,$apply,$state,$check,$failure,$change):void{
            if(in_array($change,['companies','placements','people'],true))$pdo->prepare('UPDATE tenant_module_scope SET scope_mode="isolated" WHERE tenant_id=? AND module_key=?')->execute([$workspace,$change]);
            if($change==='source')$pdo->prepare('UPDATE external_entity_mappings SET last_error="Changed evidence" WHERE tenant_id=? AND id=?')->execute([$workspace,$mappingId]);
            if($change==='mirror')$pdo->prepare('UPDATE external_entity_mappings SET sync_status="error" WHERE tenant_id=? AND id=?')->execute([$workspace,$mirror['id']]);
            if($change==='ownership')$pdo->prepare('UPDATE placements SET coreflux_overridden_fields=\'["status"]\' WHERE tenant_id=? AND id=?')->execute([$owner,$placement]);
            if($change==='candidate')$pdo->prepare('UPDATE people SET status="inactive" WHERE tenant_id=? AND id=?')->execute([$owner,$person]);
            if($change==='display')$pdo->prepare('UPDATE placements SET external_id="different-number" WHERE tenant_id=? AND id=?')->execute([$owner,$placement]);
            if($change==='competing')mappingUpsert($owner,'jobdiva','placement','99194999',$placement,$payload);
            if($change==='policy')$pdo->prepare('UPDATE tenants SET is_active=0 WHERE id=?')->execute([$workspace]);
            $before=$state();$check($failure(fn()=>$apply($expected))!==''&&$state()===$before,'Changed '.$change.' evidence refuses the pending remote result without writes');
        },true);
        foreach(['startId'=>'99194999','candidateId'=>'99194998','jobId'=>'99194997']as$key=>$value){
            $wrong=$exact;$wrong['row'][$key]=$value;$before=$state();
            $check($failure(fn()=>$apply($preview(),$wrong))!==''&&$state()===$before,'A verified-looking response with different '.$key.' cannot change the source link');
        }
        $before=$state();$pdo->exec('SET @fixture_assignment_repair_fail=1');$message=$failure(fn()=>$apply($preview()));$pdo->exec('SET @fixture_assignment_repair_fail=0');
        $check(str_contains($message,'Synthetic required assignment repair audit failure')&&$state()===$before,'Required audit failure rolls back source metadata and history together');
        $beforePlacement=$read('placements',$placement);$beforeRates=$all('placement_rates');$beforeMirror=$read('external_entity_mappings',(int)$mirror['id']);
        $result=$apply($expected);$saved=$read('external_entity_mappings',$mappingId);$savedPayload=json_decode($saved['payload_snapshot'],true,512,JSON_THROW_ON_ERROR);
        $check($result['action']==='remote_verified'&&$result['mapping_changed']&&$saved['external_id']==='jd:99194001'&&(int)$saved['internal_entity_id']===$placement,'Exact source refresh preserves the physical prefixed provider pair');
        $check($savedPayload['_jd_contract']===$payload['_jd_contract']&&!isset($savedPayload['_jd_job'])&&$savedPayload['startId']==='99194001','Exact source refresh retains financial detail and removes cached enrichment');
        $check($read('placements',$placement)===$beforePlacement&&$all('placement_rates')===$beforeRates&&$read('external_entity_mappings',(int)$mirror['id'])===$beforeMirror,'Source verification leaves business fields, approved rates and separate mirror evidence unchanged');
        $q=$pdo->query('SELECT tenant_id,meta_json FROM audit_log WHERE event="placement.assignment_source_repaired" ORDER BY id DESC LIMIT 1');$audit=$q->fetch(PDO::FETCH_ASSOC);$detail=json_decode($audit['meta_json'],true,512,JSON_THROW_ON_ERROR);
        $check((int)$audit['tenant_id']===$workspace&&$detail['directory_owner_id']===$owner&&$detail['source_external_id']==='jd:99194001'&&$detail['before']['placement']===$detail['after']['placement'],'Required repair audit records original workspace, shared owner, preserved provider pair and before/after evidence');
        $before=$state();$check($failure(fn()=>$apply($expected))!==''&&$state()===$before,'Old source review cannot replay after a committed refresh');
        $again=$apply($preview());$check(!$again['mapping_changed']&&$state()===$before,'Fresh identical source verification creates no duplicate history or audit');
        foreach(['held','inactive','completed','transition','allowed']as$case)JobdivaStoredReviewTransaction::run(static function()use($pdo,$owner,$placement,$person,$case,$preview,$apply,$exact,$read,$check):void{
            $pdo->prepare('UPDATE placements SET deleted_at="2026-11-01 00:00:00" WHERE tenant_id=? AND id=?')->execute([$owner,$placement]);
            if($case==='held')$pdo->prepare('UPDATE placements SET coreflux_overridden_fields=\'["status"]\' WHERE tenant_id=? AND id=?')->execute([$owner,$placement]);
            if($case==='inactive')$pdo->prepare('UPDATE people SET status="inactive" WHERE tenant_id=? AND id=?')->execute([$owner,$person]);
            $response=$exact;if($case==='completed')$response['row']['status']='Completed';if($case==='transition')$response['row']['status']='On Hold';
            $result=$apply($preview(),$response);$saved=$read('placements',$placement);
            $check($case==='allowed'?($result['placements_restored']===1&&$saved['deleted_at']===null):($result['placements_restored']===0&&$result['review_required']===1&&$saved['deleted_at']!==null),'Archived restoration respects '.$case.' lifecycle and worker evidence');
        },true);
        $missing=['status'=>'not_found','row'=>null,'seen_ids'=>[],'error'=>'Synthetic missing result'];
        JobdivaStoredReviewTransaction::run(static function()use($apply,$preview,$missing,$read,$placement,$check):void{
            $before=$read('placements',$placement);$result=$apply($preview(),$missing);
            $check($result['action']==='review'&&$result['quarantined']===1&&$read('placements',$placement)===$before,'An empty provider response quarantines only source metadata and never archives a placement');
        },true);
        $terminal=['status'=>'not_assignment','row'=>null,'seen_ids'=>['99194001'],'identity'=>['reason'=>'rejected_assignment_state'],'error'=>'Synthetic exact terminal result'];
        foreach(['none','owned','document']as$case)JobdivaStoredReviewTransaction::run(static function()use($pdo,$owner,$workspace,$placement,$case,$insert,$apply,$preview,$terminal,$read,$check):void{
            if($case==='owned')$pdo->prepare('UPDATE placements SET coreflux_overridden_fields=\'["end_date"]\' WHERE tenant_id=? AND id=?')->execute([$owner,$placement]);
            if($case==='document')$insert('people_pipeline_stages',['tenant_id'=>$workspace,'person_id'=>$read('placements',$placement)['person_id'],'stage'=>'placed','placement_id'=>$placement]);
            $result=$apply($preview(),$terminal);
            $check($case==='none'?($result['placements_archived']===1&&$read('placements',$placement)['deleted_at']!==null):($result['placements_archived']===0&&$read('placements',$placement)['deleted_at']===null),'Exact terminal repair respects '.$case.' ownership/downstream evidence across directories');
        },true);
        JobdivaStoredReviewTransaction::run(static function()use($pdo,$workspace,$mappingId,$insert,$apply,$preview,$missing,$read,$check):void{
            $insert('jobdiva_connections',['tenant_id'=>$workspace,'client_id'=>'synthetic','username'=>'synthetic','password_enc'=>'not-a-credential','last_sync_at'=>'2026-10-20 12:00:00']);
            $pdo->prepare('UPDATE external_entity_mappings SET last_seen_at="2026-10-20 12:00:00",sync_status="ok" WHERE tenant_id=? AND id=?')->execute([$workspace,$mappingId]);
            $result=$apply($preview(),$missing);$check($result['action']==='trusted_stored'&&$read('external_entity_mappings',$mappingId)['last_seen_at']==='2026-10-20 12:00:00','Stored trust does not manufacture a new provider observation timestamp');
            $pdo->prepare('UPDATE external_entity_mappings SET sync_status="error" WHERE tenant_id=? AND id=?')->execute([$workspace,$mappingId]);
            $result=$apply($preview(),$missing);$check($result['action']==='review','Quarantined source evidence cannot promote itself to trusted stored evidence');
        },true);
        $check($financial===array_map($all,['accounting_events','accounting_journal_entries','accounting_journal_entry_lines','time_entries','payroll_runs','billing_payments','ap_payments']),'Assignment source repair executes no time, accounting, payroll or payment operations');
    },true);
    $check(!$pdo->inTransaction()&&!JobdivaStoredReviewTransaction::inScope(),'Assignment repair fixture rolls back all synthetic business records');
}finally{if($pdo->inTransaction())$pdo->rollBack();if($trigger)$pdo->exec('DROP TRIGGER fixture_assignment_repair_audit');setRequestTenantId(null);clearRequestModuleScope();}
echo "$checks assignment source repair checks passed.\n";
