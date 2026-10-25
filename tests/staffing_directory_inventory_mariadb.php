<?php
declare(strict_types=1);
$ci=getenv('GITHUB_ACTIONS')==='true'&&getenv('CI')==='true';
$local=getenv('COREFLUX_DELIVERY_LOCAL_FIXTURE')==='1'&&in_array('--confirm-disposable-fixture',$argv,true)&&(string)getenv('COREFLUX_DELIVERY_TEST_PASSWORD')!=='';
if (!$ci&&!$local) throw new RuntimeException('Explicit disposable fixture configuration required');
require_once __DIR__.'/../deploy/staffing_directory_inventory.php';
require_once __DIR__.'/../deploy/staffing_pilot_alignment.php';
require_once __DIR__.'/../deploy/staffing_pilot_commercial_alignment.php';
require_once __DIR__.'/../deploy/staffing_pilot_entity_inheritance.php';
require_once __DIR__.'/staffing_pilot_entity_apply_cases.php';
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
    $pdo->exec('ALTER TABLE external_entity_mappings ADD COLUMN payload_snapshot JSON, ADD COLUMN last_synced_at DATETIME');
    $snapshot=['_jd_contract'=>['start_id'=>'start001','branch'=>'Reviewed division','workers_comp_class'=>'0',
        'worksite_country'=>'United States','start_date'=>'05/18/2026','end_date'=>null,'end_date_present'=>true,
        'engagement_type'=>'w2','placement_status'=>'active','private_sentinel'=>'DO-NOT-EMIT-WORKER-DATA']];
    $s=$pdo->prepare('UPDATE external_entity_mappings SET payload_snapshot=:p,last_synced_at="2026-10-24 12:00:00" WHERE tenant_id=11 AND internal_entity_type="placement"');
    $s->execute(['p'=>json_encode($snapshot)]);
    $pilot['company_directory_id']=11;
    $pdo->exec('ALTER TABLE placements ADD COLUMN client_id BIGINT, ADD COLUMN end_client_company_id BIGINT');
    $pdo->exec('CREATE TABLE companies(id BIGINT PRIMARY KEY,tenant_id BIGINT,deleted_at DATETIME NULL)');
    $pdo->exec('INSERT INTO companies VALUES(77,11,NULL),(88,12,NULL)');
    $pdo->exec('CREATE TABLE staffing_clients(id BIGINT PRIMARY KEY,tenant_id BIGINT,company_id BIGINT)');
    $pdo->exec('INSERT INTO staffing_clients VALUES(77,11,77),(88,12,77)');
    $pdo->exec('UPDATE placements SET client_id=77,end_client_company_id=88 WHERE id=1');
    $pdo->exec("INSERT INTO external_entity_mappings(tenant_id,source_system,internal_entity_type,internal_entity_id,external_id)
        VALUES(11,'jobdiva','company',77,'companyAlpha'),(12,'jobdiva','company',88,'companyAlpha')");
    $pdo->exec('CREATE TABLE placement_rates(id BIGINT PRIMARY KEY,tenant_id BIGINT,placement_id BIGINT,
        effective_from DATE,effective_to DATE,superseded_by BIGINT,approved_at DATETIME NULL,
        bill_rate DECIMAL(10,4),pay_rate DECIMAL(10,4),bill_rate_unit VARCHAR(20),pay_rate_unit VARCHAR(20),currency CHAR(3))');
    $pdo->exec("INSERT INTO placement_rates VALUES
        (1,11,1,'2026-01-01',NULL,NULL,NULL,98,65,'hour','hour','USD'),
        (2,11,1,'2026-01-01',NULL,NULL,'2026-01-01',98,60,'hour','hour','USD'),
        (3,12,1,'2026-01-01',NULL,NULL,NULL,999,999,'hour','hour','USD'),
        (4,11,1,'2027-01-01',NULL,NULL,NULL,999,999,'hour','hour','USD'),
        (5,11,1,'2025-01-01','2025-12-31',NULL,NULL,999,999,'hour','hour','USD'),
        (6,11,1,'2026-01-01',NULL,1,NULL,999,999,'hour','hour','USD')");
    $commercialContract=['start_id'=>'start001','engagement_type'=>'w2','bill_rate'=>98,'pay_rate'=>65,
        'bill_rate_unit'=>'hour','pay_rate_unit'=>'hour','currency'=>'USD','client_company_id'=>'companyAlpha'];
    $pdo->exec('ALTER TABLE accounting_entities ADD COLUMN code VARCHAR(32),ADD COLUMN legal_name VARCHAR(200)');
    $pdo->exec("UPDATE accounting_entities SET code='SYN',legal_name='Synthetic employer'");
    $pdo->exec('ALTER TABLE placements ADD COLUMN coreflux_overridden_fields JSON');
    $profileSource=['candidate_id'=>'candidateAlpha','fields'=>[
        'first_name'=>['status'=>'present','value'=>'PRIVATE-FIRST'],
        'last_name'=>['status'=>'present','value'=>'PRIVATE-LAST'],
        'email_primary'=>['status'=>'present','value'=>'private@example.test'],
        'phone_primary'=>['status'=>'present','value'=>'+15551234567']]];
    $pdo->exec('SET TRANSACTION READ ONLY');$pdo->beginTransaction();
    $profile=fullCoreStaffingPilotPersonAlignment($pdo,$pilot,$profileSource);
    $check(array_values(array_unique($profile['fields']))===['core_column_unavailable'],'missing person columns remain visible without blocking the scoped identity check');
    $pdo->rollBack();
    $pdo->exec('ALTER TABLE people ADD COLUMN first_name VARCHAR(100), ADD COLUMN last_name VARCHAR(100), ADD COLUMN email_primary VARCHAR(255), ADD COLUMN phone_primary VARCHAR(100)');
    $pdo->exec("UPDATE people SET first_name='PRIVATE-FIRST',last_name='PRIVATE-OVERRIDE',email_primary='PRIVATE@example.test',phone_primary='+1 (555) 123-4567' WHERE id=1");
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
    $profileBefore=$pdo->query('SELECT * FROM people ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $mappingBefore=$pdo->query('SELECT * FROM external_entity_mappings ORDER BY tenant_id,internal_entity_type,internal_entity_id')->fetchAll(PDO::FETCH_ASSOC);
    $profile=fullCoreStaffingPilotPersonAlignment($pdo,$pilot,$profileSource);
    $check($profile['fields']===['first_name'=>'matches_fresh_source','last_name'=>'differs_from_fresh_source',
        'email_primary'=>'matches_fresh_source','phone_primary'=>'matches_fresh_source'],'person comparison uses the explicit shared owner and preserves a differing Core name');
    $check(!str_contains(json_encode($profile),'PRIVATE')&&!$profile['records_changed'],'person comparison never emits private values or proposes writes');
    $check(fullCoreStaffingPilotPersonAlignment($pdo,array_replace($pilot,['person_directory_id'=>12]),$profileSource)['status']==='identity_not_verified','foreign person owner cannot establish profile comparison');
    $check(fullCoreStaffingPilotPersonAlignment($pdo,array_replace($pilot,['person_id'=>4]),$profileSource)['status']==='identity_not_verified','unrelated worker cannot be compared through the pilot placement');
    $check(fullCoreStaffingPilotPersonAlignment($pdo,$pilot,array_replace($profileSource,['candidate_id'=>'other']))['status']==='source_person_mismatch','a different provider candidate cannot supply profile values');
    foreach(['missing','ambiguous','unrecognized'] as $state){
        $different=$profileSource;$different['fields']['email_primary']=['status'=>$state,'value'=>null];
        $check(fullCoreStaffingPilotPersonAlignment($pdo,$pilot,$different)['fields']['email_primary']==='source_value_'.$state,'source contact '.$state.' cannot become a false match');
    }
    $check($pdo->query('SELECT * FROM people ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$profileBefore
        &&$pdo->query('SELECT * FROM external_entity_mappings ORDER BY tenant_id,internal_entity_type,internal_entity_id')->fetchAll(PDO::FETCH_ASSOC)===$mappingBefore,
        'profile comparisons preserve every person, source snapshot and mapping row');
    $alignment=fullCoreStaffingPilotAlignment($pdo,$pilot);
    $check($alignment['status']==='compared'&&$alignment['fields']['branch']==='matches_stored_source',
        'read-only alignment compares a verified assignment snapshot with current values');
    $check($alignment['fields']['end_date']==='matches_explicit_open_end',
        'explicit source open end is distinguished from missing end-date evidence');
    $check($alignment['fields']['workers_comp_class']==='source_value_unrecognized',
        'source WC zero does not certify a compensation class');
    $check($alignment['fields']['start_date']==='core_column_unavailable'&&$alignment['source_last_synced_at']==='2026-10-24 12:00:00',
        'missing Core schema and stored source freshness remain visible');
    $check(!$alignment['records_changed']&&!$alignment['source_refreshed']
        &&!str_contains(json_encode($alignment),'DO-NOT-EMIT')&&!str_contains(json_encode($alignment),'Reviewed division'),
        'alignment emits no source values or false live-refresh evidence');
    $fresh=$snapshot;$fresh['_jd_contract']['branch']='Fresh provider division';
    $freshAlignment=fullCoreStaffingPilotAlignment($pdo,$pilot,$fresh);
    $check($freshAlignment['status']==='compared'&&$freshAlignment['fields']['branch']==='differs_from_stored_source'
        &&$freshAlignment['source_last_synced_at']===null&&!$freshAlignment['source_refreshed']&&!$freshAlignment['records_changed'],
        'supplied source evidence is compared read-only without inheriting stored freshness or claiming a refresh');
    $check(fullCoreStaffingPilotAlignment($pdo,$pilot)===$alignment,
        'supplied source comparison leaves the saved snapshot and sync timestamp unchanged');
    $check(fullCoreStaffingPilotAlignment($pdo,array_replace($pilot,['person_id'=>4]),$fresh)['status']==='identity_not_verified',
        'supplied source cannot bypass the original placement and person identity');
    $fresh['_jd_contract']['start_id']='unrelated-start';
    $check(fullCoreStaffingPilotAlignment($pdo,$pilot,$fresh)['status']==='contract_assignment_mismatch',
        'supplied source contract must still echo the exact original assignment');
    $current=['branch'=>'Core override','workers_comp_class'=>'8810','worksite_country'=>'US','worksite_city'=>null,
        'worksite_state'=>'NJ','start_date'=>'2026-05-18','end_date'=>'2026-12-31','engagement_type'=>'w2','status'=>'active'];
    $comparison=fullCorePilotSnapshotFieldComparison($current,$snapshot,'start001');
    $check($comparison['fields']['branch']==='differs_from_stored_source', 'Core override is reported as a difference without proposing an overwrite');
    $check($comparison['fields']['worksite_country']==='matches_stored_source'&&$comparison['fields']['start_date']==='matches_stored_source',
        'recognized country aliases and explicit dates compare canonically');
    $dated=$snapshot;$dated['_jd_contract']['start_date']='2026-05-18T23:59:59.000-0400';
    $check(fullCorePilotSnapshotFieldComparison($current,$dated,'start001')['fields']['start_date']==='matches_stored_source',
        'JobDiva local ISO timestamps retain their calendar business date');
    foreach ([1779062400,1779062400000] as $timestamp) {
        $dated['_jd_contract']['start_date']=$timestamp;
        $check(fullCorePilotSnapshotFieldComparison($current,$dated,'start001')['fields']['start_date']==='matches_stored_source',
            'JobDiva epoch timestamps compare to the imported business date');
    }
    $division=$snapshot;$division['_jd_contract']['branch']='';$division['_jd_contract']['job_division']='Core override';
    $check(fullCorePilotSnapshotFieldComparison($current,$division,'start001')['fields']['branch']==='matches_stored_source',
        'the reviewed assignment division supplies the documented branch default only when branch is absent');
    $division['_jd_contract']['branch']='Explicit source branch';
    $check(fullCorePilotSnapshotFieldComparison($current,$division,'start001')['fields']['branch']==='differs_from_stored_source',
        'explicit source branch takes precedence over the assignment division');
    $check($comparison['fields']['end_date']==='differs_from_explicit_open_end', 'obsolete Core end date differs from an explicit source open end');
    $check($comparison['fields']['worksite_city']==='source_value_missing'&&$comparison['fields']['worksite_state']==='source_value_missing',
        'missing source data cannot be treated as a verified match');
    $conflicted=$snapshot; $conflicted['_jd_contract']['classification_review']=['status'=>'conflict'];
    $check(fullCorePilotSnapshotFieldComparison($current,$conflicted,'start001')['fields']['engagement_type']==='source_classification_needs_review',
        'matching classification text cannot hide conflicting source classification evidence');
    $unknown=$snapshot;$unknown['_jd_contract']['engagement_type']='W2 or 1099';
    $check(fullCorePilotSnapshotFieldComparison($current,$unknown,'start001')['fields']['engagement_type']==='source_value_unrecognized',
        'unrecognized compound classification is never a valid source value');
    $bad=$snapshot; $bad['_jd_contract']['start_date']='02/30/2026';$bad['_jd_contract']['worksite_country']='United nonsense';
    $badComparison=fullCorePilotSnapshotFieldComparison($current,$bad,'start001');
    $check($badComparison['fields']['start_date']==='source_value_unrecognized'&&$badComparison['fields']['worksite_country']==='source_value_unrecognized',
        'invalid source dates and countries are not normalized into valid matches');
    $bad=$snapshot;unset($bad['_jd_contract']['end_date_present']);
    $check(fullCorePilotSnapshotFieldComparison($current,$bad,'start001')['fields']['end_date']==='source_value_missing',
        'a null end date without presence evidence is not an explicit open end');
    $check(fullCorePilotSnapshotFieldComparison($current,$snapshot,'START001')['status']==='contract_assignment_mismatch',
        'source contract identity requires the exact opaque assignment ID');
    $check(fullCorePilotSnapshotFieldComparison($current,[],'start001')['status']==='contract_unavailable',
        'missing source contract is not inferred from other records');
    $check(fullCoreStaffingPilotAlignment($pdo,array_replace($pilot,['person_id'=>4]))['status']==='identity_not_verified',
        'field comparison cannot bypass the original person and placement identity check');
    $pdo->rollBack();
    $pdo->exec('SET TRANSACTION READ ONLY');$pdo->beginTransaction();
    $inheritance=fullCorePilotEntityInheritance($pdo,$pilot,11);
    $check($inheritance['status']==='unique_existing_default'&&$inheritance['can_follow_existing_sync_rule']
        &&$inheritance['existing_default']['id']===77&&!$inheritance['records_changed'],
        'existing sync default is observable without filling the missing placement reference');
    $check($inheritance['person_reference']==='unresolved_reference'&&$inheritance['entity_override']==='absent',
        'person employer and placement inheritance remain distinct');
    $check(fullCorePilotEntityInheritance($pdo,$pilot,12)['status']==='effective_accounting_directory_mismatch',
        'deployed accounting scope must match the reviewed directory');
    $commercial=fullCoreStaffingPilotCommercialAlignment($pdo,$pilot,$commercialContract,'2026-10-25');
    $check($commercial['rates']['draft_versions']===1&&$commercial['rates']['approved_versions']===1,
        'commercial inspection excludes foreign, future, expired and superseded rate versions');
    $check($commercial['rates']['versions'][0]['fields']['pay_rate']==='matches_fresh_source'
        &&$commercial['rates']['versions'][1]['fields']['pay_rate']==='differs_from_fresh_source',
        'matching current draft cannot conceal different approved pay');
    $check($commercial['billing_client_company']['status']==='matches_fresh_source',
        'billing company follows its exact scoped provider mapping and client link, not the end-client field');
    $check(fullCoreStaffingPilotCommercialAlignment($pdo,array_replace($pilot,['company_directory_id'=>12]),$commercialContract,'2026-10-25')['billing_client_company']['status']==='source_company_directory_or_active_record_mismatch',
        'company mapping cannot borrow a record from another directory');
    $check(fullCoreStaffingPilotCommercialAlignment($pdo,array_replace($pilot,['person_id'=>4]),$commercialContract,'2026-10-25')['status']==='identity_not_verified',
        'commercial inspection retains the exact worker gate');
    $check(!str_contains(json_encode($commercial),'companyAlpha')&&!str_contains(json_encode($commercial),'98.0000'),
        'commercial database report excludes company identifiers and rate values');
    $pdo->rollBack();
    testPilotEntityApply($pdo,$pilot,$check);
    $pdo->exec("UPDATE placements SET coreflux_overridden_fields='[\"accounting_entity_id\"]' WHERE id=1");
    $check(!fullCorePilotEntityInheritance($pdo,$pilot,11)['can_follow_existing_sync_rule'],
        'an explicit empty entity override is preserved');
    $pdo->exec("UPDATE placements SET coreflux_overridden_fields='{}' WHERE id=1");
    $check(!fullCorePilotEntityInheritance($pdo,$pilot,11)['can_follow_existing_sync_rule'],
        'unrecognized override shape does not grant inheritance');
    $pdo->exec('UPDATE placements SET coreflux_overridden_fields=NULL WHERE id=1');
    $pdo->exec("INSERT INTO accounting_entities VALUES(99,11,1,'SECOND','Second synthetic employer')");
    $check(fullCorePilotEntityInheritance($pdo,$pilot,11)['status']==='multiple_active_entities',
        'multiple active employers are surfaced instead of silently accepting the first row');
    $pdo->exec('DELETE FROM accounting_entities WHERE id=99');
    $pdo->exec('UPDATE accounting_entities SET active=0 WHERE id=77');
    $check(fullCorePilotEntityInheritance($pdo,$pilot,11)['status']==='active_entity_setup_missing',
        'inactive entities cannot become the inherited employer');
    $pdo->exec('UPDATE accounting_entities SET active=1 WHERE id=77');
    $pdo->exec('UPDATE placements SET accounting_entity_id=77 WHERE id=1');
    $selectedEntity=fullCorePilotEntityInheritance($pdo,$pilot,11);
    $check($selectedEntity['placement_matches_default']&&!$selectedEntity['can_follow_existing_sync_rule'],
        'an existing entity is preserved rather than treated as a repair');
    $pdo->exec('UPDATE placements SET accounting_entity_id=0 WHERE id=1');
    $pdo->exec("INSERT INTO external_entity_mappings(tenant_id,source_system,internal_entity_type,internal_entity_id,external_id)
        VALUES(11,'jobdiva','company',77,'duplicateCompany')");
    $check(fullCoreStaffingPilotCommercialAlignment($pdo,$pilot,$commercialContract,'2026-10-25')['billing_client_company']['status']==='source_mapping_ambiguous',
        'reverse company mapping ambiguity prevents a false client match');
    $pdo->exec("DELETE FROM external_entity_mappings WHERE external_id='duplicateCompany'");
    $pdo->exec('UPDATE placements SET client_id=88 WHERE id=1');
    $check(fullCoreStaffingPilotCommercialAlignment($pdo,$pilot,$commercialContract,'2026-10-25')['billing_client_company']['status']==='core_client_company_unresolved',
        'a staffing client in another tenant does not resolve the company');
    $pdo->exec('UPDATE placements SET client_id=77 WHERE id=1');
    $pdo->exec("INSERT INTO placement_rates SELECT 7,tenant_id,placement_id,effective_from,effective_to,superseded_by,approved_at,bill_rate,pay_rate,bill_rate_unit,pay_rate_unit,currency FROM placement_rates WHERE id=1");
    $check(fullCoreStaffingPilotCommercialAlignment($pdo,$pilot,$commercialContract,'2026-10-25')['rates']['status']==='ambiguous_current_versions',
        'multiple current drafts are not silently resolved by row order');
    $pdo->exec("UPDATE placements SET accounting_entity_id=77,workers_comp_class='8810' WHERE id=1");
    $pdo->exec("UPDATE placements SET jobdiva_job_id='job002' WHERE id=1");
    $pdo->exec('UPDATE people SET entity_id=77 WHERE id=1');
    $pdo->exec("UPDATE core_record_numbers SET canonical_record_id=99 WHERE entity_type='placement'");
    $pdo->exec("INSERT INTO external_entity_mappings(tenant_id,source_system,internal_entity_type,internal_entity_id,external_id) VALUES(11,'jobdiva','person',3,'candidateAlpha')");
    $changed=fullCoreStaffingPilotInventory($pdo,$pilot);
    $check($changed['placement_fields']['accounting_entity_id']==='present'&&$changed['placement_fields']['workers_comp_class']==='present','nonempty dimensions report presence without certifying their correctness');
    $check($changed['persisted_numbers']['placement']==='alias_requires_review','aliased record is not silently accepted as the canonical pilot');
    $check($changed['jobdiva_identity']['person']['status']==='mismatch_or_ambiguous','conflicting reverse mapping is not accepted');
    $check(fullCoreStaffingPilotAlignment($pdo,$pilot)['status']==='identity_not_verified',
        'ambiguous source mapping blocks all field comparison');
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
