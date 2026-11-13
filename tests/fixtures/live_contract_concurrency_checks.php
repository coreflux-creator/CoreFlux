<?php
/** Actual independent connections contend over live-contract review and apply. */
$liveFixture=static function(string $label)use($projectionFixture,$pdo,$child,$actor):array{
    [$a,$b,$placement,$original]=$projectionFixture('live-'.$label,$child);
    $q=$pdo->prepare('SELECT id FROM external_entity_mappings WHERE tenant_id=? AND source_system="jobdiva" AND internal_entity_type="placement" AND internal_entity_id=?');$q->execute([$child,$placement]);$mapping=(int)$q->fetchColumn();
    $expected=JobdivaStoredReviewTransaction::run(static fn():array=>jobdivaLiveContractSnapshot($child,$mapping),true);
    $root=jobdivaAssignmentStripDerivedFacets($original['payload'],$expected['base']['external']);
    $exact=['status'=>'verified','row'=>jobdivaAssignmentMarkVerified($root,$expected['base']['external'],'searchStart:criteria_exact')];
    $payload=jobdivaLiveContractInput($expected,$exact);
    $rows=[['Start ID'=>$root['startId'],'Candidate ID'=>$root['candidateId'],'Job ID'=>$root['jobId'],'Employment Category'=>'Hourly Employee','W2'=>true,
        'Bill Rate'=>125,'Pay Rate'=>50,'Bill Rate Unit'=>'hour','Pay Rate Unit'=>'hour','Currency'=>'USD','Start Date'=>'2026-11-01',
        'BILLING'=>[['COMPANY'=>[['ID'=>$original['payload']['_jd_contract']['client_company_id'],'Name'=>$original['payload']['_jd_contract']['client_company_name']]]]]]];
    $payload['_jd_assignment_detail']=$rows;$payload['_jd_contract']=jobdivaAssignmentContractBuild($rows,$payload,$root['startId']);
    return[$placement,$mapping,['mode'=>'native','entity'=>'live_contract','workspace'=>$child,'actor'=>$actor,'placement_id'=>$placement,'expected'=>$expected,'exact'=>$exact,'payload'=>$payload]];
};
foreach(['COMMIT','ROLLBACK']as$decision){
    foreach(['policy','mapping','placement','rate']as$change){
        [$placement,$mapping,$input]=$liveFixture($change.'-'.$decision);
        $pdo->beginTransaction();
        if($change==='policy')$pdo->prepare('UPDATE tenant_module_scope SET scope_mode="isolated" WHERE tenant_id=? AND module_key="placements"')->execute([$child]);
        if($change==='mapping')$pdo->prepare('UPDATE external_entity_mappings SET sync_status="error" WHERE id=?')->execute([$mapping]);
        if($change==='placement')$pdo->prepare('UPDATE placements SET title="Concurrent live title" WHERE id=?')->execute([$placement]);
        if($change==='rate')$pdo->prepare('UPDATE placement_rates SET created_by_user_id=?,bill_rate=140 WHERE placement_id=?')->execute([$actor,$placement]);
        $worker=$spawn($input);$check($waiting($worker),'Live contract waits for '.$change.' '.$decision);
        if($decision==='COMMIT')$pdo->commit();else$pdo->rollBack();$before=$projectionRows();$result=$finish($worker);
        $check($decision==='COMMIT'?(!$result['ok']&&$projectionRows()===$before):($result['ok']&&$result['result']['projected']===1),'Live contract revalidates current '.$change.' after '.$decision.': '.json_encode($result,JSON_THROW_ON_ERROR));
    }
    [$placement,$mapping,$input]=$liveFixture('retry-'.$decision);
    $worker=$spawn(array_replace($input,['mode'=>'live_contract_hold']));$saved=$line($workers[$worker]['pipes'][1]);
    $check(!empty($saved['saved'])&&!empty($saved['in_transaction']),'Live refresh holds its complete write before '.$decision.': '.json_encode($saved,JSON_THROW_ON_ERROR));
    $retry=$spawn($input);$check($waiting($retry),'Concurrent live contract retry waits for '.$decision);
    fwrite($workers[$worker]['pipes'][0],$decision.PHP_EOL);fflush($workers[$worker]['pipes'][0]);$first=$finish($worker);$again=$finish($retry);
    $q=$pdo->prepare('SELECT COUNT(*) FROM audit_log WHERE tenant_id=? AND event="placement.live_contract.refreshed" AND target_id=?');$q->execute([$child,$placement]);
    $check($first['ok']&&($decision==='COMMIT'?!$again['ok']:$again['ok'])&&(int)$q->fetchColumn()===1,'Concurrent live contract retries save one required receipt after '.$decision);
}
