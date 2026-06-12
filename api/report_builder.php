<?php
/**
 * Governed custom report builder discovery API.
 *
 * GET /api/report_builder.php?action=datasets
 * GET /api/report_builder.php?dataset=people_directory
 * GET /api/report_builder.php?action=presets
 * GET /api/report_builder.php?action=reports
 * POST /api/report_builder.php?action=run
 * POST /api/report_builder.php?action=export
 * POST /api/report_builder.php
 * PATCH /api/report_builder.php?id=123
 * DELETE /api/report_builder.php?id=123
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/report_builder.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$userId = (int) ($user['id'] ?? 0);
$tenantId = (int) $ctx['tenant_id'];
$method = api_method();

if (!reportBuilderUserCanUse($user)) api_error('Forbidden', 403, ['required' => 'reports.view']);

$action = (string) (api_query('action') ?? '');
$datasetKey = trim((string) (api_query('dataset') ?? ''));

if ($method === 'GET' && $action === 'reports') {
    $id = (int) api_query('id', 0);
    try {
        if ($id > 0) {
            api_ok(['report' => reportBuilderSavedReportGet($id, $tenantId, $userId, reportBuilderUserCanShare($user))]);
        }
        api_ok(['reports' => reportBuilderSavedReportList($tenantId, $userId, $datasetKey ?: null)]);
    } catch (ReportBuilderException $e) {
        api_error($e->getMessage(), 404);
    }
}

if ($method === 'GET' && $action === 'presets') {
    $presets = [];
    foreach (reportBuilderPresetRegistry($tenantId) as $key => $preset) {
        $dataset = reportBuilderDatasetGet((string) ($preset['dataset'] ?? ''), $tenantId);
        if (!$dataset || !reportBuilderUserCanAccessDataset($user, $dataset)) continue;
        $presets[$key] = $preset;
    }
    api_ok([
        'presets' => $presets,
        'count' => count($presets),
        'execution_supported' => true,
    ]);
}

if ($method === 'GET' && $datasetKey !== '') {
    $dataset = reportBuilderDatasetGet($datasetKey, $tenantId);
    if (!$dataset) api_error('Report dataset not found', 404);
    if (!reportBuilderUserCanAccessDataset($user, $dataset)) {
        api_error('Forbidden', 403, ['required' => $dataset['permission'] ?? null]);
    }
    api_ok(['dataset' => $dataset]);
}

if ($method === 'GET' && ($action === '' || $action === 'datasets')) {
    $datasets = [];
    foreach (reportBuilderDatasetRegistry($tenantId) as $key => $dataset) {
        if (reportBuilderUserCanAccessDataset($user, $dataset)) {
            $datasets[$key] = $dataset;
        }
    }
    api_ok([
        'datasets' => $datasets,
        'count' => count($datasets),
        'execution_supported' => true,
    ]);
}

if ($method === 'POST' && $action === 'run') {
    if (!reportBuilderUserCanBuild($user)) api_error('Forbidden', 403, ['required' => 'reports.custom.build']);
    $body = api_json_body();
    try {
        $resolved = reportBuilderApiResolveDefinition($body, $tenantId, $userId, reportBuilderUserCanShare($user));
        $definition = $resolved['definition'];
        $targetId = $resolved['target_id'];
        $definition = reportBuilderValidateDefinition((array) $definition, $tenantId);
        $dataset = reportBuilderDatasetGet((string) $definition['dataset'], $tenantId);
        if (!$dataset) api_error('Report dataset not found', 404);
        if (!reportBuilderUserCanAccessDataset($user, $dataset)) {
            api_error('Forbidden', 403, ['required' => $dataset['permission'] ?? null]);
        }
        if (reportBuilderDefinitionUsesSensitiveFields($definition) && !reportBuilderUserCanExport($user)) {
            api_error('Forbidden', 403, ['required' => 'reports.export']);
        }
        $result = reportBuilderRunDefinition($definition, $tenantId, (array) ($body['options'] ?? []));
        reportBuilderAudit($tenantId, $userId ?: null, 'reports.custom.executed', $targetId, [
            'dataset' => $definition['dataset'],
            'columns' => array_column($result['columns'] ?? [], 'field'),
            'row_count' => $result['row_count'] ?? 0,
            'source' => $resolved['source'],
            'preset_key' => $resolved['preset_key'],
        ]);
        api_ok(['result' => $result, 'execution_supported' => true, 'source' => $resolved['source'], 'preset_key' => $resolved['preset_key']]);
    } catch (ReportBuilderException $e) {
        api_error($e->getMessage(), 422);
    }
}

if ($method === 'POST' && $action === 'export') {
    if (!reportBuilderUserCanBuild($user)) api_error('Forbidden', 403, ['required' => 'reports.custom.build']);
    if (!reportBuilderUserCanExport($user)) api_error('Forbidden', 403, ['required' => 'reports.export']);
    $body = api_json_body();
    try {
        $resolved = reportBuilderApiResolveDefinition($body, $tenantId, $userId, reportBuilderUserCanShare($user));
        $definition = $resolved['definition'];
        $targetId = $resolved['target_id'];
        $definition = reportBuilderValidateDefinition((array) $definition, $tenantId);
        $dataset = reportBuilderDatasetGet((string) $definition['dataset'], $tenantId);
        if (!$dataset) api_error('Report dataset not found', 404);
        if (!reportBuilderUserCanAccessDataset($user, $dataset)) {
            api_error('Forbidden', 403, ['required' => $dataset['permission'] ?? null]);
        }
        $result = reportBuilderRunDefinition($definition, $tenantId, (array) ($body['options'] ?? []));
        reportBuilderAudit($tenantId, $userId ?: null, 'reports.custom.exported', $targetId, [
            'dataset' => $definition['dataset'],
            'columns' => array_column($result['columns'] ?? [], 'field'),
            'row_count' => $result['row_count'] ?? 0,
            'format' => 'csv',
            'source' => $resolved['source'],
            'preset_key' => $resolved['preset_key'],
        ]);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . reportBuilderCsvFilename((string) $definition['dataset']) . '"');
        header('Cache-Control: no-store');
        echo reportBuilderRenderCsv($result);
        exit;
    } catch (ReportBuilderException $e) {
        api_error($e->getMessage(), 422);
    }
}

if ($method === 'POST') {
    if (!reportBuilderUserCanBuild($user)) api_error('Forbidden', 403, ['required' => 'reports.custom.build']);
    try {
        $body = reportBuilderApiHydratePresetBody(api_json_body(), $tenantId);
        $dataset = reportBuilderDatasetGet((string) (($body['definition']['dataset'] ?? $body['dataset'] ?? '')), $tenantId);
        if (!$dataset) api_error('Report dataset not found', 404);
        if (!reportBuilderUserCanAccessDataset($user, $dataset)) api_error('Forbidden', 403, ['required' => $dataset['permission'] ?? null]);
        $id = reportBuilderSavedReportCreate($tenantId, $userId, $body, reportBuilderUserCanShare($user));
        $report = reportBuilderSavedReportGet($id, $tenantId, $userId, true);
        reportBuilderAudit($tenantId, $userId ?: null, 'reports.custom.saved', $id, [
            'dataset' => $report['dataset'] ?? null,
            'visibility' => $report['visibility'] ?? null,
            'source' => !empty($body['preset_key']) ? 'preset' : 'adhoc',
            'preset_key' => $body['preset_key'] ?? null,
        ]);
        api_ok(['id' => $id, 'report' => $report], 201);
    } catch (ReportBuilderException $e) {
        api_error($e->getMessage(), 422);
    }
}

if ($method === 'PATCH') {
    if (!reportBuilderUserCanBuild($user)) api_error('Forbidden', 403, ['required' => 'reports.custom.build']);
    $id = (int) api_query('id', 0);
    if (!$id) api_error('id required', 422);
    try {
        $body = reportBuilderApiHydratePresetBody(api_json_body(), $tenantId);
        if (isset($body['definition']['dataset'])) {
            $dataset = reportBuilderDatasetGet((string) $body['definition']['dataset'], $tenantId);
            if (!$dataset) api_error('Report dataset not found', 404);
            if (!reportBuilderUserCanAccessDataset($user, $dataset)) api_error('Forbidden', 403, ['required' => $dataset['permission'] ?? null]);
        }
        reportBuilderSavedReportUpdate($id, $tenantId, $userId, $body, reportBuilderUserCanShare($user));
        $report = reportBuilderSavedReportGet($id, $tenantId, $userId, true);
        reportBuilderAudit($tenantId, $userId ?: null, 'reports.custom.updated', $id, [
            'dataset' => $report['dataset'] ?? null,
            'visibility' => $report['visibility'] ?? null,
            'source' => !empty($body['preset_key']) ? 'preset' : 'saved_report',
            'preset_key' => $body['preset_key'] ?? null,
        ]);
        api_ok(['report' => $report]);
    } catch (ReportBuilderException $e) {
        api_error($e->getMessage(), 422);
    }
}

if ($method === 'DELETE') {
    if (!reportBuilderUserCanBuild($user)) api_error('Forbidden', 403, ['required' => 'reports.custom.build']);
    $id = (int) api_query('id', 0);
    if (!$id) api_error('id required', 422);
    try {
        reportBuilderSavedReportDelete($id, $tenantId, $userId, reportBuilderUserCanShare($user));
        reportBuilderAudit($tenantId, $userId ?: null, 'reports.custom.deleted', $id);
        api_ok(['id' => $id]);
    } catch (ReportBuilderException $e) {
        api_error($e->getMessage(), 422);
    }
}

api_error('Method not allowed', 405);

function reportBuilderApiResolveDefinition(array $body, int $tenantId, int $userId, bool $canShare): array
{
    if (!empty($body['report_id'])) {
        $saved = reportBuilderSavedReportGet((int) $body['report_id'], $tenantId, $userId, $canShare);
        return [
            'definition' => $saved['definition'] ?? [],
            'target_id' => (int) ($saved['id'] ?? 0),
            'source' => 'saved_report',
            'preset_key' => null,
        ];
    }

    if (!empty($body['preset_key'])) {
        $preset = reportBuilderPresetGet((string) $body['preset_key'], $tenantId);
        if (!$preset) throw new ReportBuilderException('Report preset not found');
        return [
            'definition' => $preset['definition'] ?? [],
            'target_id' => null,
            'source' => 'preset',
            'preset_key' => $preset['key'] ?? (string) $body['preset_key'],
        ];
    }

    return [
        'definition' => $body['definition'] ?? $body,
        'target_id' => null,
        'source' => 'adhoc',
        'preset_key' => null,
    ];
}

function reportBuilderApiHydratePresetBody(array $body, int $tenantId): array
{
    if (empty($body['preset_key']) || !empty($body['definition'])) return $body;
    $preset = reportBuilderPresetGet((string) $body['preset_key'], $tenantId);
    if (!$preset) throw new ReportBuilderException('Report preset not found');
    $body['definition'] = $preset['definition'] ?? [];
    if (trim((string) ($body['name'] ?? '')) === '') {
        $body['name'] = (string) ($preset['label'] ?? $preset['key'] ?? 'Report preset');
    }
    if (!array_key_exists('description', $body)) {
        $body['description'] = (string) ($preset['description'] ?? '');
    }
    return $body;
}
