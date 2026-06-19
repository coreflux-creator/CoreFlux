<?php
/**
 * People API — emergency contacts
 *
 *   GET    /api/people/emergency_contacts?person_id=N
 *   POST   /api/people/emergency_contacts?person_id=N
 *   PATCH  /api/people/emergency_contacts?id=N
 *   DELETE /api/people/emergency_contacts?id=N
 *
 * SPEC: /app/modules/people/SPEC.md §5.3
 */

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$method = api_method();

if ($method === 'GET') {
    rbac_legacy_require($user, 'people.pii.view');
    $personId = (int) api_query('person_id', 0);
    if ($personId <= 0) api_error('person_id required', 400);
    $rows = scopedQuery(
        'SELECT * FROM people_emergency_contacts
         WHERE tenant_id = :tenant_id AND person_id = :pid
         ORDER BY name',
        ['pid' => $personId]
    );
    api_ok(['contacts' => $rows]);
}

if ($method === 'POST') {
    rbac_legacy_require($user, 'people.pii.manage');
    $personId = (int) api_query('person_id', 0);
    if ($personId <= 0) api_error('person_id required', 400);
    $body = api_json_body();
    api_require_fields($body, ['name', 'relationship', 'phone']);
    $id = scopedInsert('people_emergency_contacts', [
        'person_id'    => $personId,
        'name'         => $body['name'],
        'relationship' => $body['relationship'],
        'phone'        => $body['phone'],
        'email'        => $body['email'] ?? null,
    ]);
    api_ok(['id' => $id], 201);
}

if ($method === 'PATCH') {
    rbac_legacy_require($user, 'people.pii.manage');
    $id = (int) api_query('id', 0);
    if ($id <= 0) api_error('id required', 400);
    $body = api_json_body();
    unset($body['id'], $body['tenant_id'], $body['person_id']);
    if (!$body) api_error('No fields to update', 422);
    $rows = scopedUpdate('people_emergency_contacts', $id, $body);
    if ($rows === 0) api_error('Not found or no change', 404);
    api_ok(['ok' => true]);
}

if ($method === 'DELETE') {
    rbac_legacy_require($user, 'people.pii.manage');
    $id = (int) api_query('id', 0);
    if ($id <= 0) api_error('id required', 400);
    $rows = scopedDelete('people_emergency_contacts', $id);
    if ($rows === 0) api_error('Not found', 404);
    api_ok(['ok' => true]);
}

api_error('Method not allowed', 405);
