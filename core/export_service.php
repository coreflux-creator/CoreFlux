<?php
/**
 * CoreFlux governed export runner.
 *
 * This is the shared execution path for dataset-backed template exports:
 * dataset fetcher -> template renderer -> audit_log. Module endpoints still
 * own their RBAC and business-specific query parameters, but they should not
 * duplicate template validation, CSV headers, filename rules, or audit writes.
 */

declare(strict_types=1);

require_once __DIR__ . '/export_templates.php';
require_once __DIR__ . '/export_datasets.php';

class ExportServiceException extends RuntimeException {}

function exportTemplateGetForDataset(int $templateId, int $tenantId, string $datasetKey): array
{
    if ($templateId <= 0) throw new ExportServiceException('template_id required');
    $dataset = exportDatasetGet($datasetKey);
    if (!$dataset) throw new ExportServiceException("Unknown dataset: {$datasetKey}");
    try {
        $template = exportTemplateGet($templateId, $tenantId);
    } catch (ExportTemplateException $e) {
        throw new ExportServiceException($e->getMessage(), 0, $e);
    }
    if ((string) ($template['dataset'] ?? '') !== $datasetKey) {
        throw new ExportServiceException("template's dataset must be {$datasetKey}");
    }
    return $template;
}

function exportDatasetFetchRows(int $tenantId, string $datasetKey, array $options = []): array
{
    $dataset = exportDatasetGet($datasetKey);
    if (!$dataset) throw new ExportServiceException("Unknown dataset: {$datasetKey}");
    $fetcher = $dataset['fetcher'] ?? null;
    if (!is_string($fetcher) || !is_callable($fetcher)) {
        throw new ExportServiceException("Dataset '{$datasetKey}' is not executable");
    }
    if (!isset($options['actor_user']) && function_exists('getCurrentUser')) {
        $actorUser = getCurrentUser();
        if (is_array($actorUser)) $options['actor_user'] = $actorUser;
    }
    $rows = $fetcher($tenantId, $options);
    if ($rows instanceof Traversable) $rows = iterator_to_array($rows, false);
    if (!is_array($rows)) throw new ExportServiceException("Dataset '{$datasetKey}' did not return rows");
    return array_values($rows);
}

function exportDatasetAuditMeta(array $meta = [], array $options = []): array
{
    $filterParams = exportDatasetAuditFilterParams($options);
    $generatedAt = (string) ($meta['generated_at'] ?? gmdate('c'));
    unset($meta['generated_at']);

    if (!array_key_exists('filter_params', $meta)) {
        $meta['filter_params'] = $filterParams;
    }
    if (!array_key_exists('option_keys', $meta)) {
        $meta['option_keys'] = array_values(array_keys($meta['filter_params']));
    }

    return array_merge(['generated_at' => $generatedAt], $meta);
}

function exportDatasetAuditFilterParams(array $options): array
{
    $out = [];
    foreach ($options as $key => $value) {
        $key = (string) $key;
        if ($key === 'actor_user') continue;
        if ($key === '' || $value === null || $value === '' || $value === []) continue;
        $out[$key] = exportDatasetAuditFilterValue($key, $value);
    }
    return $out;
}

function exportDatasetAuditFilterValue(string $key, $value)
{
    if (preg_match('/(password|secret|token|credential|authorization)/i', $key)) {
        return '[redacted]';
    }
    if (is_array($value)) {
        $out = [];
        foreach ($value as $childKey => $childValue) {
            if ($childValue === null || $childValue === '' || $childValue === []) continue;
            $out[$childKey] = exportDatasetAuditFilterValue((string) $childKey, $childValue);
        }
        return $out;
    }
    if (is_bool($value) || is_int($value) || is_float($value)) return $value;
    $text = (string) $value;
    return strlen($text) > 500 ? substr($text, 0, 500) . '...' : $text;
}

