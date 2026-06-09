<?php
/**
 * CoreFlux governed report builder registry.
 *
 * The custom report builder is a consumer of platform datasets. It does not
 * own People, Staffing, payroll, AP, or custom-field definitions; it projects
 * the export dataset contract into reportable dimensions, measures, and
 * filters with the source dataset permission preserved.
 */

declare(strict_types=1);

require_once __DIR__ . '/export_datasets.php';

class ReportBuilderException extends RuntimeException {}

/**
 * @return array<string, array>
 */
function reportBuilderDatasetRegistry(?int $tenantId = null): array
{
    $out = [];
    foreach (exportDatasetRegistry() as $key => $dataset) {
        $fields = reportBuilderFieldRegistry($key, $tenantId);
        $dimensions = [];
        $measures = [];
        $filters = [];

        foreach ($fields as $fieldKey => $field) {
            if (($field['role'] ?? 'dimension') === 'measure') {
                $measures[$fieldKey] = $field;
            } else {
                $dimensions[$fieldKey] = $field;
            }
            if (!empty($field['filterable'])) {
                $filters[$fieldKey] = [
                    'key'       => $fieldKey,
                    'label'     => $field['label'],
                    'type'      => $field['type'],
                    'operators' => reportBuilderFilterOperators((string) $field['type']),
                    'sensitive' => !empty($field['sensitive']),
                ];
            }
        }

        $out[$key] = [
            'key'                   => $key,
            'label'                 => (string) ($dataset['label'] ?? $key),
            'module_id'             => $dataset['module_id'] ?? null,
            'permission'            => $dataset['permission'] ?? null,
            'source_dataset'        => $key,
            'custom_field_entities' => array_values($dataset['custom_field_entities'] ?? []),
            'sensitive_fields'      => array_values($dataset['sensitive_fields'] ?? []),
            'audit_event'           => 'reports.custom.dataset.viewed',
            'execution_supported'   => false,
            'fields'                => $fields,
            'dimensions'            => $dimensions,
            'measures'              => $measures,
            'filters'               => $filters,
        ];
    }
    return $out;
}

function reportBuilderDatasetGet(string $key, ?int $tenantId = null): ?array
{
    $reg = reportBuilderDatasetRegistry($tenantId);
    return $reg[$key] ?? null;
}

/**
 * @return array<string, array>
 */
function reportBuilderFieldRegistry(string $datasetKey, ?int $tenantId = null): array
{
    $dataset = exportDatasetGet($datasetKey);
    if (!$dataset) return [];

    $fields = [];
    foreach (exportDatasetFieldRegistry($datasetKey, $tenantId) as $key => $field) {
        $type = reportBuilderInferFieldType($key, $field);
        $sensitive = in_array($key, $dataset['sensitive_fields'] ?? [], true) || !empty($field['sensitive']);
        $role = reportBuilderFieldRole($key, $type);
        $fields[$key] = [
            'key'          => $key,
            'label'        => (string) ($field['label'] ?? $key),
            'type'         => $type,
            'role'         => $role,
            'filterable'   => true,
            'sortable'     => true,
            'sample'       => $field['sample'] ?? '',
            'sensitive'    => $sensitive,
            'custom_field' => !empty($field['custom_field']),
            'entity_type'  => $field['entity_type'] ?? null,
        ];
    }
    return $fields;
}

function reportBuilderFieldRole(string $key, string $type): string
{
    $lower = strtolower($key);
    if (str_ends_with($lower, '_id') || $lower === 'id' || str_contains($lower, 'external_id')) {
        return 'dimension';
    }
    return in_array($type, ['number', 'currency'], true) ? 'measure' : 'dimension';
}

