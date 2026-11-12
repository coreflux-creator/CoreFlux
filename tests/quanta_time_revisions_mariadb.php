<?php
/** Native correction transaction, receipts, component lifecycle and independent approval. */
declare(strict_types=1);
define('COREFLUX_QREVIEW_FIXTURE',true);
require __DIR__.'/quanta_time_provenance_mariadb.php';
require_once __DIR__.'/../core/quanta/time_revisions.php';
// The inherited fixture uses a distinct disposable database; the next review fixture has its own name.
$pdo->exec('RENAME TABLE quanta_time_revisions TO old_empty_revisions'); $migrate('191_quanta_time_revisions.sql');
setRequestTenantId(1); putenv('COREFLUX_INTEGRATION_INSTANCE_ID='.$instance);
$applyFile=static function(string $path)use($pdo):void{foreach(coreflux_split_sql_statements(file_get_contents(__DIR__.'/../'.$path))as$sql)$pdo->exec($sql);};
$pdo->exec('DELETE FROM quanta_time_imports WHERE quanta_entry_id<>"entry-1"');
$pdo->exec('DELETE FROM time_entries WHERE id NOT IN(1,2)');
$pdo->exec('ALTER TABLE time_entries MODIFY id BIGINT AUTO_INCREMENT,
    ADD period_id BIGINT NOT NULL DEFAULT 1, ADD payable TINYINT NOT NULL DEFAULT 1,
    ADD approved_by_user_id BIGINT NULL, ADD approved_at DATETIME NULL, ADD approved_via VARCHAR(32) NULL,
    ADD client_approver_email VARCHAR(255) NULL, ADD rejected_reason VARCHAR(500) NULL,
    ADD correction_reason VARCHAR(500) NULL, ADD created_by_user_id BIGINT NULL,
    ADD dimension_snapshot_json LONGTEXT NULL, ADD dimension_snapshot_hash CHAR(64) NULL,
    ADD custom_category_id BIGINT NULL, ADD source_ref_id BIGINT NULL, ADD external_id VARCHAR(128) NULL,
    ADD bill_extracted_ref VARCHAR(255) NULL, ADD bill_extracted_by_user_id BIGINT NULL,
    ADD ap_extracted_ref VARCHAR(255) NULL, ADD ap_extracted_by_user_id BIGINT NULL,
    ADD payroll_extracted_ref VARCHAR(255) NULL, ADD payroll_extracted_by_user_id BIGINT NULL,
    ADD created_at DATETIME DEFAULT CURRENT_TIMESTAMP, ADD updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
$applyFile('modules/time/migrations/001_init.sql');
$applyFile('modules/staffing/migrations/001_timesheets.sql');
$migrate('147_staffing_timesheet_approval_columns.sql');
foreach(coreflux_split_sql_statements(file_get_contents(__DIR__.'/../core/migrations/105_ai_phase1_tool_registry_and_artifact_layer.sql'))as$sql) {
    if(preg_match('/CREATE TABLE IF NOT EXISTS artifact_(objects|events|relationships)\s*\(/',$sql))$pdo->exec($sql);
}
$pdo->exec('CREATE TABLE payroll_source_claims(time_tenant_id BIGINT,time_entry_id BIGINT,authority_id VARCHAR(80),PRIMARY KEY(time_tenant_id,time_entry_id))');
$pdo->exec('CREATE TABLE audit_log(id BIGINT AUTO_INCREMENT PRIMARY KEY,tenant_id BIGINT,actor_user_id BIGINT,event VARCHAR(128),target_id BIGINT,meta_json LONGTEXT,created_at DATETIME)');
$pdo->exec('CREATE TABLE accounting_entities(id BIGINT PRIMARY KEY,tenant_id BIGINT,active BOOLEAN)');
$pdo->exec('INSERT INTO accounting_entities VALUES(1,1,1)');
$pdo->exec('CREATE TABLE placement_referrals(id BIGINT,tenant_id BIGINT,placement_id BIGINT,fee_flat DECIMAL(12,2),fee_basis VARCHAR(32),start_date DATE,end_date DATE)');
$pdo->exec('CREATE TABLE placement_rates(id BIGINT PRIMARY KEY,tenant_id BIGINT,placement_id BIGINT,effective_from DATE,effective_to DATE,
    bill_rate DECIMAL(12,2),pay_rate DECIMAL(12,2),bill_rate_unit VARCHAR(32),pay_rate_unit VARCHAR(32),currency CHAR(3),ot_multiplier DECIMAL(5,2),dt_multiplier DECIMAL(5,2),approved_at DATETIME)');
$pdo->exec('INSERT INTO placement_rates VALUES(81,1,42,"2026-09-01",NULL,100,50,"hour","hour","USD",1.5,2,NOW())');
$remote=$validRemote; $remoteCalls=0; $mode='approved';
$GLOBALS['__quanta_transport']=static function($method,$url)use(&$remote,&$remoteCalls,&$mode,$context):array {
    if(str_ends_with(parse_url($url,PHP_URL_PATH),'/context'))return ['status'=>200,'body'=>json_encode($context)];
    $remoteCalls++; parse_str(parse_url($url,PHP_URL_QUERY),$query);
    if($mode==='offline')throw new RuntimeException('Source is offline');
    return ['status'=>200,'body'=>json_encode(['version'=>1,'workspace_id'=>'workspace-a','request_id'=>$query['request_id'],
        'checked_at'=>gmdate('Y-m-d\TH:i:s\Z'),'data'=>[$mode==='approved'?['id'=>'entry-1','state'=>'approved','entry'=>$remote]:['id'=>'entry-1','state'=>'unavailable']]])];
};
$entry=quantaNormalizeEntry($remote,$sites,'approved'); $access=quantaWorkspaceForDelivery(1); $route=quantaRoutes(1)[0];
foreach($entry['components'] as $component=>$hours) {
    $pdo->prepare('UPDATE quanta_time_imports SET source_hash=?,source_approval_json=?,source_approval_hash=?,source_workspace_id="workspace-a",is_current=1 WHERE tenant_id=1 AND quanta_entry_id="entry-1" AND component=?')
        ->execute([quantaComponentHash($entry,$route,$component,$hours),...array_values(quantaTimeApprovalFields($entry)),$component]);
}
$pdo->exec('UPDATE time_entries SET status="approved",created_by_user_id=7,approved_by_user_id=8,approved_at="2026-10-03 12:00:00",approved_via="manual",rate_snapshot_id=81,dimension_snapshot_json="{}"');
$pdo->prepare('UPDATE time_entries SET dimension_snapshot_hash=?')->execute([hash('sha256','{}')]);
$pdo->exec('INSERT INTO time_periods(id,tenant_id,start_date,end_date,label) VALUES(1,1,"2026-09-28","2026-10-04","Synthetic original week")');
$sheet=staffingTimesheetUpsert(19,'2026-09-28','2026-10-04',['tenant_id'=>1,'source'=>'quanta','created_by_user_id'=>7]);
$pdo->exec('UPDATE time_entries SET timesheet_id='.(int)$sheet['id']);
$pdo->exec('UPDATE quanta_time_imports SET timesheet_id='.(int)$sheet['id']);
timeReconcileTimesheetHeader((int)$sheet['id'],8);
$passed=0;
$fails=static function(callable $fn,string $part=''):bool{try{$fn();return false;}catch(Throwable $e){if($part!==''&&!str_contains($e->getMessage(),$part))throw$e;return true;}};
$snapshot=static function()use($pdo):array{$out=[];foreach(['time_entries','quanta_time_imports','quanta_time_approval_evidence','quanta_time_revisions','staffing_timesheets','time_periods','artifact_objects','artifact_events','artifact_relationships','audit_log']as$table)$out[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);return$out;};
$preview=static fn()=>quantaTimeRevisionPreview(1,7,'entry-1');
$save=static fn(array $p,string $reason='Corrected source hours')=>quantaTimeRevisionApply(1,7,'entry-1',$p['review_hash'],$reason);
$original=$snapshot();
$check($fails($preview,'already matches')&&$snapshot()===$original,'unchanged source cannot create a meaningless correction');
$remote['regular_hours']=7.5;$remote['overtime_hours']=0;$remote['hours']=7.5;$remote['updated_at']='2026-10-04T10:00:00Z';
$p=$preview();$check($snapshot()===$original&&count($p['before'])===2&&$p['after']['components']===['regular'=>7.5],'preview is read-only and shows the component removal');
$check($fails(fn()=>quantaTimeRevisionApply(2,7,'entry-1',$p['review_hash'],'test'),'current company'),'foreign company cannot apply a review');
$check($fails(fn()=>quantaTimeRevisionApply(1,8,'entry-1',$p['review_hash'],'test'),'changed'),'another actor cannot apply the original review');
$check($fails(fn()=>$save($p,''),'reason'),'blank reason is rejected');
$remote['regular_hours']=7;$remote['hours']=7;
$check($fails(fn()=>$save($p),'changed')&&$snapshot()===$original,'changed source invalidates review and leaves all native records unchanged');
$remote['regular_hours']=7.5;$remote['hours']=7.5;
$pdo->exec('UPDATE time_periods SET status="closed" WHERE id=1');
$check($fails($preview,'closed'),'closed original period prevents correction');$pdo->exec('UPDATE time_periods SET status="open" WHERE id=1');
$pdo->exec('INSERT INTO payroll_source_claims VALUES(1,1,"held-source")');
$check($fails($preview,'reserved'),'held payroll source cannot be revised');$pdo->exec('DELETE FROM payroll_source_claims');
$pdo->exec('UPDATE time_entries SET bill_extracted_ref="invoice-1" WHERE id=1');
$check($fails($preview,'downstream'),'reference-only downstream extraction blocks revision');$pdo->exec('UPDATE time_entries SET bill_extracted_ref=NULL WHERE id=1');
$pdo->exec('INSERT INTO time_downstream_feed(tenant_id,period_id,placement_id,bundle_type,entries_json) VALUES(1,1,42,"ar",\'{"entry_ids":[1,2]}\')');
$check($fails($preview,'bundle'),'even an unconsumed prepared bundle must be resolved first');$pdo->exec('DELETE FROM time_downstream_feed');
$mode='unavailable';$check($fails($preview,'approve'),'withdrawn source cannot be reimported as a correction');$mode='approved';
$pdo->exec('UPDATE time_entries SET source="manual_entry" WHERE id=1');
$check($fails(fn()=>quantaTimeAssertManualCorrection($pdo,1,timeEntryGet(1)),'Quanta'),'changed label cannot bypass original source lineage');
$pdo->exec('UPDATE time_entries SET source="quanta" WHERE id=1');
$p=$preview();$before=$snapshot();
$pdo->exec("CREATE TRIGGER reject_correction_receipt BEFORE INSERT ON quanta_time_revisions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='forced correction receipt failure'");
$check($fails(fn()=>$save($p),'forced correction')&&$snapshot()===$before,'receipt failure rolls back replacements, imports, approvals, headers and events');
$pdo->exec('DROP TRIGGER reject_correction_receipt');
$result=$save($p);$new=(int)$result['result']['new_entry_ids']['regular'];$after=$snapshot();
$check(timeEntryGet($new)['status']==='pending_review'&&timeEntryGet($new)['rate_snapshot_id']===null&&timeEntryGet($new)['approved_at']===null,'replacement has no inherited Core approval or rate');
$old=timeEntryGet(1);$savedOriginal=$before['time_entries'][0];unset($old['status'],$old['superseded_by_id'],$old['updated_at'],$savedOriginal['status'],$savedOriginal['superseded_by_id'],$savedOriginal['updated_at']);
$check($old===$savedOriginal&&(int)timeEntryGet(1)['superseded_by_id']===$new,'original frozen hours, rate and approval remain intact');
$check((int)$pdo->query('SELECT is_current FROM quanta_time_imports WHERE component="overtime"')->fetchColumn()===0&&timeEntryGet(2)['status']==='superseded','removed component remains in history and is no longer current');
$check(count(quantaImportLookup(1,['entry-1'])['entry-1'])===1&&staffingTimesheetRow(1,(int)$sheet['id'])['status']==='submitted','current source and weekly projection contain replacement hours only');
$calls=$remoteCalls;$mode='offline';
$check($save($p)===$result&&$snapshot()===$after&&$remoteCalls===$calls,'lost-response retry recovers the original receipt even when provider is unavailable');$mode='approved';
$check($fails(fn()=>$save($p,'different reason'),'different request')&&$snapshot()===$after,'same review cannot be reused with a changed request');
$check($fails(fn()=>timeApproveEntry($new,['id'=>7]),'different approver'),'correction operator cannot approve its own replacement');
$pdo->beginTransaction();$proof=quantaTimeAssertCurrent($pdo,1,[$new]);$pdo->rollBack();
$check($proof['source_entries']===1,'replacement retains source proof at approval/consumption boundary');
$pdo->beginTransaction();
$check($fails(fn()=>staffingTimesheetApprovalPlan(7,staffingTimesheetRow(1,(int)$sheet['id'],true),1),'different approver'),'weekly approval also enforces independent correction review');
staffingTimesheetApplyApproval(9,(int)$sheet['id'],[$new=>['rate_id'=>81,'dimensions'=>[]]],['tenant_id'=>1]);$pdo->commit();
$check(timeEntryGet($new)['status']==='approved'&&(int)timeEntryGet($new)['approved_by_user_id']===9,'native approval writes a new independent reviewer and frozen evidence');
// Add a component back and move the source into a different open week.
$remote['regular_hours']=6;$remote['overtime_hours']=2;$remote['hours']=8;$remote['work_date']='2026-10-05';$remote['updated_at']='2026-10-06T10:00:00Z';
$p2=$preview();$r2=$save($p2,'Corrected date and overtime');
$check(count($r2['result']['new_entry_ids'])===2&&count($r2['result']['timesheet_ids'])===2,'date move reconciles both weeks and restores a previously removed component');
$current=quantaImportLookup(1,['entry-1'])['entry-1'];
$check((int)$current['overtime']['is_current']===1&&$current['regular']['work_date']==='2026-10-05','reinstated component retains provider ID and the corrected date');
$check(count(quantaTimeRevisionHistory(1,7,'entry-1')['items'])===2,'operator can retrieve both immutable correction receipts');
$check(quantaTimeRevisionHistory(1,7,'other-entry')['items']===[],'history is source scoped');
$history=$pdo->query('SELECT * FROM quanta_time_revisions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);$migrate('191_quanta_time_revisions.sql');
$check($history===$pdo->query('SELECT * FROM quanta_time_revisions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),'schema replay preserves the correction history');
echo "$passed Quanta correction integration checks passed\n";
$pdo->exec('DROP DATABASE '.$db);
