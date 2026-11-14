<?php
/** Real two-connection writer exclusion and unchanged-data proof for the code-only pilot upgrade. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('COREFLUX_ENV')!=='coreaccounting'||getenv('COREFLUX_STANDALONE_DATABASE')!=='coreaccounting_ci'||!in_array('--confirm-disposable-fixture',$argv,true))exit(2);
require_once __DIR__.'/../core/db.php';require_once __DIR__.'/../deploy/thunderhawk_payroll_selection_upgrade.php';require_once __DIR__.'/../deploy/thunderhawk_pilot_backup.php';
$source=getDB();if($source->query('SELECT DATABASE()')->fetchColumn()!=='coreaccounting_ci')exit(2);
$scratch='coreaccounting_time_payroll_upgrade';$created=false;$locked=false;$pdo=null;$directory=null;$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException('FAIL '.$label);$checks++;echo 'PASS '.$label."\n";};
$refuses=static function(callable $fn,string $text):bool{try{$fn();return false;}catch(Throwable $e){if(!str_contains($e->getMessage(),$text))throw $e;return true;}};
$connect=static fn()=>new PDO('mysql:host='.DB_HOST.';dbname='.$scratch.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
try{
    $source->exec('CREATE DATABASE '.$scratch);$created=true;$pdo=$connect();
    $pdo->exec('CREATE TABLE _migrations LIKE coreaccounting_ci._migrations');
    $pdo->exec('INSERT INTO _migrations SELECT * FROM coreaccounting_ci._migrations');
    $pdo->exec('CREATE TABLE source_records(id INT PRIMARY KEY,tenant_id INT NOT NULL,hours DECIMAL(6,2),proof LONGBLOB) ENGINE=InnoDB');
    $binary="\0\xff retained proof ' \" ;\n";
    $pdo->prepare('INSERT INTO source_records VALUES(17,2002,2,?),(24,2002,1.25,?),(25,2,3,?)')->execute([$binary,$binary,$binary]);
    $pdo->exec('CREATE TABLE source_claims(id INT PRIMARY KEY,tenant_id INT,entry_id INT) ENGINE=InnoDB');
    $pdo->exec('INSERT INTO source_claims VALUES(1,2002,17)');
    $baseline=payrollSelectionBaseline($pdo);
    $check($baseline['tables']['source_records']['rows']===3&&$baseline['tables']['source_claims']['rows']===1,'baseline retains new and previously reserved source in separate companies');
    payrollSelectionLock($pdo);$locked=true;
    $check(payrollSelectionPreserved($pdo,$baseline)['business_records_unchanged'],'read locks permit complete preservation verification');
    $other=$connect();$other->exec('SET SESSION lock_wait_timeout=1');$other->exec('SET SESSION innodb_lock_wait_timeout=1');
    foreach(['UPDATE source_records SET hours=9 WHERE id=17','UPDATE source_records SET hours=9 WHERE id=24','UPDATE source_records SET hours=9 WHERE id=25','DELETE FROM source_claims','ALTER TABLE source_records ADD changed INT']as$sql)
        $check($refuses(fn()=>$other->exec($sql),'Lock wait timeout'),'native read lock excludes competing source, claim or schema writes');
    $check((string)$other->query('SELECT hours FROM source_records WHERE id=24')->fetchColumn()==='1.25','read-only inspection remains available while writers are held');
    $directory=sys_get_temp_dir().'/payroll-selection-upgrade-'.bin2hex(random_bytes(12));if(!mkdir($directory,0700))throw new RuntimeException('Private backup path failed');
    $backup=thunderhawkPilotDatabaseCapture($pdo,$directory.'/before.sql');
    $check($backup['bytes']>100&&(fileperms($backup['path'])&0777)===0600&&payrollSelectionBaseline($pdo)===$baseline,'private backup under the same locks preserves every row and migration');
    thunderhawkTimePayrollUnlock($pdo);$locked=false;
    $other->exec('UPDATE source_records SET hours=9 WHERE id=25');
    $check($refuses(fn()=>payrollSelectionPreserved($pdo,$baseline),'Application data or schema changed'),'code recovery refuses a change in another company');
    $other->exec('UPDATE source_records SET hours=3 WHERE id=25');
    $other->exec('DELETE FROM source_claims');
    $check($refuses(fn()=>payrollSelectionPreserved($pdo,$baseline),'Application data or schema changed'),'code recovery refuses changed payroll reservations');
    $other->exec('INSERT INTO source_claims VALUES(1,2002,17)');
    $other->exec('ALTER TABLE source_records ADD changed INT');
    $check($refuses(fn()=>payrollSelectionPreserved($pdo,$baseline),'Application data or schema changed'),'schema drift blocks code recovery even with unchanged original row values');
    $other->exec('ALTER TABLE source_records DROP COLUMN changed');
    $other->exec('CREATE INDEX changed ON source_records(hours)');
    $check($refuses(fn()=>payrollSelectionPreserved($pdo,$baseline),'Application data or schema changed'),'index-only drift cannot pass a row-preservation check');
    $other->exec('DROP INDEX changed ON source_records');
    $other->exec('CREATE TRIGGER changed BEFORE INSERT ON source_records FOR EACH ROW SET NEW.hours=0');
    $check($refuses(fn()=>payrollSelectionPreserved($pdo,$baseline),'Application data or schema changed'),'trigger-only drift cannot pass a row-preservation check');
    $other->exec('DROP TRIGGER changed');
    payrollSelectionLock($pdo);$locked=true;
    $proof=payrollSelectionPreserved($pdo,$baseline);
    $check($proof['migrations_applied']===0&&$proof['tables']===3&&$proof['business_records_unchanged'],'a fresh preservation check proves the complete database unchanged without schema migration');
    thunderhawkTimePayrollUnlock($pdo);$locked=false;$other=null;
}finally{
    if($locked&&$pdo)thunderhawkTimePayrollUnlock($pdo);$pdo=null;
    if($created)$source->exec('DROP DATABASE '.$scratch);
    if($directory){foreach(glob($directory.'/*')as$file)if(is_file($file)&&!is_link($file))unlink($file);rmdir($directory);}
}
echo $checks." payroll selection code-upgrade checks passed\n";
