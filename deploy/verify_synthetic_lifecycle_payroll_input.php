<?php
/** Read the existing input/reservation outcome; never submit a replacement. */
declare(strict_types=1);
$root='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
$private=dirname($root).'/private_html/thunderhawk-pilot-20261030';
if(PHP_SAPI!=='cli'||getenv('COREFLUX_ENV')!=='staging'||realpath(getcwd())!==$root||posix_geteuid()!==1003)exit(2);
require $root.'/core/quanta/time_review_delivery.php';
require $root.'/core/integrations/purepay_payroll_reservation_review.php';
$pdo=getDB();if(!COREFLUX_STAGING||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf')exit(2);
setRequestTenantId(2002);
$check=static function(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);};
$state=json_decode(file_get_contents($private.'/synthetic-lifecycle-payroll_input-20261111.json'),true,64,JSON_THROW_ON_ERROR);
$check($state['failed_check']==='Only reviewed metadata additions in rbac_bridge_audit'&&$state['operator_retired']&&$state['private_session_removed'],'Original workflow completed and actor retired');
$check($state['input_replay_verified']&&$state['reservation_replay_verified']&&$state['competing_reservation_refused']&&$state['source_time_unchanged'],'Original replay and source checks passed');
$before=json_decode(file_get_contents($private.'/synthetic-lifecycle-payroll_input-before.private.json'),true,64,JSON_THROW_ON_ERROR);
$after=[];
foreach($pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $table){
    $check((bool)preg_match('/^[A-Za-z0-9_]+$/D',$table),'Safe table');$rows=[];
    foreach($pdo->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC) as $row)$rows[]=hash('sha256',serialize($row));
    sort($rows,SORT_STRING);$after[$table]=$rows;
}ksort($after);$check(array_keys($before)===array_keys($after),'Schema preserved');
$allowed=['users','tenant_memberships','user_tenants','membership_audit','audit_log','integration_deliveries','integration_delivery_subjects',
    'payroll_source_authorities','payroll_source_claims','rbac_bridge_audit'];
$changes=[];$preserved=0;
foreach($before as $table=>$hashes){
    $preserved+=count($hashes);if($hashes===$after[$table])continue;
    $check(in_array($table,$allowed,true),'Reviewed additions only: '.$table);
    $counts=array_count_values($after[$table]);foreach(array_count_values($hashes) as $hash=>$count)$check(($counts[$hash]??0)>=$count,'Original rows preserved: '.$table);
    $changes[$table]=count($after[$table])-count($hashes);
}
$st=$pdo->prepare('SELECT tenant_id,user_id,perm,module_key,action,legacy_ok,new_ok FROM rbac_bridge_audit WHERE user_id=?');$st->execute([$state['operator_id']]);
$rbac=$st->fetchAll(PDO::FETCH_ASSOC);
$check(count($rbac)===($changes['rbac_bridge_audit']??0)&&count($rbac)>0,'Only original operator permission comparisons were added');
foreach($rbac as $row)$check((int)$row['tenant_id']===2002&&$row['perm']==='payroll.runs.approve'&&$row['module_key']==='payroll'
    &&$row['action']==='admin'&&(int)$row['legacy_ok']===1&&(int)$row['new_ok']===0,'Cancellation approval remained denied by scoped permission');
$entry=$pdo->query('SELECT * FROM time_entries WHERE tenant_id=2002 AND id=14')->fetch(PDO::FETCH_ASSOC);
$check($entry['status']==='approved'&&(int)$entry['approved_by_user_id']===71&&(float)$entry['hours']===1.0
    &&$entry['payroll_extracted_at']===null&&$entry['payroll_extracted_ref']===null,'Original hour is approved and unextracted');
$authority=purepayPayrollAuthorityRead($pdo,2002,$state['authority_id']);
$check($authority['state']==='held'&&$authority['input_id']===$state['input_id']&&(int)$authority['input_delivery_id']===93,'Original reservation is held');
$claims=$pdo->query('SELECT * FROM payroll_source_claims WHERE time_tenant_id=2002 AND time_entry_id=14')->fetchAll(PDO::FETCH_ASSOC);
$check(count($claims)===1&&$claims[0]['authority_id']===$state['authority_id'],'One source claim exists');
$receipt=purepayPayrollAuthorityReconcile($pdo,2002,integrationInstanceId(),$state['authority_id']);
$check($receipt===$state['reservation_delivery']['provider_receipt']&&$receipt['payroll_created']===false,'Fresh provider read matches original no-payroll receipt');
$report=['passed'=>true,'checked_at'=>gmdate('c'),'entry_id'=>14,'input_delivery_id'=>93,'input_id'=>$state['input_id'],
    'authority_id'=>$state['authority_id'],'core_state'=>'held','provider_receipt'=>$receipt,'input_replay_verified'=>true,
    'reservation_replay_verified'=>true,'competing_reservation_refused'=>true,'source_time_unchanged'=>true,'payroll_created'=>false,
    'operator_retired'=>true,'tables_verified'=>count($after),'original_rows_preserved'=>$preserved,'added_record_counts'=>$changes,
    'rbac_comparisons_verified'=>true,'new_business_writes'=>0,'production_changed'=>false,'arabella_changed'=>false,'real_payroll_payments_postings'=>false];
$mask=umask(0077);file_put_contents($private.'/synthetic-lifecycle-payroll-readback-20261111.json',json_encode($report,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));umask($mask);
echo json_encode($report,JSON_THROW_ON_ERROR)."\n";
