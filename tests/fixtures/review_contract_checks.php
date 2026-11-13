<?php
/** Uncertain Starts share the real canonical database, then roll every case back. */
require_once __DIR__.'/../../core/jobdiva/review_contract_refresh.php';
JobdivaStoredReviewTransaction::run(static function()use($pdo,$owner,$workspace,$mappingId,$placement,$person,$actor,$root,$rows,$state,$read,$insert,$check,$failure):void{
    $reviewRoot=array_replace($root,['status'=>'Offer accepted','__cf_jobdiva_review_run_token'=>'synthetic-review']);
    $review=mappingUpsert($workspace,'jobdiva','jobdiva_assignment_review','jd:99310001',99310001,$reviewRoot);
    $mirror=mappingUpsert($workspace,'jobdiva','jobdiva_assignment','jd:99310001',99310001,$root);
    $pdo->prepare('UPDATE external_entity_mappings SET last_seen_at="2026-10-02 03:04:05" WHERE id IN(?,?,?)')->execute([$mappingId,$review['id'],$mirror['id']]);
    $rid=(int)$review['id'];$expected=jobdivaReviewContractSnapshot($workspace,$rid);$input=jobdivaReviewContractInput($expected);
    $payload=$input+['_jd_assignment_detail'=>$rows,'_jd_contract'=>jobdivaAssignmentContractBuild($rows,jobdivaAssignmentStripDerivedFacets($input,'99310001'),'99310001')];
    $before=$state();$check(jobdivaReviewContractValidate($expected,$payload)===$payload&&$before===$state(),'Review queue validates exact financial evidence without writing or qualifying its workflow label alone');
    foreach(['root','detail','contract','worker_facet']as$case){
        $bad=$payload;if($case==='root')$bad['startId']='99319999';
        if($case==='detail')$bad['_jd_assignment_detail'][0]['Candidate ID']='99319999';
        if($case==='contract')$bad['_jd_contract']['pay_rate']=999;
        if($case==='worker_facet')$bad['_jd_candidate']=['id'=>'99319999'];
        $before=$state();$check($failure(fn()=>jobdivaReviewContractApply($expected,$bad,null,$actor))!==''&&$before===$state(),'Review queue rejects mismatched '.$case.' without partial changes');
    }
    foreach(['policy','native','worker','review','primary','mirror','rate','field_map','disconnected']as$case)JobdivaStoredReviewTransaction::run(static function()use($pdo,$workspace,$owner,$person,$placement,$mappingId,$rid,$mirror,$actor,$expected,$payload,$state,$insert,$check,$failure,$case):void{
        if($case==='policy')$pdo->prepare('UPDATE tenant_module_scope SET scope_mode="isolated" WHERE tenant_id=? AND module_key="placements"')->execute([$workspace]);
        if($case==='native')$pdo->prepare('UPDATE placements SET title="Later queue edit" WHERE id=?')->execute([$placement]);
        if($case==='worker')$pdo->prepare('UPDATE people SET status="inactive" WHERE id=?')->execute([$person]);
        if(in_array($case,['review','primary','mirror'],true))$pdo->prepare('UPDATE external_entity_mappings SET content_hash=? WHERE id=?')->execute([str_repeat('a',64),$case==='review'?$rid:($case==='primary'?$mappingId:$mirror['id'])]);
        if($case==='rate')$insert('placement_rates',['tenant_id'=>$owner,'placement_id'=>$placement,'effective_from'=>'2026-10-01','bill_rate'=>150,'pay_rate'=>75]);
        if($case==='field_map')$insert('tenant_integration_field_map',['tenant_id'=>$workspace,'integration'=>'jobdiva','entity_type'=>'placement','external_field'=>'title','internal_field'=>'title']);
        if($case==='disconnected')$insert('jobdiva_connections',['tenant_id'=>$workspace,'client_id'=>'synthetic','username'=>'synthetic','password_enc'=>'fixture-disabled','status'=>'disconnected']);
        $before=$state();$check($failure(fn()=>jobdivaReviewContractApply($expected,$payload,null,$actor))!==''&&$before===$state(),'Review queue rejects changed '.$case.' after provider lookup');
    },true);
    foreach(['placement','jobdiva_assignment_review','jobdiva_assignment','receipt']as$case){
        $before=$state();$pdo->prepare('SET @fixture_review_contract_failure=?')->execute([$case]);
        $error=$failure(fn()=>jobdivaReviewContractApply($expected,$payload,null,$actor));$pdo->exec('SET @fixture_review_contract_failure=NULL');
        $check(str_contains($error,'Synthetic review contract failure')&&$before===$state(),'Required '.$case.' observation rolls back the complete review: '.$error);
    }
    foreach(['current','held','inactive','closed']as$case)JobdivaStoredReviewTransaction::run(static function()use($pdo,$workspace,$person,$placement,$rid,$actor,$rows,$state,$read,$check,$case):void{
        $pdo->prepare('UPDATE placements SET deleted_at=NOW(),actual_end_date=?,coreflux_overridden_fields=? WHERE id=?')->execute([$case==='closed'?'2026-10-01':null,$case==='held'?'["title","deleted_at"]':'["title"]',$placement]);
        if($case==='inactive')$pdo->prepare('UPDATE people SET status="inactive" WHERE id=?')->execute([$person]);
        $expected=jobdivaReviewContractSnapshot($workspace,$rid);$input=jobdivaReviewContractInput($expected);
        $payload=$input+['_jd_assignment_detail'=>$rows,'_jd_contract'=>jobdivaAssignmentContractBuild($rows,jobdivaAssignmentStripDerivedFacets($input,'99310001'),'99310001')];
        $result=jobdivaReviewContractApply($expected,$payload,null,$actor);
        $check($case==='current'?($result['restored']===1&&$read('placements',$placement)['deleted_at']===null):($result['projected']===0&&!empty($read('placements',$placement)['deleted_at'])),'Review queue retains archive policy for '.$case.' placement');
    },true);
    foreach(['draft','ended']as$desired)foreach([false,true]as$held)JobdivaStoredReviewTransaction::run(static function()use($pdo,$workspace,$placement,$rid,$actor,$rows,$read,$check,$desired,$held):void{
        $pdo->prepare('UPDATE placements SET coreflux_overridden_fields=? WHERE id=?')->execute([$held?'["title","status"]':'["title"]',$placement]);
        $expected=jobdivaReviewContractSnapshot($workspace,$rid);$input=jobdivaReviewContractInput($expected);$detail=$rows;
        if($desired==='draft')$detail[0]['SALARY'][0]['Approved']=false;
        else{$detail[0]['BILLING'][0]['Closed']=true;$detail[0]['End Date']='2026-10-02';}
        $payload=$input+['_jd_assignment_detail'=>$detail,'_jd_contract'=>jobdivaAssignmentContractBuild($detail,jobdivaAssignmentStripDerivedFacets($input,'99310001'),'99310001')];
        $result=jobdivaReviewContractApply($expected,$payload,null,$actor);
        $check($result['demoted']===(int)!$held&&$read('placements',$placement)['status']===($held?'on_hold':$desired),'Exact '.$desired.' review respects '.($held?'native':'source').' lifecycle ownership');
    },true);
    JobdivaStoredReviewTransaction::run(static function()use($pdo,$workspace,$owner,$actor,$rid,$rows,$read,$state,$check,$failure):void{
        $corporation='99310019';$expected=jobdivaReviewContractSnapshot($workspace,$rid,$corporation);$input=jobdivaReviewContractInput($expected);$detail=$rows;
        $detail[0]['Employment Category']='Subcontract';$detail[0]['W2']=false;$detail[0]['C2C']=true;$detail[0]['Corporation ID']=$corporation;$detail[0]['Pay Rate to Vendor']=50;
        $payload=$input+['_jd_assignment_detail'=>$detail,'_jd_contract'=>jobdivaAssignmentContractBuild($detail,jobdivaAssignmentStripDerivedFacets($input,'99310001'),'99310001')];
        $company=['id'=>$corporation,'name'=>'Synthetic queued corporate payee'];$before=$state();
        $pdo->exec('SET @fixture_review_contract_failure="receipt"');$error=$failure(fn()=>jobdivaReviewContractApply($expected,$payload,$company,$actor));$pdo->exec('SET @fixture_review_contract_failure=NULL');
        $check(str_contains($error,'Synthetic review contract failure')&&$before===$state(),'Corporate company and vendor creation roll back with the required review receipt');
        $result=jobdivaReviewContractApply($expected,$payload,$company,$actor);
        $check($result['updated']===1&&mappingFindInternal($workspace,'jobdiva','company',$corporation)!==null,'Exact queued corporate company and assignment commit together');
    },true);
    JobdivaStoredReviewTransaction::run(static function()use($pdo,$workspace,$actor,$root,$rows,$check):void{
        $external='99311001';$source=array_replace($root,['startId'=>$external,'status'=>'Offer accepted']);
        $review=mappingUpsert($workspace,'jobdiva','jobdiva_assignment_review',$external,(int)$external,$source);
        $pdo->prepare('UPDATE external_entity_mappings SET last_seen_at="2026-10-03 04:05:06" WHERE id=?')->execute([$review['id']]);
        $expected=jobdivaReviewContractSnapshot($workspace,(int)$review['id']);$input=jobdivaReviewContractInput($expected);$detail=$rows;$detail[0]['Start ID']=$external;
        $payload=$input+['_jd_assignment_detail'=>$detail,'_jd_contract'=>jobdivaAssignmentContractBuild($detail,jobdivaAssignmentStripDerivedFacets($input,$external),$external)];
        $result=jobdivaReviewContractApply($expected,$payload,null,$actor);$primary=mappingFindInternal($workspace,'jobdiva','placement',$external);
        $mirror=mappingFindInternal($workspace,'jobdiva','jobdiva_assignment',$external);
        $check($result['created']===1&&$primary&&$mirror,'An exact current contract creates a previously unprojected assignment');
        $check($primary['last_seen_at']==='2026-10-03 04:05:06'&&$mirror['last_seen_at']===$primary['last_seen_at'],'New assignment and mirror use the actual review census date');
    },true);
    $primaryBefore=$read('external_entity_mappings',$mappingId);$mirrorBefore=$read('external_entity_mappings',(int)$mirror['id']);
    $result=jobdivaReviewContractApply($expected,$payload,null,$actor);
    $saved=$read('placements',$placement);$primary=$read('external_entity_mappings',$mappingId);$savedMirror=$read('external_entity_mappings',(int)$mirror['id']);
    $check($result['updated']===1&&$saved['title']==='Native retained title'&&$saved['external_id']==='retained-live-placement','Review queue projects through the shared owner and retains native title and permanent number');
    foreach([[$primaryBefore,$primary],[$mirrorBefore,$savedMirror]]as[$old,$new])$check($new['external_id']===$old['external_id']&&$new['internal_entity_id']===$old['internal_entity_id']&&$new['last_seen_at']===$old['last_seen_at']&&$new['content_hash']===mappingHash(json_decode($new['payload_snapshot'],true)),'Review queue retains original source pair, census date and consistent payload fingerprint');
    $before=$state();$check($failure(fn()=>jobdivaReviewContractApply($expected,$payload,null,$actor))!==''&&$before===$state(),'A stale review retry cannot overwrite the newly saved source');
    $again=jobdivaReviewContractSnapshot($workspace,$rid);$input=jobdivaReviewContractInput($again);$fresh=$input+['_jd_assignment_detail'=>$rows,'_jd_contract'=>jobdivaAssignmentContractBuild($rows,jobdivaAssignmentStripDerivedFacets($input,'99310001'),'99310001')];
    $counts=static fn():array=>array_map(static fn($t)=>(int)$pdo->query('SELECT COUNT(*) FROM '.$t)->fetchColumn(),['people','companies','placements','placement_rates','external_entity_mappings']);
    $before=$counts();$result=jobdivaReviewContractApply($again,$fresh,null,$actor);
    $check($result['updated']===1&&$counts()===$before,'A fresh review replay creates no duplicate worker, company, assignment, rate or provider identity');
    $check(str_contains($failure(fn()=>jobdivaRefreshReviewContract($workspace,$rid,$actor)),'outside an existing transaction'),'Review provider lookup refuses existing database transactions');
},true);
