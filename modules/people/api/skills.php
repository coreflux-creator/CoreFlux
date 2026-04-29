<?php
/**
 * People API — skills sub-resource
 *
 *   GET    /api/people/skills?person_id=N
 *   POST   /api/people/skills?person_id=N        → add { skill, years_experience?, proficiency? }
 *   PATCH  /api/people/skills?id=N               → update one row
 *   DELETE /api/people/skills?id=N
 *
 * SPEC: /app/modules/people/SPEC.md §5.3
 */

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/people.php';
require_once __DIR__ . '/../lib/audit.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$method = api_method();

if ($method === 'GET') {
    RBAC::requirePermission($user, 'people.view');
    $personId = (int) api_query('person_id', 0);
    if ($personId <= 0) api_error('person_id required', 400);
    api_ok(['skills' => peopleSkills($personId)]);
}

if ($method === 'POST') {
    RBAC::requirePermission($user, 'people.manage');
    $personId = (int) api_query('person_id', 0);
    if ($personId <= 0) api_error('person_id required', 400);
    $body = api_json_body();
    api_require_fields($body, ['skill']);

    $id = scopedInsert('people_skills', [
        'person_id'        => $personId,
        'skill'            => $body['skill'],
        'years_experience' => $body['years_experience'] ?? null,
        'proficiency'      => $body['proficiency']      ?? null,
    ]);
    peopleAudit('people.skill.added', ['person_id' => $personId, 'skill' => $body['skill']], $personId);
    api_ok(['id' => $id], 201);
}

if ($method === 'PATCH') {
    RBAC::requirePermission($user, 'people.manage');
    $id = (int) api_query('id', 0);
    if ($id <= 0) api_error('id required', 400);
    $body = api_json_body();
    unset($body['id'], $body['tenant_id'], $body['person_id']);
    if (!$body) api_error('No fields to update', 422);
    $rows = scopedUpdate('people_skills', $id, $body);
    if ($rows === 0) api_error('Not found or no change', 404);
    api_ok(['ok' => true]);
}

if ($method === 'DELETE') {
    RBAC::requirePermission($user, 'people.manage');
    $id = (int) api_query('id', 0);
    if ($id <= 0) api_error('id required', 400);
    $rows = scopedDelete('people_skills', $id);
    if ($rows === 0) api_error('Not found', 404);
    api_ok(['ok' => true]);
}

api_error('Method not allowed', 405);
