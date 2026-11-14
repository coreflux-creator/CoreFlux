<?php
/** One pinned data migration; existing payroll and template rows are never rewritten. */
declare(strict_types=1);
require_once __DIR__.'/thunderhawk_time_payroll_schema.php';

const TH_PAYROLL_EXPORT_MIGRATION='core/migrations/204_payroll_calculation_export_template.sql';
const TH_PAYROLL_EXPORT_MIGRATION_SHA256='6c192647facda7ced9c085c285d7def56fe2a1917835564fccfe1ef54f9a4e71';

function thunderhawkPayrollExportPlan(string $root): array
{
    $path=$root.'/'.TH_PAYROLL_EXPORT_MIGRATION;
    if(is_link($path)||str_replace('\\','/',(string)realpath($path))!==str_replace('\\','/',$path))throw new RuntimeException('Payroll export migration path differs');
    $sql=file_get_contents($path);$hash=corefluxMigrationHash($sql);
    if($hash!==TH_PAYROLL_EXPORT_MIGRATION_SHA256)throw new RuntimeException('Payroll export migration fingerprint differs');
    $statements=coreflux_split_sql_statements($sql);
    if(count($statements)!==1||!str_starts_with($statements[0],'INSERT INTO export_templates'))throw new RuntimeException('Only the reviewed template insert is allowed');
    if(!preg_match("~'(\\[.*\\])',1,1\\s+WHERE~sD",substr($statements[0],0,strpos($statements[0],'WHERE NOT EXISTS')+5),$match)) {
        throw new RuntimeException('Reviewed template mapping is unavailable');
    }
    $mappings=json_decode($match[1],true,512,JSON_THROW_ON_ERROR);
    return ['filename'=>basename(TH_PAYROLL_EXPORT_MIGRATION),'sha256'=>$hash,'sql'=>$statements[0],
        'template'=>['scope'=>'platform','tenant_id'=>null,'dataset'=>'payroll_disbursements','name'=>'Payroll calculation audit',
            'delimiter'=>',','quote_char'=>'"','has_header_row'=>1,'encoding'=>'utf-8','column_mappings'=>$mappings,
            'based_on_template_id'=>null,'is_active'=>1,'is_system'=>1,'created_by_user_id'=>null]];
}

function thunderhawkPayrollExportTemplates(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM export_templates ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
}

function thunderhawkPayrollExportBaseline(PDO $pdo,array $plan): array
{
    $tables=thunderhawkTimePayrollTableState($pdo);
    $ledger=$pdo->query('SELECT * FROM _migrations ORDER BY filename')->fetchAll(PDO::FETCH_ASSOC);
    foreach($ledger as$row)if($row['filename']===$plan['filename']||!empty($row['last_error']))throw new RuntimeException('Migration is already recorded or requires review');
    $templates=thunderhawkPayrollExportTemplates($pdo);
    foreach($templates as$row)if($row['scope']==='platform'&&$row['dataset']==='payroll_disbursements'&&$row['name']==='Payroll calculation audit'&&(int)$row['is_system']===1)throw new RuntimeException('Audit template already exists');
    return ['tables'=>$tables,'ledger'=>$ledger,'templates'=>$templates];
}

function thunderhawkPayrollExportLock(PDO $pdo): void
{
    if($pdo->inTransaction()||(int)$pdo->query('SELECT @@autocommit')->fetchColumn()!==1
        ||(int)$pdo->query('SELECT @@innodb_table_locks')->fetchColumn()!==1)throw new RuntimeException('Clean connection and native table locks required');
    $wsrep=$pdo->query("SHOW VARIABLES LIKE 'wsrep_on'")->fetch(PDO::FETCH_ASSOC);
    if($wsrep&&strtoupper((string)$wsrep['Value'])!=='OFF')throw new RuntimeException('Cluster needs a separate upgrade procedure');
    $tables=$pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);sort($tables,SORT_STRING);$locks=[];
    foreach($tables as$table){
        if(!preg_match('/^[A-Za-z0-9_]+$/D',$table))throw new RuntimeException('Unexpected table lock');
        $locks[]='`'.$table.'` '.(in_array($table,['export_templates','_migrations'],true)?'WRITE':'READ');
    }
    $pdo->exec('SET SESSION lock_wait_timeout=10');$pdo->exec('SET autocommit=0');
    try{$pdo->exec('LOCK TABLES '.implode(',',$locks));}
    catch(Throwable $e){$pdo->exec('SET autocommit=1');throw $e;}
}

