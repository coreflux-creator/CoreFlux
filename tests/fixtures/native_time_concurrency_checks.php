<?php
/** Independent connections must observe committed time, identities and directory policy. */
$nativeTimeState=static function()use($pdo):array {
    $out=[];
    foreach(['time_entries','staffing_timesheets','time_periods','artifact_objects','artifact_events','artifact_relationships','core_record_numbers','quanta_time_imports','audit_log']as$table) {
        $rows=$pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);$encoded=array_map(static fn($r)=>json_encode($r,JSON_THROW_ON_ERROR),$rows);sort($encoded);$out[$table]=hash('sha256',json_encode($encoded,JSON_THROW_ON_ERROR));
    }
    return $out;
};
$nativeInsert=static function(string $table,array $row)use($pdo):int {
    $pdo->prepare('INSERT INTO '.$table.' (`'.implode('`,`',array_keys($row)).'`) VALUES('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row));return(int)$pdo->lastInsertId();
};
$nativeSeed=static function(string $suffix)use($pdo,$nativeInsert,$tenant,$actor):array {
    $person=$nativeInsert('people',['tenant_id'=>$tenant,'first_name'=>'Synthetic','last_name'=>'Native time race '.$suffix,'classification'=>'w2','status'=>'active']);
    $placement=$nativeInsert('placements',['tenant_id'=>$tenant,'person_id'=>$person,'title'=>'Native time race '.$suffix,'status'=>'active','engagement_type'=>'w2','start_date'=>'2026-10-01','worksite_country'=>'US']);
    $q=$pdo->prepare('SELECT id FROM time_periods WHERE tenant_id=? AND start_date="2026-10-05" AND end_date="2026-10-11"');$q->execute([$tenant]);
    $period=(int)$q->fetchColumn()?:$nativeInsert('time_periods',['tenant_id'=>$tenant,'start_date'=>'2026-10-05','end_date'=>'2026-10-11','label'=>'Native time race']);
    $sheet=$nativeInsert('staffing_timesheets',['tenant_id'=>$tenant,'person_id'=>$person,'period_start'=>'2026-10-05','period_end'=>'2026-10-11','status'=>'submitted','total_hours'=>8]);
    $entry=$nativeInsert('time_entries',['tenant_id'=>$tenant,'person_id'=>$person,'placement_id'=>$placement,'period_id'=>$period,'timesheet_id'=>$sheet,'work_date'=>'2026-10-05','hour_type'=>'regular','category'=>'regular_billable','hours'=>8,'status'=>'pending_review','created_by_user_id'=>$actor]);
    return compact('person','placement','period','sheet','entry');
};
foreach(['COMMIT','ROLLBACK']as$decision)foreach(['approve','supersede','source_link','archive','merge','person_archive','daily_total']as$change) {
    $fixture=$nativeSeed($change.' '.$decision);extract($fixture,EXTR_PREFIX_ALL,'nt');
    $survivor=null;
    if($change==='merge')$survivor=$nativeInsert('placements',['tenant_id'=>$tenant,'person_id'=>$nt_person,'title'=>'Native race current placement','status'=>'active','engagement_type'=>'w2','start_date'=>'2026-10-01','worksite_country'=>'US']);
    $pdo->beginTransaction();
    if($change==='approve')$pdo->prepare('UPDATE staffing_timesheets SET status="approved",approved_at=NOW(),approved_by_user_id=? WHERE id=?')->execute([$actor,$nt_sheet]);
    elseif($change==='supersede')$pdo->prepare('UPDATE time_entries SET status="superseded" WHERE id=?')->execute([$nt_entry]);
    elseif($change==='source_link')$nativeInsert('quanta_time_imports',['tenant_id'=>$tenant,'quanta_entry_id'=>'native-race-'.$nt_entry,'component'=>'regular','time_entry_id'=>$nt_entry,'timesheet_id'=>$nt_sheet,'source_hash'=>str_repeat('b',64),'source_timesheet_status'=>'approved']);
    elseif($change==='archive')$pdo->prepare('UPDATE placements SET deleted_at=NOW() WHERE id=?')->execute([$nt_placement]);
    elseif($change==='person_archive')$pdo->prepare('UPDATE people SET deleted_at=NOW() WHERE id=?')->execute([$nt_person]);
    elseif($change==='merge') {
        coreRecordNumbersMerge($pdo,$tenant,'placement',$survivor,$nt_placement,$actor);
        $pdo->prepare('UPDATE placements SET deleted_at=NOW() WHERE id=?')->execute([$nt_placement]);
    } else $nativeInsert('time_entries',['tenant_id'=>$tenant,'person_id'=>$nt_person,'placement_id'=>$nt_placement,'period_id'=>$nt_period,'timesheet_id'=>$nt_sheet,'work_date'=>'2026-10-05','hour_type'=>'regular','category'=>'regular_billable','hours'=>18,'status'=>'approved','approved_by_user_id'=>$actor,'approved_at'=>'2026-10-12 11:00:00']);
    $worker=$spawn(['mode'=>'native','entity'=>'native_time','workspace'=>$tenant,'actor'=>$actor,'payload'=>['id'=>$nt_entry,'hours'=>7]]);
    $check($waiting($worker),'Native time writer waits for concurrent '.$change.' '.$decision);
    if($decision==='COMMIT')$pdo->commit();else$pdo->rollBack();
    $before=$nativeTimeState();$result=$finish($worker);
    if($decision==='COMMIT')$check(!$result['ok']&&$nativeTimeState()===$before,'Committed '.$change.' refuses stale native time edit without changing time or receipts: '.json_encode($result));
    else $check($result['ok']&&(float)$result['result']['entry']['hours']===7.0&&$result['result']['timesheet']['status']==='draft','Rolled-back '.$change.' permits the unchanged original time to be edited: '.json_encode($result));
}
// Two individually valid new entries cannot exceed the worker's daily capacity together.
foreach(['COMMIT','ROLLBACK']as$decision) {
    $fixture=$nativeSeed('daily competition '.$decision);extract($fixture,EXTR_PREFIX_ALL,'nt');
    $input=['mode'=>'hold','entity'=>'native_time','workspace'=>$tenant,'actor'=>$actor,'payload'=>['timesheet_id'=>$nt_sheet,'placement_id'=>$nt_placement,'work_date'=>'2026-10-05','hours'=>9]];
    $first=$spawn($input);$saved=$line($workers[$first]['pipes'][1]);
    $check(!empty($saved['saved'])&&!empty($saved['in_transaction']),'Native time creation retains its uncommitted header and daily locks '.$decision);
    $second=$spawn(array_replace($input,['mode'=>'native']));$check($waiting($second),'Second native time creation waits for the first daily total '.$decision);
    fwrite($workers[$first]['pipes'][0],$decision.PHP_EOL);fflush($workers[$first]['pipes'][0]);$firstResult=$finish($first);$secondResult=$finish($second);
    $q=$pdo->prepare('SELECT SUM(hours) FROM time_entries WHERE tenant_id=? AND timesheet_id=? AND status!="superseded"');$q->execute([$tenant,$nt_sheet]);
    $check($firstResult['ok']&&($secondResult['ok']===($decision==='ROLLBACK'))&&(float)$q->fetchColumn()===17.0,'Concurrent native edits preserve the 24-hour limit after '.$decision.': '.json_encode($secondResult));
}