function reportBuilderInferFieldType(string $key, array $field): string
{
    $declared = strtolower((string) ($field['field_type'] ?? ''));
    if (in_array($declared, ['number', 'date', 'boolean', 'currency'], true)) return $declared;

    $lower = strtolower($key . ' ' . (string) ($field['label'] ?? ''));
    if (str_contains($lower, 'date') || str_ends_with(strtolower($key), '_at') || str_contains($lower, 'expiry')) {
        return 'date';
    }
    if (str_contains($lower, 'amount') || str_contains($lower, 'dollars') || str_contains($lower, 'cents') ||
        str_contains($lower, 'pay') || str_contains($lower, 'rate') || str_contains($lower, 'margin')) {
        return 'currency';
    }
    if (str_contains($lower, 'hours') || str_contains($lower, 'count') || str_contains($lower, 'total') ||
        str_contains($lower, 'percentage') || str_contains($lower, 'pct')) {
        return 'number';
    }
    return 'text';
}

/**
 * @return list<string>
 */
function reportBuilderFilterOperators(string $type): array
{
    return match ($type) {
        'number', 'currency', 'date' => ['equals', 'not_equals', 'greater_than', 'less_than', 'between', 'is_blank', 'is_not_blank'],
        'boolean' => ['equals', 'is_blank', 'is_not_blank'],
        default => ['equals', 'not_equals', 'contains', 'starts_with', 'is_blank', 'is_not_blank'],
    };
}

function reportBuilderUserCanUse(array $user): bool
{
    if (!function_exists('rbac_legacy_can')) return true;
    return rbac_legacy_can($user, 'reports.view') || rbac_legacy_can($user, 'reports.custom.build');
}

function reportBuilderUserCanBuild(array $user): bool
{
    if (!function_exists('rbac_legacy_can')) return true;
    return rbac_legacy_can($user, 'reports.custom.build');
}

function reportBuilderUserCanShare(array $user): bool
{
    if (!function_exists('rbac_legacy_can')) return true;
    return rbac_legacy_can($user, 'reports.custom.share');
}

function reportBuilderUserCanAccessDataset(array $user, array $dataset): bool
{
    $permission = (string) ($dataset['permission'] ?? '');
    if ($permission === '' || !function_exists('rbac_legacy_can')) return true;
    return rbac_legacy_can($user, $permission);
}

function reportBuilderValidateDefinition(array $raw, ?int $tenantId = null): array
{
    $datasetKey = trim((string) ($raw['dataset'] ?? ''));
    if ($datasetKey === '') throw new ReportBuilderException('dataset is required');
    $dataset = reportBuilderDatasetGet($datasetKey, $tenantId);
    if (!$dataset) throw new ReportBuilderException("Unknown report dataset: {$datasetKey}");

    $fields = $dataset['fields'] ?? [];
    $definition = [
        'dataset'    => $datasetKey,
        'columns'    => reportBuilderNormalizeFieldList($raw['columns'] ?? [], $fields, 'columns'),
        'dimensions' => reportBuilderNormalizeFieldList($raw['dimensions'] ?? [], $fields, 'dimensions', 'dimension'),
        'measures'   => reportBuilderNormalizeFieldList($raw['measures'] ?? [], $fields, 'measures', 'measure'),
        'filters'    => reportBuilderNormalizeFilters($raw['filters'] ?? [], $fields),
        'sorts'      => reportBuilderNormalizeSorts($raw['sorts'] ?? [], $fields),
        'limit'      => min(10000, max(1, (int) ($raw['limit'] ?? 1000))),
    ];

    if (!$definition['columns'] && !$definition['dimensions'] && !$definition['measures']) {
        throw new ReportBuilderException('At least one column, dimension, or measure is required');
    }

    return $definition;
}

function reportBuilderNormalizeFieldList($raw, array $fields, string $label, ?string $requiredRole = null): array
{
    if ($raw === null || $raw === '') return [];
    if (!is_array($raw)) throw new ReportBuilderException("{$label} must be an array");
    $out = [];
    foreach ($raw as $entry) {
        $key = is_array($entry) ? (string) ($entry['field'] ?? $entry['key'] ?? '') : (string) $entry;
        if ($key === '') throw new ReportBuilderException("{$label} contains an empty field");
        if (!isset($fields[$key])) throw new ReportBuilderException("{$label} field '{$key}' is not available");
        if ($requiredRole !== null && ($fields[$key]['role'] ?? 'dimension') !== $requiredRole) {
            throw new ReportBuilderException("{$label} field '{$key}' is not a {$requiredRole}");
        }
        $out[] = [
            'field'     => $key,
            'label'     => (string) ($fields[$key]['label'] ?? $key),
            'type'      => (string) ($fields[$key]['type'] ?? 'text'),
            'sensitive' => !empty($fields[$key]['sensitive']),
        ];
    }
    return $out;
}

