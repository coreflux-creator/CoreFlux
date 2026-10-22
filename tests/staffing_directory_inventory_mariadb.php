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
    $pdo->rollBack(); $pdo->exec('DROP TABLE tenant_module_scope');
    $partial=fullCoreStaffingDirectoryInventory($pdo,['Synthetic child']);
    $check($partial['status']==='partial'&&$partial['workspaces'][0]['configured_scopes']===null,'missing scope schema is explicit rather than empty policy');
    $pdo->exec('DROP TABLE tenants');
    $check(fullCoreStaffingDirectoryInventory($pdo,['Synthetic child'])['status']==='unavailable','missing tenant metadata is not presented as an empty directory');
    echo "$passed passed\n";
} finally {
    if($pdo->inTransaction())$pdo->rollBack();
    $pdo->exec('DROP DATABASE '.$db);
}
