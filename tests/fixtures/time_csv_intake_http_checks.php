<?php
/** CSV source provenance, native authorization, all-or-nothing writes and review. */
require_once __DIR__.'/../../modules/time/lib/csv_intake.php';
$csvTables=array_values(array_unique([...$tables,'time_csv_imports','time_csv_row_evidence']));
$csvPath='/modules/time/api/csv_import.php';$csvOptions=['external_approval'=>true,'skip_invalid'=>false,'update_existing'=>true];
$csvFlags='&already_approved=1&update_existing=1';
$csv="Placement ID,Work date,Hours,External approver email,External approval time,External approval reference,Source system,External ID (source row)\nPL-{$placement},2026-11-16,3,external-source@example.invalid,2026-11-13T12:00:00Z,Manager source record A,other,csv-source-a\nPL-{$placement},2026-11-17,2,external-source@example.invalid,2026-11-13T12:00:00Z,Manager source record B,other,csv-source-b\n";
$before=$snapshot($csvTables);
$expect($nativeRequest('POST',$csvPath.'?action=dry_run'.$csvFlags,['csv'=>$csv],false,$workspace)[0]===401&&$snapshot($csvTables)===$before,'CSV review requires native authentication');
RBACResolver::grantModule($member,'time','read',[$workspace],$actor);RBACResolver::grantModule($member,'staffing','read',[$workspace],$actor);
$expect($nativeRequest('POST',$csvPath.'?action=commit'.$csvFlags,['csv'=>$csv],true,$workspace)[0]===403&&$snapshot($csvTables)===$before,'CSV readers cannot import time');
RBACResolver::grantModule($member,'time','admin',[$workspace],$actor);RBACResolver::grantModule($member,'staffing','admin',[$workspace],$actor);
$previewCsv=static fn(string $source,string $flags='&already_approved=1&update_existing=1')=>$nativeRequest('POST',$csvPath.'?action=dry_run'.$flags,['csv'=>$source],true,$workspace);
$commitCsv=static fn(string $source,?string $revision,string $flags='&already_approved=1&update_existing=1')=>$nativeRequest('POST',$csvPath.'?action=commit'.$flags,['csv'=>$source,'review_revision'=>$revision],true,$workspace);
[$status,$preview]=$previewCsv($csv);
$expect($status===200&&$preview['error_count']===0&&strlen($preview['review_revision'])===64&&$preview['workspace_id']===$workspace&&$snapshot($csvTables)===$before,'CSV preview preserves original ownership and writes no import evidence');
$expect($preview['rows'][2]['source_approval']['state']==='asserted'&&$preview['rows'][2]['source_approver_email']==='external-source@example.invalid','CSV preview exposes the original external assertion without declaring it Core-approved');
$uploader=$read('users',$actor);[$claimStatus,$claimPreview]=$previewCsv(str_replace('external-source@example.invalid',$uploader['email'],$csv));
$expect($claimStatus===200&&$claimPreview['error_count']===0&&$claimPreview['rows'][2]['source_approval']['approved_by_email']===$uploader['email'],'External reviewer may upload its own source assertion while actual Core approval stays independent');
$expect($commitCsv($csv,null)[0]===409&&$snapshot($csvTables)===$before,'CSV commit requires its displayed preview revision');
$changed=str_replace(',3,',',4,',$csv);$expect($commitCsv($changed,$preview['review_revision'])[0]===409&&$snapshot($csvTables)===$before,'CSV bytes changed since preview cannot be imported');
$originalPlacement=$read('placements',$placement);$pdo->prepare('UPDATE placements SET title="Changed after CSV preview" WHERE id=?')->execute([$placement]);$before=$snapshot($csvTables);
$expect($commitCsv($csv,$preview['review_revision'])[0]===409&&$snapshot($csvTables)===$before,'CSV commit rechecks the displayed assignment under current ownership locks');
$cols=array_keys($originalPlacement);$pdo->prepare('UPDATE placements SET '.implode(',',array_map(static fn($c)=>'`'.$c.'`=?',$cols)).' WHERE id=?')->execute([...array_values($originalPlacement),$placement]);
[$status,$preview]=$previewCsv($csv);$before=$snapshot($csvTables);
$pdo->exec("CREATE TRIGGER cf_http_csv_receipt BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.tenant_id=900021 AND NEW.event='time.bulk.uploaded' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic CSV receipt failure'; END IF; END");
try {$expect($commitCsv($csv,$preview['review_revision'])[0]===503&&$snapshot($csvTables)===$before,'CSV final receipt failure restores source, rows, periods, headers and artifacts');}
finally {$pdo->exec('DROP TRIGGER cf_http_csv_receipt');}
[$status,$import]=$commitCsv($csv,$preview['review_revision']);$csvIds=array_values($import['ids']??[]);
$expect($status===200&&$import['imported_count']===2&&$import['requires_independent_review']===true&&$import['pre_approved']===false&&count($csvIds)===2,'External-approved CSV creates pending entries for an independent Core decision');
$first=$read('time_entries',$csvIds[0]);$second=$read('time_entries',$csvIds[1]);$source=$read('time_csv_imports',$import['import_id']);
$expect($first['status']==='pending_review'&&$first['approved_by_user_id']===null&&$first['approved_at']===null&&$first['rate_snapshot_id']===null&&(int)$first['tenant_id']===$workspace,'CSV importer is recorded as creator without inventing approval or changing time ownership');
$expect(!str_contains($source['source_ct'],'external-source')&&decryptField(base64_decode($source['source_ct'],true))===$csv&&hash('sha256',$csv)===$source['source_hash'],'CSV source bytes are retained encrypted with an exact source hash');
[$status,,,$download]=$nativeRequest('GET',$csvPath.'?action=source&id='.$import['import_id'],null,true,$workspace);
$expect($status===200&&$download===$csv,'Original CSV can be retrieved through its authenticated workspace');
$pdo->prepare('UPDATE time_csv_imports SET source_hash=? WHERE id=?')->execute([str_repeat('0',64),$import['import_id']]);
try {$expect($nativeRequest('GET',$csvPath.'?action=source&id='.$import['import_id'],null,true,$workspace)[0]===503,'CSV download refuses source bytes that do not match their retained fingerprint');}
finally {$pdo->prepare('UPDATE time_csv_imports SET source_hash=? WHERE id=?')->execute([$source['source_hash'],$import['import_id']]);}
$expect(in_array($nativeRequest('GET',$csvPath.'?action=source&id='.$import['import_id'],null,true,$tenant)[0],[403,404],true),'CSV source cannot be downloaded from the parent workspace');
$before=$snapshot($csvTables);[$status,$replay]=$commitCsv($csv,$preview['review_revision']);
$expect($status===200&&$replay['replayed']===true&&$replay['ids']===$import['ids']&&$snapshot($csvTables)===$before,'Lost-response CSV replay returns its exact saved result without duplicate writes');
$own=$nativeRequest('POST','/modules/time/api/entries.php?action=decision_review',['ids'=>$csvIds,'decision'=>'approve'],true,$workspace);
$expect($own[0]===403&&$snapshot($csvTables)===$before,'CSV importer cannot independently approve its own imported time');
$independent=StaffingNativeTimeEdit::runForWorkspace($workspace,static fn()=>timeNativeDecisionReview($creator,'entries',$csvIds,'approve'));
$proof=$independent['entries'][0]['csv_source_evidence'];
$expect($proof['matches_source']&&$proof['source_row']===2&&$proof['external_approval']['reference']==='Manager source record A'&&$proof['import_id']===$import['import_id'],'Independent time review shows the original CSV evidence and source row');
$q=$pdo->prepare('SELECT * FROM time_csv_row_evidence WHERE tenant_id=? AND time_entry_id=? AND is_current=1');$q->execute([$workspace,$csvIds[0]]);$evidence=$q->fetch(PDO::FETCH_ASSOC);
$pdo->prepare('UPDATE time_csv_row_evidence SET source_approval_json=? WHERE id=?')->execute([json_encode(['state'=>'asserted','reference'=>'Changed after review']),$evidence['id']]);$before=$snapshot($csvTables);
try {StaffingNativeTimeEdit::runForWorkspace($workspace,static fn()=>timeNativeDecisionCommit($creator,'entries',$csvIds,'approve',$independent['revision']));$expect(false,'CSV evidence mutation must refuse the stale Core decision');}
catch(RuntimeException $e){$expect((int)$e->getCode()===409&&$snapshot($csvTables)===$before,'Changed CSV approval evidence invalidates an independent Core review');}
$pdo->prepare('UPDATE time_csv_row_evidence SET source_approval_json=? WHERE id=?')->execute([$evidence['source_approval_json'],$evidence['id']]);
$independent=StaffingNativeTimeEdit::runForWorkspace($workspace,static fn()=>timeNativeDecisionReview($creator,'entries',$csvIds,'approve'));
StaffingNativeTimeEdit::runForWorkspace($workspace,static fn()=>timeNativeDecisionCommit($creator,'entries',$csvIds,'approve',$independent['revision']));
$approved=$read('time_entries',$csvIds[0]);
$expect($approved['status']==='approved'&&(int)$approved['approved_by_user_id']===$creator&&$approved['dimension_snapshot_json']!==null,'Independent Core review approves imported time with its actual reviewer and frozen evidence');
$before=$snapshot($csvTables);[$status,$replay]=$commitCsv($csv,$preview['review_revision']);
$expect($status===200&&$replay['replayed']&&$snapshot($csvTables)===$before,'Replaying a CSV after Core approval retains the approved history');
$bad="Placement ID,Work date,Hours\nP-{$placement},2026-11-23,2\n";[$status,$badPreview]=$previewCsv($bad,'');
$before=$snapshot($csvTables);[$status,$refused]=$commitCsv($bad,$badPreview['review_revision'],'');
$expect($status===200&&$refused['imported_count']===0&&$snapshot($csvTables)===$before,'Wrong person-number prefix cannot masquerade as a placement number');
$missing="Placement ID,Work date,Hours\nPL-{$placement},2026-11-23,2\n";[$status,$missingPreview]=$previewCsv($missing);
$expect($status===200&&$missingPreview['error_count']===1,'External-approval mode requires reviewer, timestamp and reference rather than a checkbox alone');
$mixed="Placement ID,Work date,Hours\nPL-{$placement},2026-11-23,2\nPL-2147483000,2026-11-24,2\n";
[$status,$mixedPreview]=$previewCsv($mixed,'');$before=$snapshot($csvTables);[$status,$refused]=$commitCsv($mixed,$mixedPreview['review_revision'],'');
$expect($status===200&&$refused['imported_count']===0&&$snapshot($csvTables)===$before,'CSV all-or-nothing mode leaves no rows or source receipt when any row is invalid');
[$status,$mixedPreview]=$previewCsv($mixed,'&skip_invalid=1');[$status,$partial]=$commitCsv($mixed,$mixedPreview['review_revision'],'&skip_invalid=1');
$expect($status===200&&$partial['imported_count']===1&&$partial['skipped_count']===1&&count($partial['errors'])===1,'Reviewed Skip invalid mode records only accepted rows with explicit row errors');
$weekly="Placement ID,Work date,Hours\nPL-{$placement},2026-12-06,40\n";[$status,$weeklyPreview]=$previewCsv($weekly,'');[$status,$weeklyImport]=$commitCsv($weekly,$weeklyPreview['review_revision'],'');
$weeklyEntry=$read('time_entries',array_values($weeklyImport['ids'])[0]);$weeklyProof=StaffingNativeTimeEdit::runForWorkspace($workspace,static fn()=>timeCsvEvidenceForEntry($weeklyEntry));
$expect($status===200&&$weeklyImport['imported_count']===1&&$weeklyProof['hours_basis']==='weekly_total'&&$weeklyEntry['status']==='pending_review','Weekly aggregate is retained as source total without inventing daily distribution or approval');
$expect($snapshot($financialTables)===$financialBefore,'CSV intake and independent review execute no financial work');
