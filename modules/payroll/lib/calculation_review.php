<?php
/** Review identity covers the complete saved calculation, not only headline totals. */
declare(strict_types=1);
require_once __DIR__.'/payroll.php';

function payrollCalculationRun(int $tenant,int $runId,bool $lock=false): array
{
    $q=getDB()->prepare('SELECT r.*,p.schedule_id,p.cycle_id,p.period_start,p.period_end,p.pay_date
        FROM payroll_runs r JOIN payroll_pay_periods p ON p.tenant_id=r.tenant_id AND p.id=r.pay_period_id
        WHERE r.tenant_id=? AND r.id=?'.($lock?' FOR UPDATE':''));
    $q->execute([$tenant,$runId]);$run=$q->fetch(PDO::FETCH_ASSOC);
    if (!$run) throw new RuntimeException('Payroll calculation is unavailable in this workspace.',409);
    return $run;
}

function payrollCalculationFields(array $row,array $fields): array
{
    $result=[];
    foreach ($fields as $name) {
        if (!array_key_exists($name,$row)) throw new RuntimeException('Payroll calculation review requires a system update.',503);
        $result[$name]=$row[$name]===null?null:(string)$row[$name];
    }
    return $result;
}

function payrollCalculationFingerprint(array $run,bool $lock=false): string
{
    $pdo=getDB();$tenant=(int)$run['tenant_id'];$runId=(int)$run['id'];
    $snapshot=['version'=>1,'run'=>payrollCalculationFields($run,[
        'id','tenant_id','pay_period_id','schedule_id','cycle_id','period_start','period_end','pay_date','run_type',
        'computation_revision','created_by_user_id','computed_by_user_id','computed_at','employee_count',
        'gross_total_cents','taxes_total_cents','deductions_total_cents','net_total_cents','employer_taxes_cents']),
        'lines'=>[]];
    $q=$pdo->prepare('SELECT * FROM payroll_line_items WHERE tenant_id=? AND run_id=? ORDER BY id'.($lock?' FOR UPDATE':''));
    $q->execute([$tenant,$runId]);$lines=$q->fetchAll(PDO::FETCH_ASSOC);
    if (!$lines || count($lines)!==(int)$run['employee_count']) throw new RuntimeException('Payroll employee lines changed. Recompute before review.',409);
    $componentFields=[
        'payroll_earnings'=>['id','tenant_id','line_item_id','code','hours','rate_cents','amount_cents','taxable','source_rate','source_multiplier','source_entry_ids_json','source_snapshot_hash'],
        'payroll_taxes'=>['id','tenant_id','line_item_id','code','jurisdiction','taxable_wage_cents','rate_bps','amount_cents','is_employer'],
        'payroll_deductions'=>['id','tenant_id','line_item_id','code','is_pretax','amount_cents'],
    ];
    foreach ($lines as $line) {
        $item=payrollCalculationFields($line,[
            'id','tenant_id','run_id','employee_id','work_state','pay_type','pay_rate_cents','pay_frequency','hours_regular','hours_overtime',
            'gross_cents','pretax_cents','taxable_cents','employee_taxes_cents','posttax_cents','net_cents','employer_taxes_cents',
            'payment_method','earnings_basis','source_time_json','source_time_sha256']);
        foreach ($componentFields as $table=>$fields) {
            $q=$pdo->prepare('SELECT * FROM '.$table.' WHERE tenant_id=? AND line_item_id=? ORDER BY id'.($lock?' FOR UPDATE':''));
            $q->execute([$tenant,$line['id']]);
            $item[$table]=array_map(static fn($row)=>payrollCalculationFields($row,$fields),$q->fetchAll(PDO::FETCH_ASSOC));
        }
        $snapshot['lines'][]=$item;
    }
    return hash('sha256',json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
}

function payrollCalculationAssert(array $run,bool $lock=true): void
{
    $hash=(string)($run['computation_sha256']??'');
    if ((int)($run['computation_revision']??0)<=0 || !preg_match('/^[a-f0-9]{64}$/D',$hash)
        || !hash_equals($hash,payrollCalculationFingerprint($run,$lock))) {
        throw new RuntimeException('Payroll calculation changed or has no retained review evidence. Recompute and review the new calculation.',409);
    }
}

/** A workflow can never be repointed to the latest computation by an approval request. */
function payrollCalculationAssertWorkflow(array $run,array $instance): void
{
    $payload=json_decode((string)($instance['payload_json']??'{}'),true,512,JSON_THROW_ON_ERROR);
    if ((int)$run['tenant_id']!==(int)$instance['tenant_id'] || $instance['subject_type']!=='payroll_run'
        || (int)$instance['subject_id']!==(int)$run['id'] || (int)$run['workflow_instance_id']!==(int)$instance['id']
        || (int)($payload['computation_revision']??0)!==(int)$run['computation_revision']
        || !hash_equals((string)$run['computation_sha256'],(string)($payload['computation_sha256']??''))) {
        throw new RuntimeException('This payroll review no longer matches the calculation. Refresh and open its current review.',409);
    }
}

/** Called by the common engine before locking the workflow: run -> lines -> workflow. */
function payrollReviewLockSubject(int $tenant,int $runId): array
{
    if (!getDB()->inTransaction()) throw new LogicException('Payroll review requires its protected transaction.');
    $run=payrollCalculationRun($tenant,$runId,true);
    if ($run['status']!=='computed' || !empty($run['approved_at']) || !empty($run['paid_at'])
        || !empty($run['journal_entry_id']) || !empty($run['cash_journal_entry_id'])) {
        throw new RuntimeException('Only the current unapproved payroll calculation can be reviewed.',409);
    }
    payrollCalculationAssert($run);
    $q=getDB()->prepare('SELECT COUNT(*) FROM payroll_line_items WHERE tenant_id=? AND run_id=? AND status<>"computed"');
    $q->execute([$tenant,$runId]);
    if ((int)$q->fetchColumn()>0) throw new RuntimeException('Payroll line status changed. Recompute before review.',409);
    return $run;
}

/** New sealed calculations cannot reach accounting after their approved values change. */
function payrollCalculationAssertApproved(array $run): void
{
    if ((int)($run['computation_revision']??0)===0) return; // Historical approval remains a separate reconciliation path.
    if ((int)($run['approved_computation_revision']??0)!==(int)$run['computation_revision']
        || !hash_equals((string)($run['computation_sha256']??''),(string)($run['approved_computation_sha256']??''))) {
        throw new RuntimeException('Payroll does not have approval for this exact calculation.',409);
    }
    payrollCalculationAssert(payrollCalculationRun((int)$run['tenant_id'],(int)$run['id'],getDB()->inTransaction()),getDB()->inTransaction());
}

function payrollReviewAssertActor(int $tenant,array $run,?int $userId,string $action,string $via): void
{
    if (!in_array($action,['approve','reject','comment'],true)) throw new InvalidArgumentException('Payroll review requires an approve or reject decision.');
    if ($via!=='app' || !$userId) throw new RuntimeException('Payroll approval access is required through a signed-in reviewer.',403);
    if (in_array($userId,[(int)$run['created_by_user_id'],(int)$run['computed_by_user_id']],true)) {
        throw new RuntimeException('Separation of duties: the payroll preparer cannot review the same calculation.',403);
    }
    require_once __DIR__.'/approval_settings.php';
    if (!in_array($userId,array_column(payrollRunEligibleReviewers($tenant),'id'),true)) {
        throw new RuntimeException('Payroll approval access is required for the current workspace.',403);
    }
}
