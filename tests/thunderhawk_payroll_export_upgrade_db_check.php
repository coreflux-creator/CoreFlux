<?php
/** Actual migration, native locks, backup and precise paired recovery on disposable tables. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('COREFLUX_ENV')!=='coreaccounting'
    ||getenv('COREFLUX_STANDALONE_DATABASE')!=='coreaccounting_ci'
    ||!in_array('--confirm-disposable-fixture',$argv,true))exit(2);
require_once __DIR__.'/../core/db.php';
require_once __DIR__.'/../deploy/thunderhawk_payroll_export_migration.php';
require_once __DIR__.'/../deploy/thunderhawk_pilot_backup.php';
$source=getDB();if($source->query('SELECT DATABASE()')->fetchColumn()!=='coreaccounting_ci')exit(2);
$scratch='coreaccounting_time_payroll_upgrade';$created=false;$locked=false;$pdo=null;$directory=null;$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException('FAIL '.$label);$checks++;echo 'PASS '.$label."\n";};
$refuses=static function(callable $fn,string $text):bool{try{$fn();return false;}catch(Throwable $e){if(!str_contains($e->getMessage(),$text))throw $e;return true;}};
$connect=static fn()=>new PDO('mysql:host='.DB_HOST.';dbname='.$scratch.';charset=utf8mb4',DB_USER,DB_PASS,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$plan=thunderhawkPayrollExportPlan(dirname(__DIR__));
try{
    $source->exec('CREATE DATABASE '.$scratch);$created=true;$pdo=$connect();
    foreach(['export_templates','_migrations']as$table)$pdo->exec('CREATE TABLE `'.$table.'` LIKE coreaccounting_ci.`'.$table.'`');
    $pdo->exec("INSERT INTO export_templates SELECT * FROM coreaccounting_ci.export_templates WHERE NOT(scope='platform' AND dataset='payroll_disbursements' AND name='Payroll calculation audit' AND is_system=1)");
    $pdo->prepare('INSERT INTO _migrations SELECT * FROM coreaccounting_ci._migrations WHERE filename<>?')->execute([$plan['filename']]);
    $pdo->exec('CREATE TABLE pilot_export_rows(id INT PRIMARY KEY,payload LONGBLOB) ENGINE=InnoDB');
    $pdo->prepare('INSERT INTO pilot_export_rows VALUES(1,?)')->execute(["\0\xff retain historical payroll ' \" ;\n"]);
    $baseline=thunderhawkPayrollExportBaseline($pdo,$plan);
    $check(count($plan['template']['column_mappings'])===26&&$baseline['tables']['export_templates']['rows']>0,
        'exact audit preset is pinned while existing tenant and platform templates remain populated');
    $directory=sys_get_temp_dir().'/payroll-export-upgrade-'.bin2hex(random_bytes(12));
    if(!mkdir($directory,0700))throw new RuntimeException('Private fixture backup directory failed');
    $pdo->exec('SET autocommit=0');$pdo->exec('LOCK TABLES '.implode(',',array_map(static fn($t)=>'`'.$t.'` READ',array_keys($baseline['tables']))));$locked=true;
    $backup=thunderhawkPilotDatabaseCapture($pdo,$directory.'/before.sql');
    $check($backup['bytes']>100&&(fileperms($backup['path'])&0777)===0600&&thunderhawkTimePayrollTableState($pdo)===$baseline['tables'],
        'native private database backup preserves all populated rows and binary values under read locks');
    thunderhawkTimePayrollUnlock($pdo);$locked=false;
    $pdo->exec('UPDATE pilot_export_rows SET payload="concurrent writer" WHERE id=1');
    thunderhawkPayrollExportLock($pdo);$locked=true;
    $journal=[];$record=static function(array $event)use(&$journal):void{$journal[]=$event;};
    $check($refuses(fn()=>thunderhawkPayrollExportApply($pdo,$plan,$baseline,$record),'Original records changed'),
        'a writer between backup and final locks invalidates the migration baseline');
    thunderhawkTimePayrollUnlock($pdo);$locked=false;
    $pdo->prepare('UPDATE pilot_export_rows SET payload=? WHERE id=1')->execute(["\0\xff retain historical payroll ' \" ;\n"]);
    thunderhawkPayrollExportLock($pdo);$locked=true;
    $blocked=static function(string $sql)use($connect,$refuses):bool{
        $other=$connect();$other->exec('SET SESSION lock_wait_timeout=1');$other->exec('SET SESSION innodb_lock_wait_timeout=1');
        return $refuses(fn()=>$other->exec($sql),'Lock wait timeout');
    };
    $check($blocked('UPDATE pilot_export_rows SET payload="writer" WHERE id=1'),'unrelated business writers wait behind the native read lock');
    $check($blocked('UPDATE export_templates SET name=name WHERE id=1'),
        'template edits wait behind the native write lock');
    $fail=static function(array $event):void{if($event['phase']==='template_inserted')throw new RuntimeException('fixture journal failure');};
    $check($refuses(fn()=>thunderhawkPayrollExportApply($pdo,$plan,$baseline,$fail),'fixture journal failure')
        &&thunderhawkPayrollExportBaseline($pdo,$plan)===$baseline,
        'journal failure after the template insert rolls back both migration and template');
    $proof=thunderhawkPayrollExportApply($pdo,$plan,$baseline,$record);
    $check($proof['added_templates']===1&&$proof['applied_migrations']===1&&$proof['preserved_tables']===3,
        'one template and one canonical migration receipt commit with every original row preserved');
    $check($blocked('UPDATE pilot_export_rows SET payload="after commit" WHERE id=1'),
        'native writer locks remain effective after the data migration commits');
    $check($refuses(fn()=>thunderhawkPayrollExportApply($pdo,$plan,$baseline,$record),'Original records changed')
        &&thunderhawkPayrollExportPreserved($pdo,$baseline,$plan)===$proof,
        'an ambiguous retry cannot insert a second template or rewrite the migration receipt');
    $pdo->prepare('UPDATE export_templates SET name="Changed audit preset" WHERE id=?')->execute([$proof['template_id']]);
    $check($refuses(fn()=>thunderhawkPayrollExportRecover($pdo,$baseline,$plan),'New audit template changed'),
        'recovery refuses to remove a template whose data changed after installation');
    $pdo->exec('ROLLBACK');
    $pdo->prepare('UPDATE _migrations SET sha256=? WHERE filename=?')->execute([str_repeat('0',64),$plan['filename']]);
    $check($refuses(fn()=>thunderhawkPayrollExportRecover($pdo,$baseline,$plan),'Export migration receipt changed'),
        'recovery refuses an altered canonical migration receipt');
    $pdo->exec('ROLLBACK');
    $existingId=(int)$baseline['templates'][0]['id'];
    $pdo->prepare('UPDATE export_templates SET name="Changed preexisting template" WHERE id=?')->execute([$existingId]);
    $check($refuses(fn()=>thunderhawkPayrollExportRecover($pdo,$baseline,$plan),'Existing export templates changed'),
        'recovery refuses if an existing tenant or platform template changed');
    $pdo->exec('ROLLBACK');
    thunderhawkPayrollExportRecover($pdo,$baseline,$plan);
    $check(thunderhawkPayrollExportBaseline($pdo,$plan)===$baseline,
        'paired recovery removes only the unchanged new preset and ledger row and restores every original value');
    thunderhawkPayrollExportRecover($pdo,$baseline,$plan);
    $check(thunderhawkPayrollExportBaseline($pdo,$plan)===$baseline,'recovery can verify a pre-commit interruption without deleting original records');
    $late=static function(array $event):void{if($event['phase']==='migration_complete')throw new RuntimeException('fixture receipt failure after commit');};
    $check($refuses(fn()=>thunderhawkPayrollExportApply($pdo,$plan,$baseline,$late),'fixture receipt failure after commit')
        &&thunderhawkPayrollExportPreserved($pdo,$baseline,$plan)['added_templates']===1,
        'lost completion receipt after commit is recognized as an already applied paired migration');
    thunderhawkPayrollExportRecover($pdo,$baseline,$plan);
    $check(thunderhawkPayrollExportBaseline($pdo,$plan)===$baseline,'explicit recovery restores original values after a lost completion receipt');
    thunderhawkTimePayrollUnlock($pdo);$locked=false;
}finally{
    if($locked&&$pdo)thunderhawkTimePayrollUnlock($pdo);
    $pdo=null;
    if($created)$source->exec('DROP DATABASE '.$scratch);
    if($directory){foreach(glob($directory.'/*')as$file)if(is_file($file)&&!is_link($file))unlink($file);rmdir($directory);}
}
echo $checks." payroll export migration and recovery checks passed\n";