function reportBuilderNormalizeFilters($raw, array $fields): array
{
    if ($raw === null || $raw === '') return [];
    if (!is_array($raw)) throw new ReportBuilderException('filters must be an array');
    $out = [];
    foreach ($raw as $entry) {
        if (!is_array($entry)) throw new ReportBuilderException('filters entries must be objects');
        $key = (string) ($entry['field'] ?? $entry['key'] ?? '');
        if ($key === '' || !isset($fields[$key])) throw new ReportBuilderException("Filter field '{$key}' is not available");
        $type = (string) ($fields[$key]['type'] ?? 'text');
        $operator = (string) ($entry['operator'] ?? 'equals');
        if (!in_array($operator, reportBuilderFilterOperators($type), true)) {
            throw new ReportBuilderException("Filter operator '{$operator}' is not valid for {$key}");
        }
        $out[] = [
            'field'     => $key,
            'operator'  => $operator,
            'value'     => $entry['value'] ?? null,
            'value_to'  => $entry['value_to'] ?? null,
            'type'      => $type,
            'sensitive' => !empty($fields[$key]['sensitive']),
        ];
    }
    return $out;
}

function reportBuilderNormalizeSorts($raw, array $fields): array
{
    if ($raw === null || $raw === '') return [];
    if (!is_array($raw)) throw new ReportBuilderException('sorts must be an array');
    $out = [];
    foreach ($raw as $entry) {
        $key = is_array($entry) ? (string) ($entry['field'] ?? $entry['key'] ?? '') : (string) $entry;
        if ($key === '' || !isset($fields[$key])) throw new ReportBuilderException("Sort field '{$key}' is not available");
        $dir = strtolower((string) (is_array($entry) ? ($entry['direction'] ?? 'asc') : 'asc'));
        $out[] = [
            'field' => $key,
            'direction' => $dir === 'desc' ? 'desc' : 'asc',
        ];
    }
    return $out;
}

