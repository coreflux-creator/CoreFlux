<?php
/**
 * People — I-9 verification status (singleton per employee)
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/employees.php';
require_once __DIR__ . '/../lib/audit.php';

$ctx = api_require_auth();
$user = $ctx['user'];

switch (api_method()) {
    case 'GET': {
        rbac_legacy_require($user, 'people.pii.view');
        $empId = (int) (api_query('employee_id') ?? 0);
        if (!$empId) api_error('Missing employee_id', 422);
        $row = scopedFind(
            'SELECT * FROM people_i9 WHERE tenant_id = :tenant_id AND employee_id = :emp',
            ['emp' => $empId]
        );
        peopleAudit('people.pii.viewed', ['employee_id' => $empId, 'resource' => 'i9'], $empId);
        api_ok(['i9' => $row]);
    }
    case 'POST':
    case 'PUT':
    case 'PATCH': {
        rbac_legacy_require($user, 'people.pii.manage');
        $body = api_json_body();
        api_require_fields($body, ['employee_id']);
        $empId = (int) $body['employee_id'];

        $existing = scopedFind(
            'SELECT id FROM people_i9 WHERE tenant_id = :tenant_id AND employee_id = :emp',
            ['emp' => $empId]
        );

        $data = [
            'employee_id'      => $empId,
            'status'           => $body['status']           ?? 'pending',
            'list_a_document'  => $body['list_a_document']  ?? null,
            'list_b_document'  => $body['list_b_document']  ?? null,
            'list_c_document'  => $body['list_c_document']  ?? null,
            'verified_at'      => $body['verified_at']      ?? null,
            'verifier_user_id' => $body['verifier_user_id'] ?? ($ctx['user']['id'] ?? null),
            'reverify_due'     => $body['reverify_due']     ?? null,
            'notes'            => $body['notes']            ?? null,
        ];

        if ($existing) {
            scopedUpdate('people_i9', (int) $existing['id'], $data);
            peopleAudit('people.updated', ['employee_id' => $empId, 'resource' => 'i9', 'action' => 'update'], $empId);
            api_ok(['id' => (int) $existing['id'], 'updated' => true]);
        }
        $id = scopedInsert('people_i9', $data);
        peopleAudit('people.updated', ['employee_id' => $empId, 'resource' => 'i9', 'action' => 'create', 'i9_id' => $id], $empId);
        api_ok(['id' => $id, 'created' => true], 201);
    }
}
api_error('Method not allowed', 405);
