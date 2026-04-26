<?php
/**
 * Payroll — Runs API (the heart of payroll)
 *
 * GET                           → list runs (newest first)
 * GET ?id=<n>                   → run detail with line items, earnings, taxes, deductions
 * POST { pay_period_id }        → create a draft run (status='draft')
 * POST { run_id, action='compute' [, hours_overrides[]] }
 *                               → compute / recompute all line items in a run
 * POST { run_id, action='approve' } → mark run approved (and period 'approved')
 * POST { run_id, action='paid' }   → mark run paid
 *
 * Compute is DETERMINISTIC and produces all numbers. AI never participates.
 */

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../lib/payroll.php';
require_once __DIR__ . '/../lib/compute.php';

$ctx = api_require_auth();

switch (api_method()) {
    case 'GET': {
        $id = (int) (api_query('id') ?? 0);
        if ($id) {
            api_ok(_payrollRunDetail($id));
        }
        $rows = scopedQuery(
            'SELECT r.*, pp.period_start, pp.period_end, pp.pay_date, pp.schedule_id
             FROM payroll_runs r
             JOIN payroll_pay_periods pp ON pp.id = r.pay_period_id AND pp.tenant_id = r.tenant_id
             WHERE r.tenant_id = :tenant_id
             ORDER BY pp.pay_date DESC, r.id DESC LIMIT 50'
        );
        api_ok(['runs' => $rows, 'count' => count($rows)]);
    }

    case 'POST': {
        $body = api_json_body();
        $action = $body['action'] ?? null;

        if (!$action) {
            api_require_fields($body, ['pay_period_id']);
            $period = scopedFind(
                'SELECT * FROM payroll_pay_periods WHERE tenant_id = :tenant_id AND id = :id',
                ['id' => (int) $body['pay_period_id']]
            );
            if (!$period) api_error('Pay period not found', 404);
            $runId = scopedInsert('payroll_runs', [
                'pay_period_id' => (int) $body['pay_period_id'],
                'run_type'      => $body['run_type'] ?? 'regular',
                'status'        => 'draft',
            ]);
            api_ok(['id' => $runId], 201);
        }

        $runId = (int) ($body['run_id'] ?? 0);
        if (!$runId) api_error('Missing run_id', 422);
        $run = scopedFind(
            'SELECT * FROM payroll_runs WHERE tenant_id = :tenant_id AND id = :id',
            ['id' => $runId]
        );
        if (!$run) api_error('Run not found', 404);

        if ($action === 'compute') {
            _payrollComputeRun($runId, $body['hours_overrides'] ?? []);
            api_ok(_payrollRunDetail($runId));
        }
        if ($action === 'approve') {
            scopedUpdate('payroll_runs', $runId, [
                'status'       => 'approved',
                'approved_at'  => date('Y-m-d H:i:s'),
                'approved_by'  => $ctx['user']['id'] ?? null,
            ]);
            scopedUpdate('payroll_line_items', 0, []); // no-op for type-check; per-row updates below:
            $pdo = getDB();
            if ($pdo) {
                $stmt = $pdo->prepare(
                    "UPDATE payroll_line_items SET status='approved', updated_at=NOW()
                     WHERE tenant_id = :tenant_id AND run_id = :rid"
                );
                $stmt->execute(['tenant_id' => currentTenantId(), 'rid' => $runId]);
            }
            scopedUpdate('payroll_pay_periods', (int) $run['pay_period_id'], ['status' => 'approved']);
            api_ok(['ok' => true, 'status' => 'approved']);
        }
        if ($action === 'paid') {
            scopedUpdate('payroll_runs', $runId, [
                'status'  => 'paid',
                'paid_at' => date('Y-m-d H:i:s'),
            ]);
            $pdo = getDB();
            if ($pdo) {
                $stmt = $pdo->prepare(
                    "UPDATE payroll_line_items SET status='paid', updated_at=NOW()
                     WHERE tenant_id = :tenant_id AND run_id = :rid"
                );
                $stmt->execute(['tenant_id' => currentTenantId(), 'rid' => $runId]);
            }
            scopedUpdate('payroll_pay_periods', (int) $run['pay_period_id'], ['status' => 'paid']);
            api_ok(['ok' => true, 'status' => 'paid']);
        }

        api_error('Unknown action', 422);
    }
}

api_error('Method not allowed', 405);


// =========================================================================
// Helpers
// =========================================================================

