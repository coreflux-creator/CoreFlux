<?php
/** Real populated migration, native contention and interruption recovery in a disposable DB. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('COREFLUX_ENV')!=='coreaccounting'||getenv('COREFLUX_STANDALONE_DATABASE')!=='coreaccounting_ci'||!in_array('--confirm-disposable-fixture',$argv,true))exit(2);
require_once __DIR__.'/../core/db.php';require_once __DIR__.'/../deploy/thunderhawk_supplemental_time_schema.php';require_once __DIR__.'/../deploy/thunderhawk_pilot_backup.php';
$source=getDB();if($source->query('SELECT DATABASE()')->fetchColumn()!=='coreaccounting_ci')exit(2);
$scratch='coreaccounting_time_payroll_upgrade';$created=false;$locked=false;$pdo=null;$directory=null;$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException('FAIL '.$label);$checks++;echo 'PASS '.$label."\n";};
$refuses=static function(callable $fn,string $text):bool{try{$fn();return false;}catch(Throwable $e){if(!str_contains($e->getMessage(),$text))throw $e;return true;}};
$connect=static fn()=>new PDO('mysql:host='.DB_HOST.';dbname='.$scratch.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$plan=thunderhawkSupplementPlan(dirname(__DIR__));$journal=[];$record=static function(array $event)use(&$journal):void{$journal[]=$event;};
try{
    $source->exec('CREATE DATABASE '.$scratch);$created=true;$pdo=$connect();
    foreach(['staffing_timesheets','_migrations']as$table)$pdo->exec('CREATE TABLE `'.$table.'` LIKE coreaccounting_ci.`'.$table.'`');
    $pdo->exec('ALTER TABLE staffing_timesheets DROP INDEX uq_sts_tenant_person_week_supplement,DROP INDEX idx_sts_supplement_parent,ADD UNIQUE KEY uq_sts_tenant_person_week(tenant_id,person_id,period_start),DROP COLUMN supplement_sequence,DROP COLUMN supplement_of_id,DROP COLUMN supplement_review_hash');
    $pdo->prepare('INSERT INTO _migrations SELECT * FROM coreaccounting_ci._migrations WHERE filename<>?')->execute([$plan['filename']]);
    $pdo->exec('INSERT INTO staffing_timesheets(tenant_id,person_id,period_start,period_end,status,total_hours,approved_by_user_id,approved_at) VALUES(2002,387,"2026-11-09","2026-11-15","approved",2,98,"2026-11-10 12:00:00"),(2,15,"2026-11-09","2026-11-15","draft",0,NULL,NULL)');
    $pdo->exec('CREATE TABLE pilot_original_rows(id INT PRIMARY KEY,payload LONGBLOB) ENGINE=InnoDB');
    $binary="\0\xff keep original approval ' \" ;\n";$pdo->prepare('INSERT INTO pilot_original_rows VALUES(1,?)')->execute([$binary]);
    $before=thunderhawkSupplementBaseline($pdo,$plan);$check($before['tables']['staffing_timesheets']['rows']===2&&count($plan['statements'])===20,'pinned migration starts from populated original headers in two companies');
    $directory=sys_get_temp_dir().'/supplement-upgrade-'.bin2hex(random_bytes(12));if(!mkdir($directory,0700))throw new RuntimeException('Private backup path failed');
    thunderhawkSupplementLock($pdo);$locked=true;$backup=thunderhawkPilotDatabaseCapture($pdo,$directory.'/before.sql');
    $check($backup['bytes']>100&&(fileperms($backup['path'])&0777)===0600&&thunderhawkTimePayrollTableState($pdo)===$before['tables'],'native private backup preserves original header and binary evidence');
    thunderhawkTimePayrollUnlock($pdo);$locked=false;
    $pdo->exec('UPDATE pilot_original_rows SET payload="Changed" WHERE id=1');thunderhawkSupplementLock($pdo);$locked=true;
    $check($refuses(fn()=>thunderhawkSupplementApply($pdo,$plan,$before,$record),'Original records changed'),'changed business data between backup and writer locks stops migration');
    thunderhawkTimePayrollUnlock($pdo);$locked=false;$pdo->prepare('UPDATE pilot_original_rows SET payload=? WHERE id=1')->execute([$binary]);
    thunderhawkSupplementLock($pdo);$locked=true;
    $blocked=static function(string $sql)use($connect,$refuses):bool{$other=$connect();$other->exec('SET SESSION lock_wait_timeout=1');$other->exec('SET SESSION innodb_lock_wait_timeout=1');return $refuses(fn()=>$other->exec($sql),'Lock wait timeout');};
    $check($blocked('UPDATE staffing_timesheets SET total_hours=99 WHERE tenant_id=2002'),'native write lock blocks concurrent primary time changes');
    $proof=thunderhawkSupplementApply($pdo,$plan,$before,$record);
    $check($proof['preserved_rows']===array_sum(array_column($before['tables'],'rows'))&&$proof['added_columns']===3&&$proof['applied_migrations']===1,'full migration retains every original value and records one applied migration');
    $check($blocked('UPDATE staffing_timesheets SET total_hours=99 WHERE tenant_id=2002')&&$blocked('UPDATE pilot_original_rows SET payload="writer" WHERE id=1'),'native table locks remain effective after DDL and commit');
    $check($refuses(fn()=>thunderhawkSupplementApply($pdo,$plan,$before,$record),'already started'),'an ambiguous repeat cannot replay the original installation');
    $pdo->exec('UPDATE staffing_timesheets SET supplement_review_hash=REPEAT("a",64) WHERE tenant_id=2002');
    $check($refuses(fn()=>thunderhawkSupplementRecover($pdo,$before,$plan),'business use'),'recovery refuses new supplemental business evidence');$pdo->exec('ROLLBACK');
    $pdo->exec('UPDATE staffing_timesheets SET total_hours=3 WHERE tenant_id=2002');
    $check($refuses(fn()=>thunderhawkSupplementRecover($pdo,$before,$plan),'Original business values changed'),'recovery never overwrites a changed original approval');$pdo->exec('ROLLBACK');
    $pdo->prepare('UPDATE _migrations SET sha256=? WHERE filename=?')->execute([str_repeat('0',64),$plan['filename']]);
    $check($refuses(fn()=>thunderhawkSupplementRecover($pdo,$before,$plan),'receipt changed'),'recovery refuses altered migration provenance');$pdo->exec('ROLLBACK');
    thunderhawkSupplementRecover($pdo,$before,$plan);
    $check(thunderhawkSupplementBaseline($pdo,$plan)===$before,'paired recovery restores original uniqueness, columns, values and migration history');
    thunderhawkSupplementRecover($pdo,$before,$plan);$check(thunderhawkSupplementBaseline($pdo,$plan)===$before,'recovery is verifiable after an interruption before any DDL');
    foreach([3,8,13,18]as$stop){
        $fail=static function(array $event)use($stop):void{if($event['phase']==='statement_complete'&&$event['index']===$stop)throw new RuntimeException('fixture DDL interruption');};
        $check($refuses(fn()=>thunderhawkSupplementApply($pdo,$plan,$before,$fail),'fixture DDL interruption'),'injected interruption is retained after DDL statement '.$stop);
        thunderhawkTimePayrollUnlock($pdo);$locked=false;$pdo=null;$pdo=$connect();thunderhawkSupplementLock($pdo);$locked=true;
        thunderhawkSupplementRecover($pdo,$before,$plan);
        $check(thunderhawkSupplementBaseline($pdo,$plan)===$before,'fresh connection recovers exact original state after DDL statement '.$stop);
    }
    $late=static function(array $event):void{if($event['phase']==='migration_complete')throw new RuntimeException('fixture completion receipt loss');};
    $check($refuses(fn()=>thunderhawkSupplementApply($pdo,$plan,$before,$late),'fixture completion receipt loss')&&thunderhawkSupplementPreserved($pdo,$before,$plan)['applied_migrations']===1,'lost completion receipt leaves one recognizable applied migration');
    thunderhawkSupplementRecover($pdo,$before,$plan);$check(thunderhawkSupplementBaseline($pdo,$plan)===$before,'explicit recovery after receipt loss preserves all original business records');
    thunderhawkTimePayrollUnlock($pdo);$locked=false;
}finally{
    if($locked&&$pdo)thunderhawkTimePayrollUnlock($pdo);$pdo=null;
    if($created)$source->exec('DROP DATABASE '.$scratch);
    if($directory){foreach(glob($directory.'/*')as$file)if(is_file($file)&&!is_link($file))unlink($file);rmdir($directory);}
}
echo $checks." supplemental time migration and recovery checks passed\n";
