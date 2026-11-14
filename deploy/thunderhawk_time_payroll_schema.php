<?php
/** Exact pilot upgrade primitives. No CLI entry point and no automatic migration. */
declare(strict_types=1);
require_once __DIR__.'/../core/migration_hash.php';
require_once __DIR__.'/../core/migrate.php';

const TH_TIME_PAYROLL_MIGRATIONS = [
    'core/migrations/199_company_active_name.sql'=>'74d451442502e0c412a3273b9ff49c1ab750275ed865d1cb8735aec751257875',
    'core/migrations/200_client_source_ownership.sql'=>'e6010361f59893e7abf82c49a829ba9c08f5e0df41cec131f22d9bac472dc74a',
    'core/migrations/201_staffing_email_review_basis.sql'=>'f1cd6b4b224695f6ec1909362be90c0cfb7439f8fb1c81320901e3b1b5c559c7',
    'core/migrations/202_time_csv_intake_evidence.sql'=>'64914a15ab02dda3ae2157eb567f5a440d4063c288a00af5305150097688fe65',
    'core/migrations/203_time_approval_economics.sql'=>'802ea9013d5f1feedd011515f65a9f7a0188ea6451748585cf4f3b7f8e65b4e2',
    'modules/payroll/migrations/010_approved_source_earnings.sql'=>'112b2841166b4f026c6223306844162be38ef07a696fd2807b28e51828b96a32',
    'modules/payroll/migrations/011_calculation_review_identity.sql'=>'27e39bd92aa416450cc5e50caccafa26fe3bb94d7c89441ec24d1bce4606b3f6',
];
const TH_TIME_PAYROLL_ADDED_COLUMNS = [
    'companies'=>['active_name_key'],
    'staffing_clients'=>['coreflux_source_state'],
    'approval_tokens'=>['issued_review_json'],
    'payroll_runs'=>['computation_revision','computation_sha256','approved_computation_revision','approved_computation_sha256'],
    'payroll_line_items'=>['earnings_basis','source_time_json','source_time_sha256'],
    'payroll_earnings'=>['source_rate','source_multiplier','source_entry_ids_json','source_snapshot_hash'],
];
const TH_TIME_PAYROLL_NEW_TABLES = ['time_csv_imports','time_csv_row_evidence','time_approval_rates','time_accounting_handoffs'];

function thunderhawkTimePayrollMigrationPlan(string $root): array
{
    $result=[];
    foreach(TH_TIME_PAYROLL_MIGRATIONS as $path=>$hash){
        $file=$root.'/'.$path;
        if(is_link($file)||realpath($file)!==$file)throw new RuntimeException('Invalid migration path');
        $sql=file_get_contents($file);
        if($sql===false||corefluxMigrationHash($sql)!==$hash)throw new RuntimeException('Unreviewed pilot migration: '.$path);
        $result[$path]=['filename'=>str_starts_with($path,'core/')?basename($path):$path,
            'sha256'=>$hash,'statements'=>coreflux_split_sql_statements($sql)];
    }
    return $result;
}

/** Capture columns once so additive columns do not masquerade as changes to old values. */
function thunderhawkTimePayrollTableState(PDO $pdo,?array $projection=null): array
{
    $names=$projection===null?$pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN):array_keys($projection);
    sort($names,SORT_STRING);$result=[];
    foreach($names as $table){
        if(!preg_match('/^[A-Za-z0-9_]+$/D',$table))throw new RuntimeException('Invalid table name');
        $columns=$projection[$table]['columns']??$pdo->query('SHOW COLUMNS FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC);
        $select=implode(',',array_map(static fn($c)=>'`'.$c['Field'].'`',$columns));
        $decimals=[];foreach($columns as $column)if(str_starts_with(strtolower($column['Type']),'decimal('))$decimals[]=$column['Field'];
        $hashes=[];
        foreach($pdo->query('SELECT '.$select.' FROM `'.$table.'`')as$row){
            foreach($decimals as $field){
                if($row[$field]===null)continue;
                $value=(string)$row[$field];
                if(str_contains($value,'.'))$value=rtrim(rtrim($value,'0'),'.');
                $row[$field]=$value==='-0'?'0':$value;
            }
            $hashes[]=hash('sha256',serialize($row));
        }
        sort($hashes,SORT_STRING);
        $result[$table]=['columns'=>$columns,'rows'=>count($hashes),'sha256'=>hash('sha256',implode("\n",$hashes))];
    }
    return $result;
}

