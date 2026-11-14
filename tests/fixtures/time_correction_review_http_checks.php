<?php
/** Extends the invented original-workspace fixture; no posting or provider IO. */
(static function()use($pdo,$tenant,$workspace,$actor,$member,$person,$placement,$sibling,$approved,$replacement,$approvedHeader,$rates,$api,$request,$expect,$snapshot,$tables,$insert,$read):void {
    $all=[...$tables,'billing_invoices','billing_invoice_lines','ap_bills','ap_bill_lines','time_downstream_feed'];
    $reviewPath=$api.'?action=correction_review&id='.$sibling;$historyPath=$api.'?action=entry_history&id='.$approved;
    $before=$snapshot($all);
    $expect($request('GET',$reviewPath,null,false,$workspace)[0]===401,'Correction review requires sign-in');
    RBACResolver::grantModule($member,'time','read',[$workspace],$actor);
    $expect($request('GET',$reviewPath,null,true,$workspace)[0]===403,'Read-only time access cannot prepare a correction');
    [$status,$history]=$request('GET',$historyPath,null,true,$workspace);
    $expect($status===200&&count($history['entries'])===2&&(int)$history['entries'][0]['id']===$approved&&(int)$history['entries'][1]['id']===(int)$replacement['id'],'Read-only time access can follow original and replacement history');
    RBACResolver::grantModule($member,'staffing','admin',[$workspace],$actor);
    [$status,$detail]=$request('GET','/modules/staffing/api/timesheets.php?action=detail&id='.$approvedHeader,null,true,$workspace);
    $expect($status===200&&$detail['permissions']===['correct_time'=>false,'time_history'=>true],'Timesheet screen distinguishes read-only history from correction authority');
    RBACResolver::grantModule($member,'time','write',[$workspace],$actor);
    [$status,$detail]=$request('GET','/modules/staffing/api/timesheets.php?action=detail&id='.$approvedHeader,null,true,$workspace);
    $expect($status===200&&$detail['permissions']['correct_time']===true&&(int)$detail['workspace_id']===$workspace,'Time managers receive correction navigation in the original workspace');
    [$status,$review]=$request('GET',$reviewPath,null,true,$workspace);
    $expect($status===200&&(int)$review['workspace_id']===$workspace&&(int)$review['worker']['id']===$person&&(int)$review['placement']['id']===$placement&&$snapshot($all)===$before,'Correction review reads source and current identities without creating or changing records');
    $savePath=$api.'?action=correct&id='.$sibling;
    $body=['hours'=>2.5,'work_date'=>'2026-10-27','category'=>'OT_nonbillable','description'=>'Synthetic corrected day','correction_reason'=>'Reviewed paper correction','review_revision'=>$review['revision']];
    $missing=$body;unset($missing['review_revision']);
    $expect($request('POST',$savePath,$missing,true,$workspace)[0]===409&&$snapshot($all)===$before,'Manual correction requires the reviewed original revision');
    $expect($request('POST',$savePath,array_replace($body,['review_revision'=>str_repeat('0',64)]),true,$workspace)[0]===409&&$snapshot($all)===$before,'A different correction review cannot replace this original');
    $original=$read('time_entries',$sibling);
    $pdo->prepare('UPDATE time_entries SET description="Intervening original evidence" WHERE id=?')->execute([$sibling]);$changed=$snapshot($all);
    $expect($request('POST',$savePath,$body,true,$workspace)[0]===409&&$snapshot($all)===$changed,'Correction cannot silently accept changed approved evidence after review');
    $pdo->prepare('UPDATE time_entries SET description=?,updated_at=? WHERE id=?')->execute([$original['description'],$original['updated_at'],$sibling]);
    $priorPlacement=$read('placements',$placement);
    $pdo->prepare('UPDATE placements SET title="Intervening assignment description" WHERE id=?')->execute([$placement]);$changed=$snapshot($all);
    $expect($request('POST',$savePath,$body,true,$workspace)[0]===409&&$snapshot($all)===$changed,'Correction cannot silently accept a changed assignment after review');
    $pdo->prepare('UPDATE placements SET title=?,updated_at=? WHERE id=?')->execute([$priorPlacement['title'],$priorPlacement['updated_at'],$placement]);
    $q=$pdo->prepare('SELECT id FROM accounting_entities WHERE tenant_id=? ORDER BY id LIMIT 1');$q->execute([$tenant]);$entity=(int)$q->fetchColumn();
    $event=$insert('accounting_events',['tenant_id'=>$tenant,'entity_id'=>$entity,'event_type'=>'staffing.worker_hours.approved','source_module'=>'staffing','source_record_id'=>'timesheet:'.$approvedHeader.':placement:'.$placement.':w2','event_date'=>'2026-10-25','payload'=>json_encode(['timesheet_id'=>$approvedHeader]),'status'=>'received']);
    try {$changed=$snapshot($all);$expect($request('GET',$reviewPath,null,true,$workspace)[0]===409&&$request('POST',$savePath,$body,true,$workspace)[0]===409&&$snapshot($all)===$changed,'Retained parent-ledger accounting history blocks ordinary correction before any posting');}
    finally {$pdo->prepare('DELETE FROM accounting_events WHERE id=?')->execute([$event]);}
    foreach(['billing','ap']as$kind) {
        if($kind==='billing') {
            $document=$insert('billing_invoices',['tenant_id'=>$workspace,'invoice_number'=>'SYNTHETIC-CORRECTION-'.$sibling,'client_name'=>'Invented client','issue_date'=>'2026-10-25','due_date'=>'2026-11-25']);
            $line=$insert('billing_invoice_lines',['invoice_id'=>$document,'source_type'=>'time_entry','source_ref_id'=>$sibling,'description'=>'Invented original hours']);$docTable='billing_invoices';$lineTable='billing_invoice_lines';
        } else {
            $document=$insert('ap_bills',['tenant_id'=>$tenant,'bill_number'=>'SYNTHETIC-CORRECTION-'.$sibling,'internal_ref'=>'SYNTHETIC-'.$sibling,'vendor_name'=>'Invented vendor','received_at'=>'2026-10-25','bill_date'=>'2026-10-25','due_date'=>'2026-11-25']);
            $line=$insert('ap_bill_lines',['bill_id'=>$document,'source_type'=>'time_entry','source_ref_id'=>$sibling,'description'=>'Invented original hours']);$docTable='ap_bills';$lineTable='ap_bill_lines';
        }
        try {$changed=$snapshot($all);$expect($request('GET',$reviewPath,null,true,$workspace)[0]===409&&$request('POST',$savePath,$body,true,$workspace)[0]===409&&$snapshot($all)===$changed,'Actual '.$kind.' source lines protect time even without extracted flags');}
        finally {$pdo->prepare('DELETE FROM '.$lineTable.' WHERE id=?')->execute([$line]);$pdo->prepare('DELETE FROM '.$docTable.' WHERE id=?')->execute([$document]);}
    }
    $document=$insert('billing_invoices',['tenant_id'=>$workspace,'invoice_number'=>'SYNTHETIC-BUNDLE-'.$sibling,'client_name'=>'Invented bundle client','issue_date'=>'2026-10-25','due_date'=>'2026-11-25']);
    $line=$insert('billing_invoice_lines',['invoice_id'=>$document,'source_type'=>'time','source_ref_id'=>$sibling,'description'=>'Bundle identity is not entry identity']);
    $bundle=null;
    try {
        $changed=$snapshot($all);
        $expect($request('GET',$reviewPath,null,true,$workspace)[0]===200&&$snapshot($all)===$changed,'Unrelated bundle number equal to entry number does not falsely prevent correction');
        $bundle=$insert('time_downstream_feed',['tenant_id'=>$workspace,'period_id'=>$original['period_id'],'placement_id'=>$placement,'bundle_type'=>'ar','entries_json'=>json_encode(['entry_ids'=>[$sibling]]),'status'=>'ready']);
        $pdo->prepare('UPDATE billing_invoice_lines SET source_ref_id=? WHERE id=?')->execute([$bundle,$line]);$changed=$snapshot($all);
        $expect($request('GET',$reviewPath,null,true,$workspace)[0]===409&&$request('POST',$savePath,$body,true,$workspace)[0]===409&&$snapshot($all)===$changed,'Original entry membership finds a linked invoice bundle despite absent consumption flags');
        $pdo->prepare('DELETE FROM billing_invoice_lines WHERE id=?')->execute([$line]);$line=null;$changed=$snapshot($all);
        $expect($request('GET',$reviewPath,null,true,$workspace)[0]===409&&$snapshot($all)===$changed,'Ready downstream amounts cannot silently retain superseded original time');
        $pdo->prepare('UPDATE time_downstream_feed SET status="consumed",consumed_ref_id=? WHERE id=?')->execute([$document,$bundle]);$changed=$snapshot($all);
        $expect($request('POST',$savePath,$body,true,$workspace)[0]===409&&$snapshot($all)===$changed,'Consumed source bundle remains protected even when document lines are absent');
    } finally {
        if($line)$pdo->prepare('DELETE FROM billing_invoice_lines WHERE id=?')->execute([$line]);
        $pdo->prepare('DELETE FROM billing_invoices WHERE id=?')->execute([$document]);
        if($bundle)$pdo->prepare('DELETE FROM time_downstream_feed WHERE id=?')->execute([$bundle]);
    }
    $pdo->prepare('UPDATE placements SET deleted_at=NOW() WHERE id=?')->execute([$placement]);$changed=$snapshot($all);
    $expect($request('GET',$historyPath,null,true,$workspace)[0]===200&&$snapshot($all)===$changed,'Original correction history survives placement retirement without rewriting identity');
    $expect(in_array($request('GET',$reviewPath,null,true,$workspace)[0],[404,409],true),'Retained history does not grant correction of a retired assignment');
    $pdo->prepare('UPDATE placements SET deleted_at=?,updated_at=? WHERE id=?')->execute([$priorPlacement['deleted_at'],$priorPlacement['updated_at'],$placement]);
    $replacementBefore=$read('time_entries',(int)$replacement['id']);
    $pdo->prepare('UPDATE time_entries SET status="superseded",superseded_by_id=? WHERE id=?')->execute([$approved,$replacement['id']]);$changed=$snapshot($all);
    $expect($request('GET',$historyPath,null,true,$workspace)[0]===409&&$snapshot($all)===$changed,'Cyclic correction history is refused without rewriting rows');
    $pdo->prepare('UPDATE time_entries SET status=?,superseded_by_id=?,updated_at=? WHERE id=?')->execute([$replacementBefore['status'],$replacementBefore['superseded_by_id'],$replacementBefore['updated_at'],$replacement['id']]);
    $pdo->prepare('UPDATE time_entries SET status="superseded",superseded_by_id=? WHERE id=?')->execute([$replacement['id'],$sibling]);$changed=$snapshot($all);
    $expect($request('GET',$historyPath,null,true,$workspace)[0]===409&&$snapshot($all)===$changed,'Ambiguous predecessors cannot be presented as a valid correction chain');
    $pdo->prepare('UPDATE time_entries SET status=?,superseded_by_id=?,updated_at=? WHERE id=?')->execute([$original['status'],$original['superseded_by_id'],$original['updated_at'],$sibling]);
    [$status,$review]=$request('GET',$reviewPath,null,true,$workspace);$body['review_revision']=$review['revision'];
    [$status,$saved]=$request('POST',$savePath,$body,true,$workspace);
    $expect($status===200&&(int)$saved['workspace_id']===$workspace&&(int)$saved['original_timesheet_id']===$approvedHeader&&(int)$saved['timesheet_id']!==$approvedHeader,'Reviewed correction returns both original and replacement weeks after a date change');
    $new=$read('time_entries',(int)$saved['new_entry_id']);
    $expect($new['status']==='draft'&&$new['approved_at']===null&&$new['approved_by_user_id']===null&&$new['rate_snapshot_id']===null&&$new['dimension_snapshot_json']===null,'Replacement does not inherit approval, rate or dimension authority');
    $expect((float)$read('staffing_timesheets',$approvedHeader)['total_hours']===1.5&&(float)$read('staffing_timesheets',(int)$saved['timesheet_id'])['total_hours']===3.5,'Reviewed cross-week correction updates both totals once');
    [$status,$history]=$request('GET',$api.'?action=entry_history&id='.$new['id'],null,true,$workspace);
    $expect($status===200&&count($history['entries'])===2&&(int)$history['entries'][0]['id']===$sibling&&$history['entries'][1]['correction_reason']===$body['correction_reason'],'Replacement history links back to its original and records the correction reason');
    $pdo->prepare('UPDATE time_entries SET status="approved",approved_by_user_id=?,approved_at="2026-11-01 12:00:00",approved_via="manual",rate_snapshot_id=? WHERE id=?')->execute([$actor,$rates,$new['id']]);
    [$status,$again]=$request('GET',$api.'?action=correction_review&id='.$new['id'],null,true,$workspace);
    [$status,$second]=$request('POST',$api.'?action=correct&id='.$new['id'],['hours'=>2,'correction_reason'=>'Second synthetic correction','review_revision'=>$again['revision']],true,$workspace);
    [$historyStatus,$chain]=$request('GET',$api.'?action=entry_history&id='.$sibling,null,true,$workspace);
    $expect($status===200&&$historyStatus===200&&array_map('intval',array_column($chain['entries'],'id'))===[$sibling,(int)$new['id'],(int)$second['new_entry_id']],'Multiple reviewed corrections retain the full ordered original and replacement chain');
    $otherPeriod=$insert('time_periods',['tenant_id'=>$tenant,'start_date'=>'2076-10-05','end_date'=>'2076-10-11','label'=>'Invented foreign history period']);
    $otherEntry=$insert('time_entries',['tenant_id'=>$tenant,'person_id'=>$person,'placement_id'=>$placement,'period_id'=>$otherPeriod,'work_date'=>'2076-10-05','category'=>'regular_billable','hours'=>1,'status'=>'approved']);
    try {
        $changed=$snapshot($all);$expect($request('GET',$api.'?action=entry_history&id='.$otherEntry,null,true,$workspace)[0]===404&&$request('GET',$api.'?action=correction_review&id='.$otherEntry,null,true,$workspace)[0]===404&&$snapshot($all)===$changed,'Shared directories do not expose another workspace time correction or history');
        $terminal=$read('time_entries',(int)$second['new_entry_id']);
        $pdo->prepare('UPDATE time_entries SET status="superseded",superseded_by_id=? WHERE id=?')->execute([$otherEntry,$terminal['id']]);$changed=$snapshot($all);
        $expect($request('GET',$api.'?action=entry_history&id='.$sibling,null,true,$workspace)[0]===409&&$snapshot($all)===$changed,'A replacement pointer cannot disclose foreign-workspace time or silently truncate its history');
        $pdo->prepare('UPDATE time_entries SET status=?,superseded_by_id=?,updated_at=? WHERE id=?')->execute([$terminal['status'],$terminal['superseded_by_id'],$terminal['updated_at'],$terminal['id']]);
    }
    finally {$pdo->prepare('DELETE FROM time_entries WHERE id=?')->execute([$otherEntry]);$pdo->prepare('DELETE FROM time_periods WHERE id=?')->execute([$otherPeriod]);}
}) ();