function thunderhawkPayrollExportPreserved(PDO $pdo,array $before,array $plan,bool $allowAbsent=false): array
{
    $after=thunderhawkTimePayrollTableState($pdo);
    if(array_keys($after)!==array_keys($before['tables']))throw new RuntimeException('Database table inventory changed');
    foreach($before['tables']as$table=>$state){
        if(!in_array($table,['export_templates','_migrations'],true)&&$after[$table]!==$state)throw new RuntimeException('Existing business values changed: '.$table);
        if($after[$table]['columns']!==$state['columns'])throw new RuntimeException('Database columns changed: '.$table);
    }
    $templates=thunderhawkPayrollExportTemplates($pdo);$oldIds=array_column($before['templates'],'id');
    $old=array_values(array_filter($templates,static fn($row)=>in_array($row['id'],$oldIds,true)));
    if($old!==$before['templates'])throw new RuntimeException('Existing export templates changed');
    $added=array_values(array_filter($templates,static fn($row)=>!in_array($row['id'],$oldIds,true)));
    if(count($added)>1||(!$allowAbsent&&count($added)!==1))throw new RuntimeException('Unexpected new export templates');
    if($added){
        $template=$added[0];$template['column_mappings']=json_decode($template['column_mappings_json'],true,512,JSON_THROW_ON_ERROR);
        foreach($plan['template']as$key=>$expected){
            $actual=$template[$key]??null;if(is_int($expected))$actual=(int)$actual;
            if($actual!==$expected)throw new RuntimeException('New audit template changed: '.$key);
        }
        if($template['created_at']!==$template['updated_at'])throw new RuntimeException('New audit template was edited');
    }
    $ledger=$pdo->query('SELECT * FROM _migrations ORDER BY filename')->fetchAll(PDO::FETCH_ASSOC);
    $old=array_values(array_filter($ledger,static fn($row)=>$row['filename']!==$plan['filename']));
    if($old!==$before['ledger'])throw new RuntimeException('Previous migration ledger changed');
    $entries=array_values(array_filter($ledger,static fn($row)=>$row['filename']===$plan['filename']));
    if(count($entries)!==count($added))throw new RuntimeException('Template and migration receipt are not paired');
    if($entries&&($entries[0]['sha256']!==$plan['sha256']||$entries[0]['last_error']!==null||(int)$entries[0]['duration_ms']!==0))throw new RuntimeException('Export migration receipt changed');
    return ['preserved_tables'=>count($before['tables']),'preserved_rows'=>array_sum(array_column($before['tables'],'rows')),
        'added_templates'=>count($added),'template_id'=>$added?(int)$added[0]['id']:null,'applied_migrations'=>count($entries)];
}

/** Both inserts commit together while the native table writer locks remain held. */
function thunderhawkPayrollExportApply(PDO $pdo,array $plan,array $before,callable $journal): array
{
    if((int)$pdo->query('SELECT @@autocommit')->fetchColumn()!==0)throw new RuntimeException('Native migration writer guard is required');
    if(thunderhawkTimePayrollTableState($pdo)!==$before['tables'])throw new RuntimeException('Original records changed before the migration lock');
    try{
        $journal(['phase'=>'migration_start','filename'=>$plan['filename'],'sha256'=>$plan['sha256']]);
        if($pdo->exec($plan['sql'])!==1)throw new RuntimeException('Expected exactly one audit template');
        $journal(['phase'=>'template_inserted']);
        $pdo->prepare('INSERT INTO _migrations(filename,sha256,last_error,duration_ms) VALUES(?,?,NULL,0)')->execute([$plan['filename'],$plan['sha256']]);
        $result=thunderhawkPayrollExportPreserved($pdo,$before,$plan);
        $journal(['phase'=>'migration_committing']+$result);$pdo->exec('COMMIT');
        $journal(['phase'=>'migration_complete']+$result);
        return $result;
    }catch(Throwable $e){$pdo->exec('ROLLBACK');throw $e;}
}

/** Explicit paired recovery removes only this unchanged preset and migration receipt. */
function thunderhawkPayrollExportRecover(PDO $pdo,array $before,array $plan): void
{
    if((int)$pdo->query('SELECT @@autocommit')->fetchColumn()!==0)throw new RuntimeException('Native recovery writer guard is required');
    $proof=thunderhawkPayrollExportPreserved($pdo,$before,$plan,true);
    try{
        if($proof['added_templates']===1){
            $q=$pdo->prepare('DELETE FROM export_templates WHERE id=?');$q->execute([$proof['template_id']]);
            if($q->rowCount()!==1)throw new RuntimeException('Audit template recovery differs');
            $q=$pdo->prepare('DELETE FROM _migrations WHERE filename=? AND sha256=?');$q->execute([$plan['filename'],$plan['sha256']]);
            if($q->rowCount()!==1)throw new RuntimeException('Migration recovery differs');
        }
        if(thunderhawkPayrollExportBaseline($pdo,$plan)!==$before)throw new RuntimeException('Recovered database values differ');
        $pdo->exec('COMMIT');
    }catch(Throwable $e){$pdo->exec('ROLLBACK');throw $e;}
}
