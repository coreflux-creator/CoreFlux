<?php
/**
 * People — Addresses (simple tenant+employee scoped CRUD)
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
        $rows = scopedQuery(
            'SELECT * FROM people_addresses WHERE tenant_id = :tenant_id AND employee_id = :emp
             ORDER BY kind, effective_from DESC',
            ['emp' => $empId]
        );
        peopleAudit('people.pii.viewed', ['employee_id' => $empId, 'resource' => 'legacy_addresses', 'row_count' => count($rows)], $empId);
        api_ok(['addresses' => $rows]);
    }
    case 'POST': {
        rbac_legacy_require($user, 'people.pii.manage');
        $body = api_json_body();
        api_require_fields($body, ['employee_id', 'street1', 'city']);
        $id = scopedInsert('people_addresses', [
            'employee_id'    => (int) $body['employee_id'],
            'kind'           => $body['kind']           ?? 'home',
            'street1'        => $body['street1'],
            'street2'        => $body['street2']        ?? null,
            'city'           => $body['city'],
            'region'         => $body['region']         ?? null,
            'postal_code'    => $body['postal_code']    ?? null,
            'country'        => $body['country']        ?? 'US',
            'effective_from' => $body['effective_from'] ?? null,
            'effective_to'   => $body['effective_to']   ?? null,
        ]);
        peopleAudit('people.updated', ['employee_id' => (int) $body['employee_id'], 'resource' => 'legacy_addresses', 'address_id' => $id, 'action' => 'create'], (int) $body['employee_id']);
        api_ok(['id' => $id], 201);
    }
    case 'PUT':
    case 'PATCH': {
        rbac_legacy_require($user, 'people.pii.manage');
        $id = (int) (api_query('id') ?? 0);
        if (!$id) api_error('Missing id', 422);
        $body = api_json_body();
        $allowed = ['kind','street1','street2','city','region','postal_code','country','effective_from','effective_to'];
        $update = array_intersect_key($body, array_flip($allowed));
        if (!$update) api_ok(['ok' => true]);
        scopedUpdate('people_addresses', $id, $update);
        peopleAudit('people.updated', ['resource' => 'legacy_addresses', 'address_id' => $id, 'fields' => array_keys($update), 'action' => 'update'], $id);
        api_ok(['ok' => true]);
    }
    case 'DELETE': {
        rbac_legacy_require($user, 'people.pii.manage');
        $id = (int) (api_query('id') ?? 0);
        if (!$id) api_error('Missing id', 422);
        scopedDelete('people_addresses', $id);
        peopleAudit('people.updated', ['resource' => 'legacy_addresses', 'address_id' => $id, 'action' => 'delete'], $id);
        api_ok(['ok' => true]);
    }
}
api_error('Method not allowed', 405);
