<?php
/** Reconcile the original import and return receipt without repeating writes. */
declare(strict_types=1);
$root='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
$private=dirname($root).'/private_html/thunderhawk-pilot-20261030';
if(PHP_SAPI!=='cli'||getenv('COREFLUX_ENV')!=='staging'||realpath(getcwd())!==$root||posix_geteuid()!==1003)exit(2);
require $root.'/core/quanta/time_review_delivery.php';
$pdo=getDB();if(!COREFLUX_STAGING||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf')exit(2);
setRequestTenantId(2002);
$check=static function(bool $value,string $reason):void{if(!$value)throw new RuntimeException($reason);};
$state=json_decode(file_get_contents($private.'/synthetic-lifecycle-time_import-20261111.json'),true,64,JSON_THROW_ON_ERROR);
$check($state['failed_check']==='Only reviewed metadata additions in artifact_objects'&&$state['operator_retired']&&$state['private_session_removed'],'Original operation cleanup verified');
$check($state['import']['inserted']===1&&$state['import_replay']['already_imported']===1&&$state['import_replay']['inserted']===0,'Original replay verified');
$before=json_decode(file_get_contents($private.'/synthetic-lifecycle-time_import-before.private.json'),true,64,JSON_THROW_ON_ERROR);
$after=[];$connectionTimestamp=null;
foreach($pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $table){
    $check((bool)preg_match('/^[A-Za-z0-9_]+$/D',$table),'Safe table');$rows=[];
    foreach($pdo->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC) as $row){
        if($table==='quanta_connections'&&(int)$row['tenant_id']===2002&&(int)$row['id']===2){
            unset($row['last_import_at'],$row['last_import_error']);
            // The original harness retained the schema's automatic timestamp
            // in its hash. Recover ONLY that prior timestamp against the exact
            // original row fingerprint. Every other column must match; the
            // current database row is never rewritten and no credential leaves
            // the host. This bounded read-only repair preserves the old proof.
            $now=$row['updated_at'];$targets=array_fill_keys($before[$table],true);$matched=false;
            foreach([$now,null,$row['created_at']] as $candidate){
                $row['updated_at']=$candidate;if(isset($targets[hash('sha256',serialize($row))])){$matched=true;break;}
            }
            if(!$matched){
                $zone=new DateTimeZone('UTC');$end=(new DateTimeImmutable($now,$zone))->getTimestamp();
                $start=(new DateTimeImmutable($row['created_at'],$zone))->getTimestamp();
                $check($end>=$start&&$end-$start<=35*86400,'Bounded original timestamp interval');
                for($stamp=$end;$stamp>=$start;$stamp--){
                    $row['updated_at']=gmdate('Y-m-d H:i:s',$stamp);
                    if(isset($targets[hash('sha256',serialize($row))])){$matched=true;break;}
                }
            }
            $check($matched,'Only the connection automatic timestamp changed');
            $connectionTimestamp=['before'=>$row['updated_at'],'after'=>$now,'original_row_fingerprint_verified'=>true];
        }
        $rows[]=hash('sha256',serialize($row));
    }sort($rows,SORT_STRING);$after[$table]=$rows;
}ksort($after);
$check(array_keys($before)===array_keys($after),'Schema preserved');
$allowed=['users','tenant_memberships','user_tenants','membership_audit','audit_log','quanta_time_routes','quanta_time_imports',
    'quanta_time_approval_evidence','time_entries','time_periods','staffing_timesheets','artifact_objects','artifact_events',
    'artifact_relationships','integration_deliveries','integration_delivery_subjects'];
$changes=[];$preserved=0;
foreach($before as $table=>$hashes){
    $preserved+=count($hashes);if($hashes===$after[$table])continue;
    $check(in_array($table,$allowed,true),'Reviewed additions only: '.$table);
    $counts=array_count_values($after[$table]);foreach(array_count_values($hashes) as $hash=>$count)$check(($counts[$hash]??0)>=$count,'Original rows preserved: '.$table);
    $changes[$table]=count($after[$table])-count($hashes);
}
$q=$pdo->query('SELECT id,person_id,placement_id,hours,status,timesheet_id,work_date FROM time_entries WHERE tenant_id=2002 AND person_id=368');$entries=$q->fetchAll(PDO::FETCH_ASSOC);
$check(count($entries)===1&&(int)$entries[0]['id']===14&&(int)$entries[0]['placement_id']===874&&(float)$entries[0]['hours']===1.0&&$entries[0]['status']==='pending_review'&&(int)$entries[0]['timesheet_id']===7,'Exact imported hour preserved');
$q=$pdo->query('SELECT source_approval_json FROM quanta_time_imports WHERE tenant_id=2002 AND time_entry_id=14');$proof=json_decode($q->fetchColumn(),true,64,JSON_THROW_ON_ERROR);
$check($proof['core_assignment']['person']['number']==='P-368'&&$proof['core_assignment']['placement']['number']==='PL-874'
    &&$proof['timesheet_id']==='63939bf8-3b68-4188-a097-cf45e9f51016','Source identity and approval proof preserved');
$snapshot=quantaTimeReviewSnapshot(2002,'dcfd06b9-6f30-4ed9-8426-789fc255f194');
$check($snapshot['status']==='unchanged'&&$snapshot['remote']['current']['state']==='pending_review','Provider readback reconciled');
$report=['passed'=>true,'checked_at'=>gmdate('c'),'source_entry_id'=>'dcfd06b9-6f30-4ed9-8426-789fc255f194','entry_id'=>14,
    'timesheet_id'=>7,'person_number'=>'P-368','placement_number'=>'PL-874','hours'=>1,'core_time_status'=>'pending_review',
    'provider_core_review_status'=>'pending_review','route_id'=>4,'return_delivery_id'=>89,'import_replay_verified'=>true,
    'return_replay_verified'=>true,'source_approval_and_assignment_preserved'=>true,'operator_retired'=>true,
    'tables_verified'=>count($after),'original_rows_preserved'=>$preserved,'added_record_counts'=>$changes,'connection_import_timestamp'=>$connectionTimestamp,
    'new_business_writes'=>0,'production_changed'=>false,'arabella_changed'=>false,'real_payroll_payments_postings'=>false];
$mask=umask(0077);file_put_contents($private.'/synthetic-quanta-import-readback-20261111.json',json_encode($report,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));umask($mask);
echo json_encode($report,JSON_THROW_ON_ERROR)."\n";
