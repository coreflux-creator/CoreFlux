<?php
/** Canonical MariaDB employment compensation, exact replay, sharing and rollback. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('GITHUB_ACTIONS')!=='true'||getenv('CI')!=='true'||getenv('DB_NAME')!=='coreaccounting_ci')exit(2);
require_once __DIR__.'/../modules/people/lib/compensation.php';
require_once __DIR__.'/../modules/people/lib/employees.php';
require_once __DIR__.'/../core/integrations/staffing_field_contract.php';
$pdo=getDB();if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='coreaccounting_ci')exit(2);
$checks=[];$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);$checks[]=$label;};
foreach(staffingCompensationFieldDefinitions()as$field=>$definition){
    $rule=staffingFieldContractRules('people_compensation',$field);
    $check($rule['owner']==='core_employment_compensation'&&$rule['definition_status']==='reviewed_core_definition','Native compensation definition has explicit ownership: '.$field);
    foreach(['jobdiva','quanta','purepay','purepayroll']as$provider)$check($rule['remote_contracts'][$provider]['status']==='unsupported_with_gap'&&empty($rule['remote_contracts'][$provider]['mappings']),'Unimplemented dated compensation receiver stays explicit: '.$provider.'.'.$field);
}
$fails=static function(callable $f,?int $code=null):bool{try{$f();return false;}catch(Throwable $e){return $code===null||(int)$e->getCode()===$code;}};
$all=static fn(string $t):array=>$pdo->query('SELECT * FROM '.$t.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$tables=['people_employees','people_compensation','people_change_log','time_entries','payroll_runs','payroll_line_items','accounting_journal_entries'];
$baseline=array_map($all,$tables);$parent=990713;$child=990714;$shadow=null;
if($pdo->query('SELECT id FROM tenants WHERE id IN(990713,990714)')->fetchColumn())throw new RuntimeException('Compensation fixture already exists');
$pdo->beginTransaction();
try{
    $pdo->exec('INSERT INTO tenants(id,name,is_active,is_simulation,tenant_type) VALUES(990713,"Synthetic compensation parent",1,1,"master")');
    $pdo->exec('INSERT INTO tenants(id,name,is_active,is_simulation,tenant_type,parent_id) VALUES(990714,"Synthetic compensation child",1,1,"sub",990713)');
    $pdo->exec('INSERT INTO users(tenant_id,name,email,role,is_active,password,password_hash) VALUES(990713,"Synthetic compensation writer","compensation-db@example.invalid","admin",1,NULL,NULL)');$actor=(int)$pdo->lastInsertId();
    require __DIR__.'/fixtures/people_compensation_checks.php';
}finally{if($shadow)$pdo->exec('DROP TEMPORARY TABLE '.$shadow);if($pdo->inTransaction())$pdo->rollBack();setRequestTenantId(null);clearRequestModuleScope();}
$check(array_map($all,$tables)===$baseline,'Compensation fixture preserves every original payroll, time, employee, history and journal row');
echo json_encode(['passed'=>true,'checks'=>count($checks),'labels'=>$checks],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
