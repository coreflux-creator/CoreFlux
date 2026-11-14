<?php
/** Native payroll computation; the API adds approval workflow within this transaction. */
declare(strict_types=1);
require_once __DIR__.'/payroll.php';
require_once __DIR__.'/compute.php';
require_once __DIR__.'/source_earnings.php';
require_once __DIR__.'/workflow.php';
require_once __DIR__.'/artifacts.php';
require_once __DIR__.'/../../../core/tx_helpers.php';

function payrollSourceEarningsRequireSchema(PDO $pdo): void
{
    $required=['payroll_runs'=>['computation_revision'],
        'payroll_line_items'=>['earnings_basis','source_time_json','source_time_sha256','hours_regular','hours_overtime'],
        'payroll_earnings'=>['source_rate','source_multiplier','source_entry_ids_json','source_snapshot_hash','hours']];
    $q=$pdo->prepare('SELECT COLUMN_NAME,DATA_TYPE,NUMERIC_SCALE FROM information_schema.columns WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    foreach ($required as $table=>$columns) {
        $q->execute([$table]);$present=[];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $column) $present[$column['COLUMN_NAME']]=$column;
        if (array_diff($columns,array_keys($present))) throw new RuntimeException('Payroll approved-source earnings update is required before computation.',503);
        foreach (array_intersect($columns,['hours','hours_regular','hours_overtime','source_rate','source_multiplier']) as $name) {
            if ($present[$name]['DATA_TYPE']!=='decimal' || (int)$present[$name]['NUMERIC_SCALE']!==4) throw new RuntimeException('Payroll approved-source precision update is required before computation.',503);
        }
    }
}

/** Computation and its independent review request are one recoverable change. */
function payrollComputeRunForReview(int $runId,array $overrides,int $actor,int $revision): int
{
    $pdo=getDB();$owns=cf_tx_begin($pdo);
    if (!$owns) $pdo->exec('SAVEPOINT payroll_compute_review');
    try {
        payrollComputeRun($runId,$overrides,$actor,$revision);
        $instance=payrollRunWorkflowStart((int)currentTenantId(),$runId,$actor);
        if (!$instance) throw new RuntimeException('Could not start payroll approval workflow',503);
        if ($owns) $pdo->commit(); else $pdo->exec('RELEASE SAVEPOINT payroll_compute_review');
        return $instance;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            if ($owns) $pdo->rollBack();
            else {$pdo->exec('ROLLBACK TO SAVEPOINT payroll_compute_review');$pdo->exec('RELEASE SAVEPOINT payroll_compute_review');}
        }
        throw $error;
    }
}

