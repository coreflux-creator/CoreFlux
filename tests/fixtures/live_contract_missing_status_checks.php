<?php
/** A mapped Start with a blank workflow status needs exact billing and salary evidence. */
JobdivaStoredReviewTransaction::run(static function()use($pdo,$workspace,$mappingId,$placement,$person,$actor,$root,$rows,$state,$read,$check,$failure):void{
    $unlabelled=array_replace($root,['status'=>null,'startStatus'=>null]);
    $exact=['status'=>'contract_review','row'=>$unlabelled];
    $pdo->prepare('UPDATE external_entity_mappings SET sync_status="stale" WHERE tenant_id=? AND internal_entity_type IN ("person","placement")')->execute([$workspace]);
    $expected=jobdivaLiveContractSnapshot($workspace,$mappingId);
    $input=jobdivaLiveContractInput($expected,$exact);
    $detail=$rows;$detail[0]['BILLING'][0]['Actual Start']=true;
    $payload=$input+['_jd_assignment_detail'=>$detail,'_jd_contract'=>jobdivaAssignmentContractBuild($detail,jobdivaAssignmentStripDerivedFacets($input,'99310001'),'99310001')];
    $before=$state();
    $check(jobdivaLiveContractValidate($expected,$exact,$payload)===$payload&&$state()===$before,'Blank-status validation retains raw source and performs no writes');
    foreach(['no_salary_approval','closed','missing_detail','different_candidate','altered_price','explicit_offer']as$case){
        $bad=$payload;$remote=$exact;
        if($case==='no_salary_approval')$bad['_jd_assignment_detail'][0]['SALARY'][0]['Approved']=false;
        if($case==='closed')$bad['_jd_assignment_detail'][0]['BILLING'][0]['Closed']=true;
        if(in_array($case,['no_salary_approval','closed'],true))$bad['_jd_contract']=jobdivaAssignmentContractBuild($bad['_jd_assignment_detail'],jobdivaAssignmentStripDerivedFacets($input,'99310001'),'99310001');
        if($case==='missing_detail')unset($bad['_jd_assignment_detail']);
        if($case==='different_candidate')$remote['row']['candidateId']='99319999';
        if($case==='altered_price')$bad['_jd_contract']['pay_rate']=999;
        if($case==='explicit_offer')$remote['row']['status']='Offer';
        $check($failure(fn()=>jobdivaLiveContractApply($expected,$remote,$bad,null,$actor))!==''&&$state()===$before,'Blank-status '.$case.' cannot change native records or retained source');
    }
    foreach(['error','deleted_in_source']as$status)JobdivaStoredReviewTransaction::run(static function()use($pdo,$workspace,$mappingId,$actor,$exact,$payload,$state,$check,$failure,$status):void{
        $pdo->prepare('UPDATE external_entity_mappings SET sync_status=? WHERE tenant_id=? AND internal_entity_type="person"')->execute([$status,$workspace]);
        $expected=jobdivaLiveContractSnapshot($workspace,$mappingId);$before=$state();
        $check($failure(fn()=>jobdivaLiveContractApply($expected,$exact,$payload,null,$actor))!==''&&$state()===$before,'Fresh contract cannot bypass a '.$status.' worker link');
    },true);
    $pdo->exec('SET @fixture_live_contract_audit=1');$error=$failure(fn()=>jobdivaLiveContractApply($expected,$exact,$payload,null,$actor));$pdo->exec('SET @fixture_live_contract_audit=0');
    $check(str_contains($error,'Synthetic live contract audit failure')&&$state()===$before,'Missing-status source refresh rolls back all writes if its audit fails');
    $result=jobdivaLiveContractApply($expected,$exact,$payload,null,$actor);
    $saved=$read('placements',$placement);$mapping=$read('external_entity_mappings',$mappingId);$snapshot=json_decode($mapping['payload_snapshot'],true);
    $check($result['projected']===1&&$saved['status']==='active'&&$saved['title']==='Native retained title','Exact approved billing/salary evidence refreshes the current placement and preserves native ownership');
    $check((int)$saved['person_id']===$person&&$mapping['sync_status']==='ok'&&$mapping['external_id']==='jd:99310001','Fresh mapped contract retains person and original provider identities');
    $q=$pdo->prepare('SELECT sync_status FROM external_entity_mappings WHERE tenant_id=? AND internal_entity_type="person" AND internal_entity_id=?');$q->execute([$workspace,$person]);
    $check($q->fetchColumn()==='ok'&&($snapshot['__cf_jobdiva_lifecycle_source']??'')==='EmployeeAssignmentRecordsDetail','Stale person observation refreshes through the native resolver with explicit contract provenance');
    $check($failure(fn()=>jobdivaLiveContractApply($expected,$exact,$payload,null,$actor))!=='','Stale missing-status review cannot be applied twice');
    $current=jobdivaLiveContractSnapshot($workspace,$mappingId);$again=jobdivaLiveContractInput($current,$exact);
    $again['_jd_assignment_detail']=$detail;$again['_jd_contract']=jobdivaAssignmentContractBuild($detail,jobdivaAssignmentStripDerivedFacets($again,'99310001'),'99310001');
    $counts=[$pdo->query('SELECT COUNT(*) FROM people')->fetchColumn(),$pdo->query('SELECT COUNT(*) FROM placements')->fetchColumn(),$pdo->query('SELECT COUNT(*) FROM placement_rates')->fetchColumn()];
    $result=jobdivaLiveContractApply($current,$exact,$again,null,$actor);
    $check($result['projected']===1&&$counts===[$pdo->query('SELECT COUNT(*) FROM people')->fetchColumn(),$pdo->query('SELECT COUNT(*) FROM placements')->fetchColumn(),$pdo->query('SELECT COUNT(*) FROM placement_rates')->fetchColumn()],'A fresh repeat of the same exact contract creates no duplicate people, placements or rates');
},true);

