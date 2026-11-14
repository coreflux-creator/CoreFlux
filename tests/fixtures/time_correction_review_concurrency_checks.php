<?php
/** A reviewed correction cannot use original facts superseded by another transaction. */
foreach(['COMMIT','ROLLBACK']as$decision)foreach(['entry','placement','header','accounting','invoice','bundle']as$change) {
    $fixture=$nativeSeed('reviewed correction '.$change.' '.$decision);extract($fixture,EXTR_PREFIX_ALL,'cr');
    setRequestTenantId($tenant);$_SESSION['tenant_id']=$tenant;
    $pdo->prepare('UPDATE time_entries SET status="approved",approved_by_user_id=?,approved_at="2026-10-12 11:00:00" WHERE id=?')->execute([$actor,$cr_entry]);
    $pdo->prepare('UPDATE staffing_timesheets SET status="approved" WHERE id=?')->execute([$cr_sheet]);
    $review=timeNativeCorrectionReview($cr_entry);$event=null;$invoice=null;$lineId=null;$bundle=null;
    try {
        $pdo->beginTransaction();
        if($change==='entry')$pdo->prepare('UPDATE time_entries SET description="Concurrent approved evidence" WHERE id=?')->execute([$cr_entry]);
        elseif($change==='placement')$pdo->prepare('UPDATE placements SET title="Concurrent assignment context" WHERE id=?')->execute([$cr_placement]);
        elseif($change==='header')$pdo->prepare('UPDATE staffing_timesheets SET total_hours=9 WHERE id=?')->execute([$cr_sheet]);
        elseif($change==='accounting') {
            $q=$pdo->prepare('SELECT id FROM accounting_entities WHERE tenant_id=? ORDER BY id LIMIT 1');$q->execute([$tenant]);$entity=(int)$q->fetchColumn();
            $event=$nativeInsert('accounting_events',['tenant_id'=>$tenant,'entity_id'=>$entity,'event_type'=>'staffing.worker_hours.approved','source_module'=>'staffing','source_record_id'=>'timesheet:'.$cr_sheet.':placement:'.$cr_placement.':w2','event_date'=>'2026-10-11','payload'=>'{}','status'=>'received']);
        } elseif($change==='bundle') {
            $bundle=$nativeInsert('time_downstream_feed',['tenant_id'=>$tenant,'period_id'=>$cr_period,'placement_id'=>$cr_placement,'bundle_type'=>'ar','entries_json'=>json_encode(['entry_ids'=>[$cr_entry]]),'status'=>'ready']);
        } else {
            $invoice=$nativeInsert('billing_invoices',['tenant_id'=>$tenant,'invoice_number'=>'SYNTHETIC-RACE-'.$cr_entry,'client_name'=>'Invented time client','issue_date'=>'2026-10-11','due_date'=>'2026-11-11']);
            $lineId=$nativeInsert('billing_invoice_lines',['invoice_id'=>$invoice,'source_type'=>'time_entry','source_ref_id'=>$cr_entry,'description'=>'Invented concurrent original hours']);
        }
        $worker=$spawn(['mode'=>'native','entity'=>'generic_time','operation'=>'correct','workspace'=>$tenant,'actor'=>$actor,'entry_id'=>$cr_entry,'payload'=>['hours'=>6,'correction_reason'=>'Synthetic reviewed correction race','review_revision'=>$review['revision']]]);
        $check($waiting($worker),'Reviewed correction waits for concurrent '.$change.' '.$decision);
        if($decision==='COMMIT')$pdo->commit();else$pdo->rollBack();$before=$nativeTimeState();$result=$finish($worker);
        if($decision==='COMMIT')$check(!$result['ok']&&$nativeTimeState()===$before,'Reviewed correction refuses committed '.$change.' without replacing approved time: '.json_encode($result));
        else $check($result['ok']&&(int)$result['result']['superseded_entry_id']===$cr_entry,'Reviewed correction accepts unchanged facts after rolled-back '.$change.': '.json_encode($result));
    } finally {
        if($pdo->inTransaction())$pdo->rollBack();
        if($lineId)$pdo->prepare('DELETE FROM billing_invoice_lines WHERE id=?')->execute([$lineId]);
        if($invoice)$pdo->prepare('DELETE FROM billing_invoices WHERE id=?')->execute([$invoice]);
        if($event)$pdo->prepare('DELETE FROM accounting_events WHERE id=?')->execute([$event]);
        if($bundle)$pdo->prepare('DELETE FROM time_downstream_feed WHERE id=?')->execute([$bundle]);
    }
}
