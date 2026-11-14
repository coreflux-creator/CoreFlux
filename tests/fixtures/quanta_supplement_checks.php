<?php
/** Runs on the populated, disposable correction fixture, including its old approval. */
declare(strict_types=1);
$supplementStart=$passed;
$late=array_replace($validRemote,['id'=>'late-1','timesheet_id'=>'late-source-sheet','work_date'=>'2026-10-03',
    'regular_hours'=>1.25,'overtime_hours'=>0,'hours'=>1.25,'description'=>'Synthetic late source',
    'approved_at'=>'2026-10-05T10:00:00Z','updated_at'=>'2026-10-05T10:00:00Z']);
$lateTwo=array_replace($late,['id'=>'late-2','regular_hours'=>0.5,'hours'=>0.5]);
$sources=['late-1'=>$late,'late-2'=>$lateTwo,'entry-1'=>$remote];
$GLOBALS['__quanta_transport']=static function($method,$url)use(&$sources,$context):array {
    if(str_ends_with(parse_url($url,PHP_URL_PATH),'/context'))return ['status'=>200,'body'=>json_encode($context)];
    parse_str(parse_url($url,PHP_URL_QUERY),$query);$data=[];
    foreach(explode(',',$query['entry_ids'])as$id)$data[]=isset($sources[$id])?['id'=>$id,'state'=>'approved','entry'=>$sources[$id]]:['id'=>$id,'state'=>'unavailable'];
    return ['status'=>200,'body'=>json_encode(['version'=>1,'workspace_id'=>'workspace-a','request_id'=>$query['request_id'],
        'checked_at'=>gmdate('Y-m-d\TH:i:s\Z'),'data'=>$data])];
};
$access=quantaWorkspaceForDelivery(1);
$latePreview=static fn(array $rows)=>quantaPreviewRows(1,$rows,$sites,'approved',$access);
$lateImport=static fn(array $rows,array $reviews=[],bool $confirm=false)=>quantaImportSelected(1,7,$rows,$sites,'approved',array_column($rows,'id'),$access,[],$reviews,$confirm);
$before=$snapshot();$p=$latePreview([$late]);
$check($p['counts']['conflict']===1&&str_contains($p['rows'][0]['message'],'system update')&&$snapshot()===$before,'old schema refuses supplemental intake without touching its original approval');
$migration='modules/staffing/migrations/008_supplemental_timesheets.sql';$applyFile($migration);
$check(staffingTimesheetSupplementSchema(),'supplement schema is installed on the populated fixture');
$originalHeader=staffingTimesheetRow(1,(int)$sheet['id']);
$check((int)$originalHeader['supplement_sequence']===0&&$originalHeader['supplement_of_id']===null&&$originalHeader['status']==='approved','migration retains the approved primary identity and status');
// Reproduce the real old source: already approved/reserved, without modern rate evidence.
$pdo->prepare('UPDATE time_entries SET dimension_snapshot_json="{}",dimension_snapshot_hash=? WHERE id=?')->execute([hash('sha256','{}'),$sibling]);
$pdo->prepare('INSERT INTO payroll_source_claims VALUES(1,?,"held-original-source")')->execute([$sibling]);
$preserved=static function()use($pdo,$sheet,$sibling):array {
    $q=$pdo->prepare('SELECT * FROM time_accounting_handoffs WHERE tenant_id=1 AND timesheet_id=? ORDER BY id');$q->execute([$sheet['id']]);
    return [staffingTimesheetRow(1,(int)$sheet['id']),timeEntryGet($sibling),$q->fetchAll(PDO::FETCH_ASSOC),
        $pdo->query('SELECT * FROM payroll_source_claims ORDER BY time_entry_id')->fetchAll(PDO::FETCH_ASSOC)];
};
$original=$preserved();$p=$latePreview([$late,$lateTwo]);
$reviews=array_column($p['rows'],'destination_review_hash','id');$before=$snapshot();
$check($p['counts']['supplement']===2&&$p['rows'][0]['destination']['period_start']==='2026-09-28'
    &&$p['rows'][0]['destination']['supplement_of_id']===(int)$sheet['id'],'preview identifies the actual Core week and preserved parent for each new source');