function _payrollRunDetail(int $runId): array {
    $run = scopedFind(
        'SELECT r.*, pp.schedule_id, pp.period_start, pp.period_end, pp.pay_date
         FROM payroll_runs r
         JOIN payroll_pay_periods pp ON pp.id = r.pay_period_id AND pp.tenant_id = r.tenant_id
         WHERE r.tenant_id = :tenant_id AND r.id = :id',
        ['id' => $runId]
    );
    if (!$run) return ['run' => null, 'lines' => []];

    $lines = scopedQuery(
        "SELECT li.*,
                e.legal_first_name, e.legal_last_name, e.preferred_name, e.employee_number
         FROM payroll_line_items li
         JOIN people_employees e
           ON e.id = li.employee_id AND e.tenant_id = li.tenant_id
         WHERE li.tenant_id = :tenant_id AND li.run_id = :rid
         ORDER BY e.legal_last_name, e.legal_first_name",
        ['rid' => $runId]
    );

    // Pull components for all line ids in one shot
    $lineIds = array_map(fn($l) => (int) $l['id'], $lines);
    $earnByLine = $taxByLine = $dedByLine = [];
    if ($lineIds) {
        $in = implode(',', array_map('intval', $lineIds));
        $pdo = getDB();
        if ($pdo) {
            foreach ($pdo->query("SELECT * FROM payroll_earnings WHERE line_item_id IN ($in)") as $r) {
                $earnByLine[(int) $r['line_item_id']][] = $r;
            }
            foreach ($pdo->query("SELECT * FROM payroll_taxes WHERE line_item_id IN ($in)") as $r) {
                $taxByLine[(int) $r['line_item_id']][] = $r;
            }
            foreach ($pdo->query("SELECT * FROM payroll_deductions WHERE line_item_id IN ($in)") as $r) {
                $dedByLine[(int) $r['line_item_id']][] = $r;
            }
        }
    }
    foreach ($lines as &$l) {
        $lid = (int) $l['id'];
        $l['earnings']   = $earnByLine[$lid] ?? [];
        $l['taxes']      = $taxByLine[$lid]  ?? [];
        $l['deductions'] = $dedByLine[$lid]  ?? [];
    }
    unset($l);

    return ['run' => $run, 'lines' => $lines];
}

/**
 * Compute every employee in the run's schedule. Idempotent: deletes prior
 * line items for this run, then re-creates them from current data.
 *
 * $hoursOverrides: optional array keyed by employee_id with
 *   [ 'hours_regular' => float, 'hours_overtime' => float, 'bonus_cents' => int ]
 */
function _payrollComputeRun(int $runId, array $hoursOverrides = []): void {
    $tenant = currentTenantId();
    $run = scopedFind(
        'SELECT r.*, pp.schedule_id, pp.period_start, pp.period_end, pp.pay_date
         FROM payroll_runs r
         JOIN payroll_pay_periods pp ON pp.id = r.pay_period_id AND pp.tenant_id = r.tenant_id
         WHERE r.tenant_id = :tenant_id AND r.id = :id',
        ['id' => $runId]
    );
    if (!$run) throw new RuntimeException('Run not found');
    $period = [
        'period_start' => $run['period_start'],
        'period_end'   => $run['period_end'],
        'pay_date'     => $run['pay_date'],
    ];

    $settings = payrollGetTenantSettings();
    $emps = payrollEmployeesForSchedule((int) $run['schedule_id']);

    $pdo = getDB();
    if (!$pdo) throw new RuntimeException('No database');

    $pdo->beginTransaction();
    try {
        // Wipe previous line items + components for this run (idempotent recompute)
        $oldLines = $pdo->prepare(
            'SELECT id FROM payroll_line_items WHERE tenant_id = :t AND run_id = :r'
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
            $extras = $hoursOverrides[$empId] ?? [];
            $cctx = payrollBuildComputeContext($empId, $period, $settings, $extras);
            if (!$cctx) continue; // skip employees without comp/tax/profile

            $result = payrollComputeLine($cctx);

            $lineId = scopedInsert('payroll_line_items', [
                'run_id'              => $runId,
                'employee_id'         => $empId,
                'work_state'          => $cctx['work_state'],
                'pay_type'            => $cctx['pay_type'],
                'pay_rate_cents'      => $cctx['pay_rate_cents'],
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
            'employee_count'         => $totals['count'],
            'gross_total_cents'      => $totals['gross'],
            'taxes_total_cents'      => $totals['taxes'],
            'deductions_total_cents' => $totals['ded'],
            'net_total_cents'        => $totals['net'],
            'employer_taxes_cents'   => $totals['er'],
            'computed_at'            => date('Y-m-d H:i:s'),
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
