<?php
/**
 * Time API — tenant custom categories (SPEC §3.3)
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/time.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$method = api_method();

if ($method === 'GET') {
    RBAC::requirePermission($user, 'time.view');
    $rows = scopedQuery('SELECT * FROM tenant_time_categories WHERE tenant_id = :tenant_id ORDER BY parent_bucket, label');
    api_ok(['rows' => $rows]);
}

if ($method === 'POST') {
    RBAC::requirePermission($user, 'time.categories.manage');
    $body = api_json_body();
    api_require_fields($body, ['code','label','parent_bucket']);
    if (!preg_match('/^[a-z][a-z0-9_]{0,39}$/', $body['code'])) api_error('code must be snake_case', 422);
    if (!in_array($body['parent_bucket'], ['billable','nonbillable','pto','unpaid'], true)) api_error('Invalid parent_bucket', 422);
    $id = scopedInsert('tenant_time_categories', [
        'code' => $body['code'], 'label' => $body['label'], 'parent_bucket' => $body['parent_bucket'],
        'is_overtime' => !empty($body['is_overtime']) ? 1 : 0,
        'active' => 1,
    ]);
    timeAudit('time.category.created', ['id' => $id, 'code' => $body['code']], $id);
    api_ok(['id' => $id], 201);
}

if ($method === 'PATCH') {
    RBAC::requirePermission($user, 'time.categories.manage');
    $id = (int) api_query('id', 0);
    if ($id <= 0) api_error('id required', 400);
    $body = api_json_body();
    unset($body['id'], $body['tenant_id'], $body['code']);
    if (!$body) api_error('No fields to update', 422);
    $rows = scopedUpdate('tenant_time_categories', $id, $body);
    if ($rows === 0) api_error('Not found or no change', 404);
    timeAudit('time.category.updated', ['id' => $id, 'fields' => array_keys($body)], $id);
    api_ok(['ok' => true]);
}

if ($method === 'DELETE') {
    RBAC::requirePermission($user, 'time.categories.manage');
    $id = (int) api_query('id', 0);
    if ($id <= 0) api_error('id required', 400);
    $rows = scopedUpdate('tenant_time_categories', $id, ['active' => 0]);
    if ($rows === 0) api_error('Not found', 404);
    timeAudit('time.category.deactivated', ['id' => $id], $id);
    api_ok(['ok' => true]);
}

api_error('Method not allowed', 405);
