<?php
/** Native CSV review must observe another connection's committed source facts. */
$csvFinancialBefore=$approvalFinancialState();
$csvRaceState=static function()use($pdo,$nativeTimeState):array {
    $out=$nativeTimeState();
    foreach(['time_csv_imports','time_csv_row_evidence']as$table)$out[$table]=hash('sha256',serialize($pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)));
    return $out;
};
$csvRaceOptions=['update_existing'=>true];
foreach(['COMMIT','ROLLBACK']as$transactionDecision)foreach(['hours','worker_name','period','placement_title','source_identity']as$change) {
    $f=$nativeSeed('CSV '.$change.' '.$transactionDecision);
    $csv="Entry ID,Placement ID,Work date,Hours\n{$f['entry']},PL-{$f['placement']},2026-10-05,7\n";
    $preview=timeCsvImportPreview($tenant,$reviewer,$csv,null,$csvRaceOptions);
    $check($preview['error_count']===0,'CSV race fixture has a valid native preview');
    try {
        $pdo->beginTransaction();
        if($change==='hours')$pdo->prepare('UPDATE time_entries SET hours=6 WHERE id=?')->execute([$f['entry']]);
        elseif($change==='worker_name')$pdo->prepare('UPDATE people SET first_name="Changed" WHERE id=?')->execute([$f['person']]);
        elseif($change==='period')$pdo->prepare('UPDATE time_periods SET status="locked" WHERE id=?')->execute([$f['period']]);
        elseif($change==='source_identity')$pdo->prepare('UPDATE time_entries SET source_system="other",external_id=? WHERE id=?')->execute(['csv-changed-source-'.$f['entry'],$f['entry']]);
        else $pdo->prepare('UPDATE placements SET title="Changed assignment title" WHERE id=?')->execute([$f['placement']]);
        $worker=$spawn(['mode'=>'native','entity'=>'generic_time','operation'=>'csv_import','workspace'=>$tenant,'actor'=>$reviewer,'csv'=>$csv,'options'=>$csvRaceOptions,'revision'=>$preview['review_revision']]);
        $check($waiting($worker),'CSV commit waits for '.$change.' '.$transactionDecision);
        if($transactionDecision==='COMMIT')$pdo->commit();else$pdo->rollBack();$before=$csvRaceState();$result=$finish($worker);
        if($transactionDecision==='COMMIT')$check(!$result['ok']&&$csvRaceState()===$before,'Committed '.$change.' invalidates CSV review without writes: '.json_encode($result));
        else {
            $q=$pdo->prepare('SELECT hours,status,created_by_user_id FROM time_entries WHERE id=?');$q->execute([$f['entry']]);$row=$q->fetch(PDO::FETCH_ASSOC);
            $check($result['ok']&&(int)($result['result']['imported_count']??0)===1&&(float)$row['hours']===7.0&&$row['status']==='pending_review'&&(int)$row['created_by_user_id']===$actor,'Rolled-back '.$change.' allows reviewed CSV while retaining the original creator: '.json_encode($result));
            try {StaffingNativeTimeEdit::runForWorkspace($tenant,static fn()=>timeNativeApprovalSnapshot(timeEntryGet($f['entry']),$reviewer));$check(false,'CSV updater cannot approve despite being different from the original creator');}
            catch(RuntimeException $e){$check((int)$e->getCode()===403,'CSV updater cannot approve despite being different from the original creator');}
        }
    } finally {if($pdo->inTransaction())$pdo->rollBack();if($change==='period')$pdo->prepare('UPDATE time_periods SET status="open" WHERE id=?')->execute([$f['period']]);}
}
// Hold the original entry while two independent identical requests queue.
$f=$nativeSeed('CSV concurrent exact replay');$csv="Entry ID,Placement ID,Work date,Hours\n{$f['entry']},PL-{$f['placement']},2026-10-05,7\n";
$preview=timeCsvImportPreview($tenant,$reviewer,$csv,null,$csvRaceOptions);
try {
    $pdo->beginTransaction();$pdo->prepare('SELECT id FROM time_entries WHERE id=? FOR UPDATE')->execute([$f['entry']]);
    $input=['mode'=>'native','entity'=>'generic_time','operation'=>'csv_import','workspace'=>$tenant,'actor'=>$reviewer,'csv'=>$csv,'options'=>$csvRaceOptions,'revision'=>$preview['review_revision']];
    $firstWorker=$spawn($input);$check($waiting($firstWorker),'First exact CSV request waits for original entry');
    $secondWorker=$spawn($input);$check($waiting($secondWorker),'Concurrent exact CSV request waits for serialized intake');
    $pdo->commit();$firstResult=$finish($firstWorker);$secondResult=$finish($secondWorker);
    $check($firstResult['ok']&&$secondResult['ok']&&$firstResult['result']['import_id']===$secondResult['result']['import_id']&&$firstResult['result']['ids']===$secondResult['result']['ids'],'Concurrent identical CSV requests share one durable import and entry result: '.json_encode([$firstResult,$secondResult]));
    $q=$pdo->prepare('SELECT COUNT(*) FROM time_csv_imports WHERE tenant_id=? AND request_hash=?');$q->execute([$tenant,timeCsvImportRequest($csv,null,$csvRaceOptions)]);
    $check((int)$q->fetchColumn()===1,'Concurrent CSV replay retains exactly one original source receipt');
} finally {if($pdo->inTransaction())$pdo->rollBack();}
$check($approvalFinancialState()===$csvFinancialBefore,'CSV concurrency acceptance creates no financial execution');
