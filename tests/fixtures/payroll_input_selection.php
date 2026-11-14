<?php
/** Included only by the disposable MariaDB input fixture; uses its actual review and transport services. */
declare(strict_types=1);
$choose=static fn(array $ids):array=>purepayPayrollInputPreview($pdo,1,7,$instance,$owners,'P-1','2026-10-01','2026-10-15',$ids);
$candidates=static fn(?int $after=null):array=>purepayPayrollInputCandidates($pdo,1,$instance,$owners,'P-1','2026-10-01','2026-10-15',$after);
foreach([[],[1,1],[0],['2'],[1,2.0],['id'=>1],range(1,201)]as$invalid){
    $rejected=false;try{purepayPayrollInputSelection($invalid);}catch(InvalidArgumentException $e){$rejected=true;}
    $check($rejected,'explicit selection rejects empty, duplicate, noninteger, keyed or excessive entries');
}
$check(purepayPayrollInputSelection([2,1])===[1,2]&&purepayPayrollInputSelection(null)===null,'selection sorts stable IDs and retains the legacy whole-period contract');
$page=$candidates();
$check($page['tenant_id']===1&&array_column($page['rows'],'id')===[1,2]&&array_column($page['rows'],'available')===[true,true],'candidate discovery is scoped to the authorized person, time owner and dates');
$check($candidates(1)['rows'][0]['id']===2&&$candidates(2)['rows']===[],'candidate cursor advances without reintroducing earlier entries');
$check($fails(fn()=>$candidates(0)),'nonpositive candidate cursor is rejected');
foreach([[3],[999],[1,3]]as$invalid)$check($fails(fn()=>$choose($invalid)),'foreign or missing selected time cannot be silently omitted');
$check($fails(fn()=>purepayPayrollInputPreview($pdo,1,7,$instance,$owners,'P-1','2026-10-04','2026-10-15',[2])),'selection cannot escape its reviewed period');
$pdo->exec("INSERT INTO people VALUES(2,10,'Other','Person','other@example.test','active',NULL)");
$pdo->exec("INSERT INTO core_record_numbers(tenant_id,entity_type,record_id,canonical_record_id,record_number) VALUES(10,'person',2,2,'P-2')");
$check(purepayPayrollInputCandidates($pdo,1,$instance,$owners,'P-2','2026-10-01','2026-10-15')['rows']===[],'another person cannot discover this person’s approved time');
$pdo->exec('DELETE FROM core_record_numbers WHERE entity_type="person" AND record_id=2');$pdo->exec('DELETE FROM people WHERE id=2');
$savedArchive=$pdo->query('SELECT rate_json FROM time_approval_rates WHERE tenant_id=1 AND time_entry_id=1')->fetchColumn();
$pdo->exec('UPDATE time_approval_rates SET rate_json="{}" WHERE tenant_id=1 AND time_entry_id=1');
$page=$candidates();$picked=$choose([2]);
$check(!$page['rows'][0]['available']&&is_string($page['rows'][0]['reason'])&&$page['rows'][1]['available'],'missing original approval evidence is visible without hiding other approved entries');
$check($picked['selected_entry_ids']===[2]&&array_column(array_column($picked['packet']['components'],'source'),'time_entry_id')===[2]
    &&$picked['packet']['period_start']==='2026-10-01'&&$picked['packet']['period_end']==='2026-10-15','selected new time keeps the actual payroll dates without rebuilding unrelated old time');
