<?php
/**
 * People API — custom field DEFINITIONS (per-tenant)
 *
 *   GET    /api/people/custom_fields                → list defs
 *   POST   /api/people/custom_fields                → create def
 *   PATCH  /api/people/custom_fields?id=N           → update def
 *   DELETE /api/people/custom_fields?id=N           → soft delete
 *
 * SPEC: /app/modules/people/SPEC.md §3.1, §5.3
 */

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/people.php';
require_once __DIR__ . '/../lib/audit.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$method = api_method();

const ALLOWED_FIELD_TYPES = ['text','number','date','boolean','select','multiselect'];

if ($method === 'GET') {
    RBAC::requirePermission($user, 'people.view');
    api_ok(['fields' => peopleCustomFieldDefs()]);
}

if ($method === 'POST') {
    RBAC::requirePermission($user, 'people.custom_fields.manage');
    $body = api_json_body();
    api_require_fields($body, ['field_key', 'field_label', 'field_type']);

    if (!preg_match('/^[a-z][a-z0-9_]{0,79}$/', $body['field_key'])) {
        api_error('field_key must be snake_case ([a-z][a-z0-9_]*)', 422);
    }
    if (!in_array($body['field_type'], ALLOWED_FIELD_TYPES, true)) {
        api_error('Invalid field_type', 422, ['allowed' => ALLOWED_FIELD_TYPES]);
    }
    $id = scopedInsert('people_custom_field_defs', [
        'field_key'    => $body['field_key'],
        'field_label'  => $body['field_label'],
        'field_type'   => $body['field_type'],
        'options_json' => isset($body['options']) ? json_encode($body['options']) : null,
        'required'     => !empty($body['required']) ? 1 : 0,
        'pii'          => !empty($body['pii']) ? 1 : 0,
        'order_index'  => (int) ($body['order_index'] ?? 0),
    ]);
    peopleAudit('people.custom_field.defined', ['id' => $id, 'field_key' => $body['field_key']], $id);
    api_ok(['id' => $id], 201);
}

if ($method === 'PATCH') {
    RBAC::requirePermission($user, 'people.custom_fields.manage');
    $id = (int) api_query('id', 0);
    if ($id <= 0) api_error('id required', 400);
    $body = api_json_body();
    unset($body['id'], $body['tenant_id'], $body['field_key']); // key is immutable
    if (isset($body['field_type']) && !in_array($body['field_type'], ALLOWED_FIELD_TYPES, true)) {
        api_error('Invalid field_type', 422);
    }
    if (isset($body['options'])) {
        $body['options_json'] = json_encode($body['options']);
        unset($body['options']);
    }
    if (!$body) api_error('No fields to update', 422);
    $rows = scopedUpdate('people_custom_field_defs', $id, $body);
    if ($rows === 0) api_error('Not found or no change', 404);
    api_ok(['ok' => true]);
}

if ($method === 'DELETE') {
    RBAC::requirePermission($user, 'people.custom_fields.manage');
    $id = (int) api_query('id', 0);
    if ($id <= 0) api_error('id required', 400);
    $rows = scopedUpdate('people_custom_field_defs', $id, ['deleted_at' => date('Y-m-d H:i:s')]);
    if ($rows === 0) api_error('Not found', 404);
    api_ok(['ok' => true]);
}

api_error('Method not allowed', 405);
