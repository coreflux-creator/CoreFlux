<?php
/** One-shot browser acceptance, restricted to the existing synthetic P-387/PL-902. */
declare(strict_types=1);
const TB_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const TB_PRIVATE='/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030';
const TB_STATE=TB_PRIVATE.'/time-correction-browser-20261112.json';
const TB_BEFORE=TB_PRIVATE.'/time-correction-browser-before-20261112.private.json';
const TB_CREDENTIAL=TB_PRIVATE.'/time-correction-browser-credential-20261112.private.json';
const TB_SOURCE='3a18a0f9-6761-4a6e-be8f-0a5bcaa552c2';
const TB_ARTIFACT='b782c0fc-f028-4a01-982b-c7df232c91b4';
if(PHP_SAPI!=='cli'||getenv('COREFLUX_ENV')!=='staging'||getcwd()!==TB_ROOT||posix_geteuid()!==1003
 ||realpath(TB_PRIVATE)!==TB_PRIVATE||(fileperms(TB_PRIVATE)&0777)!==0700
 ||!in_array($argv[1]??'',['prepare','inspect','staffing-read','finish','retire'],true))exit(2);
require_once TB_ROOT.'/core/db.php';
require_once TB_ROOT.'/core/memberships.php';
require_once TB_ROOT.'/core/rbac/permissions.php';
require_once TB_ROOT.'/core/quanta/time_revisions.php';
require_once TB_ROOT.'/core/ai/artifacts.php';
$pdo=getDB();
if(!COREFLUX_STAGING||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'
 ||integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf'||COREFLUX_INTEGRATION_DELIVERY_TENANTS!=='2002'
 ||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=2002')->fetchColumn()!==1)exit(2);
setRequestTenantId(2002);setRequestModuleScope('time');
$pins=json_decode(file_get_contents(__DIR__.'/time-correction-runtime-pins.json'),true,512,JSON_THROW_ON_ERROR);
foreach($pins as$file=>$hash)if(hash('sha256',str_replace("\r\n","\n",file_get_contents(TB_ROOT.'/'.$file)))!==$hash)throw new RuntimeException('Runtime changed: '.$file);
function tbSave(string $path,array $data,bool $new=false):void{
 if(is_link($path)||realpath(dirname($path))!==TB_PRIVATE)throw new RuntimeException('Invalid checkpoint');
 $old=umask(0077);try{$f=fopen($path,$new?'x':'w');if(!$f)throw new RuntimeException('Existing or unavailable checkpoint');
 $s=json_encode($data,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT);if(fwrite($f,$s)!==strlen($s)||!fflush($f)||!fsync($f))throw new RuntimeException('Incomplete checkpoint');fclose($f);}finally{umask($old);}
}
function tbRow(string $table,int|string $id):array{global $pdo;$s=$pdo->prepare('SELECT * FROM `'.$table.'` WHERE tenant_id=2002 AND id=?');$s->execute([$id]);return $s->fetch(PDO::FETCH_ASSOC)?:[];}
function tbSnapshot():array{
 global $pdo;$out=[];
 foreach($pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN)as$table){
  if(!preg_match('/^[A-Za-z0-9_]+$/D',$table))throw new RuntimeException('Invalid table');$rows=[];
  foreach($pdo->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC)as$row){
   if((int)($row['tenant_id']??0)===2002){
    if($table==='time_entries'&&(int)$row['id']===16)foreach(['status','superseded_by_id','updated_at']as$f)unset($row[$f]);
    if($table==='staffing_timesheets'&&(int)$row['id']===8)foreach(['status','total_hours','submitted_at','approved_at','approved_by_user_id','approved_via','updated_at']as$f)unset($row[$f]);
    if($table==='quanta_time_imports'&&$row['quanta_entry_id']===TB_SOURCE)continue;
    if($table==='artifact_objects'&&$row['id']===TB_ARTIFACT)continue;
   }
   $rows[]=hash('sha256',serialize($row));
  }sort($rows,SORT_STRING);$out[$table]=$rows;
 }ksort($out);return $out;
}
function tbExpect(bool $yes,string $message):void{if(!$yes)throw new RuntimeException($message);}
$action=$argv[1];
if($action==='prepare'){
 tbExpect(!file_exists(TB_STATE)&&!file_exists(TB_BEFORE)&&!file_exists(TB_CREDENTIAL),'Inspect existing browser checkpoint; do not repeat preparation');
 $old=tbRow('time_entries',16);$header=tbRow('staffing_timesheets',8);$artifact=tbRow('artifact_objects',TB_ARTIFACT);
 tbExpect($old['status']==='approved'&&(float)$old['hours']===1.5&&(int)$old['approved_by_user_id']===90&&(int)$old['person_id']===387&&(int)$old['placement_id']===902,'Current synthetic approval differs');
 tbExpect($header['status']==='approved'&&(float)$header['total_hours']===1.5&&$header['artifact_id']===TB_ARTIFACT,'Current header differs');
 $s=$pdo->prepare('SELECT * FROM quanta_time_imports WHERE tenant_id=2002 AND quanta_entry_id=?');$s->execute([TB_SOURCE]);$imports=$s->fetchAll(PDO::FETCH_ASSOC);
 tbExpect(count($imports)===1&&(int)$imports[0]['time_entry_id']===16&&(int)$imports[0]['is_current']===1,'Current source differs');
 tbSave(TB_BEFORE,['tables'=>tbSnapshot(),'entry'=>$old,'header'=>$header,'artifact'=>$artifact,'imports'=>$imports,'revision_count'=>(int)$pdo->query('SELECT COUNT(*) FROM quanta_time_revisions WHERE tenant_id=2002')->fetchColumn()],true);
 $report=['started_at'=>gmdate('c'),'phase'=>'preparing','passed'=>false,'actors'=>[], 'feature_sha256'=>hash_file('sha256',dirname(TB_ROOT).'/private_html/coreflux.thunderhawk-pilot.php'),
 'tenant_id'=>2002,'person_number'=>'P-387','placement_number'=>'PL-902','original_entry_id'=>16,'source_entry_id'=>TB_SOURCE,'production_changed'=>false,'arabella_changed'=>false,'financial_execution'=>false];
 tbSave(TB_STATE,$report,true);$credentials=[];
 foreach(['corrector'=>['people'=>'read','placements'=>'read','time'=>'admin','integrations'=>'admin'],'approver'=>['people'=>'read','placements'=>'read','time'=>'admin']]as$role=>$grants){
  $email='core-time-browser-'.$role.'-20261112@example.invalid';$s=$pdo->prepare('SELECT COUNT(*) FROM users WHERE email=?');$s->execute([$email]);tbExpect(!(int)$s->fetchColumn(),'Reserved actor exists');
  $password=bin2hex(random_bytes(32));$hash=password_hash($password,PASSWORD_DEFAULT);
  $pdo->beginTransaction();try{
   $pdo->prepare('INSERT INTO users(tenant_id,name,email,password,password_hash,role,is_active,is_global_admin) VALUES(2002,?,?,?, ?,"admin",1,0)')->execute(['Synthetic time '.$role,$email,$hash,$hash]);$id=(int)$pdo->lastInsertId();
   $membership=(int)provisionMembership($id,2002,'admin',['is_primary'=>true,'persona_label'=>'Synthetic time '.$role,'status'=>'active'])['membership_id'];$pdo->commit();
  }catch(Throwable$e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}
  $report['actors'][$role]=['id'=>$id,'membership_id'=>$membership,'email'=>$email,'grants'=>$grants];tbSave(TB_STATE,$report);
  foreach($grants as$module=>$level)RBACResolver::grantModule($membership,$module,$level,[2002],4);
  foreach(['payroll','billing','ap','accounting','treasury']as$module)tbExpect(!RBACResolver::can($id,2002,$module,'read'),'Unexpected financial access');
  $credentials[$role]=['username'=>$email,'password'=>$password];unset($password,$hash);
 }tbSave(TB_CREDENTIAL,$credentials,true);unset($credentials);$report['phase']='browser_ready';tbSave(TB_STATE,$report);
 echo json_encode(['phase'=>$report['phase'],'actors'=>$report['actors'],'credential_printed'=>false]);exit;
}
tbExpect(is_file(TB_STATE)&&is_file(TB_BEFORE),'No browser checkpoint');
$report=json_decode(file_get_contents(TB_STATE),true,512,JSON_THROW_ON_ERROR);$before=json_decode(file_get_contents(TB_BEFORE),true,512,JSON_THROW_ON_ERROR);
if($action==='staffing-read'){
 tbExpect($report['phase']==='browser_ready'&&!isset($report['actors']['approver']['grants']['staffing']),'Inspect the existing read grant');
 $actor=$report['actors']['approver'];$report['actors']['approver']['grants']['staffing']='read';tbSave(TB_STATE,$report);
 RBACResolver::grantModule($actor['membership_id'],'staffing','read',[2002],4);
 tbExpect(RBACResolver::can($actor['id'],2002,'staffing','read',2002),'Staffing summary read missing');
 tbExpect(!RBACResolver::can($actor['id'],2002,'staffing','write',2002),'Staffing write unexpectedly granted');
 echo json_encode(['staffing_read_added'=>true,'actor_id'=>$actor['id'],'tenant_id'=>2002]);exit;
}
$s=$pdo->prepare('SELECT * FROM quanta_time_revisions WHERE tenant_id=2002 AND source_entry_id=? ORDER BY id');$s->execute([TB_SOURCE]);$revisions=$s->fetchAll(PDO::FETCH_ASSOC);
$latest=end($revisions);$receipt=$latest?quantaTimeRevisionReceipt($latest):null;$newId=$receipt?(int)array_values($receipt['result']['new_entry_ids'])[0]:0;
if($action==='inspect'){echo json_encode(['phase'=>$report['phase'],'actors'=>$report['actors'],'current_entry'=>tbRow('time_entries',$newId),'revision'=>$receipt,'header'=>tbRow('staffing_timesheets',8)]);exit;}
tbExpect(!in_array($report['phase'],['complete','retired'],true),'Browser checkpoint already terminal');
if($action==='finish'){
 tbExpect(count($revisions)===$before['revision_count']+1&&(int)$latest['actor_user_id']===$report['actors']['corrector']['id'],'Exactly one browser correction required');
 $saved=json_decode(decryptField($latest['before_ct']),true,512,JSON_THROW_ON_ERROR);
 tbExpect(integrationDeliveryHash($saved['prior']['entries']['regular'])===integrationDeliveryHash($before['entry']),'Original entry receipt changed');
 tbExpect(integrationDeliveryHash(array_values($saved['prior']['imports']))===integrationDeliveryHash($before['imports']),'Original import receipt changed');
 $old=tbRow('time_entries',16);tbExpect($old['status']==='superseded'&&(int)$old['superseded_by_id']===$newId,'Prior entry has no replacement');
 $new=tbRow('time_entries',$newId);tbExpect($new['status']==='approved'&&(float)$new['hours']===2.0&&(int)$new['created_by_user_id']===$report['actors']['corrector']['id']
  &&(int)$new['approved_by_user_id']===$report['actors']['approver']['id']&&(int)$new['rate_snapshot_id']===23&&(int)$new['person_id']===387&&(int)$new['placement_id']===902&&(int)$new['timesheet_id']===8
  &&$new['source']==='quanta'&&$new['source_system']==='quanta'&&$new['work_date']==='2026-11-09','Replacement independent approval differs');
 $header=staffingTimesheetRow(2002,8);$artifact=artifactGet(2002,TB_ARTIFACT);
 tbExpect($header['status']==='approved'&&(float)$header['total_hours']===2.0&&(int)$header['approved_by_user_id']===$report['actors']['approver']['id'],'Header not reconciled');
 tbExpect($artifact['status']===staffingTimesheetArtifactStatus($header['status'])&&artifactComparableJson($artifact['payload'])===artifactComparableJson(staffingTimesheetArtifactPayload($header)),'Artifact not reconciled');
 $s=$pdo->prepare('SELECT * FROM quanta_time_imports WHERE tenant_id=2002 AND quanta_entry_id=?');$s->execute([TB_SOURCE]);$imports=$s->fetchAll(PDO::FETCH_ASSOC);
 tbExpect(count($imports)===1&&(int)$imports[0]['id']===(int)$before['imports'][0]['id']&&(int)$imports[0]['time_entry_id']===$newId&&(int)$imports[0]['is_current']===1,'Current source mapping differs');
 $s=$pdo->prepare('SELECT COUNT(*) FROM quanta_time_approval_evidence WHERE tenant_id=2002 AND quanta_time_import_id=?');$s->execute([$imports[0]['id']]);tbExpect((int)$s->fetchColumn()===3,'All three source approvals required');
 $report['new_entry_id']=$newId;$report['revision_id']=(int)$latest['id'];$report['current_hours']=2;$report['independent_test_approver']=true;$report['original_approval_preserved']=true;
 $report['browser_workflow_verified']=true;
}
foreach($report['actors']as$actor){
 foreach($actor['grants']as$module=>$level)RBACResolver::revokeModule($actor['membership_id'],$module,4);
 deactivateMembership($actor['id'],2002);
 $pdo->prepare('UPDATE users SET is_active=0,password=NULL,password_hash=NULL WHERE tenant_id=2002 AND id=? AND email=?')->execute([$actor['id'],$actor['email']]);
 $s=$pdo->prepare('SELECT COUNT(*) FROM membership_module_access WHERE membership_id=?');$s->execute([$actor['membership_id']]);tbExpect(!(int)$s->fetchColumn(),'Actor grants remain');
}
if(is_file(TB_CREDENTIAL))tbExpect(!is_link(TB_CREDENTIAL)&&unlink(TB_CREDENTIAL),'Credential removal failed');
$report['actors_retired']=true;tbSave(TB_STATE,$report);
$after=tbSnapshot();tbExpect(array_keys($before['tables'])===array_keys($after),'Schema changed');$changed=[];
$allowed=['time_entries','quanta_time_approval_evidence','quanta_time_revisions','artifact_events','integration_deliveries','integration_delivery_subjects','users','tenant_memberships','user_tenants','membership_audit','audit_log','rbac_bridge_audit'];
foreach($before['tables']as$table=>$hashes){if($hashes===$after[$table])continue;tbExpect(in_array($table,$allowed,true),'Unexpected table change: '.$table);
 $counts=array_count_values($after[$table]);foreach(array_count_values($hashes)as$hash=>$n)tbExpect(($counts[$hash]??0)>=$n,'Original record changed: '.$table);
 $changed[$table]=count($after[$table])-count($hashes);
}
tbExpect(hash_file('sha256',dirname(TB_ROOT).'/private_html/coreflux.thunderhawk-pilot.php')===$report['feature_sha256'],'Operational switches changed');
$report['tables_verified']=count($after);$report['original_rows_checked']=array_sum(array_map('count',$before['tables']));$report['added_rows']=$changed;
$report['finished_at']=gmdate('c');$report['passed']=$action==='finish';$report['phase']=$action==='finish'?'complete':'retired';tbSave(TB_STATE,$report);echo json_encode($report,JSON_THROW_ON_ERROR);
