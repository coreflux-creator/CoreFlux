<?php
/**
 * Payroll — Pay Cycles CRUD + advance.
 *
 *   GET                      → list cycles
 *   GET ?id=<n>              → detail (incl. recent periods)
 *   POST                     → create cycle on a schedule
 *   PUT  ?id=<n>             → update (rename, toggle active, override anchor/offset)
 *   DELETE ?id=<n>           → soft-disable (active=0)
 *   POST ?action=advance     → manually advance a cycle (insert next period + draft run)
 *   POST ?action=auto_advance → cron-style sweep across all active cycles
 *
 * Cycles are cohorts on top of pay schedules. A schedule defines cadence;
 * a cycle defines the cohort that runs on that cadence (e.g. NY engineers
 * on a bi-weekly schedule). Profiles bind to a cycle via cycle_id; periods
 * are generated per-cycle so each cohort has its own calendar.
 */

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../lib/cycles.php';

$ctx = api_require_auth();

switch (api_method()) {
    case 'GET': {
        $id = (int) (api_query('id') ?? 0);
        if ($id) {
            $row = scopedFind(
                'SELECT c.*, s.name AS schedule_name, s.frequency, s.period_start_anchor,
                        s.pay_date_offset_days
                 FROM payroll_pay_cycles c
                 JOIN payroll_pay_schedules s
                   ON s.id = c.schedule_id AND s.tenant_id = c.tenant_id
                 WHERE c.tenant_id = :tenant_id AND c.id = :id',
                ['id' => $id]
            );
            if (!$row) api_error('Not found', 404);

            $periods = scopedQuery(
                'SELECT id, period_number, period_start, period_end, pay_date, status
                 FROM payroll_pay_periods
                 WHERE tenant_id = :tenant_id AND cycle_id = :cid
                 ORDER BY period_number DESC LIMIT 12',
                ['cid' => $id]
            );
            api_ok(['cycle' => $row, 'periods' => $periods]);
        }
        api_ok(['cycles' => payrollCycleList()]);
    }

    case 'POST': {
        $action = (string) (api_query('action') ?? '');
        $body   = api_json_body();

        if ($action === 'advance') {
            $cycleId = (int) ($body['cycle_id'] ?? api_query('id') ?? 0);
            if (!$cycleId) api_error('cycle_id required', 422);
            try {
                $res = payrollCycleAdvance($cycleId, (int) ($ctx['user']['id'] ?? 0));
                api_ok(['ok' => true] + $res, 201);
            } catch (PayCycleException $e) {
                api_error($e->getMessage(), 422);
            }
        }

        if ($action === 'auto_advance') {
            $log = payrollCycleAutoAdvanceAll();
            $advanced = 0; $notDue = 0; $errors = 0;
            foreach ($log as $r) {
                if ($r['status'] === 'advanced') $advanced++;
                elseif ($r['status'] === 'not_due' || $r['status'] === 'inactive') $notDue++;
                elseif ($r['status'] === 'error') $errors++;
            }
            api_ok(['ok' => true, 'advanced' => $advanced, 'not_due' => $notDue,
                    'errors' => $errors, 'log' => $log]);
        }

        // Default POST = create.
        api_require_fields($body, ['name', 'schedule_id']);
        $sched = scopedFind(
            'SELECT id FROM payroll_pay_schedules WHERE tenant_id = :tenant_id AND id = :id',
            ['id' => (int) $body['schedule_id']]
        );
        if (!$sched) api_error('schedule_id not found', 404);

        $cohort = $body['cohort_filter_json'] ?? null;
        if (is_array($cohort)) $cohort = json_encode($cohort, JSON_UNESCAPED_SLASHES);
        if ($cohort !== null && strlen((string) $cohort) > 1000) {
            api_error('cohort_filter_json too long (max 1000 chars)', 422);
        }

        $id = scopedInsert('payroll_pay_cycles', [
            'name'                          => trim((string) $body['name']),
            'schedule_id'                   => (int) $body['schedule_id'],
            'cohort_filter_json'            => $cohort,
            'anchor_date_override'          => $body['anchor_date_override'] ?? null,
            'pay_date_offset_days_override' => isset($body['pay_date_offset_days_override'])
                                                ? (int) $body['pay_date_offset_days_override'] : null,
            'next_period_number'            => 1,
            'active'                        => 1,
            'notes'                         => $body['notes'] ?? null,
        ]);
        payrollAuditLight('payroll.cycle.created', ['cycle_id' => $id, 'name' => $body['name']], $id);
        api_ok(['id' => $id], 201);
    }

    case 'PUT':
    case 'PATCH': {
        $id = (int) (api_query('id') ?? 0);
        if (!$id) api_error('Missing id', 422);
        $body = api_json_body();
        $allowed = ['name','cohort_filter_json','anchor_date_override',
                    'pay_date_offset_days_override','active','notes'];
        $data = [];
        foreach ($allowed as $f) {
            if (!array_key_exists($f, $body)) continue;
            $v = $body[$f];
            if ($f === 'cohort_filter_json' && is_array($v)) $v = json_encode($v, JSON_UNESCAPED_SLASHES);
            $data[$f] = $v;
        }
        scopedUpdate('payroll_pay_cycles', $id, $data);
        payrollAuditLight('payroll.cycle.updated', ['cycle_id' => $id, 'fields' => array_keys($data)], $id);
        api_ok(['ok' => true]);
    }

    case 'DELETE': {
        $id = (int) (api_query('id') ?? 0);
        if (!$id) api_error('Missing id', 422);
        scopedUpdate('payroll_pay_cycles', $id, ['active' => 0]);
        payrollAuditLight('payroll.cycle.deactivated', ['cycle_id' => $id], $id);
        api_ok(['ok' => true]);
    }
}

api_error('Method not allowed', 405);
