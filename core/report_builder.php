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
require_once __DIR__ . '/CsvExportService.php';

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
            'execution_supported'   => reportBuilderDatasetExecutionSupported($key),
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
 * Named report presets are platform metadata over governed datasets. They do
 * not bypass dataset permissions or execution; API callers resolve them into a
 * normal report definition before validation, preview, export, or saving.
 *
 * @return array<string, array>
 */
function reportBuilderPresetRegistry(?int $tenantId = null): array
{
    $raw = [
        'people.active_directory' => [
            'label'       => 'Active People Directory',
            'description' => 'Active people with core identity and classification fields.',
            'module_id'   => 'people',
            'definition'  => [
                'dataset' => 'people_directory',
                'columns' => ['first_name', 'last_name', 'email_primary', 'classification', 'employment_type', 'status'],
                'filters' => [['field' => 'status', 'operator' => 'equals', 'value' => 'active']],
                'sorts'   => [
                    ['field' => 'last_name', 'direction' => 'asc'],
                    ['field' => 'first_name', 'direction' => 'asc'],
                ],
                'limit'   => 1000,
            ],
        ],
        'staffing.active_placements' => [
            'label'       => 'Active Placements Roster',
            'description' => 'Active placement roster with worker, client, dates, and rates.',
            'module_id'   => 'staffing',
            'definition'  => [
                'dataset' => 'placements_directory',
                'columns' => [
                    'person_name',
                    'person_email',
                    'title',
                    'engagement_type',
                    'status',
                    'start_date',
                    'end_date',
                    'end_client_name',
                    'bill_rate',
                    'pay_rate',
                ],
                'filters' => [['field' => 'status', 'operator' => 'equals', 'value' => 'active']],
                'sorts'   => [['field' => 'start_date', 'direction' => 'desc']],
                'limit'   => 1000,
            ],
        ],
        'placements.expiring_soon' => [
            'label'       => 'Expiring Placements',
            'description' => 'Placements with a due or end date approaching, resolved through the shared placement dataset.',
            'module_id'   => 'placements',
            'definition'  => [
                'dataset' => 'placements_directory',
                'columns' => [
                    'placement_id',
                    'person_first_name',
                    'person_last_name',
                    'person_name',
                    'person_email',
                    'title',
                    'engagement_type',
                    'status',
                    'start_date',
                    'due_date',
                    'end_date',
                    'expiring_date',
                    'end_client_name',
                ],
                'filters' => [
                    ['field' => 'status', 'operator' => 'in', 'value' => ['active', 'pending_start', 'on_hold']],
                    ['field' => 'expiring_date', 'operator' => 'is_not_blank'],
                ],
                'sorts'   => [['field' => 'expiring_date', 'direction' => 'asc']],
                'limit'   => 1000,
            ],
        ],
        'placements.active_by_client' => [
            'label'       => 'Active Placements by Client',
            'description' => 'Grouped count of active placements by end client.',
            'module_id'   => 'placements',
            'definition'  => [
                'dataset' => 'placements_directory',
                'dimensions' => ['end_client_name'],
                'measures' => ['placement_count'],
                'filters' => [['field' => 'status', 'operator' => 'equals', 'value' => 'active']],
                'sorts'   => [
                    ['field' => 'placement_count', 'direction' => 'desc'],
                    ['field' => 'end_client_name', 'direction' => 'asc'],
                ],
                'limit'   => 1000,
            ],
        ],
    ];

    $out = [];
    foreach ($raw as $key => $preset) {
        $definition = (array) ($preset['definition'] ?? []);
        $datasetKey = (string) ($definition['dataset'] ?? '');
        $dataset = reportBuilderDatasetGet($datasetKey, $tenantId);
        if (!$dataset || empty($dataset['execution_supported'])) continue;
        try {
            reportBuilderValidateDefinition($definition, $tenantId);
        } catch (\Throwable $e) {
            error_log('[report_builder.presets] invalid preset ' . $key . ': ' . $e->getMessage());
            continue;
        }

        $out[$key] = [
            'key'              => $key,
            'label'            => (string) ($preset['label'] ?? $key),
            'description'      => (string) ($preset['description'] ?? ''),
            'module_id'        => $preset['module_id'] ?? ($dataset['module_id'] ?? null),
            'source_module_id' => $dataset['module_id'] ?? null,
            'dataset'          => $datasetKey,
            'dataset_label'    => (string) ($dataset['label'] ?? $datasetKey),
            'permission'       => $dataset['permission'] ?? null,
            'definition'       => $definition,
        ];
    }
    return $out;
}

