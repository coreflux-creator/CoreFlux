import json,shlex,subprocess
from pathlib import Path
r=Path(__file__).resolve().parents[1];out=r/'output'
h=json.loads((out/'synthetic-correction-revision-handle.json').read_text(encoding='utf-8'))
php=r'''chdir('/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html');require HARNESS;
require 'core/quanta/time_revisions.php';
$pdo=getDB();setRequestTenantId(2002);
if(!COREFLUX_STAGING||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx')exit(2);
$private=ThunderhawkCorrectionNative::PRIVATE;
$prior=json_decode(file_get_contents($private.'/synthetic-lifecycle-revision-20261112-correction.json'),true,64,JSON_THROW_ON_ERROR);
if($prior['passed']||$prior['step']!=='correction_applied'||!$prior['operator_retired']||!$prior['private_session_removed']||!in_array('Corrector cannot approve the replacement',$prior['checks'],true))exit(3);
$pdo->exec('START TRANSACTION READ ONLY');
try{
 $receipt=$pdo->query('SELECT * FROM quanta_time_revisions WHERE tenant_id=2002 AND id=1')->fetch(PDO::FETCH_ASSOC);
 $saved=json_decode(decryptField($receipt['before_ct']),true,64,JSON_THROW_ON_ERROR);
 $oldImport=$saved['prior']['imports'][0];$oldEntry=$saved['prior']['entries']['regular'];
 if((int)$oldImport['time_entry_id']!==15||(int)$oldEntry['id']!==15||quantaTimeRevisionReceipt($receipt)!==$prior['receipt'])throw new RuntimeException('Unexpected saved history');
 $old=$pdo->query('SELECT * FROM time_entries WHERE tenant_id=2002 AND id=15')->fetch(PDO::FETCH_ASSOC);
 if($old['status']!=='superseded'||(int)$old['superseded_by_id']!==16)throw new RuntimeException('Wrong original state');
 foreach(['status','superseded_by_id','updated_at'] as $f)unset($old[$f],$oldEntry[$f]);
 if(integrationDeliveryHash($old)!==integrationDeliveryHash($oldEntry))throw new RuntimeException('Original approved fields changed');
 $q=$pdo->prepare('SELECT source_approval_hash,source_hash,source_approval_json FROM quanta_time_approval_evidence WHERE tenant_id=2002 AND quanta_time_import_id=? ORDER BY id');$q->execute([$oldImport['id']]);$evidence=$q->fetchAll(PDO::FETCH_ASSOC);
 if(count($evidence)!==2||$evidence[0]['source_approval_hash']!==$oldImport['source_approval_hash']||$evidence[0]['source_hash']!==$oldImport['source_hash']||$evidence[0]['source_approval_json']!==$oldImport['source_approval_json'])throw new RuntimeException('Original source evidence changed');
 $reflection=new ReflectionClass(ThunderhawkCorrectionNative::class);$h=$reflection->newInstanceWithoutConstructor();$h->pdo=$pdo;$reflection->getProperty('phase')->setValue($h,'revision');
 $after=$reflection->getMethod('snapshot')->invoke($h);
 $before=json_decode(file_get_contents($private.'/synthetic-correction-revision-before.private.json'),true,512,JSON_THROW_ON_ERROR);
 if(array_keys($before)!==array_keys($after))throw new RuntimeException('Schema changed');$changed=[];
 foreach($before as $table=>$hashes){
  if($hashes===$after[$table])continue;$old=array_count_values($hashes);$new=array_count_values($after[$table]);$missing=[];$added=[];
  foreach($old as $hash=>$n)if($n>($new[$hash]??0))$missing[$hash]=$n-($new[$hash]??0);
  foreach($new as $hash=>$n)if($n>($old[$hash]??0))$added[$hash]=$n-($old[$hash]??0);
  if($table==='staffing_timesheets'){
   $row=$pdo->query('SELECT * FROM staffing_timesheets WHERE tenant_id=2002 AND id=8')->fetch(PDO::FETCH_ASSOC);
   if($row['status']!=='submitted'||(float)$row['total_hours']!==1.5||$row['approved_at']!==null||$row['approved_by_user_id']!==null)throw new RuntimeException('Wrong pending weekly state');
   $oldHeader=$saved['headers'][8];$currentHeader=$row;
   foreach(['status','total_hours','approved_at','approved_by_user_id','approved_via','submitted_at','updated_at'] as $f)unset($oldHeader[$f],$currentHeader[$f]);
   if(integrationDeliveryHash($oldHeader)!==integrationDeliveryHash($currentHeader))throw new RuntimeException('Unrelated header fields changed');
   foreach(['status','total_hours','approved_at','approved_by_user_id','approved_via','updated_at'] as $f)unset($row[$f]);
   if(array_sum($missing)!==1||array_sum($added)!==1||!isset($added[hash('sha256',serialize($row))]))throw new RuntimeException('Unrelated header row changed');
  }else if($missing||!in_array($table,['time_entries','quanta_time_approval_evidence','quanta_time_revisions','artifact_events','audit_log','users','tenant_memberships','user_tenants','membership_audit'],true))throw new RuntimeException('Unexpected change '.$table);
  $changed[$table]=['before'=>count($hashes),'after'=>count($after[$table]),'original_rows_replaced'=>array_sum($missing)];
 }
 $new=$pdo->query('SELECT * FROM time_entries WHERE tenant_id=2002 AND id=16')->fetch(PDO::FETCH_ASSOC);
 if($new['status']!=='pending_review'||(float)$new['hours']!==1.5||(int)$new['created_by_user_id']!==88||$new['approved_at']!==null)throw new RuntimeException('Replacement changed');
 $report=['checked_at'=>gmdate('c'),'passed'=>true,'read_only'=>true,'correction_not_repeated'=>true,'original_receipt_preserved'=>true,'tables_verified'=>count($after),'changed_tables'=>$changed,'original_approval_preserved'=>true,'both_source_approvals_retained'=>true,'receipt_id'=>1,'new_entry_id'=>16,'operator_id'=>88,'known_helper_gaps'=>['Canonical encrypted JSON sorts keys; its original import contents match by canonical content.','Current header reconciliation refreshes submitted_at; the older snapshot omitted that allowed field.']];
}finally{$pdo->rollBack();}
$path=$private.'/synthetic-correction-revision-verification.json';$old=umask(0077);try{$f=fopen($path,'x');if(!$f)throw new RuntimeException('Existing verifier receipt');fwrite($f,json_encode($report,JSON_THROW_ON_ERROR));fclose($f);}finally{umask($old);}
echo json_encode($report,JSON_THROW_ON_ERROR);'''.replace('HARNESS',repr(h['directory']+'/thunderhawk_correction_native.php'))
ssh=['ssh','-T','-i','C:/Users/kunal/.ssh/coreflux_github_actions_rsa','-o','BatchMode=yes','-o','StrictHostKeyChecking=yes','master_fpepuvndbb@138.197.121.111']
p=subprocess.run(ssh+['COREFLUX_ENV=staging /usr/bin/php8.2 -r '+shlex.quote(php)],capture_output=True,text=True,timeout=45)
if p.returncode:raise RuntimeError(p.stderr[-1600:])
report=json.loads(p.stdout);(out/'synthetic-correction-revision-verification.json').write_text(json.dumps(report,indent=2)+'\n',encoding='utf-8');print(json.dumps(report))
