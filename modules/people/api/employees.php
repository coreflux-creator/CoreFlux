<?php
/**
 * People Module — Employees API
 *
 * GET                 → list (search, filter)
 * GET ?id=<n>         → detail (masked PII)
 * POST                → create
 * PUT ?id=<n>         → update in-place (non-history fields only)
 * DELETE ?id=<n>      → soft-terminate (status=terminated, terminated_at=today)
 *
 * History-tracked fields (comp, tax, banking) have their own endpoints.
 */

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/encryption.php';
require_once __DIR__ . '/../lib/employees.php';
require_once __DIR__ . '/../lib/audit.php';

$ctx = api_require_auth();
$user = $ctx['user'];

switch (api_method()) {

    case 'GET': {
        rbac_legacy_require($user, 'people.view');
        $id = (int) (api_query('id') ?? 0);

        if ($id > 0) {
            $row = peopleGetEmployee($id);
            if (!$row) api_error('Not found', 404);
            $canViewPii = rbac_legacy_can($user, 'people.pii.view');
            if ($canViewPii) {
                _logPiiAccess($id, 'employee.pii.viewed', [
                    'fields' => ['ssn_last4','date_of_birth','gender','marital_status','citizenship_status'],
                ]);
            }
            api_ok(['employee' => _presentEmployee($row, includePIIMask: $canViewPii)]);
        }

        $rows = peopleListActiveEmployees(
            api_query('q'),
            api_query('department')
        );
        $out = array_map(fn($r) => _presentEmployee($r, includePIIMask: false), $rows);
        api_ok(['employees' => $out, 'count' => count($out)]);
    }

    case 'POST': {
        rbac_legacy_require($user, 'people.manage');
        $body = api_json_body();
        api_require_fields($body, ['legal_first_name', 'legal_last_name']);

        // Employee number: caller may supply, or auto-increment per tenant
        $empNumber = trim((string)($body['employee_number'] ?? ''));
        if ($empNumber === '') $empNumber = _nextEmployeeNumber();

        $piiInput = _employeePiiInputFields($body);
        if ($piiInput) {
            rbac_legacy_require($user, 'people.pii.manage');
        }

        $data = [
            'employee_number'    => $empNumber,
            'user_id'            => $body['user_id']             ?? null,
            'legal_first_name'   => $body['legal_first_name'],
            'legal_middle_name'  => $body['legal_middle_name']   ?? null,
            'legal_last_name'    => $body['legal_last_name'],
            'preferred_name'     => $body['preferred_name']      ?? null,
            'date_of_birth'      => $body['date_of_birth']       ?? null,
            'gender'             => $body['gender']              ?? null,
            'marital_status'     => $body['marital_status']      ?? null,
            'citizenship_status' => $body['citizenship_status']  ?? null,
            'work_auth_status'   => $body['work_auth_status']    ?? null,
            'personal_email'     => $body['personal_email']      ?? null,
            'status'             => $body['status']              ?? 'pending',
            'employment_type'    => $body['employment_type']     ?? 'full_time',
            'flsa_class'         => $body['flsa_class']          ?? 'non_exempt',
            'hire_date'          => $body['hire_date']           ?? null,
            'start_date'         => $body['start_date']          ?? null,
            'manager_id'         => $body['manager_id']          ?? null,
            'department'         => $body['department']          ?? null,
            'location'           => $body['location']            ?? null,
            'job_title'          => $body['job_title']           ?? null,
            'work_email'         => $body['work_email']          ?? null,
        ];

        if (!empty($body['ssn'])) {
            $data['ssn_cipher'] = encryptField($body['ssn']);
            $data['ssn_last4']  = last4($body['ssn']);
            $data['ssn_hash']   = fieldHash($body['ssn']);
        }

        $id = scopedInsert('people_employees', $data);
        _logChange($id, 'employee', $id, 'create', array_keys($data));
        if ($piiInput) _logPiiAccess($id, 'employee.pii.updated', ['action' => 'create', 'fields' => $piiInput]);

        $row = peopleGetEmployee($id);
        api_ok(['employee' => _presentEmployee($row, includePIIMask: rbac_legacy_can($user, 'people.pii.view'))], 201);
    }

    case 'PUT':
    case 'PATCH': {
        rbac_legacy_require($user, 'people.manage');
        $id = (int) (api_query('id') ?? 0);
        if (!$id) api_error('Missing id', 422);

        $existing = peopleGetEmployee($id);
        if (!$existing) api_error('Not found', 404);

        $body = api_json_body();
        $allowed = [
            'legal_first_name','legal_middle_name','legal_last_name','preferred_name',
            'date_of_birth','gender','marital_status','citizenship_status','work_auth_status',
            'personal_email','employment_type','flsa_class','hire_date','start_date',
            'manager_id','department','location','job_title','work_email','photo_url',
        ];
        $update = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $body)) $update[$field] = $body[$field];
        }
        $piiInput = _employeePiiInputFields($body);
        if ($piiInput) {
            rbac_legacy_require($user, 'people.pii.manage');
        }
        // SSN is sensitive — accept only if explicitly provided and recompute companions
        if (array_key_exists('ssn', $body) && $body['ssn'] !== null && $body['ssn'] !== '') {
            $update['ssn_cipher'] = encryptField($body['ssn']);
            $update['ssn_last4']  = last4($body['ssn']);
            $update['ssn_hash']   = fieldHash($body['ssn']);
        }
        if (!$update) api_ok(['employee' => _presentEmployee($existing, includePIIMask: rbac_legacy_can($user, 'people.pii.view'))]);

        scopedUpdate('people_employees', $id, $update);
        _logChange($id, 'employee', $id, 'update', array_keys($update));
        if ($piiInput) {
            _logPiiAccess($id, 'employee.pii.updated', ['action' => 'update', 'fields' => $piiInput]);
        }

        $row = peopleGetEmployee($id);
        api_ok(['employee' => _presentEmployee($row, includePIIMask: rbac_legacy_can($user, 'people.pii.view'))]);
    }

    case 'DELETE': {
        rbac_legacy_require($user, 'people.terminate');
        $id = (int) (api_query('id') ?? 0);
        if (!$id) api_error('Missing id', 422);
        $existing = peopleGetEmployee($id);
        if (!$existing) api_error('Not found', 404);

        scopedUpdate('people_employees', $id, [
            'status'        => 'terminated',
            'terminated_at' => date('Y-m-d'),
        ]);
        scopedInsert('people_employment_history', [
            'employee_id'    => $id,
            'event_type'     => 'termination',
            'effective_date' => date('Y-m-d'),
            'status'         => 'terminated',
            'reason'         => api_query('reason'),
            'created_by'     => $ctx['user']['id'] ?? null,
        ]);
        _logChange($id, 'employee', $id, 'terminate', ['status','terminated_at']);
        api_ok(['ok' => true]);
    }
}