function exportTemplateRenderDatasetToStream(
    int $tenantId,
    string $datasetKey,
    int $templateId,
    array $options,
    $stream,
    ?int $actorUserId = null,
    ?int $targetId = null,
    array $auditMeta = [],
    ?array $template = null
): array {
    $dataset = exportDatasetGet($datasetKey);
    if (!$dataset) throw new ExportServiceException("Unknown dataset: {$datasetKey}");
    $template = $template ?: exportTemplateGetForDataset($templateId, $tenantId, $datasetKey);
    $rows = exportDatasetFetchRows($tenantId, $datasetKey, $options);
    try {
        exportTemplateRenderToStream($templateId, $rows, $stream, $tenantId);
    } catch (ExportTemplateException $e) {
        throw new ExportServiceException($e->getMessage(), 0, $e);
    }

    $event = (string) ($dataset['audit_event'] ?? 'export.dataset.exported');
    $meta = exportDatasetAuditMeta(array_merge([
        'dataset' => $datasetKey,
        'template_id' => $templateId,
        'template_name' => (string) ($template['name'] ?? ''),
        'format' => 'csv',
        'rows' => count($rows),
    ], $auditMeta), $options);
    exportDatasetAudit($tenantId, $actorUserId, $event, $targetId, $meta);

    return [
        'dataset' => $datasetKey,
        'template_id' => $templateId,
        'template_name' => (string) ($template['name'] ?? ''),
        'rows' => count($rows),
        'generated_at' => $meta['generated_at'] ?? null,
        'filter_params' => $meta['filter_params'] ?? [],
    ];
}

function exportTemplateStreamDatasetCsv(
    int $tenantId,
    string $datasetKey,
    int $templateId,
    array $options,
    string $filenamePrefix,
    ?int $actorUserId = null,
    ?int $targetId = null,
    array $auditMeta = []
): array {
    $template = exportTemplateGetForDataset($templateId, $tenantId, $datasetKey);
    $filename = exportTemplateCsvFilename($filenamePrefix, (string) ($template['name'] ?? 'template'), $auditMeta['filename_parts'] ?? []);
    if (!headers_sent()) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Cache-Control: no-store');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
    }
    $out = fopen('php://output', 'w');
    if ($out === false) throw new ExportServiceException('Could not open output stream');
    try {
        return exportTemplateRenderDatasetToStream($tenantId, $datasetKey, $templateId, $options, $out, $actorUserId, $targetId, $auditMeta, $template);
    } finally {
        fclose($out);
    }
}

function exportTemplateCsvFilename(string $prefix, string $templateName, array $parts = []): string
{
    $bits = array_merge([$prefix, $templateName], array_map('strval', $parts));
    $clean = [];
    foreach ($bits as $bit) {
        $slug = strtolower(trim((string) $bit));
        $slug = preg_replace('/[^a-z0-9_-]+/', '-', $slug) ?: '';
        $slug = trim($slug, '-_');
        if ($slug !== '') $clean[] = $slug;
    }
    if (!$clean) $clean[] = 'export';
    return implode('-', $clean) . '.csv';
}

function exportDatasetAudit(int $tenantId, ?int $actorUserId, string $event, ?int $targetId, array $meta = []): void
{
    if (!function_exists('getDB')) return;
    $meta = exportDatasetAuditMeta($meta, $meta['filter_params'] ?? []);
    try {
        getDB()->prepare(
            'INSERT INTO audit_log
                (tenant_id, actor_user_id, event, target_id, meta_json, created_at)
             VALUES
                (:tenant_id, :actor_user_id, :event, :target_id, :meta_json, NOW())'
        )->execute([
            'tenant_id' => $tenantId,
            'actor_user_id' => $actorUserId,
            'event' => $event,
            'target_id' => $targetId,
            'meta_json' => json_encode($meta, JSON_UNESCAPED_SLASHES),
        ]);
    } catch (\Throwable $e) {
        error_log('[export.audit] ' . $event . ' failed: ' . $e->getMessage());
    }
}
