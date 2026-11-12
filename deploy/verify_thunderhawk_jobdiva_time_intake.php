<?php
/** Installed pilot checks; only session-private synthetic tables may be changed. */
declare(strict_types=1);
const JTI_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
if(PHP_SAPI!=='cli'||getcwd()!==JTI_ROOT||getenv('COREFLUX_ENV')!=='staging'||posix_geteuid()!==1003)exit(2);
require_once JTI_ROOT.'/core/db.php';
require_once JTI_ROOT.'/core/integrations/identity.php';
require_once JTI_ROOT.'/core/jobdiva/sync_time.php';
$pdo=getDB();
if(!$pdo||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||!COREFLUX_STAGING
    ||integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf'||COREFLUX_INTEGRATION_DELIVERY_TENANTS!=='2002'
    ||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=2002')->fetchColumn()!==1)exit(2);
function jtiSnapshot(PDO $pdo,?array $tables=null):array{
    $result=[];$tables??=$pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
    foreach($tables as$table){
        if(!preg_match('/^[a-zA-Z0-9_]+$/D',$table))throw new RuntimeException('Unexpected table');
        $rows=[];foreach($pdo->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC)as$row)$rows[]=hash('sha256',serialize($row));
        sort($rows);$result[$table]=['rows'=>count($rows),'hash'=>hash('sha256',implode("\n",$rows))];
    }ksort($result);return $result;
}
$report=['checked_at'=>gmdate('c'),'passed'=>false,'checks'=>[],'provider_requests'=>false,'production_changed'=>false,'financial_postings'=>false];
$check=static function(bool $ok,string $label)use(&$report){if(!$ok)throw new RuntimeException($label);$report['checks'][]=$label;};
$beforeOriginal=jtiSnapshot($pdo);$temporary=[];
$tables=['tenants','tenant_module_scope','people','placements','core_record_numbers','jobdiva_connections','tenant_staffing_settings','time_periods','time_entries','staffing_timesheets','external_entity_mappings','entity_sync_history','jobdiva_sync_audit','audit_log','jobdiva_time_intake','artifact_objects','artifact_events','artifact_relationships','payroll_source_claims','time_downstream_feed','billing_invoices','billing_invoice_lines','ap_bills','ap_bill_lines','accounting_events','payroll_native_observations'];
$snapshot=static function()use($pdo,$tables){$out=[];foreach($tables as$table)$out[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);return$out;};
$reject=static function(callable $run,string $label,string $message='')use($snapshot,$check){$before=$snapshot();try{$run();}catch(Throwable $e){if($message!==''&&!str_contains($e->getMessage(),$message))throw$e;$check($snapshot()===$before,$label.' leaves every record unchanged');return;}throw new RuntimeException('Accepted '.$label);};
try{
    foreach($tables as$table){
        $definition=$pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
        if(!str_starts_with($definition,'CREATE TABLE `'.$table.'` ('))throw new RuntimeException('Unexpected fixture definition');
        $definition=preg_replace('/^  CONSTRAINT [^\n]+FOREIGN KEY[^\n]*\n/m','',$definition);
        $definition=preg_replace('/,\n\) ENGINE/','
) ENGINE',$definition);
        if(str_contains($definition,'FOREIGN KEY'))throw new RuntimeException('Unreviewed temporary foreign key');
        $pdo->exec(preg_replace('/^CREATE TABLE /','CREATE TEMPORARY TABLE ',$definition,1));$temporary[]=$table;
    }
    setRequestTenantId(11);
    $pdo->exec("INSERT INTO tenants(id,name,parent_id,tenant_type,is_active) VALUES(10,'Synthetic owner',NULL,'master',1),(11,'Synthetic company',10,'sub',1),(12,'Synthetic foreign',NULL,'master',1)");
    $pdo->exec("INSERT INTO tenant_module_scope(tenant_id,module_key,scope_mode) VALUES(11,'people','shared'),(11,'placements','shared')");
    $pdo->exec("INSERT INTO people(id,tenant_id,first_name,last_name,email_primary,classification,status) VALUES(100,10,'Synthetic','Time','synthetic-time@example.invalid','w2','active')");
    $pdo->exec("INSERT INTO core_record_numbers(tenant_id,entity_type,record_id,record_number,canonical_record_id) VALUES(10,'person',100,'P-100',100)");
    $pdo->exec("INSERT INTO placements(id,tenant_id,person_id,title,status,engagement_type,start_date,end_date) VALUES(100,10,100,'Synthetic assignment','active','w2','2026-11-01','2026-11-30')");
    $pdo->exec("INSERT INTO jobdiva_connections(id,tenant_id,client_id,username,password_enc,status,sync_config) VALUES(1,11,'synthetic','synthetic',X'00','connected','{\"time\":{\"source\":\"jobdiva\",\"direction\":\"pull\"}}')");
    $pdo->exec("INSERT INTO external_entity_mappings(tenant_id,source_system,internal_entity_type,external_id,internal_entity_id,direction,sync_status) VALUES(11,'jobdiva','placement','start-100',100,'pull','ok')");
    $row=['id'=>'time-1','placementId'=>'start-100','date'=>'2026-11-09','hours'=>'8','category'=>'regular','description'=>'Synthetic original','approval'=>['status'=>'approved','actor'=>'external-1','at'=>'2026-11-10T10:00:00Z']];
    $apply=static fn(array $r)=>jobdivaTimeApplyRecord(11,90,$r,jobdivaTimeIntakeConnection($pdo,11));
    $fetchedConnection=jobdivaTimeIntakeConnection($pdo,11);$pdo->exec("UPDATE jobdiva_connections SET username='changed-during-fetch' WHERE id=1");
    $reject(fn()=>jobdivaTimeApplyRecord(11,90,$row,$fetchedConnection),'Account changed during provider fetch','being fetched');$pdo->exec("UPDATE jobdiva_connections SET username='synthetic' WHERE id=1");
    foreach([['category'=>'unknown'],['hours'=>'oops'],['date'=>'2026-02-30'],['date'=>'2026-12-01'],['placementId'=>'missing']]as$patch)$reject(fn()=>$apply(array_replace($row,$patch)),'Invalid or unresolved source');
    $pdo->exec("UPDATE jobdiva_connections SET sync_config='{\"time\":{\"source\":\"coreflux\",\"direction\":\"off\"}}' WHERE id=1");
    $reject(fn()=>$apply($row),'Disabled time intake','does not currently own');
    $pdo->exec("UPDATE jobdiva_connections SET sync_config='{\"time\":{\"source\":\"jobdiva\",\"direction\":\"pull\"}}' WHERE id=1");
    foreach(['locked','closed']as$status){$pdo->prepare('INSERT INTO time_periods(tenant_id,start_date,end_date,label,status) VALUES(11,"2026-11-01","2026-11-30","Synthetic protected period",?)')->execute([$status]);$reject(fn()=>$apply($row),'Protected period '.$status,'locked or closed');$pdo->exec('DELETE FROM time_periods');}
    $pdo->exec('INSERT INTO time_periods(tenant_id,period_type,start_date,end_date,label,status) VALUES(11,"monthly","2026-11-01","2026-11-30","Synthetic open month","open")');
    $pdo->exec('UPDATE core_record_numbers SET canonical_record_id=101');$reject(fn()=>$apply($row),'Retired canonical person identity','unavailable');$pdo->exec('UPDATE core_record_numbers SET canonical_record_id=100');
    $failure=static function(string $table,callable $run,?string $forbiddenHash=null)use($pdo,$reject,$row){
        $field=match($table){'jobdiva_time_intake'=>'source_hash','entity_sync_history'=>'content_hash_after','audit_log'=>'event'};
        $value=$table==='audit_log'?'jobdiva.time.intake':($forbiddenHash??mappingHash($row));
        $pdo->exec('ALTER TABLE '.$table.' ADD CONSTRAINT cf_jd_time_fixture_fail CHECK('.$field.'<>'.$pdo->quote($value).')');
        try{$reject($run,'Required '.$table.' write failure','cf_jd_time_fixture_fail');}
        finally{$pdo->exec('ALTER TABLE '.$table.' DROP CONSTRAINT cf_jd_time_fixture_fail');}
    };
    foreach(['jobdiva_time_intake','entity_sync_history','audit_log']as$table)$failure($table,fn()=>$apply($row));
    $saved=$apply($row);$id=$saved['id'];$before=$snapshot();$replay=$apply(array_reverse($row,true));
    $check($replay['replayed']&&$replay['receipt_id']===$saved['receipt_id']&&$snapshot()===$before,'Exact and reordered source replay adds no row, audit or timestamp update');
    $entry=$pdo->query('SELECT * FROM time_entries WHERE id='.$id)->fetch();$receipt=$pdo->query('SELECT * FROM jobdiva_time_intake')->fetch();
    $week=$pdo->query('SELECT period_start,period_end FROM staffing_timesheets')->fetch();
    $check($week===['period_start'=>'2026-11-09','period_end'=>'2026-11-15']&&(int)$pdo->query('SELECT COUNT(*) FROM time_periods')->fetchColumn()===1,'Monthly processing period retains a distinct seven-day staffing artifact');
    $check($entry['status']==='draft'&&$entry['approved_at']===null&&$entry['approved_by_user_id']===null&&$entry['source_system']==='jobdiva','Imported source remains an unapproved Core draft with explicit origin');
    $check(json_decode(decryptField($receipt['source_ct']),true)===$row&&$receipt['source_approval_state']==='unverified'&&!str_contains($receipt['source_ct'],'Synthetic original'),'Encrypted retained source preserves the original provider approval assertion without declaring it verified');
    $check((int)$receipt['people_tenant_id']===10&&(int)$receipt['placements_tenant_id']===10&&(int)$receipt['tenant_id']===11,'Shared directory identity and operating company remain distinct');
    $changed=array_replace($row,['hours'=>'7.5']);$next=$apply($changed);
    $check($next['id']===$id&&!$next['replayed']&&(int)$pdo->query('SELECT COUNT(*) FROM jobdiva_time_intake')->fetchColumn()===2&&(float)$pdo->query('SELECT total_hours FROM staffing_timesheets')->fetchColumn()===7.5,'Draft source revision keeps its stable entry and updates one weekly total with retained history');
    $reject(fn()=>$apply(array_replace($changed,['date'=>'2026-11-10'])),'Changed source date','explicit correction');
    $pdo->exec('UPDATE time_entries SET hours=6 WHERE id='.$id);$reject(fn()=>$apply(array_replace($changed,['hours'=>'5'])),'Competing Core edit','outside this intake');$pdo->exec('UPDATE time_entries SET hours=7.5 WHERE id='.$id);
    $pdo->exec("UPDATE time_entries SET status='approved',approved_at=NOW(),approved_by_user_id=91,rate_snapshot_id=99 WHERE id=".$id);
    $before=$snapshot();$check($apply($changed)['replayed']&&$snapshot()===$before,'Unchanged approved source replays without rewriting approval');
    $reject(fn()=>$apply(array_replace($changed,['hours'=>'6'])),'Changed approved source','independent reapproval');
    $pdo->exec("UPDATE time_entries SET status='draft',approved_at=NULL,approved_by_user_id=NULL,rate_snapshot_id=NULL WHERE id=".$id);
    $pdo->exec("UPDATE staffing_timesheets SET status='submitted'");$reject(fn()=>$apply(array_replace($changed,['hours'=>'6'])),'Submitted weekly artifact','submitted');$pdo->exec("UPDATE staffing_timesheets SET status='draft'");
    $pdo->exec('UPDATE time_entries SET payroll_extracted_ref=99 WHERE id='.$id);$reject(fn()=>$apply(array_replace($changed,['hours'=>'6'])),'Consumed draft','used downstream');$pdo->exec('UPDATE time_entries SET payroll_extracted_ref=NULL WHERE id='.$id);
    $pdo->prepare('INSERT INTO payroll_source_claims(time_tenant_id,time_entry_id,authority_id,placement_tenant_id,rate_snapshot_id) VALUES(11,?,"synthetic-held",10,99)')->execute([$id]);
    $reject(fn()=>$apply(array_replace($changed,['hours'=>'6'])),'Held payroll reservation','reserved for PurePayroll');$pdo->exec('DELETE FROM payroll_source_claims');
    $pdo->prepare('INSERT INTO time_downstream_feed(tenant_id,period_id,placement_id,bundle_type,entries_json) VALUES(11,?,100,"ar",?)')->execute([$entry['period_id'],json_encode(['entry_ids'=>[$id]])]);
    $reject(fn()=>$apply(array_replace($changed,['hours'=>'6'])),'Prepared downstream bundle','downstream bundle');$pdo->exec('DELETE FROM time_downstream_feed');
    $pdo->exec('INSERT INTO billing_invoices(id,tenant_id,invoice_number,client_name,issue_date,due_date) VALUES(1,11,"synthetic-1","Synthetic client","2026-11-09","2026-11-30")');
    $pdo->prepare('INSERT INTO billing_invoice_lines(invoice_id,source_type,source_ref_id,description) VALUES(1,"time",?,"Synthetic time")')->execute([$id]);
    $reject(fn()=>$apply(array_replace($changed,['hours'=>'6'])),'Native invoice lineage without extraction flags','linked financial document');$pdo->exec('DELETE FROM billing_invoice_lines');$pdo->exec('DELETE FROM billing_invoices');
    $pdo->exec('INSERT INTO ap_bills(id,tenant_id,bill_number,internal_ref,vendor_name,received_at,bill_date,due_date) VALUES(1,11,"synthetic-1","synthetic-1","Synthetic vendor","2026-11-09","2026-11-09","2026-11-30")');
    $pdo->prepare('INSERT INTO ap_bill_lines(bill_id,source_type,source_ref_id,description) VALUES(1,"time",?,"Synthetic time")')->execute([$id]);
    $reject(fn()=>$apply(array_replace($changed,['hours'=>'6'])),'Native bill lineage without extraction flags','linked financial document');$pdo->exec('DELETE FROM ap_bill_lines');$pdo->exec('DELETE FROM ap_bills');
    $pdo->prepare('INSERT INTO accounting_events(tenant_id,entity_id,event_type,source_module,source_record_id,event_date,payload) VALUES(11,1,"staffing.worker_hours.approved","staffing",?,"2026-11-09","{}")')->execute(['timesheet:'.$entry['timesheet_id'].':placement:100:w2']);
    $reject(fn()=>$apply(array_replace($changed,['hours'=>'6'])),'Existing accounting evidence','accounting event');$pdo->exec('DELETE FROM accounting_events');
    $observation=['observation'=>['status'=>'paid','period_start'=>'2026-11-01','period_end'=>'2026-11-15'],'comparison'=>['people'=>[['link_status'=>'linked','person'=>integrationRecordReference(integrationInstanceId(),10,'person','P-100')]]]];
    $pdo->prepare('INSERT INTO payroll_native_observations(tenant_id,connection_id,organization_id,run_id,observation_hash,core_source_hash,payload_ct,observed_by) VALUES(11,1,"synthetic-org","synthetic-run",?,?,?,90)')->execute([str_repeat('a',64),str_repeat('b',64),encryptField(json_encode($observation,JSON_THROW_ON_ERROR))]);
    $reject(fn()=>$apply(array_replace($changed,['hours'=>'6'])),'Historical payroll observation','payroll evidence');
    $reject(fn()=>$apply(array_replace($row,['id'=>'time-new-paid-period'])),'New source overlapping already paid person/date','payroll evidence');
    $pdo->exec('DELETE FROM payroll_native_observations');
    $pdo->exec("UPDATE jobdiva_connections SET username='other-source-account' WHERE id=1");$reject(fn()=>$apply($changed),'Changed JobDiva account','source, connection or directory');$pdo->exec("UPDATE jobdiva_connections SET username='synthetic' WHERE id=1");
    $pdo->exec("UPDATE tenant_module_scope SET scope_mode='isolated' WHERE tenant_id=11 AND module_key='people'");$reject(fn()=>$apply($changed),'Changed People directory','unavailable');$pdo->exec("UPDATE tenant_module_scope SET scope_mode='shared' WHERE tenant_id=11 AND module_key='people'");
    $reject(fn()=>jobdivaTimeApplyRecord(12,90,$row,$fetchedConnection),'Foreign company','does not currently own');
    $reject(fn()=>$apply(array_replace($row,['id'=>'time-2','hours'=>'17'])),'Daily capacity','exceed 24');
    $failure('jobdiva_time_intake',fn()=>$apply(array_replace($changed,['hours'=>'6'])),mappingHash(array_replace($changed,['hours'=>'6'])));
    $before=$snapshot();$pdo->exec('DELETE FROM jobdiva_time_intake');$reject(fn()=>$apply($changed),'Unreviewed legacy mapping','legacy time link');
}catch(Throwable $e){$report['failure']=$e->getMessage();}
finally{if($pdo->inTransaction())$pdo->rollBack();foreach(array_reverse($temporary)as$table)$pdo->exec('DROP TEMPORARY TABLE `'.$table.'`');}
$after=jtiSnapshot($pdo);
if($after!==$beforeOriginal)$report['failure']='Original database rows changed';
else{$report['original_tables_preserved']=count($beforeOriginal);$report['original_rows_preserved']=array_sum(array_column($beforeOriginal,'rows'));$report['temporary_tables_removed']=true;}
$report['passed']=!isset($report['failure']);$report['checks_passed']=count($report['checks']);
echo json_encode($report,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT);exit($report['passed']?0:1);