function thunderhawkTimePayrollSchemaBaseline(PDO $pdo,array $plan): array
{
    $state=thunderhawkTimePayrollTableState($pdo);
    foreach(TH_TIME_PAYROLL_NEW_TABLES as $table)if(isset($state[$table]))throw new RuntimeException('Pilot schema already started: '.$table);
    foreach(TH_TIME_PAYROLL_ADDED_COLUMNS as $table=>$added){
        if(!isset($state[$table])||array_intersect($added,array_column($state[$table]['columns'],'Field')))throw new RuntimeException('Unexpected pilot baseline: '.$table);
    }
    foreach(['payroll_line_items'=>['hours_regular','hours_overtime'],'payroll_earnings'=>['hours']]as$table=>$fields){
        $columns=array_column($state[$table]['columns'],null,'Field');
        foreach($fields as$field)if(strtolower($columns[$field]['Type']??'')!=='decimal(8,2)')throw new RuntimeException('Unexpected legacy payroll precision');
    }
    $ledger=$pdo->query('SELECT * FROM _migrations ORDER BY filename')->fetchAll(PDO::FETCH_ASSOC);
    foreach($ledger as$row)if($row['last_error']!==null&&$row['last_error']!=='')throw new RuntimeException('Existing migration error');
    if(array_intersect(array_column($plan,'filename'),array_column($ledger,'filename')))throw new RuntimeException('Pilot migration has already been attempted');
    return ['tables'=>$state,'ledger'=>$ledger];
}

/** Caller must retain its maintenance hold and backups through activation or reviewed recovery. */
function thunderhawkTimePayrollLock(PDO $pdo): void
{
    if($pdo->inTransaction()||(int)$pdo->query('SELECT @@autocommit')->fetchColumn()!==1
        ||(int)$pdo->query('SELECT @@innodb_table_locks')->fetchColumn()!==1)throw new RuntimeException('Clean connection and native table locks required');
    $wsrep=$pdo->query("SHOW VARIABLES LIKE 'wsrep_on'")->fetch(PDO::FETCH_ASSOC);
    if($wsrep&&strtoupper((string)$wsrep['Value'])!=='OFF')throw new RuntimeException('Cluster needs a separate upgrade procedure');
    $tables=$pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);sort($tables,SORT_STRING);
    $writes=[...array_keys(TH_TIME_PAYROLL_ADDED_COLUMNS),'_migrations',...TH_TIME_PAYROLL_NEW_TABLES];$locks=[];
    foreach($tables as$table){
        if(!preg_match('/^[A-Za-z0-9_]+$/D',$table))throw new RuntimeException('Invalid table lock');
        $locks[]='`'.$table.'` '.(in_array($table,$writes,true)?'WRITE':'READ');
    }
    $pdo->exec('SET SESSION lock_wait_timeout=10');$pdo->exec('SET autocommit=0');
    try{$pdo->exec('LOCK TABLES '.implode(',',$locks));}
    catch(Throwable $e){$pdo->exec('SET autocommit=1');throw $e;}
}

function thunderhawkTimePayrollUnlock(PDO $pdo): void
{
    $pdo->exec('UNLOCK TABLES');$pdo->exec('SET autocommit=1');
}

/** Create only previously absent evidence tables, before taking the final native lock set. */
function thunderhawkTimePayrollPrepareEvidence(PDO $pdo,array $plan,callable $journal): array
{
    if($pdo->inTransaction()||(int)$pdo->query('SELECT @@autocommit')->fetchColumn()!==1)throw new RuntimeException('Evidence preparation needs a clean connection');
    $existing=$pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
    if(array_intersect(TH_TIME_PAYROLL_NEW_TABLES,$existing))throw new RuntimeException('Evidence preparation already started; inspect retained receipt');
    $prepared=[];
    foreach($plan as$path=>$migration)foreach($migration['statements']as$index=>$statement){
        if(!preg_match('/^CREATE TABLE IF NOT EXISTS ([a-z_]+)\s*\(/D',$statement,$m))continue;
        $table=$m[1];
        if(!in_array($table,TH_TIME_PAYROLL_NEW_TABLES,true))throw new RuntimeException('Unreviewed new evidence table');
        $journal(['phase'=>'table_create_start','path'=>$path,'statement'=>$index,'table'=>$table,'sha256'=>hash('sha256',$statement)]);
        $pdo->exec($statement);
        $schema=$pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
        $prepared[$table]=['path'=>$path,'statement'=>$index,'statement_sha256'=>hash('sha256',$statement),'schema_sha256'=>hash('sha256',$schema)];
        $journal(['phase'=>'table_create_complete','table'=>$table]+$prepared[$table]);
    }
    if(array_keys($prepared)!==TH_TIME_PAYROLL_NEW_TABLES)throw new RuntimeException('Incomplete evidence-table preparation');
    return $prepared;
}

