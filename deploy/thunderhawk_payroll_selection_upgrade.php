<?php
/** Full-database preservation and writer exclusion for a code-only pilot upgrade. */
declare(strict_types=1);
require_once __DIR__.'/thunderhawk_time_payroll_schema.php';

function payrollSelectionBaseline(PDO $pdo): array {
    $tables=thunderhawkTimePayrollTableState($pdo);$schema=[];
    foreach(array_keys($tables)as$table)$schema[$table]=$pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
    $triggers=$pdo->query('SHOW TRIGGERS')->fetchAll(PDO::FETCH_ASSOC);
    usort($triggers,static fn($a,$b)=>strcmp($a['Trigger'],$b['Trigger']));
    return ['tables'=>$tables,'schema'=>$schema,'triggers'=>$triggers,'ledger'=>$pdo->query('SELECT * FROM _migrations ORDER BY filename')->fetchAll(PDO::FETCH_ASSOC)];
}
function payrollSelectionPreserved(PDO $pdo,array $baseline): array {
    if(payrollSelectionBaseline($pdo)!==$baseline)throw new RuntimeException('Application data or schema changed; automatic code recovery is unavailable');
    return ['tables'=>count($baseline['tables']),'business_records_unchanged'=>true,'migrations_applied'=>0];
}
function payrollSelectionLock(PDO $pdo): void {
    $tables=$pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
    foreach($tables as$t)if(!preg_match('/^[A-Za-z0-9_]+$/D',$t))throw new RuntimeException('Unexpected table identifier');
    $pdo->exec('SET SESSION lock_wait_timeout=10');$pdo->exec('SET autocommit=0');
    $pdo->exec('LOCK TABLES '.implode(',',array_map(static fn($t)=>'`'.$t.'` READ',$tables)));
}