function reportBuilderPresetGet(string $key, ?int $tenantId = null): ?array
{
    $reg = reportBuilderPresetRegistry($tenantId);
    return $reg[$key] ?? null;
}

function reportBuilderDatasetExecutionSupported(string $datasetKey): bool
{
    $dataset = exportDatasetGet($datasetKey);
    $fetcher = $dataset['fetcher'] ?? null;
    return is_string($fetcher) && is_callable($fetcher);
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
            'aggregate'    => $role === 'measure' ? reportBuilderNormalizeAggregate($field['aggregate'] ?? 'sum') : null,
            'filterable'   => true,
            'sortable'     => true,
            'sample'       => $field['sample'] ?? '',
            'sensitive'    => $sensitive,
            'custom_field' => !empty($field['custom_field']),
            'entity_type'  => $field['entity_type'] ?? null,
            'archived'     => !empty($field['archived']),
            'archived_at'  => $field['archived_at'] ?? null,
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

function reportBuilderNormalizeAggregate($raw): string
{
    $aggregate = strtolower(trim((string) $raw));
    return in_array($aggregate, ['sum', 'count', 'avg', 'min', 'max'], true) ? $aggregate : 'sum';
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
        'number', 'currency', 'date' => ['equals', 'not_equals', 'in', 'greater_than', 'greater_than_or_equal', 'less_than', 'less_than_or_equal', 'between', 'is_blank', 'is_not_blank'],
        'boolean' => ['equals', 'in', 'is_blank', 'is_not_blank'],
        default => ['equals', 'not_equals', 'in', 'contains', 'starts_with', 'is_blank', 'is_not_blank'],
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

function reportBuilderUserCanExport(array $user): bool
{
    if (!function_exists('rbac_legacy_can')) return true;
    return rbac_legacy_can($user, 'reports.export');
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
        $normalized = [
            'field'     => $key,
            'label'     => (string) ($fields[$key]['label'] ?? $key),
            'type'      => (string) ($fields[$key]['type'] ?? 'text'),
            'sensitive' => !empty($fields[$key]['sensitive']),
        ];
        if (($fields[$key]['role'] ?? 'dimension') === 'measure') {
            $rawAggregate = is_array($entry) && array_key_exists('aggregate', $entry)
                ? $entry['aggregate']
                : ($fields[$key]['aggregate'] ?? 'sum');
            $normalized['aggregate'] = reportBuilderNormalizeAggregate($rawAggregate);
        }
        $out[] = $normalized;
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

function reportBuilderDefinitionUsesSensitiveFields(array $definition, ?int $tenantId = null): bool
{
    foreach (['columns', 'dimensions', 'measures', 'filters'] as $section) {
        foreach (($definition[$section] ?? []) as $entry) {
            if (!empty($entry['sensitive'])) return true;
        }
    }
    $dataset = reportBuilderDatasetGet((string) ($definition['dataset'] ?? ''), $tenantId);
    $fields = $dataset['fields'] ?? [];
    foreach (($definition['sorts'] ?? []) as $sort) {
        $field = (string) ($sort['field'] ?? '');
        if (!empty($fields[$field]['sensitive'])) return true;
    }
    return false;
}

function reportBuilderRunDefinition(array $raw, int $tenantId, array $options = []): array
{
    $definition = reportBuilderValidateDefinition($raw, $tenantId);
    $dataset = exportDatasetGet((string) $definition['dataset']);
    if (!$dataset) throw new ReportBuilderException('Report dataset not found');
    $fetcher = $dataset['fetcher'] ?? null;
    if (!is_string($fetcher) || !is_callable($fetcher)) {
        throw new ReportBuilderException('Report dataset does not support execution');
    }

    $fetchOptions = array_merge($options, ['limit' => $definition['limit']]);
    $rows = $fetcher($tenantId, $fetchOptions);
    return reportBuilderApplyDefinitionToRows($definition, is_iterable($rows) ? $rows : []);
}

function reportBuilderRenderCsv(array $result): string
{
    $columns = [];
    foreach (($result['columns'] ?? []) as $column) {
        $field = (string) ($column['field'] ?? '');
        if ($field === '') continue;
        $columns[$field] = (string) ($column['label'] ?? $field);
    }
    if (!$columns) throw new ReportBuilderException('Report result has no columns');
    return \Core\CsvExportService::toString($columns, $result['rows'] ?? []);
}

function reportBuilderCsvFilename(string $dataset): string
{
    $safe = preg_replace('/[^A-Za-z0-9_\-.]/', '_', $dataset) ?: 'custom_report';
    return $safe . '_custom_report_' . date('Ymd_His') . '.csv';
}

function reportBuilderExportAuditMeta(array $definition, array $runOptions = []): array
{
    return [
        'generated_at' => gmdate('c'),
        'filter_params' => [
            'filters' => $definition['filters'] ?? [],
            'sorts' => $definition['sorts'] ?? [],
            'limit' => $definition['limit'] ?? null,
            'options' => reportBuilderAuditOptionParams($runOptions),
        ],
    ];
}

function reportBuilderAuditOptionParams(array $options): array
{
    $out = [];
    foreach ($options as $key => $value) {
        $key = (string) $key;
        if ($key === '' || $value === null || $value === '' || $value === []) continue;
        $out[$key] = is_array($value) ? array_values($value) : $value;
    }
    return $out;
}

function reportBuilderApplyDefinitionToRows(array $definition, iterable $rows): array
{
    $selected = reportBuilderDefinitionOutputFields($definition);
    $filtered = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        if (!reportBuilderRowMatchesFilters($row, $definition['filters'] ?? [])) continue;
        $filtered[] = $row;
    }

    $aggregated = !empty($definition['measures']);
    $working = $aggregated
        ? reportBuilderAggregateRows($definition, $filtered)
        : $filtered;
    reportBuilderSortRows($working, $definition['sorts'] ?? []);

    $limit = min(10000, max(1, (int) ($definition['limit'] ?? 1000)));
    $out = [];
    foreach (array_slice($working, 0, $limit) as $row) {
        $projected = [];
        foreach ($selected as $field) {
            $projected[$field['field']] = $row[$field['field']] ?? '';
        }
        $out[] = $projected;
    }

    return [
        'dataset' => (string) ($definition['dataset'] ?? ''),
        'columns' => $selected,
        'rows' => $out,
        'row_count' => count($out),
        'source_row_count' => count($filtered),
        'aggregated' => $aggregated,
        'truncated' => count($working) > $limit,
    ];
}

function reportBuilderSortRows(array &$rows, array $sorts): void
{
    if (!$sorts) return;
    usort($rows, function (array $a, array $b) use ($sorts): int {
        foreach ($sorts as $sort) {
            $field = (string) ($sort['field'] ?? '');
            $cmp = reportBuilderCompareValues($a[$field] ?? null, $b[$field] ?? null);
            if ($cmp !== 0) return (($sort['direction'] ?? 'asc') === 'desc') ? -$cmp : $cmp;
        }
        return 0;
    });
}

function reportBuilderAggregateRows(array $definition, array $rows): array
{
    $dimensions = $definition['dimensions'] ?? [];
    $measures = $definition['measures'] ?? [];
    $groups = [];

    foreach ($rows as $row) {
        $dimensionValues = [];
        foreach ($dimensions as $dimension) {
            $field = (string) ($dimension['field'] ?? '');
            if ($field !== '') $dimensionValues[$field] = $row[$field] ?? null;
        }

        $groupKey = json_encode($dimensionValues, JSON_UNESCAPED_SLASHES);
        if (!isset($groups[$groupKey])) {
            $groups[$groupKey] = [
                'row' => $dimensionValues,
                'state' => [],
            ];
            foreach ($measures as $measure) {
                $field = (string) ($measure['field'] ?? '');
                if ($field === '') continue;
                $groups[$groupKey]['state'][$field] = reportBuilderInitialMeasureState(reportBuilderNormalizeAggregate($measure['aggregate'] ?? 'sum'));
            }
        }

        foreach ($measures as $measure) {
            $field = (string) ($measure['field'] ?? '');
            if ($field === '') continue;
            $aggregate = reportBuilderNormalizeAggregate($measure['aggregate'] ?? 'sum');
            reportBuilderApplyMeasureValue($groups[$groupKey]['state'][$field], $aggregate, $row[$field] ?? null);
        }
    }

    $out = [];
    foreach ($groups as $group) {
        $row = $group['row'];
        foreach (($group['state'] ?? []) as $field => $state) {
            $row[$field] = reportBuilderFinalizeMeasureValue($state);
        }
        $out[] = $row;
    }
    return $out;
}

function reportBuilderInitialMeasureState(string $aggregate): array
{
    return [
        'aggregate' => $aggregate,
        'sum' => 0.0,
        'count' => 0,
        'value' => null,
    ];
}

function reportBuilderApplyMeasureValue(array &$state, string $aggregate, $value): void
{
    if ($aggregate === 'count') {
        $state['count']++;
        return;
    }

    if ($value === null || $value === '') return;

    if ($aggregate === 'min') {
        if ($state['value'] === null || reportBuilderCompareValues($value, $state['value']) < 0) $state['value'] = $value;
        return;
    }
    if ($aggregate === 'max') {
        if ($state['value'] === null || reportBuilderCompareValues($value, $state['value']) > 0) $state['value'] = $value;
        return;
    }

    $numeric = is_numeric($value) ? (float) $value : 0.0;
    $state['sum'] += $numeric;
    $state['count']++;
}

function reportBuilderFinalizeMeasureValue(array $state)
{
    $aggregate = (string) ($state['aggregate'] ?? 'sum');
    if ($aggregate === 'count') return (int) ($state['count'] ?? 0);
    if ($aggregate === 'avg') {
        $count = (int) ($state['count'] ?? 0);
        return $count > 0 ? ((float) ($state['sum'] ?? 0) / $count) : 0;
    }
    if ($aggregate === 'min' || $aggregate === 'max') return $state['value'] ?? '';

    $sum = (float) ($state['sum'] ?? 0);
    return floor($sum) === $sum ? (int) $sum : $sum;
}

function reportBuilderDefinitionOutputFields(array $definition): array
{
    $out = [];
    foreach (['columns', 'dimensions', 'measures'] as $section) {
        foreach (($definition[$section] ?? []) as $entry) {
            $field = (string) ($entry['field'] ?? '');
            if ($field === '' || isset($out[$field])) continue;
            $out[$field] = [
                'field' => $field,
                'label' => (string) ($entry['label'] ?? $field),
                'type' => (string) ($entry['type'] ?? 'text'),
                'sensitive' => !empty($entry['sensitive']),
            ];
            if (isset($entry['aggregate'])) {
                $out[$field]['aggregate'] = reportBuilderNormalizeAggregate($entry['aggregate']);
            }
        }
    }
    return array_values($out);
}

function reportBuilderRowMatchesFilters(array $row, array $filters): bool
{
    foreach ($filters as $filter) {
        $field = (string) ($filter['field'] ?? '');
        $operator = (string) ($filter['operator'] ?? 'equals');
        $value = $row[$field] ?? null;
        $expected = $filter['value'] ?? null;
        $expectedTo = $filter['value_to'] ?? null;

        $blank = $value === null || $value === '';
        $ok = match ($operator) {
            'is_blank' => $blank,
            'is_not_blank' => !$blank,
            'not_equals' => !reportBuilderValuesEqual($value, $expected),
            'in' => reportBuilderValueIn($value, $expected),
            'contains' => str_contains(strtolower((string) $value), strtolower((string) $expected)),
            'starts_with' => str_starts_with(strtolower((string) $value), strtolower((string) $expected)),
            'greater_than' => reportBuilderCompareValues($value, $expected) > 0,
            'greater_than_or_equal' => reportBuilderCompareValues($value, $expected) >= 0,
            'less_than' => reportBuilderCompareValues($value, $expected) < 0,
            'less_than_or_equal' => reportBuilderCompareValues($value, $expected) <= 0,
            'between' => reportBuilderCompareValues($value, $expected) >= 0 && reportBuilderCompareValues($value, $expectedTo) <= 0,
            default => reportBuilderValuesEqual($value, $expected),
        };
        if (!$ok) return false;
    }
    return true;
}

function reportBuilderValueIn($value, $expected): bool
{
    $values = is_array($expected)
        ? $expected
        : preg_split('/\s*,\s*/', (string) $expected, -1, PREG_SPLIT_NO_EMPTY);
    foreach ($values ?: [] as $candidate) {
        if (reportBuilderValuesEqual($value, $candidate)) return true;
    }
    return false;
}

function reportBuilderValuesEqual($left, $right): bool
{
    if (is_numeric($left) && is_numeric($right)) return (float) $left === (float) $right;
    return strtolower((string) $left) === strtolower((string) $right);
}

function reportBuilderCompareValues($left, $right): int
{
    if (is_numeric($left) && is_numeric($right)) return (float) $left <=> (float) $right;
    return strcmp(strtolower((string) $left), strtolower((string) $right));
}

function reportBuilderAudit(int $tenantId, ?int $actorUserId, string $event, ?int $targetId, array $meta = []): void
{
    if (!function_exists('getDB')) return;
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
        error_log('[report_builder.audit] ' . $event . ' failed: ' . $e->getMessage());
    }
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
