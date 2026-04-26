<?php
/**
 * Payroll — Pay Periods
 *
 * GET ?schedule_id=N            → list periods for a schedule
 * GET ?id=<n>                   → detail (with run summary)
 * POST { schedule_id, count? }  → generate next N periods (default 6)
 * PUT  ?id=<n> { status }       → update status (open/approved/paid/closed)
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../lib/payroll.php';

$ctx = api_require_auth();

switch (api_method()) {
    case 'GET': {
        $id = (int) (api_query('id') ?? 0);
        if ($id) {
            $row = scopedFind(
                'SELECT * FROM payroll_pay_periods WHERE tenant_id = :tenant_id AND id = :id',
                ['id' => $id]
            );
            if (!$row) api_error('Not found', 404);
            // Attach runs
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
        $where = ['tenant_id = :tenant_id'];
        $params = [];
        if ($schedId) { $where[] = 'schedule_id = :sched'; $params['sched'] = $schedId; }
        $sql = 'SELECT * FROM payroll_pay_periods WHERE ' . implode(' AND ', $where)
             . ' ORDER BY pay_date DESC, period_number DESC LIMIT 50';
        $rows = scopedQuery($sql, $params);
        api_ok(['periods' => $rows, 'count' => count($rows)]);
    }

    case 'POST': {
        $body = api_json_body();
        api_require_fields($body, ['schedule_id']);
        $count = max(1, min(24, (int) ($body['count'] ?? 6)));
        $ids = payrollGenerateNextPeriods((int) $body['schedule_id'], $count);
        api_ok(['created_ids' => $ids, 'count' => count($ids)], 201);
    }

    case 'PUT':
    case 'PATCH': {
        $id = (int) (api_query('id') ?? 0);
        if (!$id) api_error('Missing id', 422);
        $body = api_json_body();
        $allowed = ['status','notes'];
        $data = [];
        foreach ($allowed as $f) if (array_key_exists($f, $body)) $data[$f] = $body[$f];
        scopedUpdate('payroll_pay_periods', $id, $data);
        api_ok(['ok' => true]);
    }
}

api_error('Method not allowed', 405);
