<?php
/** Fresh census writes contend with current sharing, ownership and merge decisions. */
foreach(['COMMIT','ROLLBACK']as$decision){
    foreach(['policy','native_closure','source_context','alias','primary_choice']as$change){
        [$a,$b,$rate,$binding,$membership,$mergeInput]=$mergeFixture('terminal-'.$change.'-'.$decision);
        placementMergeApply($child,$actor,$mergeInput['body']);$primary=$mergeInput['body']['primary_jobdiva_mapping_id'];
        $expected=JobdivaStoredReviewTransaction::run(static fn()=>jobdivaAssignmentRepairSnapshot($child,$primary),true);
        $row=array_replace(json_decode($expected['mapping']['payload_snapshot'],true,512,JSON_THROW_ON_ERROR),['status'=>'Ended','endDate'=>'2026-11-01','__cf_jobdiva_canonical_status'=>'ended']);
        $pdo->beginTransaction();
        if($change==='policy')$pdo->prepare('UPDATE tenant_module_scope SET scope_mode="isolated" WHERE tenant_id=? AND module_key="placements"')->execute([$child]);
        if($change==='native_closure')$pdo->prepare('UPDATE placements SET status="ended",actual_end_date="2026-10-20",coreflux_overridden_fields=\'["status","actual_end_date"]\' WHERE id=?')->execute([$a]);
        if($change==='source_context')$pdo->prepare('UPDATE external_entity_mappings SET payload_snapshot=JSON_SET(payload_snapshot,"$.candidateId","another-worker") WHERE tenant_id=? AND id=?')->execute([$child,$primary]);
        if($change==='alias')$pdo->prepare('DELETE FROM core_record_numbers WHERE tenant_id=? AND entity_type="placement" AND record_id=?')->execute([$tenant,$b]);
        if($change==='primary_choice')$pdo->prepare('UPDATE audit_log SET meta_json=JSON_SET(meta_json,"$.primary_jobdiva_mapping_id",CAST(? AS UNSIGNED)) WHERE tenant_id=? AND event="placement.identity_merged" AND target_id=?')->execute([$binding,$child,$a]);
        $worker=$spawn(['mode'=>'native','entity'=>'terminal_census','workspace'=>$child,'actor'=>$actor,'placement_id'=>$a,'row'=>$row]);
        $check($waiting($worker),'Terminal census waits for '.$change.' '.$decision);
        if($decision==='COMMIT')$pdo->commit();else$pdo->rollBack();$before=$identityRaceState();$result=$finish($worker);
        if($decision==='COMMIT'&&in_array($change,['policy','source_context','alias'],true))$ok=!$result['ok']&&$before===$identityRaceState();
        elseif($decision==='COMMIT'&&$change==='native_closure')$ok=$result['ok']&&!$result['result']['changed']&&$result['result']['reason']==='core_owned_lifecycle'&&$before===$identityRaceState();
        else{
            $q=$pdo->prepare('SELECT meta_json FROM audit_log WHERE tenant_id=? AND event="placement.lifecycle_from_jobdiva_census" AND target_id=?');$q->execute([$child,$a]);$receipt=json_decode($q->fetchColumn()?:'{}',true,512,JSON_THROW_ON_ERROR);
            $ok=$result['ok']&&$result['result']['changed']&&$read('placements',$a)['end_date']==='2026-11-01'&&$read('placements',$b)['deleted_at']!==null
                &&(int)($receipt['source_mapping_id']??0)===($decision==='COMMIT'&&$change==='primary_choice'?$binding:$primary);
        }
        $check($ok,'Terminal census rechecks '.$change.' after '.$decision.': '.json_encode($result));
    }
    [$a,$b,$rate,$binding,$membership,$mergeInput]=$mergeFixture('terminal-merge-'.$decision);
    $q=$pdo->prepare('SELECT payload_snapshot FROM external_entity_mappings WHERE tenant_id=? AND id=?');$q->execute([$child,$binding]);$row=array_replace(json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR),['status'=>'Ended','endDate'=>'2026-11-01','__cf_jobdiva_canonical_status'=>'ended']);
    $holder=$spawn(array_replace($mergeInput,['mode'=>'placement_merge_hold']));$saved=$line($workers[$holder]['pipes'][1]);
    $check(!empty($saved['saved'])&&!empty($saved['in_transaction']),'Merge holds terminal census source identity before '.$decision);
    $worker=$spawn(['mode'=>'native','entity'=>'terminal_census','workspace'=>$child,'actor'=>$actor,'placement_id'=>$a,'row'=>$row]);$check($waiting($worker),'Terminal census waits for merge decision '.$decision);
    fwrite($workers[$holder]['pipes'][0],$decision.PHP_EOL);fflush($workers[$holder]['pipes'][0]);$merged=$finish($holder);$result=$finish($worker);
    $check($merged['ok']&&($decision==='COMMIT'?($result['ok']&&$result['result']['changed']&&$result['result']['placement_id']===$a):!$result['ok']),
        'Terminal census resolves only a committed reviewed merge after '.$decision.': '.json_encode($result));
}