$check($fails(fn()=>$lateImport([$late,$lateTwo]),'confirm')&&$snapshot()===$before,'unconfirmed supplemental import makes no changes');
$check($fails(fn()=>$lateImport([$late,$lateTwo],['late-1'=>str_repeat('a',64)],true),'confirm')&&$snapshot()===$before,'wrong or incomplete review hashes fail atomically');
$changed=array_replace($late,['hours'=>1.5,'regular_hours'=>1.5]);
$check($fails(fn()=>$lateImport([$changed,$lateTwo],$reviews,true),'confirm')&&$snapshot()===$before,'changed source hours invalidate the displayed supplement review');
$pdo->exec('INSERT INTO tenant_staffing_settings(tenant_id,week_starts_on) VALUES(1,6)');
$check($fails(fn()=>$lateImport([$late,$lateTwo],$reviews,true),'destination changed'),'calendar changes require a new source preview');
$pdo->exec('DELETE FROM tenant_staffing_settings WHERE tenant_id=1');
$pdo->exec('UPDATE staffing_timesheets SET total_hours=2 WHERE id='.(int)$sheet['id']);
$check($fails(fn()=>$lateImport([$late,$lateTwo],$reviews,true),'confirm'),'parent header changes invalidate the intake review');
$pdo->prepare('UPDATE staffing_timesheets SET total_hours=1,updated_at=? WHERE id=?')->execute([$originalHeader['updated_at'],$sheet['id']]);
foreach(['closed','locked']as$state){
    $pdo->prepare('UPDATE time_periods SET status=? WHERE id=1')->execute([$state]);$closed=$snapshot();
    $check($latePreview([$late])['counts']['conflict']===1&&$fails(fn()=>$lateImport([$late],$reviews,true),'locked or closed')&&$snapshot()===$closed,'closed or locked time period prevents supplements: '.$state);
}
$pdo->exec('UPDATE time_periods SET status="open" WHERE id=1');
$pdo->exec('INSERT INTO time_periods(tenant_id,start_date,end_date,label) VALUES(1,"2026-10-02","2026-10-03","Overlapping test period")');$overlapId=(int)$pdo->lastInsertId();
$check($latePreview([$late])['counts']['conflict']===1&&$fails(fn()=>$lateImport([$late],$reviews,true),'overlapping'),'ambiguous covering periods cannot be silently selected');
$pdo->exec('DELETE FROM time_periods WHERE id='.$overlapId);
$pdo->exec('UPDATE staffing_timesheets SET locked_at=NOW() WHERE id='.(int)$sheet['id']);
$check($latePreview([$late])['counts']['conflict']===1&&$fails(fn()=>$lateImport([$late],$reviews,true),'locked'),'locked primary cannot be bypassed through a supplement');
$pdo->prepare('UPDATE staffing_timesheets SET locked_at=NULL,updated_at=? WHERE id=?')->execute([$originalHeader['updated_at'],$sheet['id']]);
$p=$latePreview([$late,$lateTwo]);$reviews=array_column($p['rows'],'destination_review_hash','id');$before=$snapshot();
setRequestTenantId(2);
$check($fails(fn()=>$lateImport([$late],$reviews,true))&&$snapshot()===$before,'a switched workspace cannot use the original intake review');setRequestTenantId(1);
$pdo->exec("CREATE TRIGGER reject_late_source BEFORE INSERT ON quanta_time_imports FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='forced late source failure'");
$check($fails(fn()=>$lateImport([$late,$lateTwo],$reviews,true),'forced late source')&&$snapshot()===$before,'mid-import failure rolls back supplemental headers, artifacts, time and evidence');
$pdo->exec('DROP TRIGGER reject_late_source');
$p=$latePreview([$late,$lateTwo]);$reviews=array_column($p['rows'],'destination_review_hash','id');
$result=$lateImport([$late,$lateTwo],$reviews,true);$supplement=(int)$result['timesheets'][0]['id'];
$header=staffingTimesheetRow(1,$supplement);$lookup=quantaImportLookup(1,['late-1','late-2']);$first=(int)$lookup['late-1']['regular']['time_entry_id'];$second=(int)$lookup['late-2']['regular']['time_entry_id'];
$check($result['inserted']===2&&count($result['timesheets'])===1&&$supplement!==(int)$sheet['id']
    &&(int)$header['supplement_of_id']===(int)$sheet['id']&&(int)$header['supplement_sequence']===1,'one selected batch creates one distinct review unit per original week');
