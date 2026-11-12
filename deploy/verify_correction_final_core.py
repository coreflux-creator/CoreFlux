import json,shlex,subprocess
from pathlib import Path
r=Path(__file__).resolve().parents[1];out=r/'output'
php=r'''chdir('/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html');
require 'core/quanta/time_revisions.php';
$pdo=getDB();setRequestTenantId(2002);$private=dirname(getcwd()).'/private_html/thunderhawk-pilot-20261030';
if(!COREFLUX_STAGING||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||posix_geteuid()!==1003||integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf')exit(2);
$before=json_decode(file_get_contents($private.'/synthetic-lifecycle-20261112-correction-before.private.json'),true,512,JSON_THROW_ON_ERROR);
$pdo->exec('START TRANSACTION READ ONLY');
try{
 $after=[];$changed=[];$originalRows=0;
 $tables=$pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);sort($tables);
 if($tables!==array_keys($before))throw new RuntimeException('Schema changed');
 $allow=['audit_log','core_record_numbers','membership_audit','people','people_employees','people_change_log','placement_economic_parties','placement_rates','placements','tenant_memberships','user_tenants','users','payroll_profiles','payroll_pay_schedules','payroll_pay_cycles','payroll_pay_periods','quanta_worker_links','integration_deliveries','integration_delivery_subjects','quanta_connections','quanta_time_routes','quanta_time_imports','quanta_time_approval_evidence','quanta_time_revisions','time_entries','staffing_timesheets','artifact_objects','artifact_events','artifact_relationships'];
 foreach($before as $table=>$hashes){
  $current=[];$data=$pdo->query('SELECT * FROM `'.$table.'`');while($row=$data->fetch(PDO::FETCH_ASSOC))$current[]=hash('sha256',serialize($row));sort($current);$originalRows+=count($hashes);$after[$table]=$current;
  if($hashes===$current)continue;if(!in_array($table,$allow,true))throw new RuntimeException('Unexpected table changed: '.$table);
  $old=array_count_values($hashes);$new=array_count_values($current);$missing=[];$added=[];
  foreach($old as $hash=>$n)if($n>($new[$hash]??0))$missing[$hash]=$n-($new[$hash]??0);
  foreach($new as $hash=>$n)if($n>($old[$hash]??0))$added[$hash]=$n-($old[$hash]??0);
  if($missing){
   if($table!=='quanta_connections'||array_sum($missing)!==1||array_sum($added)!==1)throw new RuntimeException('An original record changed: '.$table);
   $target=$pdo->query('SELECT * FROM quanta_connections WHERE tenant_id=2002 AND id=2')->fetch(PDO::FETCH_ASSOC);
   if(!isset($added[hash('sha256',serialize($target))]))throw new RuntimeException('Unrelated connection changed');
   // The import phase separately proved every connection field except its three import timestamps/errors unchanged.
   $phase=json_decode(file_get_contents($private.'/synthetic-lifecycle-time_import-20261112-correction.json'),true,64,JSON_THROW_ON_ERROR);
   if(!$phase['passed'])throw new RuntimeException('Import preservation proof unavailable');
  }
  $changed[$table]=['before'=>count($hashes),'after'=>count($current),'original_rows_changed'=>array_sum($missing)];
 }
 $original=$pdo->query('SELECT * FROM time_entries WHERE tenant_id=2002 AND id=15')->fetch(PDO::FETCH_ASSOC);
 $replacement=$pdo->query('SELECT * FROM time_entries WHERE tenant_id=2002 AND id=16')->fetch(PDO::FETCH_ASSOC);
 if($original['status']!=='superseded'||(float)$original['hours']!==1.0||(int)$original['approved_by_user_id']!==86||(int)$original['superseded_by_id']!==16
 ||$replacement['status']!=='approved'||(float)$replacement['hours']!==1.5||(int)$replacement['approved_by_user_id']!==90||(int)$replacement['created_by_user_id']!==88)throw new RuntimeException('Final corrected approval differs');
 $header=staffingTimesheetRow(2002,8);$artifact=artifactGet(2002,$header['artifact_id']);
 if($header['status']!=='approved'||(float)$header['total_hours']!==1.5||$artifact['status']!==staffingTimesheetArtifactStatus($header['status'])||artifactComparableJson($artifact['payload'])!==artifactComparableJson(staffingTimesheetArtifactPayload($header)))throw new RuntimeException('Header/artifact mismatch');
 $source='3a18a0f9-6761-4a6e-be8f-0a5bcaa552c2';$s=$pdo->prepare('SELECT * FROM quanta_time_imports WHERE tenant_id=2002 AND quanta_entry_id=?');$s->execute([$source]);$import=$s->fetch(PDO::FETCH_ASSOC);
 if((int)$import['time_entry_id']!==16||(int)$import['is_current']!==1)throw new RuntimeException('Current source pointer mismatch');
 $receipt=$pdo->query('SELECT * FROM quanta_time_revisions WHERE tenant_id=2002 AND id=1')->fetch(PDO::FETCH_ASSOC);
 $saved=json_decode(decryptField($receipt['before_ct']),true,64,JSON_THROW_ON_ERROR);
 if((int)$saved['prior']['entries']['regular']['id']!==15||(float)$saved['prior']['entries']['regular']['hours']!==1.0)throw new RuntimeException('Immutable original missing');
 $s=$pdo->query('SELECT id,is_active,password,password_hash FROM users WHERE tenant_id=2002 AND id BETWEEN 80 AND 91');$actors=$s->fetchAll(PDO::FETCH_ASSOC);
 if(count($actors)!==12)throw new RuntimeException('Test actor inventory changed');foreach($actors as $a)if($a['is_active']||$a['password']!==null||$a['password_hash']!==null)throw new RuntimeException('Test operator remains enabled');
 if((int)$pdo->query('SELECT COUNT(*) FROM membership_module_access WHERE membership_id BETWEEN 66 AND 77')->fetchColumn())throw new RuntimeException('Test access remains');
 $historical=$pdo->query('SELECT COUNT(*) AS n,SUM(hours) AS hours FROM time_entries WHERE tenant_id=2002 AND person_id=358 AND placement_id=864 AND work_date BETWEEN "2026-10-05" AND "2026-10-18" AND status="approved"')->fetch(PDO::FETCH_ASSOC);
 if((int)$historical['n']!==10||(float)$historical['hours']!==80.0||$pdo->query('SELECT status FROM placements WHERE tenant_id=2002 AND id=874')->fetchColumn()!=='ended')throw new RuntimeException('Protected cohort changed');
 $report=['checked_at'=>gmdate('c'),'passed'=>true,'read_only'=>true,'tables_verified'=>count($tables),'original_rows_checked'=>$originalRows,'final_rows'=>array_sum(array_map('count',$after)),'changed_tables'=>$changed,'all_original_business_rows_preserved'=>true,'only_original_metadata_change'=>'Isolated Quanta connection import timestamp/error fields, verified separately by the import phase.','protected_paid_entries'=>10,'protected_paid_hours'=>80,'closed_PL874_preserved'=>true,'original_entry_id'=>15,'replacement_entry_id'=>16,'revision_id'=>1,'initial_approver'=>86,'corrector'=>88,'replacement_approver'=>90,'current_hours'=>1.5,'weekly_artifact_matches'=>true,'temporary_operators_retired'=>12,'financial_execution'=>false,'production_changed'=>false,'arabella_changed'=>false,'synthetic_case_left_active_for_portal_walkthrough'=>true];
}finally{$pdo->rollBack();}
echo json_encode($report,JSON_THROW_ON_ERROR);'''
ssh=['ssh','-T','-i','C:/Users/kunal/.ssh/coreflux_github_actions_rsa','-o','BatchMode=yes','-o','StrictHostKeyChecking=yes','master_fpepuvndbb@138.197.121.111']
p=subprocess.run(ssh+['COREFLUX_ENV=staging /usr/bin/php8.2 -r '+shlex.quote(php)],capture_output=True,text=True,timeout=45)
if p.returncode:raise RuntimeError(p.stderr[-1500:])
report=json.loads(p.stdout);(out/'synthetic-correction-final-core.json').write_text(json.dumps(report,indent=2)+'\n',encoding='utf-8');print(json.dumps(report))
