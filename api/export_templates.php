<?php
/**
 * /api/export_templates.php — tenant + platform export template CRUD.
 *
 *   GET    /api/export_templates.php?dataset=…   list visible templates
 *   GET    /api/export_templates.php?action=datasets           dataset registry
 *   GET    /api/export_templates.php?id=N                      fetch one
 *   POST   /api/export_templates.php                            create
 *   PATCH  /api/export_templates.php?id=N                      update
 *   DELETE /api/export_templates.php?id=N                      delete (soft for system)
 *   POST   /api/export_templates.php?action=clone&id=N         clone to tenant
 *   POST   /api/export_templates.php?action=parse_headers      (multipart file=…)
 *
 * Platform-scoped CRUD requires master_admin; tenant-scoped requires
 * `admin.export_templates.manage` (falls back to tenant_admin).
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/export_templates.php';
require_once __DIR__ . '/../core/export_datasets.php';

$ctx       = api_require_auth();
$user      = $ctx['user'];
$userId    = (int) ($user['id'] ?? 0);
$role      = $ctx['role'] ?? 'employee';
$tenantId  = (int) $ctx['tenant_id'];

$method = api_method();
$action = api_query('action', '');

// ───── Dataset registry (read-only, any auth'd user) ─────
if ($method === 'GET' && $action === 'datasets') {
    $reg = exportDatasetAccessibleRegistry($user);
    $out = [];
    foreach ($reg as $key => $ds) {
        $out[$key] = [
            'key'                   => $key,
            'label'                 => $ds['label'],
            'module_id'             => $ds['module_id'] ?? null,
            'permission'            => $ds['permission'] ?? null,
            'formats'               => $ds['formats'] ?? ['csv'],
            'audit_event'           => $ds['audit_event'] ?? null,
            'sensitive_fields'      => $ds['sensitive_fields'] ?? [],
            'custom_field_entities' => $ds['custom_field_entities'] ?? [],
            'fields'                => exportDatasetFieldRegistry($key, $tenantId),
        ];
    }
    api_ok(['datasets' => $out]);
}

// ───── Sample CSV header parser ─────
if ($method === 'POST' && $action === 'parse_headers') {
    if (empty($_FILES['file']['tmp_name'])) api_error('file upload required', 422);
    if (($_FILES['file']['size'] ?? 0) > 262144) api_error('Sample must be < 256 KB', 413);
    $contents = file_get_contents($_FILES['file']['tmp_name']);
    if ($contents === false) api_error('Could not read upload', 500);
    try {
        $headers = exportTemplateParseHeaders($contents, (string) ($_POST['delimiter'] ?? ','));
    } catch (ExportTemplateException $e) {
        api_error($e->getMessage(), 422);
    }
    api_ok(['headers' => $headers]);
}

// ───── Clone ─────
if ($method === 'POST' && $action === 'clone') {
    _xtplRequireManage($role);
    $id = (int) api_query('id', 0);
    if (!$id) api_error('id required', 422);
    try {
        $src = exportTemplateGet($id, $tenantId);
        _xtplRequireDatasetAccess($user, (string) ($src['dataset'] ?? ''));
        $newId = exportTemplateClone($id, $tenantId, $userId);
    } catch (ExportTemplateException $e) {
        api_error($e->getMessage(), 422);
    }
    api_ok(['id' => $newId]);
}

// ───── List / single ─────
if ($method === 'GET') {
    $id = (int) api_query('id', 0);
    if ($id) {
        try {
            $template = exportTemplateGet($id, $tenantId);
            _xtplRequireDatasetAccess($user, (string) ($template['dataset'] ?? ''));
            api_ok(['template' => $template]);
        }
        catch (ExportTemplateException $e) { api_error($e->getMessage(), 404); }
    }
    $dataset = api_query('dataset', null);
    if ($dataset !== null && $dataset !== '') _xtplRequireDatasetAccess($user, (string) $dataset);
    $rows = exportTemplateList($tenantId, $dataset);
    $accessible = exportDatasetAccessibleRegistry($user);
    if ($dataset === null || $dataset === '') {
        $rows = array_values(array_filter($rows, static fn($row) => isset($accessible[(string) ($row['dataset'] ?? '')])));
    }
    api_ok(['templates' => $rows, 'datasets' => array_keys($accessible)]);
}

// ───── Create ─────
if ($method === 'POST') {
    _xtplRequireManage($role);
    $body = api_json_body();
    _xtplRequireDatasetAccess($user, (string) ($body['dataset'] ?? ''));
    try {
        $id = exportTemplateCreate($tenantId, $body, $userId, $role);
    } catch (ExportTemplateException $e) {
        api_error($e->getMessage(), 422);
    }
    api_ok(['id' => $id, 'template' => exportTemplateGet($id, $tenantId)], 201);
}

// ───── Update ─────
if ($method === 'PATCH') {
    _xtplRequireManage($role);
    $id = (int) api_query('id', 0);
    if (!$id) api_error('id required', 422);
    $body = api_json_body();
    try {
        $existing = exportTemplateGet($id, $tenantId);
        _xtplRequireDatasetAccess($user, (string) ($existing['dataset'] ?? ''));
        exportTemplateUpdate($id, $body, $userId, $tenantId, $role);
    } catch (ExportTemplateException $e) {
        api_error($e->getMessage(), 422);
    }
    api_ok(['template' => exportTemplateGet($id, $tenantId)]);
}

// ───── Delete ─────
if ($method === 'DELETE') {
    _xtplRequireManage($role);
    $id = (int) api_query('id', 0);
    if (!$id) api_error('id required', 422);
    try {
        $existing = exportTemplateGet($id, $tenantId);
        _xtplRequireDatasetAccess($user, (string) ($existing['dataset'] ?? ''));
        exportTemplateDelete($id, $userId, $tenantId, $role);
    } catch (ExportTemplateException $e) {
        api_error($e->getMessage(), 422);
    }
    api_ok(['id' => $id]);
}

function _xtplRequireDatasetAccess(array $user, string $datasetKey): void {
    if ($datasetKey === '') api_error('dataset required', 422);
    $dataset = exportDatasetGet($datasetKey);
    if (!$dataset) api_error('Unknown dataset: ' . $datasetKey, 422);
    if (!exportDatasetUserCanAccess($user, $dataset)) {
        api_error('Forbidden', 403, ['required' => $dataset['permission'] ?? null]);
    }
}

api_error('Method not allowed', 405);

function _xtplRequireManage(string $role): void {
    if (in_array($role, ['master_admin', 'tenant_admin', 'admin'], true)) return;
    api_error('Forbidden — tenant_admin or master_admin required', 403);
}