function payrollComputeRun(int $runId, array $hoursOverrides = [], ?int $actorUserId = null, ?int $expectedRevision = null): void {
    $pdo=getDB();
    if (!$pdo) throw new RuntimeException('No database');
    payrollSourceEarningsRequireSchema($pdo);
    $owns=cf_tx_begin($pdo);
    if (!$owns) $pdo->exec('SAVEPOINT payroll_compute_run');
    try {
    $tenant = currentTenantId();
    $run = scopedFind(
        'SELECT r.*, pp.schedule_id, pp.cycle_id, pp.period_start, pp.period_end, pp.pay_date
         FROM payroll_runs r
         JOIN payroll_pay_periods pp ON pp.id = r.pay_period_id AND pp.tenant_id = r.tenant_id
         WHERE r.tenant_id = :tenant_id AND r.id = :id FOR UPDATE',
        ['id' => $runId]
    );
    if (!$run) throw new RuntimeException('Run not found');
    if (!in_array($run['status'],['draft','computed'],true) || !empty($run['journal_entry_id'])
        || !empty($run['cash_journal_entry_id']) || !empty($run['approved_at']) || !empty($run['paid_at'])) {
        throw new RuntimeException('Only an unapproved, unposted payroll run can be computed.',409);
    }
    if ($expectedRevision===null || $expectedRevision!==(int)$run['computation_revision']) {
        throw new RuntimeException('Payroll changed since this view was loaded. Refresh before recomputing.',409);
    }
    $period = [
        'period_start' => $run['period_start'],
        'period_end'   => $run['period_end'],
        'pay_date'     => $run['pay_date'],
    ];

    $settings = payrollGetTenantSettings();
    $emps = payrollEmployeesForSchedule(
        (int) $run['schedule_id'],
        !empty($run['cycle_id']) ? (int) $run['cycle_id'] : null
    );
    $sourceTime = payrollRunApprovedTime($runId);
    $settledHours = [];
    foreach ($sourceTime as $employeeId=>$source) $settledHours[$employeeId]=[
        'hours_regular'=>$source['hours_regular'],'hours_overtime'=>$source['hours_overtime'],
    ];
    $economicEarnings = payrollRunEconomicEarnings($runId);
    $sourceRoster = payrollRunSourceEmployees($emps,$settledHours,$economicEarnings);
    $emps = $sourceRoster['employees'];
    $economicOnly = $sourceRoster['economic_only'];
    if (!$emps) {
        throw new RuntimeException('No enabled payroll profiles are assigned to this pay cycle');
    }

    $hoursOverrides=payrollComputeOverrides($hoursOverrides,$emps,$sourceTime,$economicOnly);
    if ($run['status']==='computed') payrollRunWorkflowCancelPending($tenant,$runId,$actorUserId,'recompute');
        // Wipe previous line items + components for this run (idempotent recompute)
        $oldLines = $pdo->prepare(
            'SELECT id FROM payroll_line_items WHERE tenant_id = :t AND run_id = :r ORDER BY id FOR UPDATE'
        );
        $oldLines->execute(['t' => $tenant, 'r' => $runId]);
        $oldIds = array_map(fn($r) => (int) $r['id'], $oldLines->fetchAll());
        if ($oldIds) {
            $in = implode(',', array_map('intval', $oldIds));
            $pdo->exec("DELETE FROM payroll_earnings   WHERE line_item_id IN ($in)");
            $pdo->exec("DELETE FROM payroll_taxes      WHERE line_item_id IN ($in)");
            $pdo->exec("DELETE FROM payroll_deductions WHERE line_item_id IN ($in)");
            $pdo->exec("DELETE FROM payroll_line_items WHERE id IN ($in)");
        }

        $totals = ['gross'=>0,'taxes'=>0,'ded'=>0,'net'=>0,'er'=>0,'count'=>0];
        foreach ($emps as $e) {
            $empId = (int) $e['employee_id'];
            $extras = $settledHours[$empId] ?? [];
            foreach (($hoursOverrides[$empId] ?? []) as $key => $value) $extras[$key] = $value;
            $extras['commission_cents'] = (int) ($extras['commission_cents'] ?? 0)
                + (int) ($economicEarnings[$empId] ?? 0);
            if (!empty($economicOnly[$empId])) {
                $extras['hours_regular'] = 0;
                $extras['hours_overtime'] = 0;
                $extras['suppress_regular_earnings'] = true;
            }
            $cctx = payrollBuildComputeContext($empId, $period, $settings, $extras, $sourceTime[$empId] ?? null);
            if (!$cctx) {
                $employeeLabel = $e['employee_number'] ?? ('ID ' . $empId);
                throw new RuntimeException(
                    "Employee {$employeeLabel} is missing an active compensation, federal tax, or payroll profile record"
                );
            }

            $result = payrollComputeLine($cctx);

            $lineId = scopedInsert('payroll_line_items', [
                'run_id'              => $runId,
                'employee_id'         => $empId,
                'work_state'          => $cctx['work_state'],
                'pay_type'            => $cctx['pay_type'],
                'pay_rate_cents'      => $cctx['pay_rate_cents'],
                'earnings_basis'      => $cctx['earnings_basis'],
                'source_time_json'    => $cctx['source_time_json'] ?? null,
                'source_time_sha256'  => $cctx['source_time_sha256'] ?? null,
                'pay_frequency'       => $cctx['pay_frequency'],
                'hours_regular'       => $cctx['hours_regular'],
                'hours_overtime'      => $cctx['hours_overtime'],
                'gross_cents'         => $result['gross_cents'],
                'pretax_cents'        => $result['pretax_cents'],
                'taxable_cents'       => $result['taxable_cents'],
                'employee_taxes_cents'=> $result['employee_taxes_cents'],
                'posttax_cents'       => $result['posttax_cents'],
                'net_cents'           => $result['net_cents'],
                'employer_taxes_cents'=> $result['employer_taxes_cents'],
                'payment_method'      => $e['payment_method'] ?? 'direct_deposit',
                'status'              => 'computed',
            ]);

            foreach ($result['earnings']   as $row) scopedInsert('payroll_earnings',   $row + ['line_item_id' => $lineId]);
            foreach ($result['taxes']      as $row) scopedInsert('payroll_taxes',      $row + ['line_item_id' => $lineId]);
            foreach ($result['deductions'] as $row) scopedInsert('payroll_deductions', $row + ['line_item_id' => $lineId]);

            $totals['gross'] += $result['gross_cents'];
            $totals['taxes'] += $result['employee_taxes_cents'];
            $totals['ded']   += $result['pretax_cents'] + $result['posttax_cents'];
            $totals['net']   += $result['net_cents'];
            $totals['er']    += $result['employer_taxes_cents'];
            $totals['count']++;
        }

        scopedUpdate('payroll_runs', $runId, [
            'status'                 => 'computed',
            'computation_revision'   => (int)$run['computation_revision']+1,
            'employee_count'         => $totals['count'],
            'gross_total_cents'      => $totals['gross'],
            'taxes_total_cents'      => $totals['taxes'],
            'deductions_total_cents' => $totals['ded'],
            'net_total_cents'        => $totals['net'],
            'employer_taxes_cents'   => $totals['er'],
            'computed_at'            => date('Y-m-d H:i:s'),
            'computed_by_user_id'    => $actorUserId !== null && $actorUserId > 0 ? $actorUserId : null,
        ]);

        payrollRunSyncArtifact($tenant, $runId, $actorUserId);

        if ($owns) $pdo->commit(); else $pdo->exec('RELEASE SAVEPOINT payroll_compute_run');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            if ($owns) $pdo->rollBack();
            else {$pdo->exec('ROLLBACK TO SAVEPOINT payroll_compute_run');$pdo->exec('RELEASE SAVEPOINT payroll_compute_run');}
        }
        throw $e;
    }
}
