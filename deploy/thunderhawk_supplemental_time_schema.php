<?php
/** Exact additive pilot migration, original-row preservation and pre-use recovery. */
declare(strict_types=1);
require_once __DIR__.'/thunderhawk_time_payroll_schema.php';
const TH_SUPPLEMENT_PATH='modules/staffing/migrations/008_supplemental_timesheets.sql';
const TH_SUPPLEMENT_HASH='ca0cba7eaf8ab7cbf8fe26720f9a155ea7f2f29dbdb4ce80cc9a547d3a52b965';
const TH_SUPPLEMENT_COLUMNS=['supplement_sequence','supplement_of_id','supplement_review_hash'];
const TH_SUPPLEMENT_PENDING='Pilot supplemental upgrade in progress; inspect retained statement journal before recovery';

function thunderhawkSupplementPlan(string $root): array
{
    $path=$root.'/'.TH_SUPPLEMENT_PATH;
    if(is_link($path)||str_replace('\\','/',(string)realpath($path))!==str_replace('\\','/',$path))throw new RuntimeException('Supplement migration path differs');
    $sql=file_get_contents($path);
    if(corefluxMigrationHash($sql)!==TH_SUPPLEMENT_HASH)throw new RuntimeException('Supplement migration fingerprint differs');
    $statements=coreflux_split_sql_statements($sql);
    if(count($statements)!==20)throw new RuntimeException('Supplement migration statement inventory differs');
    return ['filename'=>TH_SUPPLEMENT_PATH,'sha256'=>TH_SUPPLEMENT_HASH,'statements'=>$statements];
}

function thunderhawkSupplementIndexes(PDO $pdo): array
{
    $result=[];
    foreach($pdo->query('SHOW INDEX FROM staffing_timesheets')->fetchAll(PDO::FETCH_ASSOC)as$row){
        $name=$row['Key_name'];
        $result[$name]['unique']=(int)$row['Non_unique']===0;$result[$name]['type']=$row['Index_type'];
        $result[$name]['columns'][(int)$row['Seq_in_index']]=['name'=>$row['Column_name'],'prefix'=>$row['Sub_part'],'direction'=>$row['Collation']];
    }
    foreach($result as&$index){ksort($index['columns']);$index['columns']=array_values($index['columns']);}unset($index);
    ksort($result,SORT_STRING);return $result;
}

function thunderhawkSupplementIndex(bool $unique,array $columns): array
{
    return ['unique'=>$unique,'type'=>'BTREE','columns'=>array_map(static fn($name)=>['name'=>$name,'prefix'=>null,'direction'=>'A'],$columns)];
}

function thunderhawkSupplementColumnShape(array $columns): array
{
    foreach($columns as&$column)unset($column['Key']);unset($column);return $columns;
}

function thunderhawkSupplementBaseline(PDO $pdo,array $plan): array
{
    $tables=thunderhawkTimePayrollTableState($pdo);
    if(!isset($tables['staffing_timesheets'],$tables['_migrations'])||array_intersect(TH_SUPPLEMENT_COLUMNS,array_column($tables['staffing_timesheets']['columns'],'Field')))throw new RuntimeException('Supplement schema already started or baseline unavailable');
    $indexes=thunderhawkSupplementIndexes($pdo);
    if(($indexes['uq_sts_tenant_person_week']??null)!==thunderhawkSupplementIndex(true,['tenant_id','person_id','period_start'])
        ||isset($indexes['uq_sts_tenant_person_week_supplement'],$indexes['idx_sts_supplement_parent']))throw new RuntimeException('Original timesheet uniqueness differs');
    $ledger=$pdo->query('SELECT * FROM _migrations ORDER BY filename')->fetchAll(PDO::FETCH_ASSOC);
    foreach($ledger as$row)if($row['filename']===$plan['filename']||!empty($row['last_error']))throw new RuntimeException('Migration ledger needs review');
    return ['tables'=>$tables,'indexes'=>$indexes,'ledger'=>$ledger];
}