function reportBuilderSavedReportList(int $tenantId, int $userId, ?string $dataset = null): array
{
    $pdo = getDB();
    $sql = "SELECT id, tenant_id, owner_user_id, dataset, name, description, visibility,
                   definition_json, is_active, created_at, updated_at
              FROM report_builder_reports
             WHERE tenant_id = :tenant_id
               AND is_active = 1
               AND (visibility = 'shared' OR owner_user_id = :user_id)";
    $params = ['tenant_id' => $tenantId, 'user_id' => $userId];
    if ($dataset !== null && $dataset !== '') {
        $sql .= ' AND dataset = :dataset';
        $params['dataset'] = $dataset;
    }
    $sql .= ' ORDER BY updated_at DESC, id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return array_map('reportBuilderHydrateSavedReport', $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

function reportBuilderSavedReportGet(int $id, int $tenantId, int $userId, bool $canManageShared = false): array
{
    $pdo = getDB();
    $stmt = $pdo->prepare(
        'SELECT id, tenant_id, owner_user_id, dataset, name, description, visibility,
                definition_json, is_active, created_at, updated_at
           FROM report_builder_reports
          WHERE id = :id AND tenant_id = :tenant_id AND is_active = 1
          LIMIT 1'
    );
    $stmt->execute(['id' => $id, 'tenant_id' => $tenantId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new ReportBuilderException('Saved report not found');
    if ((int) $row['owner_user_id'] !== $userId && (string) $row['visibility'] !== 'shared' && !$canManageShared) {
        throw new ReportBuilderException('Saved report not found');
    }
    return reportBuilderHydrateSavedReport($row);
}

function reportBuilderSavedReportCreate(int $tenantId, int $userId, array $args, bool $canShare = false): int
{
    $name = trim((string) ($args['name'] ?? ''));
    if ($name === '') throw new ReportBuilderException('name is required');
    $visibility = ((string) ($args['visibility'] ?? 'private')) === 'shared' ? 'shared' : 'private';
    if ($visibility === 'shared' && !$canShare) throw new ReportBuilderException('reports.custom.share required for shared reports');
    $definition = reportBuilderValidateDefinition($args['definition'] ?? $args, $tenantId);
    $pdo = getDB();
    $stmt = $pdo->prepare(
        'INSERT INTO report_builder_reports
            (tenant_id, owner_user_id, dataset, name, description, visibility,
             definition_json, is_active, created_by_user_id, updated_by_user_id, created_at, updated_at)
         VALUES
            (:tenant_id, :owner_user_id, :dataset, :name, :description, :visibility,
             :definition_json, 1, :created_by, :updated_by, NOW(), NOW())'
    );
    $stmt->execute([
        'tenant_id' => $tenantId,
        'owner_user_id' => $userId,
        'dataset' => $definition['dataset'],
        'name' => $name,
        'description' => trim((string) ($args['description'] ?? '')),
        'visibility' => $visibility,
        'definition_json' => json_encode($definition, JSON_UNESCAPED_SLASHES),
        'created_by' => $userId,
        'updated_by' => $userId,
    ]);
    return (int) $pdo->lastInsertId();
}

function reportBuilderSavedReportUpdate(int $id, int $tenantId, int $userId, array $args, bool $canShare = false): void
{
    $row = reportBuilderSavedReportGet($id, $tenantId, $userId, $canShare);
    if ((int) $row['owner_user_id'] !== $userId && !$canShare) {
        throw new ReportBuilderException('Only the owner or report sharer can update this report');
    }
    $definition = array_key_exists('definition', $args)
        ? reportBuilderValidateDefinition((array) $args['definition'], $tenantId)
        : $row['definition'];
    $visibility = ((string) ($args['visibility'] ?? $row['visibility'])) === 'shared' ? 'shared' : 'private';
    if ($visibility === 'shared' && !$canShare) throw new ReportBuilderException('reports.custom.share required for shared reports');
    $pdo = getDB();
    $stmt = $pdo->prepare(
        'UPDATE report_builder_reports
            SET name = :name, description = :description, visibility = :visibility,
                dataset = :dataset, definition_json = :definition_json,
                updated_by_user_id = :updated_by, updated_at = NOW()
          WHERE id = :id AND tenant_id = :tenant_id'
    );
    $stmt->execute([
        'id' => $id,
        'tenant_id' => $tenantId,
        'name' => trim((string) ($args['name'] ?? $row['name'])),
        'description' => trim((string) ($args['description'] ?? $row['description'] ?? '')),
        'visibility' => $visibility,
        'dataset' => $definition['dataset'],
        'definition_json' => json_encode($definition, JSON_UNESCAPED_SLASHES),
        'updated_by' => $userId,
    ]);
}

function reportBuilderSavedReportDelete(int $id, int $tenantId, int $userId, bool $canShare = false): void
{
    $row = reportBuilderSavedReportGet($id, $tenantId, $userId, $canShare);
    if ((int) $row['owner_user_id'] !== $userId && !$canShare) {
        throw new ReportBuilderException('Only the owner or report sharer can delete this report');
    }
    getDB()->prepare(
        'UPDATE report_builder_reports
            SET is_active = 0, updated_by_user_id = :user_id, updated_at = NOW()
          WHERE id = :id AND tenant_id = :tenant_id'
    )->execute(['id' => $id, 'tenant_id' => $tenantId, 'user_id' => $userId]);
}

function reportBuilderHydrateSavedReport(array $row): array
{
    $row['id'] = (int) ($row['id'] ?? 0);
    $row['tenant_id'] = (int) ($row['tenant_id'] ?? 0);
    $row['owner_user_id'] = (int) ($row['owner_user_id'] ?? 0);
    $row['definition'] = json_decode((string) ($row['definition_json'] ?? '{}'), true) ?: [];
    unset($row['definition_json']);
    return $row;
}
