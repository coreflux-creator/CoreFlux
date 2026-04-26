<?php
/**
 * Payroll — Per-Employee Profiles (CRUD + readiness check)
 *
 * GET                         → list profiles + payroll-readiness gaps for each employee
 * GET ?employee_id=N          → single profile (joined with employee identity)
 * POST                        → upsert profile for employee_id
 * PUT  ?employee_id=N         → update profile
 * DELETE ?employee_id=N       → disable
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../lib/payroll.php';

$ctx = api_require_auth();

switch (api_method()) {
    case 'GET': {
        $empId = (int) (api_query('employee_id') ?? 0);
        if ($empId) {
            $emp = peopleGetEmployee($empId);
            if (!$emp) api_error('Employee not found', 404);
            $profile = payrollGetProfile($empId);
            $gaps = peoplePayrollReadiness($empId);
            api_ok([
                'employee' => [
                    'id' => $emp['id'],
                    'employee_number' => $emp['employee_number'],
                    'legal_first_name' => $emp['legal_first_name'],
                    'legal_last_name'  => $emp['legal_last_name'],
                    'preferred_name'   => $emp['preferred_name'],
                    'work_email'       => $emp['work_email'],
                    'department'       => $emp['department'],
                    'status'           => $emp['status'],
                ],
                'profile'  => $profile,
                'gaps'     => $gaps,
                'ready'    => count($gaps) === 0 && $profile && (int)$profile['enabled'] === 1,
            ]);
        }
        // List view: every active employee + profile + gaps
        $emps = peopleListActiveEmployees(api_query('q'), api_query('department'));
        $out = [];
        foreach ($emps as $e) {
            $profile = payrollGetProfile((int) $e['id']);
            $gaps    = peoplePayrollReadiness((int) $e['id']);
            $out[] = [
                'employee_id'      => (int) $e['id'],
                'employee_number'  => $e['employee_number'],
                'name'             => trim(($e['preferred_name'] ?: $e['legal_first_name']) . ' ' . $e['legal_last_name']),
                'work_email'       => $e['work_email'],
                'department'       => $e['department'],
                'has_profile'      => $profile !== null,
                'enabled'          => $profile ? (int) $profile['enabled'] === 1 : false,
                'schedule_id'      => $profile['schedule_id'] ?? null,
                'work_state'       => $profile['work_state']  ?? null,
                'gaps'             => $gaps,
                'ready'            => count($gaps) === 0 && $profile && (int)$profile['enabled'] === 1,
            ];
        }
        api_ok(['profiles' => $out, 'count' => count($out)]);
    }

    case 'POST':
    case 'PUT':
    case 'PATCH': {
        $body = api_json_body();
        $empId = (int) ($body['employee_id'] ?? api_query('employee_id') ?? 0);
        if (!$empId) api_error('Missing employee_id', 422);

        $emp = peopleGetEmployee($empId);
        if (!$emp) api_error('Employee not found', 404);

        $existing = payrollGetProfile($empId);
        $allowed = [
            'schedule_id','work_state','payment_method','default_hours_per_period',
            'retirement_pretax_bps','health_premium_cents','hsa_pretax_cents',
            'extra_post_tax_cents','enabled','notes',
        ];
        $data = [];
        foreach ($allowed as $f) if (array_key_exists($f, $body)) $data[$f] = $body[$f];

        if ($existing) {
            scopedUpdate('payroll_profiles', (int) $existing['id'], $data);
            api_ok(['id' => (int) $existing['id']]);
        } else {
            $id = scopedInsert('payroll_profiles', $data + [
                'employee_id'    => $empId,
                'work_state'     => $data['work_state'] ?? 'CA',
                'payment_method' => $data['payment_method'] ?? 'direct_deposit',
                'enabled'        => $data['enabled'] ?? 1,
            ]);
            api_ok(['id' => $id], 201);
        }
    }

    case 'DELETE': {
        $empId = (int) (api_query('employee_id') ?? 0);
        if (!$empId) api_error('Missing employee_id', 422);
        $existing = payrollGetProfile($empId);
        if (!$existing) api_error('Not found', 404);
        scopedUpdate('payroll_profiles', (int) $existing['id'], ['enabled' => 0]);
        api_ok(['ok' => true]);
    }
}

api_error('Method not allowed', 405);