function thunderhawkSupplementLock(PDO $pdo): void
{
    if($pdo->inTransaction()||(int)$pdo->query('SELECT @@autocommit')->fetchColumn()!==1||(int)$pdo->query('SELECT @@innodb_table_locks')->fetchColumn()!==1)throw new RuntimeException('Clean native table lock connection required');
    $wsrep=$pdo->query("SHOW VARIABLES LIKE 'wsrep_on'")->fetch(PDO::FETCH_ASSOC);
    if($wsrep&&strtoupper((string)$wsrep['Value'])!=='OFF')throw new RuntimeException('Cluster requires a separate upgrade procedure');
    $names=$pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);sort($names,SORT_STRING);$locks=[];
    foreach($names as$name){if(!preg_match('/^[A-Za-z0-9_]+$/D',$name))throw new RuntimeException('Unexpected database table');$locks[]='`'.$name.'` '.(in_array($name,['staffing_timesheets','_migrations'],true)?'WRITE':'READ');}
    $pdo->exec('SET SESSION lock_wait_timeout=10');$pdo->exec('SET autocommit=0');
    try{$pdo->exec('LOCK TABLES '.implode(',',$locks));}catch(Throwable $e){$pdo->exec('SET autocommit=1');throw $e;}
}

/** Partial states must be reachable from the pinned migration and unused by business work. */
function thunderhawkSupplementPreserved(PDO $pdo,array $before,array $plan,bool $partial=false): array
{
    $tables=$pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);sort($tables,SORT_STRING);
    if($tables!==array_keys($before['tables']))throw new RuntimeException('Database inventory changed');
    $after=thunderhawkTimePayrollTableState($pdo,$before['tables']);
    foreach($before['tables']as$table=>$state){
        if($table!=='_migrations'&&$after[$table]!==$state)throw new RuntimeException('Original business values changed: '.$table);
        $columns=$pdo->query('SHOW COLUMNS FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC);
        if($table!=='staffing_timesheets'&&$columns!==$state['columns'])throw new RuntimeException('Unrelated column shape changed: '.$table);
    }
    $columns=$pdo->query('SHOW COLUMNS FROM staffing_timesheets')->fetchAll(PDO::FETCH_ASSOC);
    $original=array_values(array_filter($columns,static fn($c)=>!in_array($c['Field'],TH_SUPPLEMENT_COLUMNS,true)));
    if(thunderhawkSupplementColumnShape($original)!==thunderhawkSupplementColumnShape($before['tables']['staffing_timesheets']['columns']))throw new RuntimeException('Original timesheet column shape changed');
    $added=array_values(array_filter($columns,static fn($c)=>in_array($c['Field'],TH_SUPPLEMENT_COLUMNS,true)));
    if(count($added)!==3&&(!$partial||count($added)!==0))throw new RuntimeException('Supplement column set differs');
    if($added){
        foreach($added as$c){
            $expected=match($c['Field']){'supplement_sequence'=>['/^int(?:\(\d+\))? unsigned$/D','NO','0'],'supplement_of_id'=>['/^bigint(?:\(\d+\))? unsigned$/D','YES',null],default=>['/^char\(64\)$/D','YES',null]};
            if(!preg_match($expected[0],strtolower($c['Type']))||$c['Null']!==$expected[1]||($c['Default']===null?null:(string)$c['Default'])!==$expected[2]||$c['Extra']!=='')throw new RuntimeException('Supplement column definition differs');
        }
        if((int)$pdo->query('SELECT COUNT(*) FROM staffing_timesheets WHERE supplement_sequence<>0 OR supplement_of_id IS NOT NULL OR supplement_review_hash IS NOT NULL')->fetchColumn()!==0)throw new RuntimeException('Supplemental schema already contains business use');
    }
    $indexes=thunderhawkSupplementIndexes($pdo);$legacy='uq_sts_tenant_person_week';$new='uq_sts_tenant_person_week_supplement';$parent='idx_sts_supplement_parent';
    $retained=$indexes;unset($retained[$legacy],$retained[$new],$retained[$parent]);$old=$before['indexes'];unset($old[$legacy]);
    if($retained!==$old)throw new RuntimeException('Unrelated timesheet indexes changed');
    $expectedNew=thunderhawkSupplementIndex(true,['tenant_id','person_id','period_start','supplement_sequence']);
    $expectedParent=thunderhawkSupplementIndex(false,['tenant_id','supplement_of_id','supplement_sequence']);
    if(isset($indexes[$legacy])&&$indexes[$legacy]!==$before['indexes'][$legacy])throw new RuntimeException('Original uniqueness changed');
    if(isset($indexes[$new])&&$indexes[$new]!==$expectedNew)throw new RuntimeException('Supplement uniqueness changed');
    if(isset($indexes[$parent])&&$indexes[$parent]!==$expectedParent)throw new RuntimeException('Supplement parent index changed');
    if(!isset($indexes[$legacy])&&!isset($indexes[$new]))throw new RuntimeException('No person-week uniqueness remains');
    if(!$partial&&(!$added||isset($indexes[$legacy])||!isset($indexes[$new],$indexes[$parent])))throw new RuntimeException('Supplement migration is incomplete');
    $ledger=$pdo->query('SELECT * FROM _migrations ORDER BY filename')->fetchAll(PDO::FETCH_ASSOC);
    $old=array_values(array_filter($ledger,static fn($r)=>$r['filename']!==$plan['filename']));
    if($old!==$before['ledger'])throw new RuntimeException('Prior migration ledger changed');
    $rows=array_values(array_filter($ledger,static fn($r)=>$r['filename']===$plan['filename']));
    if(count($rows)>1||(!$partial&&count($rows)!==1))throw new RuntimeException('Supplement migration receipt missing');
    if($rows&&($rows[0]['sha256']!==$plan['sha256']||(int)$rows[0]['duration_ms']!==0||!in_array($rows[0]['last_error'],$partial?[null,TH_SUPPLEMENT_PENDING]:[null],true)))throw new RuntimeException('Supplement migration receipt changed');
    return ['preserved_tables'=>count($after),'preserved_rows'=>array_sum(array_column($before['tables'],'rows')),'added_columns'=>count($added),'applied_migrations'=>count($rows)];
}

