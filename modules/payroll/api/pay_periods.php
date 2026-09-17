<?php
/** Payroll pay periods and their existing runs. */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/payroll.php';
require_once __DIR__ . '/../lib/cycles.php';

$ctx = api_require_auth();
$user = (array) $ctx['user'];

switch (api_method()) {
    case 'GET': {
        rbac_legacy_require($user, 'payroll.view');
        $id = (int) (api_query('id') ?? 0);
        if ($id) {
            $row = scopedFind(
                'SELECT pp.*, ps.name AS schedule_name, pc.name AS cycle_name
                   FROM payroll_pay_periods pp
                   JOIN payroll_pay_schedules ps ON ps.id = pp.schedule_id AND ps.tenant_id = pp.tenant_id
              LEFT JOIN payroll_pay_cycles pc ON pc.id = pp.cycle_id AND pc.tenant_id = pp.tenant_id
                  WHERE pp.tenant_id = :tenant_id AND pp.id = :id',
                ['id' => $id]
            );
            if (!$row) api_error('Not found', 404);
            $runs = scopedQuery(
                'SELECT id, run_type, status, employee_count, gross_total_cents,
                        net_total_cents, computed_at, approved_at, paid_at
                   FROM payroll_runs
                  WHERE tenant_id = :tenant_id AND pay_period_id = :pid
                  ORDER BY id DESC',
                ['pid' => $id]
            );
            api_ok(['period' => $row, 'runs' => $runs]);
        }

        $schedId = (int) (api_query('schedule_id') ?? 0);
        $cycleId = (int) (api_query('cycle_id') ?? 0);
        $where = ['pp.tenant_id = :tenant_id'];
        $params = [];
        if ($schedId) { $where[] = 'pp.schedule_id = :sched'; $params['sched'] = $schedId; }
        if ($cycleId) { $where[] = 'pp.cycle_id = :cycle'; $params['cycle'] = $cycleId; }
        $rows = scopedQuery(
            'SELECT pp.*, ps.name AS schedule_name, pc.name AS cycle_name,
                    (SELECT pr.id FROM payroll_runs pr
                      WHERE pr.tenant_id = pp.tenant_id AND pr.pay_period_id = pp.id
                        AND pr.status <> "voided"
                      ORDER BY pr.id DESC LIMIT 1) AS latest_run_id,
                    (SELECT pr.status FROM payroll_runs pr
                      WHERE pr.tenant_id = pp.tenant_id AND pr.pay_period_id = pp.id
                        AND pr.status <> "voided"
                      ORDER BY pr.id DESC LIMIT 1) AS latest_run_status
               FROM payroll_pay_periods pp
               JOIN payroll_pay_schedules ps ON ps.id = pp.schedule_id AND ps.tenant_id = pp.tenant_id
          LEFT JOIN payroll_pay_cycles pc ON pc.id = pp.cycle_id AND pc.tenant_id = pp.tenant_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY pp.pay_date DESC, pp.period_number DESC LIMIT 100',
            $params
        );
        api_ok(['periods' => $rows, 'count' => count($rows)]);
    }

    case 'POST': {
        rbac_legacy_require($user, 'payroll.cycles.manage');
        $body = api_json_body();
        $cycleId = (int) ($body['cycle_id'] ?? 0);
        if ($cycleId > 0) {
            try {
                $result = payrollCycleAdvance($cycleId, (int) ($user['id'] ?? 0));
                api_ok(['created_ids' => [$result['period_id']], 'count' => 1] + $result, 201);
            } catch (PayCycleException $e) {
                api_error($e->getMessage(), 422);
            }
        }

        $scheduleId = (int) ($body['schedule_id'] ?? 0);
        if (!$scheduleId) api_error('cycle_id or schedule_id is required', 422);
        $cycles = scopedQuery(
            'SELECT id FROM payroll_pay_cycles
              WHERE tenant_id = :tenant_id AND schedule_id = :schedule_id AND active = 1',
            ['schedule_id' => $scheduleId]
        );
        if ($cycles) {
            api_error('Generate the next period from its pay cycle so cohort membership stays intact', 409);
        }
        $count = max(1, min(24, (int) ($body['count'] ?? 6)));
        $ids = payrollGenerateNextPeriods($scheduleId, $count);
        api_ok(['created_ids' => $ids, 'count' => count($ids)], 201);
    }

    case 'PUT':
    case 'PATCH': {
        rbac_legacy_require($user, 'payroll.cycles.manage');
        $id = (int) (api_query('id') ?? 0);
        if (!$id) api_error('Missing id', 422);
        $body = api_json_body();
        $allowed = ['status', 'notes'];
        $data = [];
        foreach ($allowed as $field) if (array_key_exists($field, $body)) $data[$field] = $body[$field];
        scopedUpdate('payroll_pay_periods', $id, $data);
        api_ok(['ok' => true]);
    }
}

api_error('Method not allowed', 405);
