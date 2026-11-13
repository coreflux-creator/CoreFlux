<?php
/** Actual merge applications in independent native sessions, inside the guarded disposable harness. */
$mergeCounter=0;
$mergeFixture=static function(string $label)use($pdo,$tenant,$child,$actor,&$mergeCounter):array{
    $serial=99520000+(++$mergeCounter);
    foreach(['people','placements','companies','staffing','accounting']as$module)$pdo->prepare('INSERT INTO tenant_module_scope(tenant_id,module_key,scope_mode) VALUES(?,?,"shared") ON DUPLICATE KEY UPDATE scope_mode="shared"')->execute([$child,$module]);
    $q=$pdo->prepare('SELECT id FROM tenant_memberships WHERE user_id=? AND tenant_id=?');$q->execute([$actor,$child]);$membership=(int)$q->fetchColumn();
    if(!$membership){$pdo->prepare('INSERT INTO tenant_memberships(user_id,tenant_id,persona_type,status) VALUES(?,?,"admin","active")')->execute([$actor,$child]);$membership=(int)$pdo->lastInsertId();}
    $pdo->prepare('UPDATE tenant_memberships SET status="active",persona_type="admin" WHERE id=?')->execute([$membership]);
    $pdo->prepare('INSERT INTO membership_module_access(membership_id,module_key,access_level) VALUES(?,"placements","admin") ON DUPLICATE KEY UPDATE access_level="admin"')->execute([$membership]);
    $pdo->prepare('INSERT INTO people(tenant_id,first_name,last_name,email_primary,status,classification) VALUES(?,"Synthetic","Merge race",?,"active","w2")')->execute([$tenant,'merge-'.$serial.'@example.invalid']);$person=(int)$pdo->lastInsertId();
    $ids=[];$rates=[];$bindings=[];
    foreach(['survivor','retired']as$side){
        $pdo->prepare('INSERT INTO placements(tenant_id,person_id,title,status,engagement_type,start_date,worksite_country) VALUES(?,?,?,"active","w2","2026-10-01","US")')->execute([$tenant,$person,$side.' '.$label]);$id=(int)$pdo->lastInsertId();$ids[]=$id;
        $pdo->prepare('INSERT INTO placement_rates(tenant_id,placement_id,effective_from,pay_rate,bill_rate,approved_at) VALUES(?,?,"2026-10-01",50,100,"2026-10-01 12:00:00")')->execute([$tenant,$id]);$rates[]=(int)$pdo->lastInsertId();
        $mapping=mappingUpsert($child,'jobdiva','placement',($side==='survivor'?'':'jd:').$serial,$id,
            ['startId'=>(string)$serial,'candidateId'=>(string)($serial+1000),'jobId'=>(string)($serial+2000),'startDate'=>'2026-10-01','status'=>'Active','__cf_jobdiva_source_object'=>'assignment']);$bindings[]=(int)$mapping['id'];
    }
    [$a,$b]=$ids;$review=placementMergeReview($child,$actor,$a,$b);$fields=array_fill_keys(array_keys($review['fields']),'survivor');$fields['title']='retired';
    $body=['survivor_id'=>$a,'retired_id'=>$b,'review_token'=>$review['review_token'],'fields'=>$fields,'primary_jobdiva_mapping_id'=>$bindings[1],
        'configuration_placement_id'=>$a,'history_policy'=>'retain_original_rows','confirm'=>'MERGE_REVIEWED_PLACEMENTS',
        'request_id'=>bin2hex(random_bytes(4)).'-0000-4000-8000-'.bin2hex(random_bytes(6)),'reason'=>'Reviewed synthetic placement consolidation'];
    return [$a,$b,$rates[0],$bindings[0],$membership,['mode'=>'native','entity'=>'placement_merge','workspace'=>$child,'actor'=>$actor,'body'=>$body]];
};
foreach(['COMMIT','ROLLBACK']as$decision){
    foreach(['policy','permission','native_choice','approved_rate','source_binding','new_history']as$change){
        [$a,$b,$rate,$binding,$membership,$input]=$mergeFixture($change.'-'.$decision);$pdo->beginTransaction();
        if($change==='policy')$pdo->prepare('UPDATE tenant_module_scope SET scope_mode="isolated" WHERE tenant_id=? AND module_key="placements"')->execute([$child]);
        if($change==='permission')$pdo->prepare('UPDATE membership_module_access SET access_level="write" WHERE membership_id=? AND module_key="placements"')->execute([$membership]);
        if($change==='native_choice')$pdo->prepare('UPDATE placements SET title="Intervening native choice",coreflux_overridden_fields=\'["title"]\' WHERE id=?')->execute([$a]);
        if($change==='approved_rate')$pdo->prepare('UPDATE placement_rates SET pay_rate=55 WHERE id=?')->execute([$rate]);
        if($change==='source_binding')$pdo->prepare('UPDATE external_entity_mappings SET sync_status="error" WHERE id=?')->execute([$binding]);
        if($change==='new_history')$pdo->prepare('INSERT INTO placement_rates(tenant_id,placement_id,effective_from,pay_rate,bill_rate) VALUES(?,?,"2026-11-01",60,120)')->execute([$tenant,$b]);
        $worker=$spawn($input);$check($waiting($worker),'Placement merge waits for '.$change.' '.$decision);
        if($decision==='COMMIT')$pdo->commit();else$pdo->rollBack();$beforeMergeAttempt=$identityRaceState();$result=$finish($worker);
        $check($decision==='COMMIT'?(!$result['ok']&&$beforeMergeAttempt===$identityRaceState()):
            ($result['ok']&&$result['result']['survivor_id']===$a&&$read('placements',$b)['deleted_at']!==null),
            'Placement merge rechecks '.$change.' after '.$decision.': '.json_encode($result));
    }
    [$a,$b,$rate,$binding,$membership,$input]=$mergeFixture('receipt-'.$decision);
    $first=$spawn(array_replace($input,['mode'=>'placement_merge_hold']));$saved=$line($workers[$first]['pipes'][1]);
    $check(!empty($saved['saved'])&&!empty($saved['in_transaction']),'Placement merge holds changes and required receipt before '.$decision);
    $retry=$spawn($input);$check($waiting($retry),'Placement merge retry waits for first receipt '.$decision);
    fwrite($workers[$first]['pipes'][0],$decision.PHP_EOL);fflush($workers[$first]['pipes'][0]);$written=$finish($first);$again=$finish($retry);
    $q=$pdo->prepare('SELECT COUNT(*) FROM audit_log WHERE tenant_id=? AND event="placement.identity_merged" AND JSON_UNQUOTE(JSON_EXTRACT(meta_json,"$.request_id"))=?');$q->execute([$child,$input['body']['request_id']]);
    $check($written['ok']&&$again['ok']&&$again['result']['replayed']===($decision==='COMMIT')&&(int)$q->fetchColumn()===1,
        'Concurrent placement merge retry commits exactly one receipt after '.$decision);
}