function thunderhawkSupplementApply(PDO $pdo,array $plan,array $before,callable $journal): array
{
    if((int)$pdo->query('SELECT @@autocommit')->fetchColumn()!==0)throw new RuntimeException('Native migration writer guard is required');
    if(thunderhawkSupplementBaseline($pdo,$plan)!==$before)throw new RuntimeException('Original records changed before migration');
    $journal(['phase'=>'migration_start','filename'=>$plan['filename'],'sha256'=>$plan['sha256']]);
    $pdo->prepare('INSERT INTO _migrations(filename,sha256,last_error,duration_ms) VALUES(?,?,?,0)')->execute([$plan['filename'],$plan['sha256'],TH_SUPPLEMENT_PENDING]);
    $pdo->exec('COMMIT');
    foreach($plan['statements']as$index=>$statement){
        $journal(['phase'=>'statement_start','index'=>$index,'sha256'=>hash('sha256',$statement)]);$pdo->exec($statement);
        $journal(['phase'=>'statement_complete','index'=>$index]);
    }
    thunderhawkSupplementPreserved($pdo,$before,$plan,true);
    $pdo->prepare('UPDATE _migrations SET last_error=NULL WHERE filename=? AND sha256=?')->execute([$plan['filename'],$plan['sha256']]);
    $result=thunderhawkSupplementPreserved($pdo,$before,$plan);$pdo->exec('COMMIT');$journal(['phase'=>'migration_complete']+$result);return $result;
}

/** Recovery refuses any new source or changed original values; it never erases use. */
function thunderhawkSupplementRecover(PDO $pdo,array $before,array $plan): void
{
    if((int)$pdo->query('SELECT @@autocommit')->fetchColumn()!==0)throw new RuntimeException('Native recovery writer guard is required');
    $proof=thunderhawkSupplementPreserved($pdo,$before,$plan,true);$indexes=thunderhawkSupplementIndexes($pdo);
    if(!isset($indexes['uq_sts_tenant_person_week']))$pdo->exec('ALTER TABLE staffing_timesheets ADD UNIQUE KEY uq_sts_tenant_person_week(tenant_id,person_id,period_start)');
    foreach(['idx_sts_supplement_parent','uq_sts_tenant_person_week_supplement']as$name)if(isset($indexes[$name]))$pdo->exec('ALTER TABLE staffing_timesheets DROP INDEX `'.$name.'`');
    if($proof['added_columns'])$pdo->exec('ALTER TABLE staffing_timesheets DROP COLUMN supplement_sequence,DROP COLUMN supplement_of_id,DROP COLUMN supplement_review_hash');
    $pdo->prepare('DELETE FROM _migrations WHERE filename=? AND sha256=?')->execute([$plan['filename'],$plan['sha256']]);$pdo->exec('COMMIT');
    if(thunderhawkSupplementBaseline($pdo,$plan)!==$before)throw new RuntimeException('Recovered schema or values differ');
}
