<?php
/** Exercise the exact pending pilot migrations on populated, disposable old-schema tables. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('COREFLUX_ENV')!=='coreaccounting'
    ||getenv('COREFLUX_STANDALONE_DATABASE')!=='coreaccounting_ci'
    ||!in_array('--confirm-disposable-fixture',$argv,true))exit(2);
require_once __DIR__.'/../core/db.php';
require_once __DIR__.'/../deploy/thunderhawk_time_payroll_schema.php';
$source=getDB();
if(!$source||$source->query('SELECT DATABASE()')->fetchColumn()!=='coreaccounting_ci')exit(2);
$scratch='coreaccounting_time_payroll_upgrade';$created=false;$locked=false;$pdo=null;$checks=[];
$check=static function(bool $ok,string $name)use(&$checks):void{if(!$ok)throw new RuntimeException('FAIL '.$name);$checks[]=$name;echo 'PASS '.$name."\n";};
$plan=thunderhawkTimePayrollMigrationPlan(dirname(__DIR__));
$connect=static fn()=>new PDO('mysql:host='.DB_HOST.';dbname='.$scratch.';charset=utf8mb4',DB_USER,DB_PASS,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$baseline=null;
try{
    $source->exec('CREATE DATABASE '.$scratch);$created=true;$pdo=$connect();
    foreach([...array_keys(TH_TIME_PAYROLL_ADDED_COLUMNS),'_migrations']as$t)$pdo->exec('CREATE TABLE `'.$t.'` LIKE coreaccounting_ci.`'.$t.'`');
    $pdo->exec('ALTER TABLE companies DROP INDEX uq_companies_tenant_active_name');
    foreach(TH_TIME_PAYROLL_ADDED_COLUMNS as$t=>$cols)$pdo->exec('ALTER TABLE `'.$t.'` '.implode(',',array_map(static fn($c)=>'DROP COLUMN `'.$c.'`',$cols)));
    $pdo->exec('ALTER TABLE companies ADD UNIQUE KEY uq_companies_tenant_name(tenant_id,name)');
    $pdo->exec('ALTER TABLE payroll_line_items MODIFY hours_regular DECIMAL(8,2) NOT NULL DEFAULT 0, MODIFY hours_overtime DECIMAL(8,2) NOT NULL DEFAULT 0');
    $pdo->exec('ALTER TABLE payroll_earnings MODIFY hours DECIMAL(8,2) NULL');
    $pdo->exec('CREATE TABLE pilot_unrelated_records(id INT PRIMARY KEY,value VARCHAR(50)) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO pilot_unrelated_records VALUES(1,'preserve unrelated identity')");
    $pdo->exec("INSERT INTO companies(id,tenant_id,name,deleted_at) VALUES
        (1,2002,'CI active company',NULL),
        (2,2002,'CI retired company','2026-10-01 00:00:00')");
    $pdo->exec("INSERT INTO staffing_clients(id,tenant_id,name) VALUES(1,2002,'CI existing client')");
    $pdo->prepare('INSERT INTO approval_tokens(tenant_id,token_hash,subject_type,subject_id,actions_json,expires_at) VALUES(2002,?,"timesheet",123,?,"2026-12-01 00:00:00")')->execute([hash('sha256','synthetic-revoked-proof'),'["approve","reject"]']);
    $pdo->exec('INSERT INTO payroll_runs(id,tenant_id,pay_period_id,status,gross_total_cents,net_total_cents) VALUES(1,2002,123,"approved",520000,400000)');
    $pdo->exec('INSERT INTO payroll_line_items(id,tenant_id,run_id,employee_id,work_state,pay_type,pay_rate_cents,pay_frequency,hours_regular,hours_overtime,gross_cents,net_cents,status) VALUES
        (1,2002,1,321,"NJ","hourly",6500,"biweekly",79.99,0.01,520000,400000,"approved"),
        (2,2002,1,322,"NJ","hourly",2000,"biweekly",0,999999.99,0,0,"computed")');
    $pdo->exec('INSERT INTO payroll_earnings(tenant_id,line_item_id,code,hours,rate_cents,amount_cents) VALUES
        (2002,1,"regular",79.99,6500,519935),(2002,1,"overtime",0.01,6500,65),(2002,2,"bonus",NULL,NULL,0)');
    $pdo->prepare('INSERT INTO _migrations(filename,sha256,last_error,duration_ms) VALUES("prior-fixture.sql",?,NULL,1)')->execute([str_repeat('a',64)]);
    $baseline=thunderhawkTimePayrollSchemaBaseline($pdo,$plan);
    $check(count($plan)===7,'all seven pending migrations are pinned to reviewed source');
    $check($baseline['tables']['payroll_runs']['rows']===1&&$baseline['tables']['payroll_earnings']['rows']===3,'old approved payroll and mixed historical earnings are populated');
    thunderhawkTimePayrollLock($pdo);$locked=true;
    $blocked=static function(string $sql)use($connect):bool{
        $other=$connect();$other->exec('SET SESSION lock_wait_timeout=1');$other->exec('SET SESSION innodb_lock_wait_timeout=1');
        try{$other->exec($sql);return false;}catch(PDOException $e){return in_array((int)($e->errorInfo[1]??0),[1205],true);}
    };
    $check($blocked('UPDATE payroll_runs SET net_total_cents=1 WHERE id=1'),'independent payroll writer waits for the upgrade lock');
    $check($blocked("UPDATE pilot_unrelated_records SET value='changed' WHERE id=1"),'unrelated business writer is fenced during the upgrade');
    $journal=[];
    thunderhawkTimePayrollApply($pdo,$plan,static function(array $event)use(&$journal):void{$journal[]=$event;});
    $report=thunderhawkTimePayrollPreserved($pdo,$baseline,$plan);
    $check($report['applied_migrations']===7&&$report['new_evidence_tables']===4,'all migrations complete and new evidence tables are empty');
    $check($report['preserved_tables']===8,'every prior table retains its original projected values');
    $check(count(array_filter($journal,static fn($e)=>$e['phase']==='migration_complete'))===7,'each applied migration has a completion receipt');
    $check(count(array_filter($journal,static fn($e)=>$e['phase']==='statement_start'))===count(array_filter($journal,static fn($e)=>$e['phase']==='statement_complete')),'every SQL statement has matching before and after journal events');
    $check($blocked('UPDATE payroll_runs SET net_total_cents=1 WHERE id=1'),'payroll writer remains fenced after implicit-commit DDL');
    $check($blocked("UPDATE pilot_unrelated_records SET value='changed' WHERE id=1"),'unrelated writer remains fenced after DDL');
    $check($pdo->query('SELECT hours_regular FROM payroll_line_items WHERE id=1')->fetchColumn()==='79.9900','existing decimal hours widen without rounding');
    $check($pdo->query('SELECT hours_overtime FROM payroll_line_items WHERE id=2')->fetchColumn()==='999999.9900','maximum legacy precision survives widening');
    $check($pdo->query('SELECT hours FROM payroll_earnings WHERE code="bonus"')->fetchColumn()===null,'null historical earning hours remain null');
    $check($pdo->query('SELECT computation_revision FROM payroll_runs WHERE id=1')->fetchColumn()===0,'old approval receives no fabricated calculation version');
    $check($pdo->query('SELECT approved_computation_sha256 FROM payroll_runs WHERE id=1')->fetchColumn()===null,'old approval receives no fabricated calculation seal');
    $check($pdo->query('SELECT issued_review_json FROM approval_tokens')->fetchColumn()===null,'old external approval token receives no invented review facts');
    $keys=$pdo->query('SELECT active_name_key FROM companies ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    $check($keys===['CI active company',null],'active company uniqueness preserves retired identity and original name');
    $indexes=$pdo->query('SHOW INDEX FROM companies')->fetchAll(PDO::FETCH_ASSOC);
    $names=array_column($indexes,'Key_name');
    $check(in_array('uq_companies_tenant_active_name',$names,true)&&!in_array('uq_companies_tenant_name',$names,true),'active-name index replaces only the legacy company index');
    $refused=false;try{thunderhawkTimePayrollSchemaBaseline($pdo,$plan);}catch(RuntimeException $e){$refused=true;}
    $check($refused,'completed upgrade cannot be blindly repeated');
    thunderhawkTimePayrollUnlock($pdo);$locked=false;

    // Recreate the disposable old shape to force a partial DDL failure.
    foreach(TH_TIME_PAYROLL_NEW_TABLES as$t)$pdo->exec('DROP TABLE `'.$t.'`');
    $pdo->exec('ALTER TABLE companies DROP INDEX uq_companies_tenant_active_name');
    foreach(TH_TIME_PAYROLL_ADDED_COLUMNS as$t=>$cols)$pdo->exec('ALTER TABLE `'.$t.'` '.implode(',',array_map(static fn($c)=>'DROP COLUMN `'.$c.'`',$cols)));
    $pdo->exec('ALTER TABLE companies ADD UNIQUE KEY uq_companies_tenant_name(tenant_id,name)');
    $pdo->exec('ALTER TABLE payroll_line_items MODIFY hours_regular DECIMAL(8,2) NOT NULL DEFAULT 0, MODIFY hours_overtime DECIMAL(8,2) NOT NULL DEFAULT 0');
    $pdo->exec('ALTER TABLE payroll_earnings MODIFY hours DECIMAL(8,2) NULL');
    foreach($plan as$m)$pdo->prepare('DELETE FROM _migrations WHERE filename=?')->execute([$m['filename']]);
    $beforeFailure=thunderhawkTimePayrollSchemaBaseline($pdo,$plan);
    $check($beforeFailure===$baseline,'rehearsal restores the exact original values before failure injection');
    thunderhawkTimePayrollLock($pdo);$locked=true;$partial=[];$failed=false;
    try{thunderhawkTimePayrollApply($pdo,$plan,static function(array $e)use(&$partial):void{
        $partial[]=$e;
        if($e['phase']==='statement_start'&&$e['path']==='core/migrations/200_client_source_ownership.sql'&&$e['statement']===4)throw new RuntimeException('CI stop before client ALTER execution');
    });}catch(RuntimeException $e){$failed=$e->getMessage()==='CI stop before client ALTER execution';}
    $check($failed,'partial migration failure stops before later source and payroll migrations');
    $check((int)$pdo->query('SELECT COUNT(*) FROM _migrations WHERE filename="199_company_active_name.sql" AND last_error IS NULL')->fetchColumn()===1,'completed DDL retains its explicit migration receipt');
    $check((int)$pdo->query('SELECT COUNT(*) FROM _migrations WHERE filename="200_client_source_ownership.sql" AND last_error IS NOT NULL')->fetchColumn()===1,'incomplete migration keeps its failure marker for reviewed recovery');
    $refused=false;try{thunderhawkTimePayrollSchemaBaseline($pdo,$plan);}catch(RuntimeException $e){$refused=true;}
    $check($refused,'partial upgrade is refused as a fresh attempt');
    $projected=thunderhawkTimePayrollTableState($pdo,$baseline['tables']);
    unset($projected['_migrations']);$expected=$baseline['tables'];unset($expected['_migrations']);
    $check($projected===$expected,'partial DDL failure preserves all original business values');
    thunderhawkTimePayrollUnlock($pdo);$locked=false;
}finally{
    if($locked&&$pdo)thunderhawkTimePayrollUnlock($pdo);
    $pdo=null;
    if($created)$source->exec('DROP DATABASE '.$scratch);
}
$check((int)$source->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='".$scratch."'")->fetchColumn()===0,'disposable upgrade schema and synthetic rows are removed');
echo count($checks)." populated pilot upgrade database checks passed\n";
