<?php
declare(strict_types=1);
$ci=getenv('GITHUB_ACTIONS')==='true'&&getenv('CI')==='true';
$local=getenv('COREFLUX_DELIVERY_LOCAL_FIXTURE')==='1'&&in_array('--confirm-disposable-fixture',$argv,true)&&(string)getenv('COREFLUX_DELIVERY_TEST_PASSWORD')!=='';
if (!$ci&&!$local) throw new RuntimeException('Explicit disposable fixture configuration required');
require_once __DIR__.'/../deploy/staffing_directory_inventory.php';
$pdo=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root',$ci?'root':getenv('COREFLUX_DELIVERY_TEST_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$db='coreflux_directory_inventory_'.($ci?'ci':gmdate('YmdHis').'_'.getmypid());
$pdo->exec('CREATE DATABASE '.$db); $pdo->exec('USE '.$db);
$passed=0; $check=static function(bool $ok,string $label)use(&$passed):void{if(!$ok)throw new RuntimeException($label);$passed++;echo "PASS $label\n";};
try {
    $pdo->exec('CREATE TABLE tenants(id BIGINT PRIMARY KEY,name VARCHAR(100),parent_id BIGINT NULL,tenant_type VARCHAR(20),is_active INT)');
    $pdo->exec("INSERT INTO tenants VALUES(10,'Synthetic pilot',NULL,'master',1),(11,'Synthetic child',10,'sub',1),(12,'Private other company',NULL,'master',1)");
    $pdo->exec('CREATE TABLE tenant_module_scope(tenant_id BIGINT,module_key VARCHAR(40),scope_mode VARCHAR(20))');
    $pdo->exec("INSERT INTO tenant_module_scope VALUES(11,'people','shared'),(11,'placements','isolated')");
    $pdo->exec('CREATE TABLE people(id BIGINT PRIMARY KEY,tenant_id BIGINT,deleted_at DATETIME NULL)');
    $pdo->exec("INSERT INTO people VALUES(1,10,NULL),(2,10,'2026-01-01'),(3,11,NULL),(4,12,NULL)");
    $pdo->exec('CREATE TABLE placements(id BIGINT PRIMARY KEY,tenant_id BIGINT,person_id BIGINT,deleted_at DATETIME NULL)');
    $pdo->exec('INSERT INTO placements VALUES(1,11,1,NULL),(2,11,2,NULL),(3,11,999,NULL),(4,10,1,NULL),(5,12,4,NULL)');
    $pdo->exec('CREATE TABLE external_entity_mappings(tenant_id BIGINT,source_system VARCHAR(40),internal_entity_type VARCHAR(40),internal_entity_id BIGINT)');
    $pdo->exec("INSERT INTO external_entity_mappings VALUES(11,'jobdiva','person',1),(11,'jobdiva','person',999),(12,'jobdiva','person',4)");
    $pdo->exec('ALTER TABLE external_entity_mappings ADD COLUMN external_id VARCHAR(128)');
    $pdo->exec("UPDATE external_entity_mappings SET external_id='candidateAlpha' WHERE internal_entity_id=1 OR tenant_id=12");
    $pdo->exec("INSERT INTO external_entity_mappings VALUES(11,'jobdiva','placement',1,'start001'),(12,'jobdiva','placement',5,'start001')");
    $pdo->exec('ALTER TABLE placements ADD COLUMN accounting_entity_id BIGINT NULL, ADD COLUMN branch VARCHAR(100), ADD COLUMN workers_comp_class VARCHAR(64), ADD COLUMN end_date DATE, ADD COLUMN jobdiva_job_id VARCHAR(64)');
    $pdo->exec("UPDATE placements SET accounting_entity_id=0,branch='Reviewed division',workers_comp_class='   ',jobdiva_job_id='job001' WHERE id=1");
    $pdo->exec('CREATE TABLE core_record_numbers(tenant_id BIGINT,entity_type VARCHAR(24),record_id BIGINT,record_number VARCHAR(48),canonical_record_id BIGINT)');
    $pdo->exec("INSERT INTO core_record_numbers VALUES(10,'person',1,'P-1',1),(11,'placement',1,'PL-1',1),(11,'person',1,'P-2',99)");
    $pdo->exec('CREATE TABLE accounting_entities(id BIGINT,tenant_id BIGINT,active INT)');
    $pdo->exec('INSERT INTO accounting_entities VALUES(77,11,1),(88,12,1)');
    $pdo->exec('ALTER TABLE people ADD COLUMN entity_id BIGINT NULL');
    $pdo->exec('UPDATE people SET entity_id=88 WHERE id=1');
    $pilot=['tenant_id'=>11,'workspace_name'=>'Synthetic child','person_directory_id'=>10,'accounting_directory_id'=>11,'person_id'=>1,'placement_id'=>1,
        'jobdiva_ids'=>['person'=>'candidateAlpha','placement'=>'start001','job'=>'job001']];
    $pdo->exec('SET TRANSACTION READ ONLY'); $pdo->beginTransaction();
    $result=fullCoreStaffingDirectoryInventory($pdo,['Synthetic child']); $row=$result['workspaces'][0];
    $check($result['status']==='available'&&count($result['workspaces'])===1&&(int)$row['id']===11,'only the explicitly named pilot workspace is reported');
    $check($row['configured_scopes']===['people'=>'shared','placements'=>'isolated'],'current module overrides remain distinct');
    $owners=array_column($row['record_directories'],null,'tenant_id');
    $check($owners[10]['people']===1&&$owners[11]['people']===1&&!isset($owners[12]),'counts include the parent but exclude unrelated directories and deleted people');
    $check($owners[10]['placements']===1&&$owners[11]['placements']===3,'parent and child placement counts are separate');
    $joins=$row['placement_person_directories'];
    $check(count($joins)===2&&$joins[0]['person_directory']===null&&(int)$joins[0]['placement_count']===1,'dangling person references are visible');
    $check((int)$joins[1]['person_directory']===10&&(int)$joins[1]['placement_count']===2&&(int)$joins[1]['deleted_people']===1,'shared and deleted person relationships are counted separately');
    $check(count($row['jobdiva_person_mapping_directories'])===2,'provider mappings report their actual record owners');
    $check(!str_contains(json_encode($result),'Private other company'),'unrequested workspace details are not returned');
    $master=fullCoreStaffingDirectoryInventory($pdo,['Synthetic pilot'])['workspaces'][0];
    $check($master['configured_scopes']===[],'absent overrides are not fabricated as configured policy');
    $check(fullCoreStaffingDirectoryInventory($pdo,['No match'])['workspaces']===[],'unknown workspace is not replaced by a default');
    $observed=fullCoreStaffingPilotInventory($pdo,$pilot);
    $check($observed['status']==='observed','explicit shared-directory pilot pair is inspected in a read-only transaction');
    $check($observed['placement_fields']['branch']==='present'&&$observed['placement_fields']['workers_comp_class']==='empty'
        &&$observed['placement_fields']['accounting_entity_id']==='empty','blank and zero assignment dimensions remain missing');
    $check($observed['placement_fields']['end_date']==='empty'&&$observed['placement_fields']['start_date']==='column_missing','open-ended and absent schema are distinct evidence');
    $check($observed['jobdiva_identity']['person']['status']==='exact_match'&&$observed['jobdiva_identity']['placement']['status']==='exact_match','source links match exact opaque IDs and ignore another tenant');
    $check($observed['jobdiva_identity']['job']['status']==='exact_match'&&$observed['placement_fields']['jobdiva_job_id']==='present'
        &&$observed['placement_fields']['staffing_job_id']==='column_missing','provider job ID stays distinct from the internal requisition and preserves opaque values');
    $check($observed['persisted_numbers']===['person'=>'allocated','placement'=>'allocated'],'persisted person number uses the actual shared owner');
    $check($observed['employer_references']['placement']==='empty'&&$observed['employer_references']['person']==='unresolved_reference','person employer fallback does not accept another accounting directory');
    $serialized=json_encode($observed);
    $check(!str_contains($serialized,'candidateAlpha')&&!str_contains($serialized,'Reviewed division')&&!str_contains($serialized,'P-1'),'raw source IDs, dimension values and number labels are not emitted');
    $wrongName=fullCoreStaffingPilotInventory($pdo,array_replace($pilot,['workspace_name'=>'synthetic child']));
    $check($wrongName['status']==='workspace_identity_mismatch'&&!isset($wrongName['placement_fields']),'workspace name must match exactly before record fields are read');
    $check(fullCoreStaffingPilotInventory($pdo,array_replace($pilot,['placement_id'=>5]))['status']==='placement_not_found','foreign placement is not used as a fallback');
    $check(fullCoreStaffingPilotInventory($pdo,array_replace($pilot,['person_id'=>4]))['status']==='person_identity_mismatch','different linked worker is not inspected');
    $check(fullCoreStaffingPilotInventory($pdo,array_replace($pilot,['person_directory_id'=>11]))['status']==='person_directory_or_active_record_mismatch','requested person owner must match the record');
    $check(fullCoreStaffingPilotInventory($pdo,array_replace($pilot,['placement_id'=>2,'person_id'=>2]))['status']==='person_directory_or_active_record_mismatch','deleted person cannot pass the pilot identity check');
    $differentCase=$pilot; $differentCase['jobdiva_ids']['person']='candidatealpha';
    $check(fullCoreStaffingPilotInventory($pdo,$differentCase)['jobdiva_identity']['person']['status']==='mismatch_or_ambiguous','provider identifiers are compared without case folding');
    $invalid=$pilot; $invalid['placement_id']='1'; $rejected=false;
    try { fullCoreStaffingPilotInventory($pdo,$invalid); } catch (InvalidArgumentException $e) { $rejected=true; }
    $check($rejected,'ambiguous numeric input is rejected before querying');
    $pdo->rollBack();
    $pdo->exec("UPDATE placements SET accounting_entity_id=77,workers_comp_class='8810' WHERE id=1");
    $pdo->exec("UPDATE placements SET jobdiva_job_id='job002' WHERE id=1");
    $pdo->exec('UPDATE people SET entity_id=77 WHERE id=1');
    $pdo->exec("UPDATE core_record_numbers SET canonical_record_id=99 WHERE entity_type='placement'");
    $pdo->exec("INSERT INTO external_entity_mappings VALUES(11,'jobdiva','person',3,'candidateAlpha')");
    $changed=fullCoreStaffingPilotInventory($pdo,$pilot);
    $check($changed['placement_fields']['accounting_entity_id']==='present'&&$changed['placement_fields']['workers_comp_class']==='present','nonempty dimensions report presence without certifying their correctness');
    $check($changed['persisted_numbers']['placement']==='alias_requires_review','aliased record is not silently accepted as the canonical pilot');
    $check($changed['jobdiva_identity']['person']['status']==='mismatch_or_ambiguous','conflicting reverse mapping is not accepted');
    $check($changed['jobdiva_identity']['job']['status']==='mismatch','a different source job cannot be inferred from the matching placement identity');
    $check($changed['employer_references']['placement']==='active_in_expected_directory'
        &&$changed['employer_references']['person']==='active_in_expected_directory','both employer reference paths check the active entity in the explicit accounting owner');
    $pdo->exec('UPDATE accounting_entities SET active=0 WHERE id=77');
    $inactive=fullCoreStaffingPilotInventory($pdo,$pilot);
    $check($inactive['employer_references']['placement']==='unresolved_reference'
        &&$inactive['employer_references']['person']==='unresolved_reference','inactive entity does not establish employer coverage');
    $pdo->exec("DELETE FROM external_entity_mappings WHERE tenant_id=11 AND internal_entity_type='placement'");
    $pdo->exec("DELETE FROM core_record_numbers WHERE entity_type='placement'");
    $absent=fullCoreStaffingPilotInventory($pdo,$pilot);
    $check($absent['jobdiva_identity']['placement']['status']==='missing'&&$absent['persisted_numbers']['placement']==='unallocated','missing mapping and unallocated persisted number stay explicit');
    $pdo->exec('ALTER TABLE placements DROP COLUMN jobdiva_job_id');
    $pdo->exec('DROP TABLE accounting_entities');
    $pdo->exec('ALTER TABLE external_entity_mappings DROP COLUMN external_id'); $pdo->exec('DROP TABLE core_record_numbers');
    $legacy=fullCoreStaffingPilotInventory($pdo,$pilot);
    $check($legacy['jobdiva_identity']['person']['status']==='schema_unavailable'&&$legacy['persisted_numbers']['placement']==='schema_unavailable','legacy schema does not fabricate source or canonical number evidence');
    $check($legacy['jobdiva_identity']['job']['status']==='schema_unavailable','missing source job column remains unavailable');
    $check($legacy['employer_references']['placement']==='schema_unavailable'
        &&$legacy['employer_references']['person']==='schema_unavailable','missing accounting schema does not establish an employer from an integer alone');
    $pdo->exec('UPDATE tenants SET is_active=0 WHERE id=11');
    $check(fullCoreStaffingPilotInventory($pdo,$pilot)['status']==='workspace_identity_mismatch','inactive workspace stops inspection');
    $pdo->exec('UPDATE tenants SET is_active=1 WHERE id=11');
    $pdo->exec('DROP TABLE tenant_module_scope');
    $partial=fullCoreStaffingDirectoryInventory($pdo,['Synthetic child']);
    $check($partial['status']==='partial'&&$partial['workspaces'][0]['configured_scopes']===null,'missing scope schema is explicit rather than empty policy');
    $pdo->exec('DROP TABLE tenants');
    $check(fullCoreStaffingDirectoryInventory($pdo,['Synthetic child'])['status']==='unavailable','missing tenant metadata is not presented as an empty directory');
    $check(fullCoreStaffingPilotInventory($pdo,$pilot)['status']==='unavailable','missing required pilot schema stops record inspection');
    echo "$passed passed\n";
} finally {
    if($pdo->inTransaction())$pdo->rollBack();
    $pdo->exec('DROP DATABASE '.$db);
}
