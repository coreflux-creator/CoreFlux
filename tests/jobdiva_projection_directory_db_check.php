<?php
/** Complete source projection in a disposable canonical schema; no provider calls. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('COREFLUX_ENV')!=='coreaccounting'
    ||getenv('COREFLUX_STANDALONE_DATABASE')!=='coreaccounting_ci'
    ||!in_array('--confirm-disposable-fixture',$argv,true))exit(2);
require_once __DIR__.'/../core/jobdiva/sync.php';
require_once __DIR__.'/../core/jobdiva/sync_placements.php';
$pdo=getDB();if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='coreaccounting_ci')exit(2);
$parent=(int)$pdo->query('SELECT MIN(id) FROM tenants')->fetchColumn();if(!$parent)exit(2);
$child=990699;$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";};
$insert=static function(string $table,array $row)use($pdo):int{
    $pdo->prepare('INSERT INTO '.$table.' (`'.implode('`,`',array_keys($row)).'`) VALUES('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row));return(int)$pdo->lastInsertId();
};
$read=static function(string $table,int $id)use($pdo):array{$q=$pdo->prepare('SELECT * FROM '.$table.' WHERE id=?');$q->execute([$id]);return$q->fetch(PDO::FETCH_ASSOC)?:[];};
$all=static fn(string $table):array=>$pdo->query('SELECT * FROM '.$table.' ORDER BY '.match($table){'core_record_numbers'=>'tenant_id,entity_type,record_id','placement_corp_details'=>'placement_id',default=>'id'})->fetchAll(PDO::FETCH_ASSOC);
$snapshot=static function()use($all):array{
    $out=[];foreach(['people','companies','staffing_clients','staffing_jobs','placements','placement_rates','placement_client_chain','placement_corp_details','placement_commissions','placement_referrals','placement_economic_parties','external_entity_mappings','entity_sync_history','core_record_numbers','custom_values','audit_log']as$table)$out[$table]=$all($table);return$out;
};
$scope=static function(string $module,string $mode)use($pdo,$child):void{$pdo->prepare('INSERT INTO tenant_module_scope(tenant_id,module_key,scope_mode) VALUES(?,?,?) ON DUPLICATE KEY UPDATE scope_mode=VALUES(scope_mode)')->execute([$child,$module,$mode]);};
$payload=static function(int $n):array{return[
    'startId'=>(string)$n,'candidateId'=>(string)($n+1),'jobId'=>(string)($n+2),
    'firstName'=>'Synthetic','lastName'=>'Source '.$n,'candidateEmail'=>'projection-'.$n.'@example.invalid',
    'startDate'=>'2026-11-01','title'=>'Synthetic source assignment '.$n,'status'=>'on_hold','worksiteCountry'=>'US',
    'sourceNote'=>'Original workspace note '.$n,'pilotFlag'=>true,
    '_jd_job'=>['jobId'=>(string)($n+2),'title'=>'Synthetic job '.$n],
    '_jd_contract'=>['source'=>'EmployeeAssignmentRecordsDetail','start_id'=>(string)$n,'engagement_type'=>'w2','placement_status'=>'on_hold','start_date'=>'2026-11-01',
        'client_company_id'=>(string)($n+3),'client_company_name'=>'Synthetic bill-to '.$n,
        'end_client_company_id'=>(string)($n+4),'end_client_name'=>'Synthetic work recipient '.$n,
        'bill_rate'=>100,'pay_rate'=>50,'bill_rate_unit'=>'hour','pay_rate_unit'=>'hour','currency'=>'USD','worksite_country'=>'US'],
];};
$project=static fn(array $p,array $opts=[])=>jobdivaProjectorProjectPlacement($child,$p,null,['payload_is_enriched'=>true]+$opts);
$financial=[$all('accounting_journal_entries'),$all('accounting_journal_entry_lines'),$all('accounting_events'),$all('payroll_runs'),$all('billing_payments'),$all('ap_payments')];
$trigger=false;
try{
    if($pdo->query('SELECT id FROM tenants WHERE id=990699')->fetchColumn())throw new RuntimeException('Projection directory fixture already exists.');
    $pdo->exec("CREATE TRIGGER fixture_projection_audit_failure BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF COALESCE(@fixture_projection_fail,0)=1 AND NEW.event='placement.jobdiva.projected' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic projection audit failure'; END IF; END");$trigger=true;
    $pdo->beginTransaction();setRequestTenantId($child);$_SESSION['tenant_id']=$child;
    $insert('tenants',['id'=>$child,'name'=>'Synthetic JobDiva source child','is_active'=>1,'is_simulation'=>1,'tenant_type'=>'sub','parent_id'=>$parent]);
    foreach(['companies','people','placements','staffing','accounting']as$module)$scope($module,'shared');
    foreach([$parent,$child]as$owner)$insert('custom_fields',['tenant_id'=>$owner,'module'=>'placements','field_name'=>'projection_pilot_flag','field_label'=>'Synthetic projection flag','field_type'=>'boolean']);
    $insert('tenant_integration_field_map',['tenant_id'=>$child,'integration'=>'jobdiva','entity_type'=>'placement','external_field'=>'pilotFlag','internal_field'=>'projection_pilot_flag','source_path'=>'pilotFlag','target_module'=>'placements','target_table'=>'custom_field_values','target_column'=>'projection_pilot_flag','linked_entity'=>'placement']);
    $map=$insert('tenant_integration_field_map',['tenant_id'=>$child,'integration'=>'jobdiva','entity_type'=>'placement','external_field'=>'sourceNote','internal_field'=>'notes','source_path'=>'sourceNote','target_module'=>'placements','target_table'=>'placements','target_column'=>'notes','linked_entity'=>'self']);
    $p=$payload(99160000);$result=$project($p);
    $check($result['projected'],'Shared full projection succeeds: '.json_encode($result['errors']));
    $id=(int)$result['placement_id'];$row=$read('placements',$id);$person=(int)$row['person_id'];
    $check((int)$row['tenant_id']===$parent&&(int)$read('people',$person)['tenant_id']===$parent,'People and placements use their shared owner');
    $custom=$pdo->prepare('SELECT tenant_id,value FROM custom_values WHERE record_id=? AND field_id IN(SELECT id FROM custom_fields WHERE field_name="projection_pilot_flag")');$custom->execute([$id]);$flag=$custom->fetch(PDO::FETCH_ASSOC);
    $check((int)$flag['tenant_id']===$parent&&$flag['value']==='1','Configured custom value uses the shared placement owner');
    $check((int)$read('staffing_jobs',(int)$row['staffing_job_id'])['tenant_id']===$parent,'Job projection uses the independently configured Staffing owner');
    $check($row['notes']===$p['sourceNote'],'Source workspace field mapping writes into the shared placement');
    $q=$pdo->prepare('SELECT tenant_id FROM placement_rates WHERE placement_id=?');$q->execute([$id]);$rateOwners=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
    $check($rateOwners&&array_unique($rateOwners)===[$parent],'Related rate rows follow the placement owner');
    $link=mappingFindInternal($child,'jobdiva','placement','99160000');
    $check((int)$link['internal_entity_id']===$id&&!mappingFindInternal($parent,'jobdiva','placement','99160000'),'Provider mapping stays in the original source workspace');
    $numbers=$all('core_record_numbers');$replayBefore=$snapshot();$counts=array_map('count',$replayBefore);
    $again=$project($p);$check($again['projected']&&(int)$again['placement_id']===$id&&$numbers===$all('core_record_numbers'),'Replay retains the same person and placement numbers');
    $replayAfter=$snapshot();$afterCounts=array_map('count',$replayAfter);
    if($counts!==$afterCounts){
        foreach($afterCounts as$table=>$count)if($count!==$counts[$table])echo 'REPLAY_DIFF '.json_encode(['table'=>$table,'before_count'=>$counts[$table],'after_count'=>$count,'added'=>array_slice($replayAfter[$table],$counts[$table])],JSON_THROW_ON_ERROR)."\n";
    }
    $check($counts===$afterCounts,'Replay adds no duplicate business records, mappings or audit evidence');
    // Every dependent stage belongs to one unit, including late field mappings.
    $pdo->prepare('UPDATE tenant_integration_field_map SET target_column=? WHERE id=?')->execute(['fixture_missing_column',$map]);
    $before=$snapshot();$failed=$project($payload(99160100));
    $check(!$failed['projected']&&!empty($failed['rolled_back'])&&$failed['mapping_writes']===0,'Late mapping failure returns a rolled-back result');
    $check($before===$snapshot(),'Late failure rolls back people, companies, assignments, rates, mappings, numbers and audit');
    $pdo->prepare('UPDATE tenant_integration_field_map SET target_column=? WHERE id=?')->execute(['notes',$map]);
    $before=$snapshot();$pdo->exec('SET @fixture_projection_fail=1');$failed=$project($payload(99160200));$pdo->exec('SET @fixture_projection_fail=0');
    $check(!$failed['projected']&&$before===$snapshot(),'Required audit failure rolls back the complete source projection');
    // Held identities are checked before source helpers create unrelated records.
    $owned=['person_id','end_client_company_id','end_client_name','client_id','staffing_job_id'];
    $pdo->prepare('UPDATE placements SET coreflux_overridden_fields=?,external_id=? WHERE id=?')->execute([json_encode($owned),'placements-xlsx-retained',$id]);
    $changed=$payload(99160300);$changed['startId']='99160000';$changed['_jd_contract']['start_id']='99160000';
    $before=$snapshot();$result=$project($changed,['existing_placement_id'=>$id]);$current=$read('placements',$id);
    $check($result['projected'],'Core-owned related identities allow unrelated source fields to refresh: '.json_encode($result['errors']));
    foreach(['person_id','end_client_company_id','client_id','staffing_job_id']as$field)$check($current[$field]===$row[$field],'Core-owned '.$field.' is retained');
    $check($current['external_id']==='placements-xlsx-retained','Retained legacy external identifier is not rewritten');
    foreach(['people','companies','staffing_clients','staffing_jobs']as$table)$check(count($before[$table])===count($all($table)),'Held identities create no extra '.$table);
    $check(!mappingFindInternal($child,'jobdiva','person','99160301')&&!mappingFindInternal($child,'jobdiva','staffing_job','99160302'),'Held identities do not claim new provider identities');
    // Retired people require an explicit Core lifecycle decision.
    $pdo->prepare('UPDATE people SET status="inactive" WHERE id=?')->execute([$person]);
    $pdo->prepare('UPDATE placements SET coreflux_overridden_fields="[]" WHERE id=?')->execute([$id]);
    $before=$snapshot();$failed=$project($p);
    $check(!$failed['projected']&&$before===$snapshot(),'Refreshing a source cannot reactivate a retired Core worker');
    $pdo->prepare('UPDATE people SET status="active" WHERE id=?')->execute([$person]);
    $scope('people','isolated');$before=$snapshot();$failed=$project($payload(99160400));
    $check(!$failed['projected']&&$before===$snapshot(),'Incompatible People and Placements policies fail before mutations');
    $scope('people','shared');$scope('companies','isolated');$before=$snapshot();$failed=$project($payload(99160500));
    $check(!$failed['projected']&&$before===$snapshot(),'Shared placements cannot import private company identities');
    $scope('companies','shared');
    foreach(['people','placements','staffing','accounting']as$module)$scope($module,'isolated');
    $result=$project($payload(99160600));$check($result['projected'],'Isolated projection succeeds with the shared company catalog: '.json_encode($result['errors']));
    $local=$read('placements',(int)$result['placement_id']);
    $custom->execute([(int)$local['id']]);$flag=$custom->fetch(PDO::FETCH_ASSOC);
    $check((int)$flag['tenant_id']===$child&&$flag['value']==='1','Custom values use fresh policies after changing sharing in the same process');
    $check((int)$local['tenant_id']===$child&&(int)$read('people',(int)$local['person_id'])['tenant_id']===$child&&(int)$read('staffing_jobs',(int)$local['staffing_job_id'])['tenant_id']===$child,'Isolated business records remain in the child workspace');
    $before=$snapshot();$failed=$project($p,['existing_placement_id'=>$id]);
    $check(!$failed['projected']&&$before===$snapshot(),'A saved mapping cannot silently migrate records after a sharing change');
    $check($financial===[$all('accounting_journal_entries'),$all('accounting_journal_entry_lines'),$all('accounting_events'),$all('payroll_runs'),$all('billing_payments'),$all('ap_payments')],'Projection makes no financial postings, payroll runs or payments');
}finally{
    if($pdo->inTransaction())$pdo->rollBack();
    $pdo->exec('SET @fixture_projection_fail=0');
    if($trigger)$pdo->exec('DROP TRIGGER fixture_projection_audit_failure');
    setRequestTenantId(null);
}
echo "$checks JobDiva projection directory checks passed.\n";
