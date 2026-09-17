<?php
/**
 * Payroll employee profiles.
 *
 * GET                         list active employees with setup readiness
 * GET ?employee_id=N          fetch one employee and profile
 * POST/PUT/PATCH              create or update one profile
 * DELETE ?employee_id=N       disable one profile
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/payroll.php';
require_once __DIR__ . '/../lib/profiles.php';

$ctx = api_require_auth();
$user = (array) $ctx['user'];
$tenantId = (int) $ctx['tenant_id'];

switch (api_method()) {
    case 'GET': {
        rbac_legacy_require($user, 'payroll.profiles.view');
        $refs = payrollProfileReferenceData($tenantId);
        $empId = (int) (api_query('employee_id') ?? 0);
        if ($empId) {
            $emp = peopleGetEmployee($empId);
            if (!$emp) api_error('Employee not found', 404);
            $profile = payrollGetProfile($empId);
            $gaps = array_values(array_unique(array_merge(
                peoplePayrollReadiness($empId),
                payrollProfileAssignmentGaps($profile, $refs)
            )));
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
                'profile' => payrollPresentProfile($profile, $refs),
                'gaps' => $gaps,
                'ready' => count($gaps) === 0 && $profile && (int) $profile['enabled'] === 1,
            ]);
        }

        $emps = peopleListActiveEmployees(api_query('q'), api_query('department'));
        $out = [];
        foreach ($emps as $e) {
            $profile = payrollGetProfile((int) $e['id']);
            $gaps = array_values(array_unique(array_merge(
                peoplePayrollReadiness((int) $e['id']),
                payrollProfileAssignmentGaps($profile, $refs)
            )));
            $presented = payrollPresentProfile($profile, $refs);
            $out[] = [
                'employee_id' => (int) $e['id'],
                'employee_number' => $e['employee_number'],
                'name' => trim(($e['preferred_name'] ?: $e['legal_first_name']) . ' ' . $e['legal_last_name']),
                'work_email' => $e['work_email'],
                'department' => $e['department'],
                'has_profile' => $profile !== null,
                'enabled' => $profile ? (int) $profile['enabled'] === 1 : false,
                'schedule_id' => $presented['schedule_id'] ?? null,
                'schedule_name' => $presented['schedule_name'] ?? null,
                'cycle_id' => $presented['cycle_id'] ?? null,
                'cycle_name' => $presented['cycle_name'] ?? null,
                'work_state' => $profile['work_state'] ?? null,
                'gaps' => $gaps,
                'ready' => count($gaps) === 0 && $profile && (int) $profile['enabled'] === 1,
            ];
        }
        api_ok(['profiles' => $out, 'count' => count($out)]);
    }

    case 'POST':
    case 'PUT':
    case 'PATCH': {
        rbac_legacy_require($user, 'payroll.profiles.manage');
        $body = api_json_body();
        $empId = (int) ($body['employee_id'] ?? api_query('employee_id') ?? 0);
        if (!$empId) api_error('Missing employee_id', 422);

        $emp = peopleGetEmployee($empId);
        if (!$emp) api_error('Employee not found', 404);

        $existing = payrollGetProfile($empId);
        $data = payrollNormalizeProfileInput($body, $existing, $tenantId);
        if ($existing) {
            scopedUpdate('payroll_profiles', (int) $existing['id'], $data);
            $id = (int) $existing['id'];
            $event = 'payroll.profile.updated';
            $status = 200;
        } else {
            $id = scopedInsert('payroll_profiles', $data + ['employee_id' => $empId]);
            $event = 'payroll.profile.created';
            $status = 201;
        }
        payrollAudit($event, [
            'employee_id' => $empId,
            'fields' => array_keys($data),
        ], $id, [
            'before' => $existing,
            'after' => payrollGetProfile($empId),
        ]);
        api_ok(['id' => $id], $status);
    }

    case 'DELETE': {
        rbac_legacy_require($user, 'payroll.profiles.manage');
        $empId = (int) (api_query('employee_id') ?? 0);
        if (!$empId) api_error('Missing employee_id', 422);
        $existing = payrollGetProfile($empId);
        if (!$existing) api_error('Not found', 404);
        scopedUpdate('payroll_profiles', (int) $existing['id'], ['enabled' => 0]);
        payrollAudit('payroll.profile.updated', [
            'employee_id' => $empId,
            'fields' => ['enabled'],
        ], (int) $existing['id'], [
            'before' => $existing,
            'after' => payrollGetProfile($empId),
        ]);
        api_ok(['ok' => true]);
    }
}

api_error('Method not allowed', 405);
