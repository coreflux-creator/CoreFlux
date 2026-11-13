<?php
$indexFixture=static function(string $label)use($projectionFixture,$pdo,$child,$actor):array{
    [$a,$b,$placement,$original]=$projectionFixture('index-'.$label,$child);
    $q=$pdo->prepare('SELECT id FROM external_entity_mappings WHERE tenant_id=? AND source_system="jobdiva" AND internal_entity_type="placement" AND internal_entity_id=?');$q->execute([$child,$placement]);$mapping=(int)$q->fetchColumn();
    $expected=JobdivaStoredReviewTransaction::run(static fn():array=>jobdivaJoinedIndexSnapshot($child,$mapping),true);
    $payload=$expected['joined_payload'];$payload['_jd_contract']['bill_rate']=125;
    return[$placement,$mapping,['mode'=>'native','entity'=>'joined_index','workspace'=>$child,'actor'=>$actor,'placement_id'=>$placement,'expected'=>$expected,'payload'=>$payload]];
};
$indexRows=static function()use($pdo,$child):array{$q=$pdo->prepare('SELECT * FROM integration_payload_field_index WHERE tenant_id=? ORDER BY id');$q->execute([$child]);return$q->fetchAll(PDO::FETCH_ASSOC);};
foreach(['COMMIT','ROLLBACK']as$decision){
    foreach(['policy','mapping','placement']as$change){
        [$placement,$mapping,$input]=$indexFixture($change.'-'.$decision);
        $pdo->beginTransaction();
        if($change==='policy')$pdo->prepare('UPDATE tenant_module_scope SET scope_mode="isolated" WHERE tenant_id=? AND module_key="placements"')->execute([$child]);
        if($change==='mapping')$pdo->prepare('UPDATE external_entity_mappings SET sync_status="error" WHERE id=?')->execute([$mapping]);
        if($change==='placement')$pdo->prepare('UPDATE placements SET title="Concurrent native title" WHERE id=?')->execute([$placement]);
        $worker=$spawn($input);$check($waiting($worker),'Source-field refresh waits for '.$change.' '.$decision);
        if($decision==='COMMIT')$pdo->commit();else$pdo->rollBack();$before=$projectionRows();$indexes=$indexRows();$result=$finish($worker);
        $check($decision==='COMMIT'?(!$result['ok']&&$projectionRows()===$before&&$indexRows()===$indexes):($result['ok']&&$result['result']['snapshot_changed']),'Source-field refresh revalidates current '.$change.' after '.$decision);
    }
    [$placement,$mapping,$input]=$indexFixture('retry-'.$decision);
    $worker=$spawn(array_replace($input,['mode'=>'index_hold']));$saved=$line($workers[$worker]['pipes'][1]);
    $check(!empty($saved['saved'])&&!empty($saved['in_transaction']),'Source-field refresh holds its snapshot, index and audit before '.$decision.': '.json_encode($saved,JSON_THROW_ON_ERROR));
    $retry=$spawn($input);$check($waiting($retry),'Concurrent source-field retry waits for '.$decision);
    fwrite($workers[$worker]['pipes'][0],$decision.PHP_EOL);fflush($workers[$worker]['pipes'][0]);$first=$finish($worker);$again=$finish($retry);
    $q=$pdo->prepare('SELECT COUNT(*) FROM audit_log WHERE tenant_id=? AND event="jobdiva.source_fields.refreshed" AND target_id=?');$q->execute([$child,$mapping]);
    $check($first['ok']&&($decision==='COMMIT'?!$again['ok']:$again['ok'])&&(int)$q->fetchColumn()===1,'Concurrent source-field retries save exactly one required receipt after '.$decision);
}
