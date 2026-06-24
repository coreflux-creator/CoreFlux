<?php
/**
 * Payroll — Pay Schedules CRUD
 *
 * GET                → list
 * GET ?id=<n>        → detail
 * POST               → create
 * PUT  ?id=<n>       → update
 * DELETE ?id=<n>     → soft-disable (active=0)
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../lib/payroll.php';

$ctx = api_require_auth();
$user = $ctx['user'];

switch (api_method()) {
    case 'GET': {
        rbac_legacy_require($user, 'payroll.view');
        $id = (int) (api_query('id') ?? 0);
        if ($id) {
            $row = scopedFind(
                'SELECT * FROM payroll_pay_schedules WHERE tenant_id = :tenant_id AND id = :id',
                ['id' => $id]
            );
            if (!$row) api_error('Not found', 404);
            api_ok(['schedule' => $row]);
        }
        $rows = scopedQuery(
            'SELECT * FROM payroll_pay_schedules
             WHERE tenant_id = :tenant_id
             ORDER BY active DESC, name'
        );
        api_ok(['schedules' => $rows, 'count' => count($rows)]);
    }

    case 'POST': {
        rbac_legacy_require($user, 'payroll.schedules.manage');
        $body = api_json_body();
        api_require_fields($body, ['name','frequency','period_start_anchor']);
        $id = scopedInsert('payroll_pay_schedules', [
            'name'                 => $body['name'],
            'frequency'            => $body['frequency'],
            'period_start_anchor'  => $body['period_start_anchor'],
            'pay_date_offset_days' => (int) ($body['pay_date_offset_days'] ?? 5),
            'timezone'             => $body['timezone'] ?? 'America/Los_Angeles',
            'active'               => 1,
            'notes'                => $body['notes'] ?? null,
        ]);
        // Auto-generate the first 6 periods
        payrollGenerateNextPeriods($id, 6);
        payrollAudit('payroll.schedule.created', [
            'schedule_id' => $id,
            'frequency' => $body['frequency'],
        ], $id);
        api_ok(['id' => $id], 201);
    }

    case 'PUT':
    case 'PATCH': {
        rbac_legacy_require($user, 'payroll.schedules.manage');
        $id = (int) (api_query('id') ?? 0);
        if (!$id) api_error('Missing id', 422);
        $body = api_json_body();
        $allowed = ['name','frequency','period_start_anchor','pay_date_offset_days','timezone','active','notes'];
        $data = [];
        foreach ($allowed as $f) if (array_key_exists($f, $body)) $data[$f] = $body[$f];
        scopedUpdate('payroll_pay_schedules', $id, $data);
        payrollAudit('payroll.schedule.updated', [
            'schedule_id' => $id,
            'changed_fields' => array_keys($data),
        ], $id);
        api_ok(['ok' => true]);
    }

    case 'DELETE': {
        rbac_legacy_require($user, 'payroll.schedules.manage');
        $id = (int) (api_query('id') ?? 0);
        if (!$id) api_error('Missing id', 422);
        scopedUpdate('payroll_pay_schedules', $id, ['active' => 0]);
        payrollAudit('payroll.schedule.deactivated', ['schedule_id' => $id], $id);
        api_ok(['ok' => true]);
    }
}

api_error('Method not allowed', 405);
