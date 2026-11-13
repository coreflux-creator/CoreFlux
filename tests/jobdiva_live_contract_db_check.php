<?php
/** Exact live-contract apply against the disposable canonical database. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('COREFLUX_ENV')!=='coreaccounting'||getenv('COREFLUX_STANDALONE_DATABASE')!=='coreaccounting_ci'||!in_array('--confirm-disposable-fixture',$argv,true))exit(2);
require_once __DIR__.'/../core/jobdiva/live_contract_refresh.php';
$pdo=getDB();if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='coreaccounting_ci')exit(2);
$owner=(int)$pdo->query('SELECT MIN(id) FROM tenants')->fetchColumn();$workspace=990707;$checks=0;$trigger='fixture_live_contract_audit';
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";};
$failure=static function(callable $f):string{try{$f();return '';}catch(Throwable $e){return $e->getMessage();}};
$insert=static function(string $table,array $row)use($pdo):int{$pdo->prepare('INSERT INTO '.$table.' (`'.implode('`,`',array_keys($row)).'`) VALUES('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row));return(int)$pdo->lastInsertId();};
$all=static function(string $table)use($pdo):array{$rows=array_map(static fn($r)=>json_encode($r,JSON_THROW_ON_ERROR),$pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC));sort($rows);return$rows;};
$tables=['people','companies','staffing_clients','staffing_jobs','placements','placement_rates','placement_corp_details','placement_economic_parties','external_entity_mappings','entity_sync_history','core_record_numbers','audit_log'];
$state=static fn():array=>array_map($all,$tables);
$read=static function(string $table,int $id)use($pdo):array{$q=$pdo->prepare('SELECT * FROM '.$table.' WHERE id=?');$q->execute([$id]);return$q->fetch(PDO::FETCH_ASSOC)?:[];};
try{
    $pdo->exec("CREATE TRIGGER {$trigger} BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF BINARY NEW.event=BINARY 'placement.live_contract.refreshed' AND @fixture_live_contract_audit=1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic live contract audit failure'; END IF; END");
    JobdivaStoredReviewTransaction::run(static function()use($pdo,$owner,$workspace,$check,$failure,$insert,$all,$state,$read):void{
        $insert('tenants',['id'=>$workspace,'parent_id'=>$owner,'tenant_type'=>'sub','name'=>'Synthetic live contracts','is_active'=>1,'is_simulation'=>1]);
        foreach(['people','placements','companies','staffing','accounting']as$module)$insert('tenant_module_scope',['tenant_id'=>$workspace,'module_key'=>$module,'scope_mode'=>'shared']);
        $actor=$insert('users',['tenant_id'=>$owner,'name'=>'Synthetic contract reviewer','email'=>'live-contract@example.invalid','password'=>'fixture-disabled','password_hash'=>'fixture-disabled','role'=>'admin','is_active'=>1]);
        $person=$insert('people',['tenant_id'=>$owner,'first_name'=>'Synthetic','last_name'=>'Live contract','email_primary'=>'live-worker@example.invalid','classification'=>'w2','status'=>'active','external_id'=>'retained-live-worker']);
        mappingUpsert($workspace,'jobdiva','person','jd:99310002',$person,['candidateId'=>'99310002']);
        $placement=$insert('placements',['tenant_id'=>$owner,'person_id'=>$person,'title'=>'Native retained title','status'=>'on_hold','engagement_type'=>'w2','start_date'=>'2026-10-01','external_id'=>'retained-live-placement','worksite_country'=>'US','coreflux_overridden_fields'=>'["title"]']);
        $root=['startId'=>'99310001','candidateId'=>'99310002','jobId'=>'99310003','startDate'=>'2026-10-01','status'=>'on_hold','title'=>'Updated source title','worksiteCountry'=>'US'];
        $mapping=mappingUpsert($workspace,'jobdiva','placement','jd:99310001',$placement,$root);$mappingId=(int)$mapping['id'];
        mappingUpsert($workspace,'jobdiva','jobdiva_job','99310003',99310003,['id'=>'99310003','jobId'=>'99310003','title'=>'Synthetic job','Per Diem'=>777]);
        mappingUpsert($workspace,'quanta','placement','retained-quanta-assignment',$placement,['id'=>'retained-quanta-assignment']);
        $expected=jobdivaLiveContractSnapshot($workspace,$mappingId);
        $exact=['status'=>'verified','row'=>jobdivaAssignmentMarkVerified($root,'99310001','searchStart:criteria_exact')];
        $input=jobdivaLiveContractInput($expected,$exact);
        $rows=[['Start ID'=>'99310001','Candidate ID'=>'99310002','Job ID'=>'99310003','Employment Category'=>'Hourly Employee','W2'=>true,'Bill Rate'=>100,'Pay Rate'=>50,'Bill Rate Unit'=>'hour','Pay Rate Unit'=>'hour','Currency'=>'USD','Start Date'=>'2026-10-01',
            'BILLING'=>[['Approved'=>true,'Actual Start'=>false,'COMPANY'=>[['ID'=>'99310004','Name'=>'Synthetic contract client']]]],'SALARY'=>[['Approved'=>true]]]];
        $fresh=$input+['_jd_assignment_detail'=>$rows,'_jd_contract'=>jobdivaAssignmentContractBuild($rows,jobdivaAssignmentStripDerivedFacets($input,'99310001'),'99310001')];
        $check(jobdivaLiveContractValidate($expected,$exact,$fresh)===$fresh,'Exact raw financial evidence rebuilds the supplied contract');
        $check(empty($fresh['_jd_contract']['overheads']['per_diem']),'Related job financial fields cannot fill missing assignment overheads');
        foreach(['bill_rate','pay_rate']as$missing){
            $incomplete=$fresh;$incomplete['_jd_contract'][$missing]=0;
            if($missing==='bill_rate')$incomplete['_jd_contract']['net_bill_rate']=0;
            $incomplete['_jd_job']['bill_rate']=999;$incomplete['_jd_job']['pay_rate']=888;
            $before=$state();
            $check(!jobdivaSyncUpsertPlacementRates($owner,$placement,'2026-10-01',$incomplete,true,$workspace)&&$state()===$before,'Missing exact '.$missing.' cannot fall back to related job pricing');
        }
        $check($expected['base']['context']['placement_owner']===$owner&&$expected['base']['mapping']['external_id']==='jd:99310001','Live refresh resolves a shared placement from its original workspace and retained provider pair');
        JobdivaStoredReviewTransaction::run(static function()use($pdo,$workspace,$mappingId,$exact,$rows,$actor,$state,$check,$failure,$read,$placement):void{
            $corporation='99310009';$company=['id'=>$corporation,'name'=>'Synthetic live corporate payee','city'=>'Hamilton','country'=>'US'];
            $snapshot=jobdivaLiveContractSnapshot($workspace,$mappingId,$corporation);
            $payload=jobdivaLiveContractInput($snapshot,$exact);$corporateRows=$rows;
            $corporateRows[0]['Employment Category']='Subcontract';$corporateRows[0]['W2']=false;$corporateRows[0]['C2C']=true;
            $corporateRows[0]['Corporation ID']=$corporation;$corporateRows[0]['Pay Rate to Vendor']=50;
            $payload['_jd_assignment_detail']=$corporateRows;$payload['_jd_contract']=jobdivaAssignmentContractBuild($corporateRows,jobdivaAssignmentStripDerivedFacets($payload,'99310001'),'99310001');
            $before=$state();$bad=array_replace($company,['id'=>'99319999']);
            $check($failure(fn()=>jobdivaLiveContractApply($snapshot,$exact,$payload,$bad,$actor))!==''&&$before===$state(),'A returned corporate payee must echo the exact contract company ID');
            $pdo->exec('SET @fixture_live_contract_audit=1');$error=$failure(fn()=>jobdivaLiveContractApply($snapshot,$exact,$payload,$company,$actor));$pdo->exec('SET @fixture_live_contract_audit=0');
            $check(str_contains($error,'Synthetic live contract audit failure')&&$before===$state(),'Late audit failure also rolls back a newly resolved corporate company and vendor: '.$error);
            $result=jobdivaLiveContractApply($snapshot,$exact,$payload,$company,$actor);
            $q=$pdo->prepare('SELECT c.name,c.city,pc.company_id,m.external_id FROM placement_corp_details pc JOIN companies c ON c.id=pc.company_id AND c.tenant_id=? JOIN external_entity_mappings m ON m.internal_entity_id=c.id AND m.tenant_id=? AND m.source_system="jobdiva" AND m.internal_entity_type="company" WHERE pc.placement_id=?');
            $q->execute([$snapshot['base']['context']['company_owner'],$workspace,$placement]);$saved=$q->fetch(PDO::FETCH_ASSOC);
            $check($result['projected']===1&&$saved&&$saved['external_id']===$corporation&&$saved['city']==='Hamilton','Fresh corporate company, profile, vendor and assignment commit together in their current directories');
        },true);
        foreach([false,true]as$coreOwned)JobdivaStoredReviewTransaction::run(static function()use($pdo,$owner,$workspace,$mappingId,$placement,$person,$actor,$exact,$fresh,$insert,$read,$check,$coreOwned):void{
            $other=$insert('people',['tenant_id'=>$owner,'first_name'=>'Synthetic','last_name'=>'Retained alternate worker','email_primary'=>'alternate-live-worker@example.invalid','classification'=>'w2','status'=>'active','external_id'=>'retained-alternate-number']);
            $pdo->prepare('UPDATE placements SET person_id=?,coreflux_overridden_fields=? WHERE id=?')->execute([$other,$coreOwned?'["title","person_id"]':'["title"]',$placement]);
            $snapshot=jobdivaLiveContractSnapshot($workspace,$mappingId);$result=jobdivaLiveContractApply($snapshot,$exact,$fresh,null,$actor);
            $check($result['projected']===1&&(int)$read('placements',$placement)['person_id']===($coreOwned?$other:$person),'Live candidate reconciliation '.($coreOwned?'preserves the Core-owned person selection':'corrects a stale source-owned person selection'));
            $check($read('people',$other)['external_id']==='retained-alternate-number'&&$read('people',$person)['external_id']==='retained-live-worker','Candidate reconciliation never rewrites either permanent person number');
        },true);
        JobdivaStoredReviewTransaction::run(static function()use($pdo,$owner,$workspace,$mappingId,$placement,$actor,$exact,$fresh,$insert,$read,$check):void{
            $rate=$insert('placement_rates',['tenant_id'=>$owner,'placement_id'=>$placement,'effective_from'=>'2026-10-01','bill_rate'=>130,'pay_rate'=>65,'approved_at'=>'2026-10-01 12:00:00','approved_by_user_id'=>$actor]);
            $pdo->prepare('UPDATE placements SET coreflux_overridden_fields=? WHERE id=?')->execute(['["title","rates"]',$placement]);
            $before=$read('placement_rates',$rate);$snapshot=jobdivaLiveContractSnapshot($workspace,$mappingId);
            $result=jobdivaLiveContractApply($snapshot,$exact,$fresh,null,$actor);
            $check($result['projected']===1&&$read('placement_rates',$rate)===$before,'The live path retains the complete Core-owned approved rate and approval actor');
        },true);
        foreach(['root','worker','detail','contract']as$case){
            $bad=$fresh;$remote=$exact;
            if($case==='root')$bad['startId']='99319999';
            if($case==='worker')$remote['row']['candidateId']='99319999';
            if($case==='detail')$bad['_jd_assignment_detail'][0]['Candidate ID']='99319999';
            if($case==='contract')$bad['_jd_contract']['pay_rate']=999;
            $before=$state();$error=$failure(fn()=>jobdivaLiveContractApply($expected,$remote,$bad,null,$actor));
            $check($error!==''&&$before===$state(),'Mismatched '.$case.' cannot change any native or source record');
        }
        foreach(['policy','mapping','worker','native','field_map','rate']as$case)JobdivaStoredReviewTransaction::run(static function()use($pdo,$workspace,$mappingId,$person,$placement,$owner,$actor,$expected,$exact,$fresh,$state,$check,$failure,$insert,$case):void{
            if($case==='policy')$pdo->prepare('UPDATE tenant_module_scope SET scope_mode="isolated" WHERE tenant_id=? AND module_key="placements"')->execute([$workspace]);
            if($case==='mapping')$pdo->prepare('UPDATE external_entity_mappings SET sync_status="error" WHERE id=?')->execute([$mappingId]);
            if($case==='worker')$pdo->prepare('UPDATE people SET status="bench" WHERE id=?')->execute([$person]);
            if($case==='native')$pdo->prepare('UPDATE placements SET title="Later native edit" WHERE id=?')->execute([$placement]);
            if($case==='field_map')$insert('tenant_integration_field_map',['tenant_id'=>$workspace,'integration'=>'jobdiva','entity_type'=>'placement','external_field'=>'title','internal_field'=>'title']);
            if($case==='rate')$insert('placement_rates',['tenant_id'=>$owner,'placement_id'=>$placement,'effective_from'=>'2026-10-01','bill_rate'=>101,'pay_rate'=>51,'created_by_user_id'=>$actor]);
            $before=$state();$error=$failure(fn()=>jobdivaLiveContractApply($expected,$exact,$fresh,null,$actor));
            $check($error!==''&&$before===$state(),'Concurrent '.$case.' change rejects the stale live result');
        },true);
        $before=$state();$pdo->exec('SET @fixture_live_contract_audit=1');$error=$failure(fn()=>jobdivaLiveContractApply($expected,$exact,$fresh,null,$actor));$pdo->exec('SET @fixture_live_contract_audit=0');
        $check(str_contains($error,'Synthetic live contract audit failure')&&$before===$state(),'Required audit failure rolls back company, placement, rates, identity and history: '.$error);
        $result=jobdivaLiveContractApply($expected,$exact,$fresh,null,$actor);$saved=$read('placements',$placement);$link=$read('external_entity_mappings',$mappingId);
        $check($result['projected']===1&&$saved['title']==='Native retained title'&&$saved['external_id']==='retained-live-placement','Live refresh projects the shared assignment while retaining Core title and placement number');
        $check($link['external_id']==='jd:99310001'&&(int)$link['internal_entity_id']===$placement&&$link['content_hash']===mappingHash(json_decode($link['payload_snapshot'],true)),'Provider pair, target and source fingerprint remain consistent');
        $check($read('people',$person)['external_id']==='retained-live-worker','Permanent person number survives source refresh');
        $q=$pdo->prepare('SELECT internal_entity_id FROM external_entity_mappings WHERE tenant_id=? AND source_system="quanta" AND external_id="retained-quanta-assignment"');$q->execute([$workspace]);
        $check((int)$q->fetchColumn()===$placement,'Quanta assignment identity remains attached to the same Core placement');
        $before=$state();$check($failure(fn()=>jobdivaLiveContractApply($expected,$exact,$fresh,null,$actor))!==''&&$before===$state(),'A stale replay cannot replace the newly saved source snapshot');
        $current=jobdivaLiveContractSnapshot($workspace,$mappingId);$beforeCounts=array_map(static fn($t)=>count($all($t)),['people','companies','placements','placement_rates','external_entity_mappings']);
        $again=jobdivaLiveContractApply($current,$exact,$fresh,null,$actor);
        $check($again['projected']===1&&$beforeCounts===array_map(static fn($t)=>count($all($t)),['people','companies','placements','placement_rates','external_entity_mappings']),'A refreshed replay creates no duplicate identities, assignments or rates');
        foreach(['held','inactive','current']as$case)JobdivaStoredReviewTransaction::run(static function()use($pdo,$workspace,$placement,$person,$mappingId,$exact,$fresh,$actor,$read,$check,$case):void{
            $pdo->prepare('UPDATE placements SET deleted_at=NOW(),coreflux_overridden_fields=? WHERE id=?')->execute([$case==='held'?'["title","deleted_at"]':'["title"]',$placement]);
            if($case==='inactive')$pdo->prepare('UPDATE people SET status="inactive" WHERE id=?')->execute([$person]);
            $snapshot=jobdivaLiveContractSnapshot($workspace,$mappingId);$result=jobdivaLiveContractApply($snapshot,$exact,$fresh,null,$actor);
            $check($case==='current'?($result['restored']===1&&$read('placements',$placement)['deleted_at']===null):($result['projected']===0&&!empty($read('placements',$placement)['deleted_at'])),'Archived '.$case.' placement follows explicit lifecycle and worker availability');
        },true);
        $check(str_contains($failure(fn()=>jobdivaRefreshLiveContract($workspace,$mappingId,$actor)),'outside an existing transaction'),'Remote lookup refuses an existing transaction');
    },true);
    $check(!$pdo->inTransaction()&&!JobdivaStoredReviewTransaction::inScope(),'All synthetic live-contract data rolls back');
}finally{if($pdo->inTransaction())$pdo->rollBack();$pdo->exec('DROP TRIGGER IF EXISTS '.$trigger);}
echo "$checks live contract checks passed.\n";
