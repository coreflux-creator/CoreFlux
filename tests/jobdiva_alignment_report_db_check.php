<?php
/** Full report against canonical schema, with independent directory policies. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('COREFLUX_ENV')!=='coreaccounting'||getenv('COREFLUX_STANDALONE_DATABASE')!=='coreaccounting_ci'||!in_array('--confirm-disposable-fixture',$argv,true))exit(2);
require_once __DIR__.'/../core/jobdiva/mapping_alignment.php';
$pdo=getDB();if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='coreaccounting_ci')exit(2);
$owner=(int)$pdo->query('SELECT MIN(id) FROM tenants')->fetchColumn();$workspace=990708;$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";};
$fails=static function(callable $fn):bool{try{$fn();return false;}catch(Throwable){return true;}};
$insert=static function(string $table,array $row)use($pdo):int{$pdo->prepare('INSERT INTO '.$table.' (`'.implode('`,`',array_keys($row)).'`) VALUES('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row));return(int)$pdo->lastInsertId();};
$state=static function()use($pdo):array{$result=[];foreach(['placements','people','companies','staffing_clients','staffing_jobs','placement_rates','external_entity_mappings','entity_sync_history','core_record_numbers','audit_log','time_entries']as$table){$rows=array_map(static fn($r)=>json_encode($r,JSON_THROW_ON_ERROR),$pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC));sort($rows);$result[$table]=$rows;}return$result;};
JobdivaStoredReviewTransaction::run(static function()use($pdo,$owner,$workspace,$check,$fails,$insert,$state):void{
    $insert('tenants',['id'=>$workspace,'parent_id'=>$owner,'tenant_type'=>'sub','name'=>'Synthetic alignment report','is_active'=>1,'is_simulation'=>1]);
    foreach(['companies'=>'shared','placements'=>'shared','people'=>'isolated','staffing'=>'isolated']as$module=>$mode)$insert('tenant_module_scope',['tenant_id'=>$workspace,'module_key'=>$module,'scope_mode'=>$mode]);
    $person=$insert('people',['tenant_id'=>$workspace,'first_name'=>'Synthetic','last_name'=>'Alignment','email_primary'=>'alignment@example.invalid','status'=>'active','classification'=>'w2']);
    $bill=$insert('companies',['tenant_id'=>$owner,'name'=>'Synthetic alignment bill-to']);
    $recipient=$insert('companies',['tenant_id'=>$owner,'name'=>'Synthetic alignment work recipient']);
    $insert('company_roles',['company_id'=>$bill,'role'=>'client']);
    $client=$insert('staffing_clients',['tenant_id'=>$workspace,'company_id'=>$bill,'name'=>'Synthetic alignment client']);
    $job=$insert('staffing_jobs',['tenant_id'=>$workspace,'client_id'=>$client,'company_id'=>$bill,'title'=>'Synthetic alignment role']);
    $placementRow=['tenant_id'=>$owner,'person_id'=>$person,'client_id'=>$client,'staffing_job_id'=>$job,'end_client_company_id'=>$recipient,
        'title'=>'Retained alignment placement','start_date'=>'2026-10-01','status'=>'on_hold','engagement_type'=>'w2','external_id'=>'retained-alignment-placement','worksite_country'=>'US'];
    $placement=$insert('placements',$placementRow);
    $root=['startId'=>'99330001','candidateId'=>'99330002','jobId'=>'99330003','startDate'=>'2026-10-01','status'=>'on_hold'];
    $map=mappingUpsert($workspace,'jobdiva','placement','jd:99330001',$placement,$root);
    mappingUpsert($workspace,'jobdiva','person','99330002',$person,['candidateId'=>'99330002']);
    mappingUpsert($workspace,'jobdiva','company','99330004',$bill,['companyId'=>'99330004']);
    mappingUpsert($workspace,'jobdiva','jobdiva_customer','99339999',99339999,['customerId'=>'99339999']);
    mappingUpsert($workspace,'quanta','placement','retained-quanta-link',$placement,[]);
    $rate=$insert('placement_rates',['tenant_id'=>$owner,'placement_id'=>$placement,'effective_from'=>'2026-10-01','bill_rate'=>100,'pay_rate'=>50,'approved_at'=>'2026-10-01 12:00:00']);
    $report=static fn(int $limit=1):array=>jobdivaMappingAlignmentReport($workspace,['sample_limit'=>$limit]);
    $before=$state();$result=$report();
    $check($result['complete']===true,'Full canonical alignment report finishes with independent directory owners: '.json_encode($result['issues']??[]));
    $ready=$result['relationships']['projector']['workflow_readiness'];
    $check(array_sum($ready)===0,'Current shared placement, private person/job/client, and shared companies all resolve');
    $check($result['directory_owners']['people']===$workspace&&$result['directory_owners']['placements']===$owner,'Report exposes independently resolved directory ownership');
    $check($result['relationships']['end_client_consumer_bridge']['jobdiva_customers_without_staffing_client']===0,'Raw customer mirror IDs are never treated as canonical company IDs');
    $check($result['relationships']['placement_graph']['person_without_jobdiva_mapping']===0,'Person source links are read from the original workspace');
    $check($state()===$before,'Reading the full report preserves all source pairs, native data, rates, histories and audit rows');
    foreach(['person_id'=>'missing_person','staffing_job_id'=>'missing_staffing_job','client_id'=>'missing_staffing_client','end_client_company_id'=>'missing_end_client_company']as$field=>$key){
        $pdo->prepare('UPDATE placements SET '.$field.'=999999999 WHERE id=?')->execute([$placement]);
        $check($report()['relationships']['projector']['workflow_readiness'][$key]===1,'A non-null but unavailable '.$field.' is a readiness failure');
        $pdo->prepare('UPDATE placements SET '.$field.'=? WHERE id=?')->execute([$placementRow[$field],$placement]);
    }
    $pdo->prepare('UPDATE companies SET deleted_at=NOW() WHERE id=?')->execute([$bill]);
    $check($report()['relationships']['projector']['workflow_readiness']['client_company_mismatch']===1,'A retired bill-to company is unavailable without conflating the work recipient');
    $pdo->prepare('UPDATE companies SET deleted_at=NULL WHERE id=?')->execute([$bill]);
    $pdo->prepare('UPDATE placements SET status="active" WHERE id=?')->execute([$placement]);
    $pdo->prepare('UPDATE placement_rates SET approved_at=NULL WHERE id=?')->execute([$rate]);
    $check($report()['relationships']['projector']['workflow_readiness']['active_missing_approved_rate']===1,'Active placement without approved coverage is blocked');
    $pdo->prepare('UPDATE placement_rates SET approved_at="2026-10-01 12:00:00" WHERE id=?')->execute([$rate]);
    $pdo->prepare('UPDATE placements SET coreflux_overridden_fields=\'["status"]\' WHERE id=?')->execute([$placement]);
    $check($report()['relationships']['placement_graph']['active_lifecycle_drift']===0,'Native-owned lifecycle is not advertised as a source repair');
    $pdo->prepare('UPDATE placements SET status="on_hold",coreflux_overridden_fields=NULL WHERE id=?')->execute([$placement]);
    $pdo->prepare('UPDATE tenant_module_scope SET scope_mode="isolated" WHERE tenant_id=? AND module_key="placements"')->execute([$workspace]);
    $check($report()['relationships']['projector']['workflow_readiness']['missing_placement']===1,'Changed sharing is read afresh and missing placements are counted instead of disappearing');
    $pdo->prepare('UPDATE tenant_module_scope SET scope_mode="shared" WHERE tenant_id=? AND module_key="placements"')->execute([$workspace]);
    $contact=$insert('company_contacts',['tenant_id'=>$owner,'company_id'=>$bill,'name'=>'Synthetic Contact']);
    mappingUpsert($workspace,'jobdiva','contact','99330005',$contact,[]);
    $check($report()['relationships']['contact_graph']['contacts_missing_company']===0,'Contacts resolve in the current company directory');
    mappingUpsert($workspace,'jobdiva','time_entry','99330006',99339998,[]);
    $check($report()['relationships']['time_graph']['time_entries_without_placement_mapping']===1,'A missing mapped time entry cannot disappear from the report');
    $period=$insert('time_periods',['tenant_id'=>$workspace,'start_date'=>'2026-10-01','end_date'=>'2026-10-31','status'=>'open','label'=>'Synthetic alignment month']);
    $time=$insert('time_entries',['tenant_id'=>$workspace,'placement_id'=>$placement,'person_id'=>$person,'period_id'=>$period,'work_date'=>'2026-10-02','category'=>'regular_billable','hours'=>1]);
    $pdo->prepare('UPDATE external_entity_mappings SET internal_entity_id=? WHERE tenant_id=? AND internal_entity_type="time_entry"')->execute([$time,$workspace]);
    $check($report()['relationships']['time_graph']['time_entries_without_placement_mapping']===0,'Workspace time can resolve a shared placement and its original source pair');
    $duplicate=$insert('placements',array_replace($placementRow,['title'=>'JobDiva Placement 99330001','external_id'=>'synthetic-duplicate']));
    $check($report()['relationships']['placement_graph']['duplicate_jobdiva_external_id_groups']===1,'Shared-directory duplicate candidates are found');
    mappingUpsert($owner,'jobdiva','placement','99338888',$duplicate,['startId'=>'99338888']);
    $check($report()['relationships']['placement_graph']['duplicate_jobdiva_external_id_groups']===0,'Another workspace source cannot be claimed from a similar shared-directory title');
    $pdo->prepare('UPDATE external_entity_mappings SET payload_snapshot=? WHERE id=?')->execute(['[]',$map['id']]);
    $check($report()['relationships']['placement_graph']['locally_disqualified_assignment_sources']===1,'Invalid source roots are reported through the shared placement');
    $insert('jobdiva_connections',['tenant_id'=>$workspace,'client_id'=>'synthetic','username'=>'synthetic','password_enc'=>'unused','sync_config'=>'{"placement":{"source":"invalid","direction":"pull"}}']);
    $failed=$report();$check($failed['complete']===false&&$failed['ok']===false&&!isset($failed['relationships']),'Invalid connection configuration returns no misleading readiness counters');
    $check($fails(fn()=>_jobdivaMappingScalar($pdo,'SELECT nonexistent_column FROM placements')),'Scalar query failures propagate');
    $check($fails(fn()=>_jobdivaMappingRows($pdo,'SELECT nonexistent_column FROM placements')),'Row query failures propagate');
},true);
$pdo->exec('RENAME TABLE integration_payload_field_index TO fixture_alignment_hidden_index');
try{$failed=jobdivaMappingAlignmentReport($owner);$check($failed['complete']===false&&!isset($failed['relationships']),'Missing required schema returns unavailable, not healthy zero');}
finally{$pdo->exec('RENAME TABLE fixture_alignment_hidden_index TO integration_payload_field_index');}
$check(!$pdo->inTransaction(),'Report leaves no transaction open');
echo $checks." alignment report checks passed.\n";
