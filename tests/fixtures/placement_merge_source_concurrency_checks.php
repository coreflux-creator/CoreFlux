<?php
/** Actual source repairs contend with family/owner changes in independent database sessions. */
foreach(['COMMIT','ROLLBACK']as$decision){
    foreach(['policy','primary_choice','source_binding','new_source','alias','native_field']as$change){
        [$a,$b,$rate,$binding,$membership,$mergeInput]=$mergeFixture('source-'.$change.'-'.$decision);
        placementMergeApply($child,$actor,$mergeInput['body']);$primary=$mergeInput['body']['primary_jobdiva_mapping_id'];
        $expected=JobdivaStoredReviewTransaction::run(static fn()=>jobdivaAssignmentRepairSnapshot($child,$primary),true);
        $payload=json_decode($expected['mapping']['payload_snapshot'],true,512,JSON_THROW_ON_ERROR);
        $exact=['status'=>'verified','row'=>jobdivaAssignmentMarkVerified($payload+['sourceRefreshNote'=>'After concurrent decision'],$expected['external'],'searchStart:criteria_exact')];
        $input=['mode'=>'native','entity'=>'assignment_repair','workspace'=>$child,'actor'=>$actor,'placement_id'=>$a,'expected'=>$expected,'exact'=>$exact];
        $pdo->beginTransaction();
        if($change==='policy')$pdo->prepare('UPDATE tenant_module_scope SET scope_mode="isolated" WHERE tenant_id=? AND module_key="placements"')->execute([$child]);
        if($change==='primary_choice')$pdo->prepare('UPDATE audit_log SET meta_json=JSON_SET(meta_json,"$.primary_jobdiva_mapping_id",CAST(? AS UNSIGNED)) WHERE tenant_id=? AND event="placement.identity_merged" AND target_id=?')->execute([$binding,$child,$a]);
        if($change==='source_binding')$pdo->prepare('UPDATE external_entity_mappings SET internal_entity_id=? WHERE id=?')->execute([$a,$primary]);
        if($change==='new_source')mappingUpsert($child,'jobdiva','placement','source-race-extra-'.$a,$a,$payload);
        if($change==='alias')$pdo->prepare('DELETE FROM core_record_numbers WHERE tenant_id=? AND entity_type="placement" AND record_id=?')->execute([$tenant,$b]);
        if($change==='native_field')$pdo->prepare('UPDATE placements SET title="Concurrent native survivor title" WHERE id=?')->execute([$a]);
        $worker=$spawn($input);$check($waiting($worker),'Retained source repair waits for '.$change.' '.$decision);
        if($decision==='COMMIT')$pdo->commit();else$pdo->rollBack();$before=$identityRaceState();$result=$finish($worker);
        $q=$pdo->prepare('SELECT internal_entity_id FROM external_entity_mappings WHERE tenant_id=? AND id=?');$q->execute([$child,$primary]);$physical=(int)$q->fetchColumn();
        $check($decision==='COMMIT'?(!$result['ok']&&$before===$identityRaceState()):
            ($result['ok']&&$result['result']['placement_id']===$a&&$physical===$b&&$read('placements',$b)['deleted_at']!==null),
            'Retained source repair rechecks '.$change.' after '.$decision.': '.json_encode($result));
    }
    [$a,$b,$rate,$binding,$membership,$mergeInput]=$mergeFixture('source-remerge-'.$decision);
    placementMergeApply($child,$actor,$mergeInput['body']);$primary=$mergeInput['body']['primary_jobdiva_mapping_id'];
    $beforeSkip=$identityRaceState();$skipWorker=$spawn(['mode'=>'native','entity'=>'historical_contract_refresh','workspace'=>$child,'actor'=>$actor,'placement_id'=>$a,'mapping_id'=>$binding]);$skipped=$finish($skipWorker);
    $check($skipped['ok']&&$skipped['result']['skipped_not_current']===1&&$skipped['result']['review_required']===0&&$beforeSkip===$identityRaceState(),
        'Live contract refresh retains a secondary source without a provider call or writes before '.$decision);
    $expected=JobdivaStoredReviewTransaction::run(static fn()=>jobdivaAssignmentRepairSnapshot($child,$primary),true);
    $payload=json_decode($expected['mapping']['payload_snapshot'],true,512,JSON_THROW_ON_ERROR);
    $exact=['status'=>'verified','row'=>jobdivaAssignmentMarkVerified($payload+['sourceRefreshNote'=>'After another merge'],$expected['external'],'searchStart:criteria_exact')];
    $pdo->prepare('INSERT INTO placements(tenant_id,person_id,title,status,engagement_type,start_date,worksite_country) VALUES(?,?,"Next current source survivor","active","w2","2026-10-01","US")')->execute([$tenant,$expected['placement']['person_id']]);$next=(int)$pdo->lastInsertId();
    $review=placementMergeReview($child,$actor,$next,$a);$body=$mergeInput['body'];
    $body['survivor_id']=$next;$body['retired_id']=$a;$body['configuration_placement_id']=$next;$body['review_token']=$review['review_token'];
    $body['request_id']=bin2hex(random_bytes(4)).'-0000-4000-8000-'.bin2hex(random_bytes(6));
    $holder=$spawn(['mode'=>'placement_merge_hold','entity'=>'placement_merge','workspace'=>$child,'actor'=>$actor,'body'=>$body]);$saved=$line($workers[$holder]['pipes'][1]);
    $check(!empty($saved['saved'])&&!empty($saved['in_transaction']),'Another merge holds the entire source family before '.$decision);
    $worker=$spawn(['mode'=>'native','entity'=>'assignment_repair','workspace'=>$child,'actor'=>$actor,'placement_id'=>$a,'expected'=>$expected,'exact'=>$exact]);
    $check($waiting($worker),'Retained source repair waits for another merge '.$decision);
    fwrite($workers[$holder]['pipes'][0],$decision.PHP_EOL);fflush($workers[$holder]['pipes'][0]);$merged=$finish($holder);$result=$finish($worker);
    $check($merged['ok']&&($decision==='COMMIT'?!$result['ok']:($result['ok']&&$result['result']['placement_id']===$a)),
        'An in-flight source review cannot cross a committed second merge after '.$decision.': '.json_encode($result));
}
