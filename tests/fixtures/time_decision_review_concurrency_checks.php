<?php
/** A review shown before another connection's edit must not authorize changed facts. */
$decisionFinancialBefore=$approvalFinancialState();
foreach([['entries','approve'],['entries','reject'],['weeks','reject']]as[$kind,$action])foreach(['COMMIT','ROLLBACK']as$transactionDecision)foreach(['hours','worker_name','period','placement_title']as$change) {
    $f=$nativeSeed('reviewed '.$kind.' '.$action.' '.$change.' '.$transactionDecision);
    $pdo->prepare('UPDATE placements SET client_id=?,accounting_entity_id=?,worksite_state="NJ",workers_comp_class="synthetic-class" WHERE id=?')->execute([$reviewClient,$reviewEntity,$f['placement']]);
    $nativeInsert('placement_rates',['tenant_id'=>$tenant,'placement_id'=>$f['placement'],'effective_from'=>'2026-10-01','bill_rate'=>100,'pay_rate'=>60,'approved_at'=>'2026-10-01 12:00:00']);
    $selection=[$kind==='weeks'?$f['sheet']:$f['entry']];$review=timeNativeDecisionReview($reviewer,$kind,$selection,$action);
    try {
        $pdo->beginTransaction();
        if($change==='hours')$pdo->prepare('UPDATE time_entries SET hours=7 WHERE id=?')->execute([$f['entry']]);
        elseif($change==='worker_name')$pdo->prepare('UPDATE people SET first_name="Changed" WHERE id=?')->execute([$f['person']]);
        elseif($change==='period')$pdo->prepare('UPDATE time_periods SET status="locked" WHERE id=?')->execute([$f['period']]);
        else $pdo->prepare('UPDATE placements SET title="Changed assignment title" WHERE id=?')->execute([$f['placement']]);
        $worker=$spawn(['mode'=>'native','entity'=>'generic_time','operation'=>'decision','workspace'=>$tenant,'actor'=>$reviewer,'kind'=>$kind,'ids'=>$selection,'decision'=>$action,'revision'=>$review['revision']]);
        $check($waiting($worker),'Reviewed decision waits for '.$kind.' '.$action.' '.$change.' '.$transactionDecision);
        if($transactionDecision==='COMMIT')$pdo->commit();else$pdo->rollBack();$before=$nativeTimeState();$result=$finish($worker);
        if($transactionDecision==='COMMIT')$check(!$result['ok']&&$nativeTimeState()===$before,'Committed '.$change.' refuses reviewed '.$kind.' '.$action.' without writes: '.json_encode($result));
        else {
            $q=$pdo->prepare('SELECT status FROM time_entries WHERE id=?');$q->execute([$f['entry']]);
            $check($result['ok']&&$q->fetchColumn()===($action==='approve'?'approved':'rejected'),'Rolled-back '.$change.' permits reviewed '.$kind.' '.$action.': '.json_encode($result));
        }
    } finally {
        if($pdo->inTransaction())$pdo->rollBack();
        if($change==='period')$pdo->prepare('UPDATE time_periods SET status="open" WHERE id=?')->execute([$f['period']]);
    }
}
$check($approvalFinancialState()===$decisionFinancialBefore,'Reviewed decision races create no financial execution');
