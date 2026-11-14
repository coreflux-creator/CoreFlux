<?php
/** Pinned additive job ownership migration, writer exclusion and pre-use recovery. */
declare(strict_types=1);
require_once __DIR__.'/thunderhawk_time_payroll_schema.php';

const TH_JOB_SOURCE_PATH='modules/staffing/migrations/009_job_source_ownership.sql';
const TH_JOB_SOURCE_HASH='2bdb63e50096d7b32c00b7aa9d072a11005c1040bb3128adffbf79f05e01c9e5';
const TH_JOB_SOURCE_PENDING='Pilot job ownership upgrade in progress; inspect retained statement journal before recovery';

function thunderhawkJobSourcePlan(string $root): array
{
    $path=$root.'/'.TH_JOB_SOURCE_PATH;
    if(is_link($path)||str_replace('\\','/',(string)realpath($path))!==str_replace('\\','/',$path))throw new RuntimeException('Job migration path differs');
    $sql=file_get_contents($path);
    if($sql===false||corefluxMigrationHash($sql)!==TH_JOB_SOURCE_HASH)throw new RuntimeException('Job migration fingerprint differs');
    $statements=coreflux_split_sql_statements($sql);
    if(count($statements)!==5)throw new RuntimeException('Job migration statement inventory differs');
    return ['filename'=>TH_JOB_SOURCE_PATH,'sha256'=>TH_JOB_SOURCE_HASH,'statements'=>$statements];
}

function thunderhawkJobSourceSchema(PDO $pdo): array
{
    $names=$pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);sort($names,SORT_STRING);$schema=[];
    foreach($names as$table){
        if(!preg_match('/^[A-Za-z0-9_]+$/D',$table))throw new RuntimeException('Unexpected table identifier');
        $schema[$table]=$pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
    }
    $triggers=$pdo->query('SHOW TRIGGERS')->fetchAll(PDO::FETCH_ASSOC);
    usort($triggers,static fn($a,$b)=>strcmp($a['Trigger'],$b['Trigger']));
    return ['schema'=>$schema,'triggers'=>$triggers];
}

function thunderhawkJobSourceBaseline(PDO $pdo,array $plan): array
{
    $tables=thunderhawkTimePayrollTableState($pdo);
    if(!isset($tables['staffing_jobs'],$tables['_migrations'])||in_array('coreflux_source_state',array_column($tables['staffing_jobs']['columns'],'Field'),true))throw new RuntimeException('Job schema already started or baseline unavailable');
    $ledger=$pdo->query('SELECT * FROM _migrations ORDER BY filename')->fetchAll(PDO::FETCH_ASSOC);
    foreach($ledger as$row)if($row['filename']===$plan['filename']||!empty($row['last_error']))throw new RuntimeException('Migration ledger needs review');
    return ['tables'=>$tables,'ledger'=>$ledger]+thunderhawkJobSourceSchema($pdo);
}

function thunderhawkJobSourceLock(PDO $pdo): void
{
    if($pdo->inTransaction()||(int)$pdo->query('SELECT @@autocommit')->fetchColumn()!==1||(int)$pdo->query('SELECT @@innodb_table_locks')->fetchColumn()!==1)throw new RuntimeException('Clean native table lock connection required');
    $wsrep=$pdo->query("SHOW VARIABLES LIKE 'wsrep_on'")->fetch(PDO::FETCH_ASSOC);
    if($wsrep&&strtoupper((string)$wsrep['Value'])!=='OFF')throw new RuntimeException('Cluster requires a separate upgrade procedure');
    $names=$pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);sort($names,SORT_STRING);$locks=[];
    foreach($names as$name){
        if(!preg_match('/^[A-Za-z0-9_]+$/D',$name))throw new RuntimeException('Unexpected database table');
        $locks[]='`'.$name.'` '.(in_array($name,['staffing_jobs','_migrations'],true)?'WRITE':'READ');
    }
    $pdo->exec('SET SESSION lock_wait_timeout=10');$pdo->exec('SET autocommit=0');
    try{$pdo->exec('LOCK TABLES '.implode(',',$locks));}catch(Throwable $e){$pdo->exec('SET autocommit=1');throw $e;}
}

/** Remove only the exact new JSON definition from SHOW CREATE, retaining all other schema. */
function thunderhawkJobSourceOriginalSchema(string $sql): string
{
    $lines=explode("\n",$sql);$matches=[];
    foreach($lines as$i=>$line)if(str_contains($line,'`coreflux_source_state`'))$matches[$i]=$line;
    if(count($matches)!==1)throw new RuntimeException('Job JSON constraint or definition differs');
    $index=array_key_first($matches);$line=$matches[$index];
    if(!preg_match('/^  `coreflux_source_state` (?:json DEFAULT NULL|longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK \(json_valid\(`coreflux_source_state`\)\)),?$/D',$line))throw new RuntimeException('Job JSON constraint or definition differs');
    if(!str_ends_with($line,','))$lines[$index-1]=rtrim($lines[$index-1],',');
    unset($lines[$index]);return implode("\n",$lines);
}