/** Recheck after acquiring locks: preparation never authorizes a stale business-data baseline. */
function thunderhawkTimePayrollPrepared(PDO $pdo,array $before,array $prepared): void
{
    if(array_keys($prepared)!==TH_TIME_PAYROLL_NEW_TABLES)throw new RuntimeException('Missing evidence preparation receipt');
    if(thunderhawkTimePayrollTableState($pdo,$before['tables'])!==$before['tables'])throw new RuntimeException('Original records changed before the upgrade lock');
    foreach($prepared as$table=>$receipt){
        $schema=$pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
        if(hash('sha256',$schema)!==$receipt['schema_sha256']||(int)$pdo->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn()!==0)throw new RuntimeException('Prepared evidence table changed: '.$table);
    }
}

/** Every statement is journaled before execution. DDL is not transactionally rolled back. */
function thunderhawkTimePayrollApply(PDO $pdo,array $plan,array $before,array $prepared,callable $journal): void
{
    thunderhawkTimePayrollPrepared($pdo,$before,$prepared);
    foreach($plan as$path=>$migration){
        $journal(['phase'=>'migration_start','path'=>$path,'sha256'=>$migration['sha256']]);
        $pdo->prepare('INSERT INTO _migrations(filename,sha256,last_error,duration_ms) VALUES (?,?,?,0)')
            ->execute([$migration['filename'],$migration['sha256'],'Pilot upgrade in progress; inspect retained statement journal before recovery']);
        foreach($migration['statements']as$index=>$statement){
            $journal(['phase'=>'statement_start','path'=>$path,'statement'=>$index,'sha256'=>hash('sha256',$statement)]);
            if(preg_match('/^CREATE TABLE IF NOT EXISTS ([a-z_]+)\s*\(/D',$statement,$m)){
                $receipt=$prepared[$m[1]]??null;
                if(!$receipt||$receipt['path']!==$path||$receipt['statement']!==$index||$receipt['statement_sha256']!==hash('sha256',$statement))throw new RuntimeException('Evidence preparation differs from migration');
            }else{$pdo->exec($statement);}
            $journal(['phase'=>'statement_complete','path'=>$path,'statement'=>$index]);
        }
        $pdo->prepare('UPDATE _migrations SET last_error=NULL WHERE filename=? AND sha256=?')->execute([$migration['filename'],$migration['sha256']]);
        $journal(['phase'=>'migration_complete','path'=>$path]);
    }
}

function thunderhawkTimePayrollPreserved(PDO $pdo,array $before,array $plan): array
{
    $after=thunderhawkTimePayrollTableState($pdo,$before['tables']);
    foreach($before['tables']as$table=>$state)if($table!=='_migrations'&&$after[$table]!==$state)throw new RuntimeException('Existing business values changed: '.$table);
    foreach(TH_TIME_PAYROLL_NEW_TABLES as$table)if((int)$pdo->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn()!==0)throw new RuntimeException('New evidence table already used');
    foreach(TH_TIME_PAYROLL_ADDED_COLUMNS as$table=>$columns){
        foreach($columns as$column){
            if($table==='companies')continue;
            $predicate=match($column){'computation_revision'=>'`computation_revision`<>0','earnings_basis'=>"`earnings_basis`<>'compensation'",default=>'`'.$column.'` IS NOT NULL'};
            if((int)$pdo->query('SELECT COUNT(*) FROM `'.$table.'` WHERE '.$predicate)->fetchColumn()!==0)throw new RuntimeException('Fabricated migration history: '.$column);
        }
    }
    $ledger=$pdo->query('SELECT * FROM _migrations ORDER BY filename')->fetchAll(PDO::FETCH_ASSOC);
    $names=array_column($plan,'filename');$old=array_values(array_filter($ledger,static fn($r)=>!in_array($r['filename'],$names,true)));
    if($old!==$before['ledger'])throw new RuntimeException('Prior migration ledger changed');
    $map=array_column($ledger,null,'filename');
    foreach($plan as$m)if(($map[$m['filename']]['sha256']??null)!==$m['sha256']||$map[$m['filename']]['last_error']!==null)throw new RuntimeException('Incomplete pilot migration');
    return ['preserved_tables'=>count($after),'preserved_rows'=>array_sum(array_column($before['tables'],'rows')),
        'new_evidence_tables'=>count(TH_TIME_PAYROLL_NEW_TABLES),'applied_migrations'=>count($plan)];
}
