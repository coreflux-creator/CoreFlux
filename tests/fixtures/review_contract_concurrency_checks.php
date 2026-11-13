<?php
/** Exact queue responses contend with committed and rolled-back native decisions. */
$queueFixture=static function(string $label)use($liveFixture,$pdo,$child):array{
    [$placement,$mapping,$live]=$liveFixture('review-'.$label);$root=$live['expected']['joined_payload'];$external=$live['expected']['base']['external'];
    $root['status']='Offer accepted';$root['__cf_jobdiva_review_run_token']='synthetic-concurrent-review';
    $review=mappingUpsert($child,'jobdiva','jobdiva_assignment_review','jd:'.$external,(int)$external,$root);$rid=(int)$review['id'];
    $expected=JobdivaStoredReviewTransaction::run(static fn()=>jobdivaReviewContractSnapshot($child,$rid),true);
    $input=jobdivaReviewContractInput($expected);$detail=$live['payload']['_jd_assignment_detail'];
    $detail[0]['BILLING'][0]['Approved']=true;$detail[0]['SALARY']=[['Approved'=>true]];
    $payload=$input+['_jd_assignment_detail'=>$detail,'_jd_contract'=>jobdivaAssignmentContractBuild($detail,jobdivaAssignmentStripDerivedFacets($input,$external),$external)];
    return [$placement,$mapping,$rid,['mode'=>'native','entity'=>'review_contract','workspace'=>$child,'actor'=>$live['actor'],'placement_id'=>$placement,'expected'=>$expected,'payload'=>$payload]];
};
foreach(['COMMIT','ROLLBACK']as$decision){
    foreach(['policy','review','primary','placement','worker','rate']as$change){
        [$placement,$mapping,$rid,$input]=$queueFixture($change.'-'.$decision);$pdo->beginTransaction();
        if($change==='policy')$pdo->prepare('UPDATE tenant_module_scope SET scope_mode="isolated" WHERE tenant_id=? AND module_key="placements"')->execute([$child]);
        if($change==='review'||$change==='primary')$pdo->prepare('UPDATE external_entity_mappings SET content_hash=? WHERE id=?')->execute([str_repeat('d',64),$change==='review'?$rid:$mapping]);
        if($change==='placement')$pdo->prepare('UPDATE placements SET title="Edited during queued lookup" WHERE id=?')->execute([$placement]);
        if($change==='worker')$pdo->prepare('UPDATE people SET status="inactive" WHERE id=?')->execute([$input['expected']['base']['placement']['person_id']]);
        if($change==='rate')$pdo->prepare('UPDATE placement_rates SET bill_rate=170 WHERE placement_id=?')->execute([$placement]);
        $worker=$spawn($input);$check($waiting($worker),'Contract review waits for '.$change.' '.$decision);
        if($decision==='COMMIT')$pdo->commit();else$pdo->rollBack();$before=$projectionRows();$result=$finish($worker);
        $check($decision==='COMMIT'?(!$result['ok']&&$projectionRows()===$before):($result['ok']&&$result['result']['updated']===1),'Contract review revalidates '.$change.' after '.$decision.': '.json_encode($result,JSON_THROW_ON_ERROR));
    }
}