foreach(['retain_existing','leave_unresolved','resolved_names']as$nameCase)JobdivaStoredReviewTransaction::run(static function()use($pdo,$workspace,$mappingId,$placement,$actor,$root,$rows,$read,$check,$nameCase):void{
    $existing=$nameCase==='leave_unresolved'?null:'Reviewed native name';
    $pdo->prepare('UPDATE placements SET recruiter_name=?,account_manager_name=? WHERE id=?')->execute([$existing,$existing,$placement]);
    $remote=array_replace($root,['primaryRecruiter'=>'1602627','primarySales'=>'1632342']);
    if($nameCase==='resolved_names')$remote=array_replace($remote,['recruiterName'=>'Resolved Recruiter','accountManagerName'=>'Resolved Manager']);
    $snapshot=jobdivaLiveContractSnapshot($workspace,$mappingId);
    $exact=['status'=>'verified','row'=>jobdivaAssignmentMarkVerified($remote,'99310001','searchStart:criteria_exact')];
    $payload=jobdivaLiveContractInput($snapshot,$exact);$payload['_jd_assignment_detail']=$rows;
    $payload['_jd_contract']=jobdivaAssignmentContractBuild($rows,jobdivaAssignmentStripDerivedFacets($payload,'99310001'),'99310001');
    $result=jobdivaLiveContractApply($snapshot,$exact,$payload,null,$actor);$saved=$read('placements',$placement);
    $check($result['projected']===1&&$saved['recruiter_name']===($nameCase==='resolved_names'?'Resolved Recruiter':$existing)
        &&$saved['account_manager_name']===($nameCase==='resolved_names'?'Resolved Manager':$existing),'Staff allocation IDs never substitute for names: '.$nameCase);
    $source=json_decode($read('external_entity_mappings',$mappingId)['payload_snapshot'],true);
    $check(($source['primaryRecruiter']??null)==='1602627'&&($source['primarySales']??null)==='1632342','Original staff source IDs remain available for later identity resolution: '.$nameCase);
},true);
