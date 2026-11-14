<?php
/** Actual generic time services on independent connections, without provider IO. */
foreach(['COMMIT','ROLLBACK']as$decision)foreach(['approve','supersede','source_link','archive','merge','person_archive','period_lock','daily_total']as$change) {
    $fixture=$nativeSeed('generic '.$change.' '.$decision);extract($fixture,EXTR_PREFIX_ALL,'gt');
    $survivor=null;
    if($change==='merge')$survivor=$nativeInsert('placements',['tenant_id'=>$tenant,'person_id'=>$gt_person,'title'=>'Generic current assignment','status'=>'active','engagement_type'=>'w2','start_date'=>'2026-10-01','worksite_country'=>'US']);
    $pdo->beginTransaction();
    if($change==='approve')$pdo->prepare('UPDATE time_entries SET status="approved",approved_at=NOW(),approved_by_user_id=? WHERE id=?')->execute([$actor,$gt_entry]);
    elseif($change==='supersede')$pdo->prepare('UPDATE time_entries SET status="superseded" WHERE id=?')->execute([$gt_entry]);
    elseif($change==='source_link')$nativeInsert('quanta_time_imports',['tenant_id'=>$tenant,'quanta_entry_id'=>'generic-race-'.$gt_entry,'component'=>'regular','time_entry_id'=>$gt_entry,'timesheet_id'=>$gt_sheet,'source_hash'=>str_repeat('c',64),'source_timesheet_status'=>'approved']);
    elseif($change==='archive')$pdo->prepare('UPDATE placements SET deleted_at=NOW() WHERE id=?')->execute([$gt_placement]);
    elseif($change==='person_archive')$pdo->prepare('UPDATE people SET deleted_at=NOW() WHERE id=?')->execute([$gt_person]);
    elseif($change==='period_lock')$pdo->prepare('UPDATE time_periods SET status="locked" WHERE id=?')->execute([$gt_period]);
    elseif($change==='merge') {
        coreRecordNumbersMerge($pdo,$tenant,'placement',$survivor,$gt_placement,$actor);
        $pdo->prepare('UPDATE placements SET deleted_at=NOW() WHERE id=?')->execute([$gt_placement]);
    } else $nativeInsert('time_entries',['tenant_id'=>$tenant,'person_id'=>$gt_person,'placement_id'=>$gt_placement,'period_id'=>$gt_period,'timesheet_id'=>$gt_sheet,'work_date'=>'2026-10-05','hour_type'=>'regular','category'=>'regular_billable','hours'=>18,'status'=>'approved','approved_by_user_id'=>$actor,'approved_at'=>'2026-10-12 11:00:00']);
    $worker=$spawn(['mode'=>'native','entity'=>'generic_time','workspace'=>$tenant,'actor'=>$actor,'entry_id'=>$gt_entry,'payload'=>['hours'=>7]]);
    $check($waiting($worker),'Generic time patch waits for concurrent '.$change.' '.$decision);
    if($decision==='COMMIT')$pdo->commit();else$pdo->rollBack();
    $before=$nativeTimeState();$result=$finish($worker);
    if($decision==='COMMIT')$check(!$result['ok']&&$nativeTimeState()===$before,'Generic patch preserves committed '.$change.' without orphan time or receipts: '.json_encode($result));
    else $check($result['ok']&&(float)$result['result']['hours']===7.0&&$result['result']['status']==='draft','Generic patch permits rolled-back '.$change.': '.json_encode($result));
    if($change==='period_lock')$pdo->prepare('UPDATE time_periods SET status="open" WHERE id=?')->execute([$gt_period]);
}
foreach(['people','placements']as$directoryModule)foreach(['COMMIT','ROLLBACK']as$decision) {
    $priorPolicies=[];
    foreach(['people','placements','staffing','time']as$module) {
        $q=$pdo->prepare('SELECT scope_mode FROM tenant_module_scope WHERE tenant_id=? AND module_key=?');$q->execute([$child,$module]);$priorPolicies[$module]=$q->fetchColumn();
        $pdo->prepare('INSERT INTO tenant_module_scope(tenant_id,module_key,scope_mode) VALUES(?,?,"shared") ON DUPLICATE KEY UPDATE scope_mode="shared"')->execute([$child,$module]);
    }
    try {
        $fixture=$nativeSeed('generic directory '.$directoryModule.' '.$decision,$child);extract($fixture,EXTR_PREFIX_ALL,'gt');
        $pdo->beginTransaction();$pdo->prepare('UPDATE tenant_module_scope SET scope_mode="isolated" WHERE tenant_id=? AND module_key=?')->execute([$child,$directoryModule]);
        $worker=$spawn(['mode'=>'native','entity'=>'generic_time','workspace'=>$child,'actor'=>$actor,'entry_id'=>$gt_entry,'payload'=>['hours'=>7]]);
        $check($waiting($worker),'Generic time patch waits for '.$directoryModule.' policy '.$decision);
        if($decision==='COMMIT')$pdo->commit();else$pdo->rollBack();$before=$nativeTimeState();$result=$finish($worker);
        if($decision==='COMMIT')$check(!$result['ok']&&$nativeTimeState()===$before,'Generic patch cannot use a committed obsolete '.$directoryModule.' directory');
        else $check($result['ok']&&(int)$result['result']['tenant_id']===$child&&(float)$result['result']['hours']===7.0,'Generic patch retains original workspace after rolled-back '.$directoryModule.' policy');
    } finally {
        if($pdo->inTransaction())$pdo->rollBack();
        foreach($priorPolicies as$module=>$mode) {
            if($mode===false)$pdo->prepare('DELETE FROM tenant_module_scope WHERE tenant_id=? AND module_key=?')->execute([$child,$module]);
            else $pdo->prepare('UPDATE tenant_module_scope SET scope_mode=? WHERE tenant_id=? AND module_key=?')->execute([$mode,$child,$module]);
        }
    }
}
foreach(['COMMIT','ROLLBACK']as$decision) {
    $fixture=$nativeSeed('generic correction '.$decision);extract($fixture,EXTR_PREFIX_ALL,'gt');
    $pdo->prepare('UPDATE time_entries SET status="approved",approved_by_user_id=?,approved_at="2026-10-12 11:00:00" WHERE id=?')->execute([$actor,$gt_entry]);
    $pdo->prepare('UPDATE staffing_timesheets SET status="approved" WHERE id=?')->execute([$gt_sheet]);
    setRequestTenantId($tenant);$_SESSION['tenant_id']=$tenant;
    $review=timeNativeCorrectionReview($gt_entry);
    $input=['mode'=>'hold','entity'=>'generic_time','operation'=>'correct','workspace'=>$tenant,'actor'=>$actor,'entry_id'=>$gt_entry,'payload'=>['hours'=>6,'correction_reason'=>'Synthetic concurrent correction','review_revision'=>$review['revision']]];
    $first=$spawn($input);$saved=$line($workers[$first]['pipes'][1]);
    $check(!empty($saved['saved'])&&!empty($saved['in_transaction']),'First generic correction holds original, replacement, week and audit '.$decision);
    $second=$spawn(array_replace($input,['mode'=>'native']));$check($waiting($second),'Second generic correction waits for original approval '.$decision);
    fwrite($workers[$first]['pipes'][0],$decision.PHP_EOL);fflush($workers[$first]['pipes'][0]);$firstResult=$finish($first);$secondResult=$finish($second);
    $q=$pdo->prepare('SELECT status,hours,superseded_by_id FROM time_entries WHERE tenant_id=? AND timesheet_id=? ORDER BY id');$q->execute([$tenant,$gt_sheet]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    $check($firstResult['ok']&&($secondResult['ok']===($decision==='ROLLBACK'))&&count($rows)===2&&$rows[0]['status']==='superseded'&&(float)$rows[0]['hours']===8.0&&$rows[1]['status']==='draft'&&(float)$rows[1]['hours']===6.0,'Concurrent generic correction retains one original and exactly one replacement after '.$decision.': '.json_encode($secondResult));
}
foreach(['COMMIT','ROLLBACK']as$decision) {
    $fixture=$nativeSeed('generic document '.$decision);extract($fixture,EXTR_PREFIX_ALL,'gt');
    $document=$nativeInsert('time_uploaded_documents',['tenant_id'=>$tenant,'uploaded_by_user_id'=>$actor,'file_name'=>'synthetic-race.txt','extraction_status'=>'extracted']);
    $input=['mode'=>'hold','entity'=>'generic_time','operation'=>'create','workspace'=>$tenant,'actor'=>$actor,'document_id'=>$document,'rows'=>[['placement_id'=>$gt_placement,'work_date'=>'2026-10-06','hours'=>3,'category'=>'regular_billable','source'=>'ai_inbox','source_ref_id'=>$document]]];
    $first=$spawn($input);$saved=$line($workers[$first]['pipes'][1]);
    $check(!empty($saved['saved'])&&!empty($saved['in_transaction']),'First uploaded batch holds document consumption and linked time '.$decision);
    $second=$spawn(array_replace($input,['mode'=>'native']));$check($waiting($second),'Second uploaded batch waits for the same document '.$decision);
    fwrite($workers[$first]['pipes'][0],$decision.PHP_EOL);fflush($workers[$first]['pipes'][0]);$firstResult=$finish($first);$secondResult=$finish($second);
    $q=$pdo->prepare('SELECT COUNT(*) FROM time_entries WHERE tenant_id=? AND source="ai_inbox" AND source_ref_id=?');$q->execute([$tenant,$document]);$count=(int)$q->fetchColumn();
    $q=$pdo->prepare('SELECT extraction_status,consumed_entry_count FROM time_uploaded_documents WHERE tenant_id=? AND id=?');$q->execute([$tenant,$document]);$doc=$q->fetch(PDO::FETCH_ASSOC);
    $check($firstResult['ok']&&($secondResult['ok']===($decision==='ROLLBACK'))&&$count===1&&$doc['extraction_status']==='consumed'&&(int)$doc['consumed_entry_count']===1,'Competing uploads save the source document exactly once after '.$decision.': '.json_encode($secondResult));
}
