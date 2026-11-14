<?php
/** Native submission cannot outrun a committed identity, period or downstream change. */
foreach(['submit','submit_week']as$operation)foreach(['COMMIT','ROLLBACK']as$decision)foreach(['entry','header','period','placement','person','daily_total','bundle']as$change) {
    $fixture=$nativeSeed('submission '.$operation.' '.$change.' '.$decision);extract($fixture,EXTR_PREFIX_ALL,'su');$bundle=null;
    $pdo->prepare('UPDATE time_entries SET status="draft" WHERE id=?')->execute([$su_entry]);
    $pdo->prepare('UPDATE staffing_timesheets SET status="draft" WHERE id=?')->execute([$su_sheet]);
    try {
        $pdo->beginTransaction();
        if($change==='entry')$pdo->prepare('UPDATE time_entries SET status="superseded" WHERE id=?')->execute([$su_entry]);
        elseif($change==='header')$pdo->prepare('UPDATE staffing_timesheets SET status="locked" WHERE id=?')->execute([$su_sheet]);
        elseif($change==='period')$pdo->prepare('UPDATE time_periods SET status="locked" WHERE id=?')->execute([$su_period]);
        elseif($change==='placement')$pdo->prepare('UPDATE placements SET actual_end_date="2026-10-04" WHERE id=?')->execute([$su_placement]);
        elseif($change==='person')$pdo->prepare('UPDATE people SET deleted_at=NOW() WHERE id=?')->execute([$su_person]);
        elseif($change==='daily_total')$nativeInsert('time_entries',['tenant_id'=>$tenant,'person_id'=>$su_person,'placement_id'=>$su_placement,'period_id'=>$su_period,'timesheet_id'=>$su_sheet,'work_date'=>'2026-10-05','hour_type'=>'regular','category'=>'regular_billable','hours'=>18,'status'=>'approved','approved_by_user_id'=>$actor,'approved_at'=>'2026-10-12 11:00:00']);
        else $bundle=$nativeInsert('time_downstream_feed',['tenant_id'=>$tenant,'period_id'=>$su_period,'placement_id'=>$su_placement,'bundle_type'=>'ar','entries_json'=>json_encode(['entry_ids'=>[$su_entry]]),'status'=>'ready']);
        $worker=$spawn(['mode'=>'native','entity'=>'generic_time','operation'=>$operation,'workspace'=>$tenant,'actor'=>$actor,'entry_id'=>$su_entry,'person_id'=>$su_person]);
        $check($waiting($worker),'Submission waits for concurrent '.$operation.' '.$change.' '.$decision);
        if($decision==='COMMIT')$pdo->commit();else$pdo->rollBack();
        $before=$nativeTimeState();$result=$finish($worker);
        if($decision==='COMMIT')$check(!$result['ok']&&$nativeTimeState()===$before,'Committed '.$change.' refuses stale '.$operation.' without partial state or receipts: '.json_encode($result));
        else {
            $q=$pdo->prepare('SELECT e.status AS entry_status,h.status AS header_status FROM time_entries e JOIN staffing_timesheets h ON h.id=e.timesheet_id WHERE e.id=?');$q->execute([$su_entry]);$state=$q->fetch(PDO::FETCH_ASSOC);
            $check($result['ok']&&$state['entry_status']==='pending_review'&&$state['header_status']==='submitted','Rolled-back '.$change.' permits original '.$operation.': '.json_encode($result));
        }
    } finally {
        if($pdo->inTransaction())$pdo->rollBack();
        if($bundle)$pdo->prepare('DELETE FROM time_downstream_feed WHERE id=?')->execute([$bundle]);
        if($change==='period')$pdo->prepare('UPDATE time_periods SET status="open" WHERE id=?')->execute([$su_period]);
    }
}
