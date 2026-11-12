<?php
/** Exact disposable CI schema; no JobDiva requests or real financial work. */
declare(strict_types=1);
if(getenv('CI')!=='true'||getenv('GITHUB_ACTIONS')!=='true')throw new RuntimeException('Isolated CI only');
putenv('COREFLUX_DATA_KEY='.base64_encode(random_bytes(32)));
putenv('COREFLUX_INTEGRATION_INSTANCE_ID=11111111-1111-4111-8111-111111111111');
require_once __DIR__.'/../core/db.php';
require_once __DIR__.'/../core/jobdiva/sync_time.php';
$pdo=getDB();if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='coreaccounting_ci')throw new RuntimeException('Exact CI database required');
$fixture='coreflux_jobdiva_time_ci';$pdo->exec('CREATE DATABASE '.$fixture);$pdo->exec('USE '.$fixture);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);setRequestTenantId(11);
$passed=0;$check=static function(bool $ok,string $label)use(&$passed){if(!$ok)throw new RuntimeException($label);$passed++;echo "PASS {$label}\n";};
$tables=['tenants','tenant_module_scope','people','placements','core_record_numbers','jobdiva_connections','tenant_staffing_settings','time_periods','time_entries','staffing_timesheets','external_entity_mappings','entity_sync_history','jobdiva_sync_audit','audit_log','jobdiva_time_intake','artifact_objects','artifact_events','artifact_relationships','payroll_source_claims','time_downstream_feed','billing_invoices','billing_invoice_lines','ap_bills','ap_bill_lines','accounting_events','payroll_native_observations'];
$snapshot=static function()use($pdo,$tables){$out=[];foreach($tables as$table)$out[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);return$out;};
$reject=static function(callable $run,string $label,string $message='')use($snapshot,$check){$before=$snapshot();try{$run();}catch(Throwable $e){if($message!==''&&!str_contains($e->getMessage(),$message))throw$e;$check($snapshot()===$before,$label.' leaves every record unchanged');return;}throw new RuntimeException('Accepted '.$label);};
try{
    foreach($tables as$table)$pdo->exec('CREATE TABLE '.$table.' LIKE coreaccounting_ci.'.$table);
    $pdo->exec("INSERT INTO tenants(id,name,parent_id,tenant_type,is_active) VALUES(10,'Synthetic owner',NULL,'master',1),(11,'Synthetic company',10,'sub',1),(12,'Synthetic foreign',NULL,'master',1)");
    $pdo->exec("INSERT INTO tenant_module_scope(tenant_id,module_key,scope_mode) VALUES(11,'people','shared'),(11,'placements','shared')");
    $pdo->exec("INSERT INTO people(id,tenant_id,first_name,last_name,email_primary,classification,status) VALUES(100,10,'Synthetic','Time','synthetic-time@example.invalid','w2','active')");
    $pdo->exec("INSERT INTO core_record_numbers(tenant_id,entity_type,record_id,record_number) VALUES(10,'person',100,'P-100')");
    $pdo->exec("INSERT INTO placements(id,tenant_id,person_id,title,status,engagement_type,start_date,end_date) VALUES(100,10,100,'Synthetic assignment','active','w2','2026-11-01','2026-11-30')");
    $pdo->exec("INSERT INTO jobdiva_connections(id,tenant_id,client_id,username,password_enc,status,sync_config) VALUES(1,11,'synthetic','synthetic',X'00','connected','{\"time\":{\"source\":\"jobdiva\",\"direction\":\"pull\"}}')");
    $pdo->exec("INSERT INTO external_entity_mappings(tenant_id,source_system,internal_entity_type,external_id,internal_entity_id,direction,sync_status) VALUES(11,'jobdiva','placement','start-100',100,'pull','ok')");
    $row=['id'=>'time-1','placementId'=>'start-100','date'=>'2026-11-09','hours'=>'8','category'=>'regular','description'=>'Synthetic original','approval'=>['status'=>'approved','actor'=>'external-1','at'=>'2026-11-10T10:00:00Z']];
    $apply=static fn(array $r)=>jobdivaTimeApplyRecord(11,90,$r);
    foreach([['category'=>'unknown'],['hours'=>'oops'],['date'=>'2026-02-30'],['date'=>'2026-12-01'],['placementId'=>'missing']]as$patch)$reject(fn()=>$apply(array_replace($row,$patch)),'Invalid or unresolved source');
    $pdo->exec("UPDATE jobdiva_connections SET sync_config='{\"time\":{\"source\":\"coreflux\",\"direction\":\"off\"}}' WHERE id=1");
    $reject(fn()=>$apply($row),'Disabled time intake','does not currently own');
    $pdo->exec("UPDATE jobdiva_connections SET sync_config='{\"time\":{\"source\":\"jobdiva\",\"direction\":\"pull\"}}' WHERE id=1");
    foreach(['locked','closed']as$status){$pdo->prepare('INSERT INTO time_periods(tenant_id,start_date,end_date,label,status) VALUES(11,"2026-11-01","2026-11-30","Synthetic protected period",?)')->execute([$status]);$reject(fn()=>$apply($row),'Protected period '.$status,'locked or closed');$pdo->exec('DELETE FROM time_periods');}
    $pdo->exec('INSERT INTO time_periods(tenant_id,period_type,start_date,end_date,label,status) VALUES(11,"monthly","2026-11-01","2026-11-30","Synthetic open month","open")');
    $failure=static function(string $table,callable $run)use($pdo,$reject){$pdo->exec("CREATE TRIGGER fail_jobdiva_time BEFORE INSERT ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic required intake failure'");try{$reject($run,'Required '.$table.' write failure','Synthetic required intake failure');}finally{$pdo->exec('DROP TRIGGER fail_jobdiva_time');}};
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
    $pdo->exec("UPDATE time_entries SET payroll_extracted_ref='existing-payroll' WHERE id=".$id);$reject(fn()=>$apply(array_replace($changed,['hours'=>'6'])),'Consumed draft','used downstream');$pdo->exec('UPDATE time_entries SET payroll_extracted_ref=NULL WHERE id='.$id);
    $pdo->exec("UPDATE jobdiva_connections SET username='other-source-account' WHERE id=1");$reject(fn()=>$apply($changed),'Changed JobDiva account','source, connection or directory');$pdo->exec("UPDATE jobdiva_connections SET username='synthetic' WHERE id=1");
    $pdo->exec("UPDATE tenant_module_scope SET scope_mode='isolated' WHERE tenant_id=11 AND module_key='people'");$reject(fn()=>$apply($changed),'Changed People directory','unavailable');$pdo->exec("UPDATE tenant_module_scope SET scope_mode='shared' WHERE tenant_id=11 AND module_key='people'");
    $reject(fn()=>jobdivaTimeApplyRecord(12,90,$row),'Foreign company','does not currently own');
    $reject(fn()=>$apply(array_replace($row,['id'=>'time-2','hours'=>'17'])),'Daily capacity','exceed 24');
    $failure('jobdiva_time_intake',fn()=>$apply(array_replace($changed,['hours'=>'6'])));
    $before=$snapshot();$pdo->exec('DELETE FROM jobdiva_time_intake');$reject(fn()=>$apply($changed),'Unreviewed legacy mapping','legacy time link');
    echo "PASS {$passed} native JobDiva time intake checks\n";
}finally{if($pdo->inTransaction())$pdo->rollBack();$pdo->exec('USE coreaccounting_ci');$pdo->exec('DROP DATABASE '.$fixture);}