function thunderhawkJobSourcePreserved(PDO $pdo,array $before,array $plan,bool $partial=false): array
{
    $schema=thunderhawkJobSourceSchema($pdo);
    if(array_keys($schema['schema'])!==array_keys($before['tables'])||$schema['triggers']!==$before['triggers'])throw new RuntimeException('Database inventory or triggers changed');
    $after=thunderhawkTimePayrollTableState($pdo,$before['tables']);
    foreach($before['tables']as$table=>$state)if($table!=='_migrations'&&$after[$table]!==$state)throw new RuntimeException('Original business values changed: '.$table);
    $columns=$pdo->query('SHOW COLUMNS FROM staffing_jobs')->fetchAll(PDO::FETCH_ASSOC);
    $added=array_values(array_filter($columns,static fn($c)=>$c['Field']==='coreflux_source_state'));
    $original=array_values(array_filter($columns,static fn($c)=>$c['Field']!=='coreflux_source_state'));
    if($original!==$before['tables']['staffing_jobs']['columns'])throw new RuntimeException('Original job column shape changed');
    if(count($added)>1||(!$partial&&count($added)!==1))throw new RuntimeException('Job ownership column missing');
    if($added){
        $c=$added[0];
        if(!in_array(strtolower($c['Type']),['json','longtext'],true)||$c['Null']!=='YES'||$c['Default']!==null||$c['Extra']!==''||$c['Key']!=='')throw new RuntimeException('Job ownership column definition differs');
        if((int)$pdo->query('SELECT COUNT(*) FROM staffing_jobs WHERE coreflux_source_state IS NOT NULL')->fetchColumn()!==0)throw new RuntimeException('Job ownership schema already contains business use');
        $schema['schema']['staffing_jobs']=thunderhawkJobSourceOriginalSchema($schema['schema']['staffing_jobs']);
    }
    if($schema['schema']!==$before['schema'])throw new RuntimeException('Database schema changed beyond the reviewed job column');
    $ledger=$pdo->query('SELECT * FROM _migrations ORDER BY filename')->fetchAll(PDO::FETCH_ASSOC);
    $old=array_values(array_filter($ledger,static fn($r)=>$r['filename']!==$plan['filename']));
    if($old!==$before['ledger'])throw new RuntimeException('Prior migration ledger changed');
    $rows=array_values(array_filter($ledger,static fn($r)=>$r['filename']===$plan['filename']));
    if(count($rows)>1||(!$partial&&count($rows)!==1))throw new RuntimeException('Job migration receipt missing');
    if($rows&&($rows[0]['sha256']!==$plan['sha256']||(int)$rows[0]['duration_ms']!==0||!in_array($rows[0]['last_error'],$partial?[null,TH_JOB_SOURCE_PENDING]:[null],true)))throw new RuntimeException('Job migration receipt changed');
    if(!$partial&&count($added)!==1)throw new RuntimeException('Job migration incomplete');
    return ['preserved_tables'=>count($after),'preserved_rows'=>array_sum(array_column($before['tables'],'rows')),'added_columns'=>count($added),'applied_migrations'=>count($rows),'original_schema_preserved'=>true];
}

function thunderhawkJobSourceApply(PDO $pdo,array $plan,array $before,callable $journal): array
{
    if((int)$pdo->query('SELECT @@autocommit')->fetchColumn()!==0)throw new RuntimeException('Native migration writer guard is required');
    if(thunderhawkJobSourceBaseline($pdo,$plan)!==$before)throw new RuntimeException('Original records changed before migration');
    $journal(['phase'=>'migration_start','filename'=>$plan['filename'],'sha256'=>$plan['sha256']]);
    $pdo->prepare('INSERT INTO _migrations(filename,sha256,last_error,duration_ms) VALUES(?,?,?,0)')->execute([$plan['filename'],$plan['sha256'],TH_JOB_SOURCE_PENDING]);$pdo->exec('COMMIT');
    foreach($plan['statements']as$index=>$statement){
        $journal(['phase'=>'statement_start','index'=>$index,'sha256'=>hash('sha256',$statement)]);$pdo->exec($statement);
        $journal(['phase'=>'statement_complete','index'=>$index]);
    }
    thunderhawkJobSourcePreserved($pdo,$before,$plan,true);
    $pdo->prepare('UPDATE _migrations SET last_error=NULL WHERE filename=? AND sha256=?')->execute([$plan['filename'],$plan['sha256']]);
    $result=thunderhawkJobSourcePreserved($pdo,$before,$plan);$pdo->exec('COMMIT');$journal(['phase'=>'migration_complete']+$result);return $result;
}

/** Refuse recovery after any original value changes or new ownership state is used. */
function thunderhawkJobSourceRecover(PDO $pdo,array $before,array $plan): void
{
    if((int)$pdo->query('SELECT @@autocommit')->fetchColumn()!==0)throw new RuntimeException('Native recovery writer guard is required');
    $proof=thunderhawkJobSourcePreserved($pdo,$before,$plan,true);
    if($proof['added_columns'])$pdo->exec('ALTER TABLE staffing_jobs DROP COLUMN coreflux_source_state');
    $pdo->prepare('DELETE FROM _migrations WHERE filename=? AND sha256=?')->execute([$plan['filename'],$plan['sha256']]);$pdo->exec('COMMIT');
    if(thunderhawkJobSourceBaseline($pdo,$plan)!==$before)throw new RuntimeException('Recovered job schema or values differ');
}
