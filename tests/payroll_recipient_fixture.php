<?php
/** Invented draft-only recipient setup for canonical disposable fixtures, never a hosted helper. */
function payrollRecipientFixture(PDO $pdo,int $tenant,int $user,array $runIds): int
{
    if (!$pdo->inTransaction() || $pdo->query('SELECT DATABASE()')->fetchColumn()!=='coreaccounting_ci') throw new RuntimeException('Disposable fixture transaction required');
    $pdo->prepare('INSERT INTO people_employees(tenant_id,employee_number,user_id,legal_first_name,legal_last_name,status) VALUES(?,?,?,"Synthetic","Recipient","active")')
        ->execute([$tenant,'CI-RECIPIENT-'.bin2hex(random_bytes(6)),$user]);$employee=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO payroll_pay_schedules(tenant_id,name,frequency,period_start_anchor) VALUES(?,"Synthetic recipient","monthly","2026-08-01")')->execute([$tenant]);$schedule=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO payroll_profiles(tenant_id,employee_id,schedule_id,enabled) VALUES(?,?,?,1)')->execute([$tenant,$employee,$schedule]);
    $pdo->prepare('INSERT INTO payroll_pay_periods(tenant_id,schedule_id,period_number,period_start,period_end,pay_date,status) VALUES(?,?,1,"2026-08-01","2026-08-31","2026-09-05","open")')->execute([$tenant,$schedule]);$period=(int)$pdo->lastInsertId();
    foreach($runIds as$id)$pdo->prepare('INSERT INTO payroll_runs(id,tenant_id,pay_period_id,status) VALUES(?,?,?,"draft")')->execute([$id,$tenant,$period]);
    return $employee;
}
