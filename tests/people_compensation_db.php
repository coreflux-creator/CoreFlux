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
    foreach(['shared','isolated']as$mode){
        $owner=$mode==='shared'?$parent:$child;
        $pdo->prepare('INSERT INTO tenant_module_scope(tenant_id,module_key,scope_mode) VALUES(?,"people",?) ON DUPLICATE KEY UPDATE scope_mode=VALUES(scope_mode)')->execute([$child,$mode]);
        $pdo->prepare('INSERT INTO people_employees(tenant_id,employee_number,legal_first_name,legal_last_name) VALUES(?,?,"Synthetic","Compensation")')->execute([$owner,'COMP-'.$mode]);$employee=(int)$pdo->lastInsertId();
        setRequestTenantId($child);setRequestModuleScope('payroll');
        $base=['pay_type'=>'hourly','pay_rate_cents'=>6500,'pay_frequency'=>'biweekly','currency'=>'USD','effective_from'=>'2026-10-01'];
        $history=peopleCompensationHistory($pdo,$child,$employee);$revision=$history['history_revision'];
        $first=peopleCompensationAppend($pdo,$child,$employee,$actor,$base,$revision);
        $history=peopleCompensationHistory($pdo,$child,$employee);
        $check(!$first['replayed']&&(int)$history['compensation'][0]['tenant_id']===$owner&&$history['owner_tenant_id']===$owner,$mode.': compensation uses the current employment directory independent of caller module');
        $before=[$all('people_compensation'),$all('people_change_log')];
        $again=peopleCompensationAppend($pdo,$child,$employee,$actor,$base,$revision);
        $check($again['replayed']&&$again['id']===$first['id']&&$before===[$all('people_compensation'),$all('people_change_log')],$mode.': exact retry reuses its receipt without a duplicate or extra audit');
        $check($fails(fn()=>peopleCompensationAppend($pdo,$child,$employee,$actor,array_replace($base,['pay_rate_cents'=>6600]),$revision),409),$mode.': changed retry cannot replace a dated rate');
        $new=array_replace($base,['effective_from'=>'2026-11-01','pay_rate_cents'=>7000]);
        $check($fails(fn()=>peopleCompensationAppend($pdo,$child,$employee,$actor,$new,$revision),409),$mode.': stale history cannot close the current rate');
        $second=peopleCompensationAppend($pdo,$child,$employee,$actor,$new,$history['history_revision']);
        $check((int)peopleActiveCompensation($employee,'2026-10-31')['id']===$first['id']&&(int)peopleActiveCompensation($employee,'2026-11-01')['id']===$second['id'],$mode.': payroll includes the old rate on its last day and the new rate on its first');
        $history=peopleCompensationHistory($pdo,$child,$employee);$before=[$all('people_compensation'),$all('people_change_log')];
        $check(peopleCompensationAppend($pdo,$child,$employee,$actor,$base,$revision)['replayed']&&$before===[$all('people_compensation'),$all('people_change_log')],$mode.': original retry after a later rate retains its original result without reopening history');
        foreach([['pay_rate_cents'=>12.5],['pay_rate_cents'=>'1e3'],['pay_rate_cents'=>-1],['pay_frequency'=>'daily'],['currency'=>null],['effective_from'=>'2026-02-30'],['effective_to'=>'2026-10-31'],['created_by'=>999],['bonus_target_cents'=>false]]as$bad){
            $check($fails(fn()=>peopleCompensationAppend($pdo,$child,$employee,$actor,array_replace($new,['effective_from'=>'2026-12-01'],$bad),$history['history_revision'])),$mode.': invalid input refuses before any history change: '.json_encode($bad));
        }
        $check($before===[$all('people_compensation'),$all('people_change_log')],$mode.': rejected inputs preserve history and audits');
        $check($fails(fn()=>peopleCompensationAppend($pdo,$child,$employee,$actor,array_replace($base,['effective_from'=>'2026-10-15']),$history['history_revision']),409),$mode.': backdated insert cannot rewrite accepted history');
        // A mandatory-audit failure occurs after the prior rate is closed, and rolls it back.
        $ddl=$pdo->query('SHOW CREATE TABLE people_change_log')->fetch(PDO::FETCH_NUM)[1];
        $ddl=preg_replace('/^CREATE TABLE /','CREATE TEMPORARY TABLE ',$ddl,1);
        $pdo->exec(str_replace('`people_change_log` (','`people_change_log` (`required_fixture_value` INT NOT NULL,',$ddl));$shadow='people_change_log';
        $check($fails(fn()=>peopleCompensationAppend($pdo,$child,$employee,$actor,array_replace($new,['effective_from'=>'2026-12-01']),$history['history_revision']))&&$all('people_compensation')===$before[0],$mode.': required audit failure restores the preceding open rate');
        $pdo->exec('DROP TEMPORARY TABLE people_change_log');$shadow=null;
        // Force insertion to fail after the old row and its update audit have been written.
        $original=$all('people_compensation');$ddl=$pdo->query('SHOW CREATE TABLE people_compensation')->fetch(PDO::FETCH_NUM)[1];
        $ddl=preg_replace('/^CREATE TABLE /','CREATE TEMPORARY TABLE ',$ddl,1);
        $pdo->exec(str_replace('`people_compensation` (','`people_compensation` (`required_fixture_value` INT NOT NULL,',$ddl));$shadow='people_compensation';
        foreach($original as$row){$data=['required_fixture_value'=>1]+$row;$pdo->prepare('INSERT INTO people_compensation (`'.implode('`,`',array_keys($data)).'`) VALUES('.implode(',',array_fill(0,count($data),'?')).')')->execute(array_values($data));}
        $shadowBefore=$all('people_compensation');$shadowHistory=peopleCompensationHistory($pdo,$child,$employee);
        $check($fails(fn()=>peopleCompensationAppend($pdo,$child,$employee,$actor,array_replace($new,['effective_from'=>'2026-12-01']),$shadowHistory['history_revision']))&&$all('people_compensation')===$shadowBefore&&$all('people_change_log')===$before[1],$mode.': failed new-row insert restores the previous rate and its audit together');
        $pdo->exec('DROP TEMPORARY TABLE people_compensation');$shadow=null;
        $pdo->prepare('INSERT INTO people_compensation(tenant_id,employee_id,pay_type,pay_rate_cents,pay_frequency,currency,effective_from) VALUES(?,?,"hourly",9999,"weekly","USD","2026-11-15")')->execute([$owner,$employee]);
        $check($fails(fn()=>peopleActiveCompensation($employee,'2026-11-15'),409),$mode.': overlapping legacy rates cannot be selected arbitrarily for payroll');
        $history=peopleCompensationHistory($pdo,$child,$employee);
        $check($fails(fn()=>peopleCompensationAppend($pdo,$child,$employee,$actor,array_replace($new,['effective_from'=>'2026-12-01']),$history['history_revision']),409),$mode.': overlapping legacy history requires explicit review');
        $pdo->prepare('UPDATE tenant_module_scope SET scope_mode=? WHERE tenant_id=? AND module_key="people"')->execute([$mode==='shared'?'isolated':'shared',$child]);
        $check($fails(fn()=>peopleCompensationHistory($pdo,$child,$employee),404)&&$fails(fn()=>peopleCompensationAppend($pdo,$child,$employee,$actor,$new,$history['history_revision']),404),$mode.': changed sharing revokes both history access and an earlier rate review');
        $check(peopleCompensationActive($pdo,$child,$employee,'2026-11-01')===null,$mode.': foreign employee cannot supply payroll compensation');
    }
}finally{if($shadow)$pdo->exec('DROP TEMPORARY TABLE '.$shadow);if($pdo->inTransaction())$pdo->rollBack();setRequestTenantId(null);clearRequestModuleScope();}
$check(array_map($all,$tables)===$baseline,'Compensation fixture preserves every original payroll, time, employee, history and journal row');
echo json_encode(['passed'=>true,'checks'=>count($checks),'labels'=>$checks],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