api_error('Method not allowed', 405);


// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function _presentEmployee(array $row, bool $includePIIMask): array {
    // Strip binary ciphertext before JSON-encoding; expose only masks.
    $out = $row;
    unset($out['ssn_cipher'], $out['ssn_hash']);
    if ($includePIIMask) {
        $out['ssn_masked'] = $row['ssn_last4'] ? '***-**-' . $row['ssn_last4'] : null;
    } else {
        unset($out['ssn_last4'], $out['date_of_birth'], $out['gender'], $out['marital_status'], $out['citizenship_status']);
    }
    return $out;
}

function _employeePiiInputFields(array $body): array {
    $fields = [];
    foreach (['date_of_birth','gender','marital_status','citizenship_status','ssn'] as $field) {
        if (array_key_exists($field, $body) && $body[$field] !== null && $body[$field] !== '') {
            $fields[] = $field;
        }
    }
    return $fields;
}

function _nextEmployeeNumber(): string {
    $row = scopedFind(
        'SELECT COALESCE(MAX(CAST(employee_number AS UNSIGNED)), 1000) AS mx
         FROM people_employees WHERE tenant_id = :tenant_id'
    );
    return (string)(((int)($row['mx'] ?? 1000)) + 1);
}

function _logChange(int $employeeId, string $entity, ?int $entityId, string $action, array $fields): void {
    try {
        scopedInsert('people_change_log', [
            'user_id'        => $_SESSION['user']['id'] ?? null,
            'employee_id'    => $employeeId,
            'entity'         => $entity,
            'entity_id'      => $entityId,
            'action'         => $action,
            'fields_changed' => json_encode($fields),
            'ip_address'     => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
        error_log('[people_change_log] ' . $e->getMessage());
    }
    $eventAction = match ($action) {
        'create' => 'created',
        'terminate' => 'terminated',
        default => 'updated',
    };
    peopleAudit("people.{$entity}.{$eventAction}", [
        'employee_id' => $employeeId,
        'entity' => $entity,
        'entity_id' => $entityId,
        'action' => $action,
        'fields' => $fields,
    ], $entityId ?: $employeeId);
}

function _logPiiAccess(int $employeeId, string $event, array $meta = []): void {
    try {
        scopedInsert('people_change_log', [
            'user_id'        => $_SESSION['user']['id'] ?? null,
            'employee_id'    => $employeeId,
            'entity'         => 'employee_pii',
            'entity_id'      => $employeeId,
            'action'         => $event,
            'fields_changed' => json_encode($meta),
            'ip_address'     => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
        error_log('[people_pii_audit] ' . $e->getMessage());
    }
    $platformEvent = str_contains($event, 'updated') || str_contains($event, 'set')
        ? 'people.pii.updated'
        : 'people.pii.viewed';
    peopleAudit($platformEvent, [
        'employee_id' => $employeeId,
        'legacy_event' => $event,
    ] + $meta, $employeeId, [
        'object_type' => 'people_employee_pii',
    ]);
}
