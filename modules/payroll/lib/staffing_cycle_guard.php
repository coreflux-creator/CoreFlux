<?php
/** Keep native payroll editors from bypassing a reviewed staffing schedule. */
declare(strict_types=1);
require_once __DIR__ . '/../../../core/sub_tenants.php';
function payrollWithUnlinkedSchedule(int $tenant,int $id,string $kind,callable $write): array
{
    if ($tenant<=0||$id<=0||!in_array($kind,['cycle','schedule'],true)) throw new RuntimeException('Payroll schedule not found.',404);
    $pdo=getDB();$owns=!$pdo->inTransaction();$savepoint='payroll_schedule_'.bin2hex(random_bytes(8));
    if($owns)$pdo->beginTransaction();else $pdo->exec('SAVEPOINT '.$savepoint);
    try{
        $q=$pdo->prepare('SELECT id FROM tenants WHERE id=? FOR UPDATE');$q->execute([$tenant]);
        if(!$q->fetchColumn())throw new RuntimeException('Payroll schedule not found.',404);
        $q=$pdo->prepare('SELECT id,payroll_pay_cycle_id FROM staffing_operating_cycles WHERE tenant_id=? ORDER BY id FOR UPDATE');$q->execute([$tenant]);$staffing=$q->fetchAll(PDO::FETCH_ASSOC);
        $q=$pdo->prepare('SELECT id FROM payroll_pay_cycles WHERE tenant_id=? AND '.($kind==='cycle'?'id':'schedule_id').'=? ORDER BY id FOR UPDATE');$q->execute([$tenant,$id]);$cycles=$q->fetchAll(PDO::FETCH_COLUMN);
        foreach($staffing as $row)if(in_array($row['payroll_pay_cycle_id'],$cycles)&&$row['payroll_pay_cycle_id']!==null)throw new RuntimeException('This payroll calendar is linked to a staffing schedule. Use the shared schedule impact review so its placements and payroll settings change together.',409);
        $table=$kind==='cycle'?'payroll_pay_cycles':'payroll_pay_schedules';
        $q=$pdo->prepare('SELECT id FROM '.$table.' WHERE tenant_id=? AND id=? FOR UPDATE');$q->execute([$tenant,$id]);
        if(!$q->fetchColumn())throw new RuntimeException('Payroll schedule not found.',404);
        $result=$write();
        if($owns)$pdo->commit();else $pdo->exec('RELEASE SAVEPOINT '.$savepoint);return $result;
    }catch(Throwable $error){if($pdo->inTransaction()){if($owns)$pdo->rollBack();else{$pdo->exec('ROLLBACK TO SAVEPOINT '.$savepoint);$pdo->exec('RELEASE SAVEPOINT '.$savepoint);}}throw $error;}
}
