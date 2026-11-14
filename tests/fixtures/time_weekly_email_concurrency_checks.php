<?php
/** The public credential is rechecked under locks after concurrent source or access changes. */
$weeklyRaceFinancialBefore=$approvalFinancialState();
$weeklyRaceState=static function()use($pdo,$nativeTimeState):array {
    return $nativeTimeState()+['weekly_tokens'=>hash('sha256',json_encode($pdo->query('SELECT id,tenant_id,actor_email,consumed_at,issued_review_json,consumed_via_action FROM approval_tokens ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),JSON_THROW_ON_ERROR))];
};
foreach(['approve','reject']as$action)foreach(['COMMIT','ROLLBACK']as$transactionDecision)foreach($action==='approve'?['hours','revoke','period','recipient','rate']:['hours','revoke','period','recipient']as$change) {
    $f=$nativeSeed('weekly email '.$action.' '.$change.' '.$transactionDecision);
    $pdo->prepare('UPDATE placements SET client_id=?,accounting_entity_id=?,worksite_state="NJ",workers_comp_class="synthetic-class",tokenized_email_approval_enabled=1,client_approver_email="independent-client-race@example.invalid" WHERE id=?')->execute([$reviewClient,$reviewEntity,$f['placement']]);
    $clientRate=$nativeInsert('placement_rates',['tenant_id'=>$tenant,'placement_id'=>$f['placement'],'effective_from'=>'2026-10-01','bill_rate'=>100,'pay_rate'=>60,'approved_at'=>'2026-10-01 12:00:00']);
    $issued=staffingEmailReviewIssue($tenant,$reviewer,$f['sheet'],'independent-weekly-race@example.invalid');$view=staffingEmailApprovalReview($issued['token'],$action);
    try {
        $pdo->beginTransaction();
        if($change==='hours')$pdo->prepare('UPDATE time_entries SET hours=7 WHERE id=?')->execute([$f['entry']]);
        elseif($change==='revoke')$pdo->prepare('UPDATE approval_tokens SET consumed_via_action="superseded",consumed_at=NOW() WHERE id=?')->execute([$issued['token_id']]);
        elseif($change==='period')$pdo->prepare('UPDATE time_periods SET status="locked" WHERE id=?')->execute([$f['period']]);
        elseif($change==='recipient')$pdo->prepare('UPDATE approval_tokens SET actor_email="changed-client@example.invalid" WHERE id=?')->execute([$issued['token_id']]);
        else $pdo->prepare('UPDATE placement_rates SET pay_rate=61 WHERE id=?')->execute([$clientRate]);
        $worker=$spawn(['mode'=>'native','entity'=>'generic_time','operation'=>'weekly_email_decision','workspace'=>$tenant,'actor'=>$reviewer,'raw'=>$issued['token'],'decision'=>$action,'revision'=>$view['revision']]);
        $check($waiting($worker),'Weekly email decision waits for '.$action.' '.$change.' '.$transactionDecision);
        if($transactionDecision==='COMMIT')$pdo->commit();else$pdo->rollBack();$before=$weeklyRaceState();$result=$finish($worker);
        if($transactionDecision==='COMMIT')$check(!$result['ok']&&$weeklyRaceState()===$before,'Committed '.$change.' refuses client '.$action.' without writes: '.json_encode($result));
        else {
            $q=$pdo->prepare('SELECT status FROM time_entries WHERE id=?');$q->execute([$f['entry']]);
            $check($result['ok']&&$q->fetchColumn()===($action==='approve'?'approved':'rejected'),'Rolled-back '.$change.' permits client '.$action.': '.json_encode($result));
        }
    } finally {if($pdo->inTransaction())$pdo->rollBack();if($change==='period')$pdo->prepare('UPDATE time_periods SET status="open" WHERE id=?')->execute([$f['period']]);}
}
$check($approvalFinancialState()===$weeklyRaceFinancialBefore,'Weekly email races create no financial execution');
