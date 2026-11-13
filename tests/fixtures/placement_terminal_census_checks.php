<?php
/** Actual terminal census behavior on the reviewed shared placement family. */
$terminal=array_replace($payload,['status'=>'Ended','endDate'=>'2026-11-01','__cf_jobdiva_canonical_status'=>'ended']);
foreach(['status','end_date','actual_end_date','deleted_at']as$field){
    JobdivaStoredReviewTransaction::run(static function()use($pdo,$workspace,$actor,$a,$terminal,$field,$state,$check):void{
        $pdo->prepare('UPDATE placements SET coreflux_overridden_fields=? WHERE id=?')->execute([json_encode([$field]),$a]);$before=$state();
        $result=jobdivaSyncApplyTerminalAssignmentLifecycle($workspace,[$terminal],$actor);
        $check($result['updated']===0&&$result['failed']===0&&($result['skipped_reasons']['core_owned_lifecycle']??0)===1&&$before===$state(),
            'Shared terminal census preserves native '.$field.' ownership after a merge');
    },true);
}
foreach(['malformed_ownership','candidate','conflicting_start','wrong_status','missing_context','date','disabled_policy','private_directory','source_receipt']as$case){
    JobdivaStoredReviewTransaction::run(static function()use($pdo,$workspace,$owner,$actor,$a,$terminal,$case,$state,$check,$insert):void{
        $row=$terminal;
        if($case==='malformed_ownership')$pdo->prepare('UPDATE placements SET coreflux_overridden_fields="{}" WHERE id=?')->execute([$a]);
        if($case==='candidate')$row['candidateId']='different-source-worker';
        if($case==='conflicting_start')$row['id']='different-start';
        if($case==='wrong_status')$row['__cf_jobdiva_canonical_status']='cancelled';
        if($case==='missing_context')unset($row['jobId']);
        if($case==='date')$row['endDate']='2026-09-01';
        if($case==='disabled_policy')$insert('jobdiva_connections',['tenant_id'=>$workspace,'client_id'=>'disabled-fixture','username'=>'disabled-fixture','password_enc'=>'disabled-fixture','status'=>'connected','sync_config'=>json_encode(['placement'=>['source'=>'coreflux','direction'=>'push']])]);
        if($case==='private_directory')$pdo->prepare('UPDATE tenant_module_scope SET scope_mode="isolated" WHERE tenant_id=? AND module_key="placements"')->execute([$workspace]);
        if($case==='source_receipt')$pdo->prepare('DELETE FROM audit_log WHERE tenant_id=? AND event="placement.identity_merged"')->execute([$workspace]);
        $before=$state();$result=jobdivaSyncApplyTerminalAssignmentLifecycle($workspace,[$row],$actor);
        $check($result['failed']===1&&$result['updated']===0&&$before===$state(),'Terminal census refuses '.$case.' without partial changes');
    },true);
}
$pdo->exec('SET @fixture_placement_terminal_receipt=1');$terminalBefore=$state();
$failedTerminal=jobdivaSyncApplyTerminalAssignmentLifecycle($workspace,[$terminal],$actor);
$check($failedTerminal['failed']===1&&$failedTerminal['updated']===0&&$terminalBefore===$state(),'Required terminal receipt failure rolls back lifecycle and commercial dates');
$pdo->exec('SET @fixture_placement_terminal_receipt=0');
JobdivaStoredReviewTransaction::run(static function()use($pdo,$workspace,$owner,$actor,$a,$b,$mb,$terminal,$state,$read,$check):void{
    $before=$state();$oldRetired=$read($b);$result=jobdivaSyncApplyTerminalAssignmentLifecycle($workspace,[$terminal],$actor);
    $check($result['updated']===1&&$result['ended']===1&&$result['failed']===0&&$read($a)['status']==='ended'&&$read($a)['end_date']==='2026-11-01',
        'Terminal census ends the current shared survivor through its retained historical source');
    $protected=array_diff_key($before,array_flip(['placements','placement_economic_parties','audit_log']));
    $check($read($b)===$oldRetired&&$protected===array_intersect_key($state(),$protected),'Terminal census retains historical placement, provider IDs, permanent numbers, approved rates, time and financial records');
    $q=$pdo->prepare('SELECT meta_json FROM audit_log WHERE tenant_id=? AND event="placement.lifecycle_from_jobdiva_census" AND target_id=?');$q->execute([$workspace,$a]);$receipt=json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
    $check($receipt['directory_owner_id']===$owner&&(int)$receipt['source_mapping_id']===(int)$mb['id']&&(int)$receipt['source_physical_placement_id']===$b
        &&$receipt['source_owner']['placement_id']===$a&&isset($receipt['before'],$receipt['after'],$receipt['source_hash']),
        'Terminal receipt identifies original workspace, current owner, reviewed source and before/after state');
    $after=$state();$replay=jobdivaSyncApplyTerminalAssignmentLifecycle($workspace,[$terminal],$actor);
    $check($replay['updated']===0&&$replay['failed']===0&&$after===$state(),'Repeated terminal census leaves lifecycle, history and receipts unchanged');
},true);
JobdivaStoredReviewTransaction::run(static function()use($workspace,$actor,$make,$payload,$requestFor,$state,$check):void{
    $left=$make('Terminal primary','jd:99510201');$right=$make('Terminal history','jd:99510202');
    $primary=mappingUpsert($workspace,'jobdiva','placement','99510201',$left,array_replace($payload,['startId'=>'99510201']));
    mappingUpsert($workspace,'jobdiva','placement','99510202',$right,array_replace($payload,['startId'=>'99510202']));
    placementMergeApply($workspace,$actor,$requestFor(placementMergeReview($workspace,$actor,$left,$right),(int)$primary['id']));
    $before=$state();$row=array_replace($payload,['startId'=>'99510202','status'=>'Ended','endDate'=>'2026-11-01','__cf_jobdiva_canonical_status'=>'ended']);
    $result=jobdivaSyncApplyTerminalAssignmentLifecycle($workspace,[$row],$actor);
    $check($result['updated']===0&&$result['failed']===0&&($result['skipped_reasons']['historical_source_not_current_owner']??0)===1&&$before===$state(),
        'A terminal secondary Start cannot end the current placement');
},true);