$check((float)$header['total_hours']===1.75&&$header['status']==='submitted'&&$header['approved_at']===null,'supplement contains only new hours and starts without approval');
$check(timeEntryGet($first)['status']==='pending_review'&&timeEntryGet($first)['rate_snapshot_id']===null&&timeEntryGet($first)['approved_by_user_id']===null,'new time inherits no approval or legacy rate');
$check($preserved()===$original,'intake preserves original approval, missing legacy rate proof, pending accounting and payroll reservation');
$check($lookup['late-1']['regular']['source_workspace_id']==='workspace-a'&&(int)$lookup['late-2']['regular']['current_timesheet_id']===$supplement,'both sources retain provider and Core identities');
$before=$snapshot();$retry=$lateImport([$late,$lateTwo],$reviews,true);
$check($retry['inserted']===0&&$retry['already_imported']===2&&$snapshot()===$before,'retry after response loss creates no extra supplement, time or receipt');
$week=staffingTimesheetWeek(19,'2026-09-28','2026-10-04');
$check((int)$week['timesheet']['id']===(int)$sheet['id']&&array_column($week['entries'],'id')==[$sibling]
    &&array_column($week['supplements'],'id')==[$supplement],'weekly grid retains primary rows and links the separate supplement');
$check((int)staffingTimesheetUpsert(19,'2026-09-28','2026-10-04',['tenant_id'=>1])['id']===(int)$sheet['id'],'primary upsert cannot select or replace the supplemental header');
$before=$snapshot();
$check($fails(fn()=>timeApproveEntry($first,['id'=>7]),'cannot approve your own')&&$snapshot()===$before,'source importer cannot approve its new time');
$pdo->beginTransaction();
$check($fails(fn()=>staffingTimesheetApprovalPlan(7,staffingTimesheetRow(1,$supplement,true),1),'different approver'),'weekly supplement approval also requires an independent reviewer');
$plan=staffingTimesheetApprovalPlan(9,staffingTimesheetRow(1,$supplement,true),1);
$check(array_keys($plan)===[$first,$second],'native approval plan includes new source rows only');
staffingTimesheetApplyApproval(9,$supplement,$plan,['tenant_id'=>1]);$pdo->commit();
$handoff=timeAccountingHandoffStatus(1,$supplement);
$q=$pdo->prepare('SELECT events_json FROM time_accounting_handoffs WHERE id=?');$q->execute([$handoff['id']]);$events=json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
$check($handoff['status']==='pending'&&count($events)===1&&(float)$events[0]['payload']['hours']===1.75
    &&(float)$events[0]['payload']['wage_cost']===87.5&&$events[0]['payload']['timesheet_id']===$supplement,'separate accounting intent includes only newly approved hours at their frozen rate');
$check((int)$pdo->query('SELECT COUNT(*) FROM accounting_events')->fetchColumn()===0&&$preserved()===$original,'supplement approval posts no accounting event and never reprices or reopens the primary');
$sources['late-1']['regular_hours']=1;$sources['late-1']['hours']=1;$sources['late-1']['updated_at']='2026-10-06T12:00:00Z';
$pdo->exec('INSERT INTO tenant_staffing_settings(tenant_id,week_starts_on) VALUES(1,6)');
$p=quantaTimeRevisionPreview(1,7,'late-1');$corrected=quantaTimeRevisionApply(1,7,'late-1',$p['review_hash'],'Correct late source hours');
$pdo->exec('DELETE FROM tenant_staffing_settings WHERE tenant_id=1');
$replacement=(int)$corrected['result']['new_entry_ids']['regular'];
$check((int)timeEntryGet($replacement)['timesheet_id']===$supplement&&$preserved()===$original,'source correction retains its supplement calendar even after settings change, without withdrawing primary accounting intent');
$before=$snapshot();$applyFile($migration);$check($snapshot()===$before,'migration replay retains all primary, supplemental and correction evidence');
$check($fails(fn()=>$pdo->exec('INSERT INTO staffing_timesheets(tenant_id,person_id,period_start,period_end) VALUES(1,19,"2026-09-28","2026-10-04")')),'database still permits only one primary header per person and week');
// An independent connection must wait on the parent before allocating another sequence.
$observer->exec('SET SESSION innodb_lock_wait_timeout=1');$pdo->beginTransaction();staffingTimesheetRow(1,(int)$sheet['id'],true);
$check($fails(fn()=>$observer->query('SELECT id FROM staffing_timesheets WHERE tenant_id=1 AND id='.(int)$sheet['id'].' FOR UPDATE'),'Lock wait timeout'),'parent lock serializes concurrent supplemental intake');$pdo->rollBack();
$third=array_replace($late,['id'=>'late-3','regular_hours'=>0.25,'hours'=>0.25]);$sources['late-3']=$third;$p=$latePreview([$third]);
$next=$lateImport([$third],array_column($p['rows'],'destination_review_hash','id'),true);$nextHeader=staffingTimesheetRow(1,(int)$next['timesheets'][0]['id']);
$check((int)$nextHeader['supplement_sequence']===2&&$preserved()===$original,'later intake gets the next independent supplement without changing earlier units');
echo ($passed-$supplementStart)." Quanta supplemental time integration checks passed\n";