$check($fails(fn()=>$choose([1,2])),'including an unavailable entry blocks the complete selection');
$pdo->prepare('UPDATE time_approval_rates SET rate_json=? WHERE tenant_id=1 AND time_entry_id=1')->execute([$savedArchive]);
$check($choose([2])['delivery']['id']===$picked['delivery']['id'],'unselected source changes do not replace the reviewed selection');
$savedEnvelope=integrationDeliveryGet($pdo,1,(int)$picked['delivery']['id']);
$payload=json_decode((string)decryptField($savedEnvelope['payload_ct']),true,64,JSON_THROW_ON_ERROR);
$check(purepayPayrollInputSavedSelection($payload)===[2]&&!array_key_exists('selected_entry_ids',$picked['packet']),'selector stays private and the provider contract remains unchanged');
$tampered=$payload;$tampered['selected_entry_ids']=[1];
$pdo->prepare('UPDATE integration_deliveries SET payload_ct=? WHERE id=?')->execute([encryptField(integrationDeliveryJson($tampered)),$savedEnvelope['id']]);
$check($fails(fn()=>purepayPayrollInputEnvelope($pdo,1,(int)$savedEnvelope['id'],$savedEnvelope['review_hash'],$instance,$owners)),'saved selection cannot disagree with the immutable packet');
$pdo->prepare('UPDATE integration_deliveries SET payload_ct=? WHERE id=?')->execute([$savedEnvelope['payload_ct'],$savedEnvelope['id']]);
$prePosts=$posts;$pdo->exec('UPDATE time_entries SET status="pending_review" WHERE id=2');
$check($fails(fn()=>$send($picked))&&$posts===$prePosts,'send rechecks every selected approval before provider IO');
$pdo->exec('UPDATE time_entries SET status="approved" WHERE id=2');
$pdo->exec('UPDATE time_entries SET payroll_extracted_ref=222 WHERE id=2');
$check(!$candidates()['rows'][1]['available']&&$fails(fn()=>$send($picked))&&$posts===$prePosts,'extraction after discovery prevents selected delivery');
$pdo->exec('UPDATE time_entries SET payroll_extracted_ref=NULL WHERE id=2');
$check($fails(fn()=>$send($picked))&&$posts===$prePosts,'a new explicit selection cannot bypass the prior unresolved send for this person');
// Model a late provider receipt for the earlier lost-response case, then recover it by GET.
// Selection does not cancel that attempt or weaken the delivery subject fence.
$lateRow=integrationDeliveryGet($pdo,1,(int)$p['delivery']['id']);
$latePayload=json_decode((string)decryptField($lateRow['payload_ct']),true,64,JSON_THROW_ON_ERROR);
$lateHash=integrationDeliveryHash($latePayload['body']);$lateId='cpi_'.integrationDeliveryHash(['org-a',$lateHash]);
$receipts[$lateId]=['id'=>$lateId,'organization_id'=>'org-a','content_hash'=>$lateHash,'state'=>'received_for_review',
    'payroll_created'=>false,'source_time_reserved'=>false,'components'=>count($latePayload['body']['components'])];
$check($reconcile($p)['state']==='delivered'&&$posts===$prePosts,'the prior uncertain input is resolved through its original receipt without resending');
$savedMode=$mode;$mode='lost';$realTransport=$GLOBALS['__purepay_transport'];
$GLOBALS['__purepay_transport']=static function($method,$url,$headers,$json)use($realTransport,$picked,$check){
    if($method==='POST')$check(integrationDeliveryHash(json_decode($json,true,64,JSON_THROW_ON_ERROR))===integrationDeliveryHash($picked['packet']),
        'wire contains only the selected provider components, with no private selector');
    return $realTransport($method,$url,$headers,$json);
};
$check($send($picked)['state']==='uncertain'&&$posts===$prePosts+1,'selected send retains an uncertain receipt after response loss');
$check($fails(fn()=>$send($picked))&&$posts===$prePosts+1,'uncertain selected input cannot be sent again');
$delivered=$reconcile($picked);
$check($delivered['state']==='delivered'&&$send($picked)===$delivered&&$posts===$prePosts+1,'selected receipt recovery and replay use the original packet once');
$GLOBALS['__purepay_transport']=$realTransport;$mode=$savedMode;
$selectedHistory=purepayPayrollInputHistory($pdo,1,$instance,$owners,'P-1');
$savedItems=array_values(array_filter($selectedHistory['items'],static fn($item)=>$item['delivery']['id']===$picked['delivery']['id']));
$check(count($savedItems)===1&&$savedItems[0]['tenant_id']===1&&$savedItems[0]['selected_entry_ids']===[2],'history retains original company and exact selection after reload');
// Exercise real paging without inventing approval evidence: these new rows must remain unavailable.
$template=$pdo->query('SELECT * FROM time_entries WHERE id=2')->fetch(PDO::FETCH_ASSOC);$columns=array_keys($template);
$insert=$pdo->prepare('INSERT INTO time_entries (`'.implode('`,`',$columns).'`) VALUES('.implode(',',array_fill(0,count($columns),'?')).')');
for($n=10;$n<111;$n++){$template['id']=$n;$insert->execute(array_values($template));}
$page=$candidates();$next=$candidates($page['next_after']);
$check(count($page['rows'])===100&&$page['next_after']===107&&array_column($next['rows'],'id')===[108,109,110]&&$next['next_after']===null,'bounded paging discovers every remaining entry without widening the original period');
$check(count(array_filter($page['rows'],static fn($row)=>$row['available']))===2,'discovery does not manufacture approval evidence for copied rows');
$pdo->exec('DELETE FROM time_entries WHERE id BETWEEN 10 AND 110');
