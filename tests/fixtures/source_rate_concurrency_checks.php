<?php
$rateFixture=static function(string $label,int $workspace,bool $missing=false)use($projectionFixture,$pdo,$tenant,$actor):array{
    [$a,$b,$placement,$input]=$projectionFixture('rate-'.$label,$workspace);
    $q=$pdo->prepare('SELECT id FROM placement_rates WHERE tenant_id=? AND placement_id=? ORDER BY id DESC LIMIT 1');$q->execute([$tenant,$placement]);$rate=(int)$q->fetchColumn();
    if($missing)$pdo->prepare('DELETE FROM placement_rates WHERE tenant_id=? AND placement_id=?')->execute([$tenant,$placement]);
    return[$placement,$rate,['mode'=>'native','entity'=>$missing?'source_rate':'rate_approval','workspace'=>$workspace,'actor'=>$actor,'placement_id'=>$placement,'rate_id'=>$rate]];
};
foreach(['COMMIT','ROLLBACK']as$decision){
    [$placement,$rate,$input]=$rateFixture('policy-'.$decision,$child,true);
    $pdo->beginTransaction();$pdo->prepare('UPDATE tenant_module_scope SET scope_mode="isolated" WHERE tenant_id=? AND module_key="placements"')->execute([$child]);
    $worker=$spawn($input);$check($waiting($worker),'Source rate repair waits for directory policy '.$decision);
    if($decision==='COMMIT')$pdo->commit();else$pdo->rollBack();$before=$projectionRows();$result=$finish($worker);
    $check($decision==='COMMIT'?(!$result['ok']&&$projectionRows()===$before):($result['ok']&&$result['result']['status']==='drafted'),'Source rate repair uses committed directory policy after '.$decision);
    [$placement,$rate,$input]=$rateFixture('native-edit-'.$decision,$tenant,true);
    $pdo->beginTransaction();placementRateDraftNative($tenant,$placement,['effective_from'=>'2026-11-01','bill_rate'=>130,'pay_rate'=>80,'bill_rate_unit'=>'hour','pay_rate_unit'=>'hour','currency'=>'USD'],$actor);
    $worker=$spawn($input);$check($waiting($worker),'Source rate repair waits for a native draft edit '.$decision);
    if($decision==='COMMIT')$pdo->commit();else$pdo->rollBack();$result=$finish($worker);
    $q=$pdo->prepare('SELECT * FROM placement_rates WHERE tenant_id=? AND placement_id=?');$q->execute([$tenant,$placement]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    $check($result['ok']&&count($rows)===1&&(float)$rows[0]['pay_rate']===($decision==='COMMIT'?80.0:50.0),'Source repair preserves a committed native draft and repairs after rollback: '.$decision);
    [$placement,$rate,$input]=$rateFixture('approval-retry-'.$decision,$child);
    $worker=$spawn(array_replace($input,['mode'=>'hold']));$saved=$line($workers[$worker]['pipes'][1]);
    $check(!empty($saved['saved'])&&!empty($saved['in_transaction']),'Shared approval holds rate and required audit before '.$decision.': '.json_encode($saved,JSON_THROW_ON_ERROR));
    $retry=$spawn($input);$check($waiting($retry),'Concurrent approval waits for the first decision '.$decision);
    fwrite($workers[$worker]['pipes'][0],$decision.PHP_EOL);fflush($workers[$worker]['pipes'][0]);$first=$finish($worker);$again=$finish($retry);
    $q=$pdo->prepare('SELECT COUNT(*) FROM audit_log WHERE tenant_id=? AND event="placement.rate.approved" AND target_id=?');$q->execute([$child,$placement]);
    $check($first['ok']&&($decision==='COMMIT'?!$again['ok']:$again['ok'])&&(int)$q->fetchColumn()===1&&$read('placement_rates',$rate)['approved_at']!==null,'Concurrent shared approvals produce exactly one owner stamp and audit after '.$decision);
}
[$placement,$rate,$input]=$rateFixture('old-snapshot',$child,true);
$worker=$spawn($input+['snapshot'=>true]);$started=$line($workers[$worker]['pipes'][1]);$check(!empty($started['snapshot']),'Source repair worker holds a snapshot preceding native ownership');
$pdo->prepare('UPDATE placements SET coreflux_overridden_fields=\'["rates"]\' WHERE tenant_id=? AND id=?')->execute([$tenant,$placement]);
fwrite($workers[$worker]['pipes'][0],'CONTINUE'.PHP_EOL);fflush($workers[$worker]['pipes'][0]);$before=$projectionRows();$result=$finish($worker);
$check($result['ok']&&$result['result']['reason']==='core_owned_rates'&&$projectionRows()===$before,'Source repair honors newly committed native ownership even inside an older snapshot');
