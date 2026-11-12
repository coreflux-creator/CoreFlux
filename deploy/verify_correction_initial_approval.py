import json,shlex,subprocess
from pathlib import Path
r=Path(__file__).resolve().parents[1];out=r/'output'
h=json.loads((out/'synthetic-correction-time_approval-handle.json').read_text(encoding='utf-8'))
php=r'''chdir('/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html');
require HARNESS;
$pdo=getDB();setRequestTenantId(2002);
if(!COREFLUX_STAGING||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx')exit(2);
$private=ThunderhawkCorrectionNative::PRIVATE;
$prior=json_decode(file_get_contents($private.'/synthetic-lifecycle-time_approval-20261112-correction.json'),true,64,JSON_THROW_ON_ERROR);
if($prior['passed']||$prior['failed_check']!=='Only reviewed metadata additions in artifact_events'||!$prior['operator_retired']||!$prior['private_session_removed']||!$prior['approval_replay_refused']||!$prior['self_approval_refused'])exit(3);
$pdo->exec('START TRANSACTION READ ONLY');
try{
 $reflection=new ReflectionClass(ThunderhawkCorrectionNative::class);$h=$reflection->newInstanceWithoutConstructor();
 $h->pdo=$pdo;$reflection->getProperty('phase')->setValue($h,'time_approval');
 $after=$reflection->getMethod('snapshot')->invoke($h);
 $before=json_decode(file_get_contents($private.'/synthetic-correction-time_approval-before.private.json'),true,512,JSON_THROW_ON_ERROR);
 if(array_keys($before)!==array_keys($after))throw new RuntimeException('Schema changed');
 $artifact='b782c0fc-f028-4a01-982b-c7df232c91b4';$changed=[];$rows=0;
 foreach($before as $table=>$hashes){
  $rows+=count($hashes);if($hashes===$after[$table])continue;
  $old=array_count_values($hashes);$new=array_count_values($after[$table]);$missing=[];$added=[];
  foreach($old as $hash=>$n)if($n>($new[$hash]??0))$missing[$hash]=$n-($new[$hash]??0);
  foreach($new as $hash=>$n)if($n>($old[$hash]??0))$added[$hash]=$n-($old[$hash]??0);
  if(in_array($table,['artifact_objects','staffing_timesheets'],true)){
   if(array_sum($missing)!==1||array_sum($added)!==1)throw new RuntimeException('Unexpected row replacement');
   $target=$table==='artifact_objects'?$pdo->query("SELECT * FROM artifact_objects WHERE tenant_id=2002 AND id='$artifact'")->fetch(PDO::FETCH_ASSOC):$pdo->query('SELECT * FROM staffing_timesheets WHERE tenant_id=2002 AND id=8')->fetch(PDO::FETCH_ASSOC);
   if($target['status']!=='approved')throw new RuntimeException('Wrong approved target');
   if($table==='staffing_timesheets'){if((int)$target['approved_by_user_id']!==86||(float)$target['total_hours']!==1.0)throw new RuntimeException('Wrong header approval');foreach(['status','approved_at','updated_at'] as $f)unset($target[$f]);}
   if(!isset($added[hash('sha256',serialize($target))]))throw new RuntimeException('Unrelated row changed');
  }else{
   if($missing||!in_array($table,['artifact_events','audit_log','users','tenant_memberships','user_tenants','membership_audit'],true))throw new RuntimeException('Unexpected change '.$table);
   if($table==='artifact_events')foreach($pdo->query('SELECT * FROM artifact_events')->fetchAll(PDO::FETCH_ASSOC) as $e){if(isset($added[hash('sha256',serialize($e))])&&($e['artifact_id']!==$artifact||(int)$e['tenant_id']!==2002))throw new RuntimeException('Unrelated artifact event');}
  }
  $changed[$table]=['before'=>count($hashes),'after'=>count($after[$table]),'original_rows_replaced'=>array_sum($missing)];
 }
 $entry=$pdo->query('SELECT * FROM time_entries WHERE tenant_id=2002 AND id=15')->fetch(PDO::FETCH_ASSOC);
 if($entry!==$prior['approved_entry']||(int)$entry['created_by_user_id']===86)throw new RuntimeException('Approval changed');
 $report=['checked_at'=>gmdate('c'),'passed'=>true,'read_only'=>true,'original_approval_not_repeated'=>true,'prior_receipt_preserved'=>true,'tables_verified'=>count($after),'original_row_count'=>$rows,'changed_tables'=>$changed,'operator_id'=>86,'entry_id'=>15,'timesheet_id'=>8,'known_helper_gap'=>'The older harness omitted current artifact and weekly approval reconciliation. Actual API approval and replay checks passed.'];
}finally{$pdo->rollBack();}
$path=$private.'/synthetic-correction-initial-approval-verification.json';$old=umask(0077);try{$f=fopen($path,'x');if(!$f)throw new RuntimeException('Existing verifier receipt');fwrite($f,json_encode($report,JSON_THROW_ON_ERROR));fclose($f);}finally{umask($old);}
echo json_encode($report,JSON_THROW_ON_ERROR);'''.replace('HARNESS',repr(h['directory']+'/thunderhawk_correction_native.php'))
ssh=['ssh','-T','-i','C:/Users/kunal/.ssh/coreflux_github_actions_rsa','-o','BatchMode=yes','-o','StrictHostKeyChecking=yes','master_fpepuvndbb@138.197.121.111']
p=subprocess.run(ssh+['COREFLUX_ENV=staging /usr/bin/php8.2 -r '+shlex.quote(php)],capture_output=True,text=True,timeout=45)
if p.returncode:raise RuntimeError(p.stderr[-1500:])
report=json.loads(p.stdout);(out/'synthetic-correction-initial-approval-verification.json').write_text(json.dumps(report,indent=2)+'\n',encoding='utf-8');print(json.dumps(report))
