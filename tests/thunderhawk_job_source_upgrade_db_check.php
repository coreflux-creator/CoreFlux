<?php
/** Real populated job upgrade, native contention and interruption recovery in a disposable DB. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('COREFLUX_ENV')!=='coreaccounting'||getenv('COREFLUX_STANDALONE_DATABASE')!=='coreaccounting_ci'||!in_array('--confirm-disposable-fixture',$argv,true))exit(2);
require_once __DIR__.'/../core/db.php';require_once __DIR__.'/../deploy/thunderhawk_job_source_upgrade.php';require_once __DIR__.'/../deploy/thunderhawk_pilot_backup.php';
$source=getDB();if($source->query('SELECT DATABASE()')->fetchColumn()!=='coreaccounting_ci')exit(2);
$scratch='coreaccounting_job_source_upgrade';$created=false;$locked=false;$pdo=null;$directory=null;$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException('FAIL '.$label);$checks++;echo 'PASS '.$label."\n";};
$refuses=static function(callable $fn,string $text):bool{try{$fn();return false;}catch(Throwable $e){if(!str_contains($e->getMessage(),$text))throw $e;return true;}};
$connect=static fn()=>new PDO('mysql:host='.DB_HOST.';dbname='.$scratch.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$plan=thunderhawkJobSourcePlan(dirname(__DIR__));$journal=[];$record=static function(array $event)use(&$journal):void{$journal[]=$event;};
try{
    $source->exec('CREATE DATABASE '.$scratch);$created=true;$pdo=$connect();
    foreach(['staffing_jobs','_migrations']as$table)$pdo->exec('CREATE TABLE `'.$table.'` LIKE coreaccounting_ci.`'.$table.'`');
    $pdo->exec('ALTER TABLE staffing_jobs DROP COLUMN coreflux_source_state');
    $pdo->prepare('INSERT INTO _migrations SELECT * FROM coreaccounting_ci._migrations WHERE filename<>?')->execute([$plan['filename']]);
    $pdo->exec("INSERT INTO staffing_jobs(tenant_id,title,status,external_id,source_system,location_country) VALUES(2002,'Core-owned closed role','closed','fixture-001','jobdiva','US'),(2,'Manual role','open',NULL,'manual','CA')");
    $pdo->exec('CREATE TABLE pilot_original_rows(id INT PRIMARY KEY,payload LONGBLOB) ENGINE=InnoDB');
    $binary="\0\xff keep original job ' \" ;\n";$pdo->prepare('INSERT INTO pilot_original_rows VALUES(1,?)')->execute([$binary]);
    $before=thunderhawkJobSourceBaseline($pdo,$plan);
    $check($before['tables']['staffing_jobs']['rows']===2&&count($plan['statements'])===5,'pinned migration starts from two companies with original status and exact source ID');
    $check($refuses(fn()=>thunderhawkJobSourceApply($pdo,$plan,$before,$record),'writer guard'),'migration refuses an unguarded connection');
    $directory=sys_get_temp_dir().'/job-upgrade-'.bin2hex(random_bytes(12));if(!mkdir($directory,0700))throw new RuntimeException('Private backup path failed');
    $pdo->exec('SET autocommit=0');$pdo->exec('LOCK TABLES '.implode(',',array_map(static fn($table)=>'`'.$table.'` READ',array_keys($before['tables']))));$locked=true;
    $backup=thunderhawkPilotDatabaseCapture($pdo,$directory.'/before.sql');
    $check($backup['bytes']>100&&(fileperms($backup['path'])&0777)===0600&&thunderhawkTimePayrollTableState($pdo)===$before['tables'],'private native backup preserves exact original jobs and binary evidence');
    thunderhawkTimePayrollUnlock($pdo);$locked=false;
    $pdo->exec('UPDATE pilot_original_rows SET payload="Changed" WHERE id=1');thunderhawkJobSourceLock($pdo);$locked=true;
    $check($refuses(fn()=>thunderhawkJobSourceApply($pdo,$plan,$before,$record),'Original records changed'),'changed data between backup and writer locks stops migration');
    thunderhawkTimePayrollUnlock($pdo);$locked=false;$pdo->prepare('UPDATE pilot_original_rows SET payload=? WHERE id=1')->execute([$binary]);
    thunderhawkJobSourceLock($pdo);$locked=true;
    $blocked=static function(string $sql)use($connect,$refuses):bool{$other=$connect();$other->exec('SET SESSION lock_wait_timeout=1');$other->exec('SET SESSION innodb_lock_wait_timeout=1');return $refuses(fn()=>$other->exec($sql),'Lock wait timeout');};
    $check($blocked("UPDATE staffing_jobs SET title='Concurrent writer' WHERE tenant_id=2002"),'native lock excludes competing job updates');
    $proof=thunderhawkJobSourceApply($pdo,$plan,$before,$record);
    $check($proof['preserved_rows']===array_sum(array_column($before['tables'],'rows'))&&$proof['added_columns']===1&&$proof['applied_migrations']===1&&$proof['original_schema_preserved'],'upgrade adds only nullable ownership and one receipt while preserving all original values and schema');
    $check($blocked("UPDATE staffing_jobs SET status='active' WHERE tenant_id=2002")&&$blocked('UPDATE pilot_original_rows SET payload="writer" WHERE id=1'),'native table locks remain effective after DDL and commit');
    $check($refuses(fn()=>thunderhawkJobSourceApply($pdo,$plan,$before,$record),'already started'),'installation cannot blindly repeat after completion');
    $pdo->exec("UPDATE staffing_jobs SET coreflux_source_state='{}',updated_at=updated_at WHERE tenant_id=2002");
    $check($refuses(fn()=>thunderhawkJobSourceRecover($pdo,$before,$plan),'business use'),'recovery never erases newly used job ownership');$pdo->exec('ROLLBACK');
    $pdo->exec("UPDATE staffing_jobs SET title='Reviewed edit' WHERE tenant_id=2002");
    $check($refuses(fn()=>thunderhawkJobSourceRecover($pdo,$before,$plan),'Original business values changed'),'recovery never overwrites a changed original job');$pdo->exec('ROLLBACK');
    $pdo->prepare('UPDATE _migrations SET sha256=? WHERE filename=?')->execute([str_repeat('0',64),$plan['filename']]);
    $check($refuses(fn()=>thunderhawkJobSourceRecover($pdo,$before,$plan),'receipt changed'),'recovery refuses altered migration provenance');$pdo->exec('ROLLBACK');
    $pdo->exec('ALTER TABLE staffing_jobs ADD INDEX fixture_unreviewed(location_country)');
    $check($refuses(fn()=>thunderhawkJobSourceRecover($pdo,$before,$plan),'schema changed'),'recovery refuses an unreviewed index even when every original value is unchanged');
    $pdo->exec('ALTER TABLE staffing_jobs DROP INDEX fixture_unreviewed');
    thunderhawkJobSourceRecover($pdo,$before,$plan);
    $check(thunderhawkJobSourceBaseline($pdo,$plan)===$before,'paired recovery restores every original column, index, source ID, value and migration record');
    thunderhawkJobSourceRecover($pdo,$before,$plan);$check(thunderhawkJobSourceBaseline($pdo,$plan)===$before,'recovery also verifies a pre-DDL interruption');
    foreach([0,2,3,4]as$stop){
        $fail=static function(array $event)use($stop):void{if($event['phase']==='statement_complete'&&$event['index']===$stop)throw new RuntimeException('fixture migration interruption');};
        $check($refuses(fn()=>thunderhawkJobSourceApply($pdo,$plan,$before,$fail),'fixture migration interruption'),'injected interruption retains evidence after statement '.$stop);
        thunderhawkTimePayrollUnlock($pdo);$locked=false;$pdo=null;$pdo=$connect();thunderhawkJobSourceLock($pdo);$locked=true;
        thunderhawkJobSourceRecover($pdo,$before,$plan);
        $check(thunderhawkJobSourceBaseline($pdo,$plan)===$before,'fresh connection recovers exact original state after statement '.$stop);
    }
    $late=static function(array $event):void{if($event['phase']==='migration_complete')throw new RuntimeException('fixture completion receipt loss');};
    $check($refuses(fn()=>thunderhawkJobSourceApply($pdo,$plan,$before,$late),'fixture completion receipt loss')&&thunderhawkJobSourcePreserved($pdo,$before,$plan)['applied_migrations']===1,'completion receipt loss retains one recognizable applied migration');
    thunderhawkJobSourceRecover($pdo,$before,$plan);$check(thunderhawkJobSourceBaseline($pdo,$plan)===$before,'explicit recovery after receipt loss preserves original records and schema');
    thunderhawkTimePayrollUnlock($pdo);$locked=false;
}finally{
    if($locked&&$pdo)thunderhawkTimePayrollUnlock($pdo);$pdo=null;
    if($created)$source->exec('DROP DATABASE '.$scratch);
    if($directory){foreach(glob($directory.'/*')as$file)if(is_file($file)&&!is_link($file))unlink($file);rmdir($directory);}
}
echo $checks." job ownership migration and recovery checks passed\n";
