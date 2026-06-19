<?php
/**
 * People — Phones, Emergency Contacts (simple tenant+employee scoped CRUD)
 * One file serves both resources distinguished by ?resource=
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/employees.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$resource = api_query('resource', 'phones');
$tables = [
    'phones'              => ['people_phones',              ['kind','number','is_preferred']],
    'emergency_contacts'  => ['people_emergency_contacts',  ['priority','name','relationship','phone','email','notes']],
];
if (!isset($tables[$resource])) api_error('Invalid resource', 422);
[$table, $allowed] = $tables[$resource];
$readPermission = $resource === 'emergency_contacts' ? 'people.pii.view' : 'people.view';
$writePermission = $resource === 'emergency_contacts' ? 'people.pii.manage' : 'people.manage';

switch (api_method()) {
    case 'GET': {
        rbac_legacy_require($user, $readPermission);
        $empId = (int) (api_query('employee_id') ?? 0);
        if (!$empId) api_error('Missing employee_id', 422);
        $rows = scopedQuery(
            "SELECT * FROM `$table` WHERE tenant_id = :tenant_id AND employee_id = :emp ORDER BY id",
            ['emp' => $empId]
        );
        api_ok([$resource => $rows]);
    }
    case 'POST': {
        rbac_legacy_require($user, $writePermission);
        $body = api_json_body();
        api_require_fields($body, ['employee_id']);
        $data = ['employee_id' => (int) $body['employee_id']];
        foreach ($allowed as $f) if (array_key_exists($f, $body)) $data[$f] = $body[$f];
        $id = scopedInsert($table, $data);
        api_ok(['id' => $id], 201);
    }
    case 'PUT':
    case 'PATCH': {
        rbac_legacy_require($user, $writePermission);
        $id = (int) (api_query('id') ?? 0);
        if (!$id) api_error('Missing id', 422);
        $body = api_json_body();
        $update = array_intersect_key($body, array_flip($allowed));
        if (!$update) api_ok(['ok' => true]);
        scopedUpdate($table, $id, $update);
        api_ok(['ok' => true]);
    }
    case 'DELETE': {
        rbac_legacy_require($user, $writePermission);
        $id = (int) (api_query('id') ?? 0);
        if (!$id) api_error('Missing id', 422);
        scopedDelete($table, $id);
        api_ok(['ok' => true]);
    }
}
api_error('Method not allowed', 405);
