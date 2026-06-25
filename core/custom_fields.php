<?php
/**
 * CoreFlux Custom Fields Platform Service.
 *
 * This is the shared contract layer. Domain modules still own their storage
 * during migration, but platform services can discover supported entities and
 * write values without knowing each module's table details.
 */

declare(strict_types=1);

require_once __DIR__ . '/ModuleRegistry.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit.php';

function customFieldEntityRegistry(): array
{
    return ModuleRegistry::getInstance()->getCustomFieldEntities();
}

function customFieldEntity(string $entityType): ?array
{
    return ModuleRegistry::getInstance()->getCustomFieldEntity($entityType);
}

function customFieldLayouts(?string $entityType = null): array
{
    $registry = customFieldEntityRegistry();
    if ($entityType !== null) {
        return $registry[$entityType]['layouts'] ?? [];
    }
    $out = [];
    foreach ($registry as $key => $entity) {
        $out[$key] = $entity['layouts'] ?? [];
    }
    return $out;
}

function customFieldSurfaceLayout(string $entityType, string $surface, ?int $tenantId = null): array
{
    $entity = customFieldEntity($entityType);
    if (!$entity) throw new InvalidArgumentException("Unknown custom field entity: {$entityType}");
    $surface = strtolower(trim($surface));
    if ($surface === '') throw new InvalidArgumentException('surface is required');

    $declared = array_values(array_map('strval', $entity['surfaces'] ?? []));
    $enabled = in_array($surface, $declared, true);
    $raw = is_array($entity['layouts'] ?? null) ? $entity['layouts'] : [];
    $layout = customFieldNormalizeSurfaceLayout($raw, $surface);
    $override = customFieldTenantSurfaceLayout($tenantId, $entityType, $surface);
    $source = 'manifest';
    if ($override !== null) {
        $layout = customFieldMergeSurfaceLayout($layout, $surface, $override);
        $source = 'tenant_override';
    }

    return [
        'entity_type' => $entityType,
        'surface'     => $surface,
        'enabled'     => $enabled,
        'layout'      => $layout,
        'source'      => $source,
        'tenant_id'   => $tenantId,
    ];
}

function customFieldAllSurfaceLayouts(?string $entityType = null, ?int $tenantId = null): array
{
    $entities = $entityType !== null
        ? array_filter([customFieldEntity($entityType)])
        : customFieldEntityRegistry();
    $out = [];
    foreach ($entities as $entity) {
        $key = (string) ($entity['entity_type'] ?? '');
        if ($key === '') continue;
        $out[$key] = [];
        foreach (($entity['surfaces'] ?? ['forms', 'detail', 'lists', 'exports', 'reports']) as $surface) {
            $out[$key][(string) $surface] = customFieldSurfaceLayout($key, (string) $surface, $tenantId);
        }
    }
    return $out;
}

function customFieldNormalizeSurfaceLayout(array $raw, string $surface): array
{
    if (isset($raw[$surface]) && is_array($raw[$surface])) return $raw[$surface];

    return match ($surface) {
        'forms' => [
            'sections' => array_values(array_map('strval', $raw['form_sections'] ?? [])),
            'field_order' => array_values(array_map('strval', $raw['field_order'] ?? [])),
        ],
        'detail' => [
            'sections' => array_values(array_map('strval', $raw['detail_sections'] ?? ($raw['form_sections'] ?? []))),
            'field_order' => array_values(array_map('strval', $raw['field_order'] ?? [])),
        ],
        'lists' => [
            'columns' => array_values(array_map('strval', $raw['list_columns'] ?? [])),
        ],
        'exports' => [
            'columns' => array_values(array_map('strval', $raw['export_columns'] ?? ($raw['list_columns'] ?? []))),
        ],
        'reports' => [
            'dimensions' => array_values(array_map('strval', $raw['report_dimensions'] ?? [])),
            'filters' => array_values(array_map('strval', $raw['report_filters'] ?? [])),
        ],
        default => $raw,
    };
}

function customFieldTenantSurfaceLayout(?int $tenantId, string $entityType, string $surface): ?array
{
    if ($tenantId === null || $tenantId <= 0) return null;
    try {
        $stmt = getDB()->prepare(
            'SELECT layout_json
               FROM custom_field_layout_overrides
              WHERE tenant_id = :tenant_id
                AND entity_type = :entity_type
                AND surface = :surface
              LIMIT 1'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'entity_type' => $entityType,
            'surface' => $surface,
        ]);
        $json = $stmt->fetchColumn();
        if (!is_string($json) || trim($json) === '') return null;
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    } catch (Throwable $e) {
        error_log('[custom_fields.layouts] tenant override unavailable: ' . $e->getMessage());
        return null;
    }
}

function customFieldMergeSurfaceLayout(array $base, string $surface, array $override): array
{
    $normalized = customFieldNormalizeSurfaceLayout([$surface => $override], $surface);
    foreach ($normalized as $key => $value) {
        $base[$key] = is_array($value) ? array_values($value) : $value;
    }
    return $base;
}

function customFieldSurfaceLayoutFieldSlots(string $surface): array
{
    return match (strtolower(trim($surface))) {
        'forms', 'detail' => ['field_order'],
        'lists', 'exports' => ['columns'],
        'reports' => ['dimensions', 'filters'],
        default => [],
    };
}

function customFieldSurfaceLayoutMetadataKeys(): array
{
    return [
        'field_def_id' => true,
        'field_key' => true,
        'field_label' => true,
        'field_type' => true,
        'options_json' => true,
        'required' => true,
        'is_required' => true,
        'pii' => true,
        'order_index' => true,
        'visible_to' => true,
        'editable_by' => true,
        'archived' => true,
        'is_archived' => true,
        'deleted_at' => true,
        'value' => true,
        'updated_at' => true,
    ];
}

function customFieldSurfaceLayoutIncludesArchived(string $surface): bool
{
    return in_array(strtolower(trim($surface)), ['exports', 'reports'], true);
}

function customFieldSurfaceLayoutAllowedFieldKeys(
    int $tenantId,
    string $entityType,
    string $surface
): array {
    $allowed = [];
    foreach (customFieldDefinitions($tenantId, $entityType, customFieldSurfaceLayoutIncludesArchived($surface)) as $def) {
        $key = (string) ($def['field_key'] ?? '');
        if ($key === '') continue;
        $allowed[$key] = true;
        if (customFieldSurfaceLayoutIncludesArchived($surface)) {
            $allowed['custom_fields.' . $entityType . '.' . $key] = true;
        }
    }
    if (strtolower(trim($surface)) === 'lists') {
        $allowed += customFieldSurfaceLayoutMetadataKeys();
    }
    return $allowed;
}

function customFieldValidateSurfaceLayout(int $tenantId, string $entityType, string $surface, array $layout): array
{
    $surface = strtolower(trim($surface));
    $normalized = customFieldNormalizeSurfaceLayout([$surface => $layout], $surface);
    $slots = customFieldSurfaceLayoutFieldSlots($surface);
    if (!$slots) return $normalized;

    $allowed = customFieldSurfaceLayoutAllowedFieldKeys($tenantId, $entityType, $surface);
    foreach ($slots as $slot) {
        foreach (array_values((array) ($normalized[$slot] ?? [])) as $fieldKey) {
            $fieldKey = (string) $fieldKey;
            if ($fieldKey === '' || isset($allowed[$fieldKey])) continue;
            throw new InvalidArgumentException("Layout {$surface}.{$slot} references unknown custom field '{$fieldKey}'");
        }
    }
    return $normalized;
}

function customFieldSurfaceLayoutVisibleFieldKeys(
    int $tenantId,
    string $entityType,
    string $surface,
    array $user,
    bool $includeRestricted = false
): array {
    $visible = [];
    try {
        foreach (customFieldDefinitions($tenantId, $entityType, customFieldSurfaceLayoutIncludesArchived($surface)) as $def) {
            if (!$includeRestricted && !customFieldUserCanViewDefinition($user, $def)) continue;
            $key = (string) ($def['field_key'] ?? '');
            if ($key === '') continue;
            $visible[$key] = true;
            if (customFieldSurfaceLayoutIncludesArchived($surface)) {
                $visible['custom_fields.' . $entityType . '.' . $key] = true;
            }
        }
    } catch (Throwable $e) {
        error_log('[custom_fields.layouts] field visibility unavailable: ' . $e->getMessage());
    }
    if (strtolower(trim($surface)) === 'lists') {
        $visible += customFieldSurfaceLayoutMetadataKeys();
    }
    return $visible;
}

function customFieldFilterSurfaceLayoutForUser(
    int $tenantId,
    string $entityType,
    string $surface,
    array $layout,
    array $user,
    bool $includeRestricted = false
): array {
    $slots = customFieldSurfaceLayoutFieldSlots($surface);
    if (!$slots) return $layout;
    $visible = customFieldSurfaceLayoutVisibleFieldKeys($tenantId, $entityType, $surface, $user, $includeRestricted);
    foreach ($slots as $slot) {
        if (!isset($layout[$slot]) || !is_array($layout[$slot])) continue;
        $layout[$slot] = array_values(array_filter(
            $layout[$slot],
            static fn($fieldKey) => isset($visible[(string) $fieldKey])
        ));
    }
    return $layout;
}

function customFieldSurfaceLayoutForUser(
    string $entityType,
    string $surface,
    int $tenantId,
    array $user,
    bool $includeRestricted = false
): array {
    $resolved = customFieldSurfaceLayout($entityType, $surface, $tenantId);
    $resolved['layout'] = customFieldFilterSurfaceLayoutForUser(
        $tenantId,
        $entityType,
        (string) ($resolved['surface'] ?? $surface),
        (array) ($resolved['layout'] ?? []),
        $user,
        $includeRestricted
    );
    $resolved['field_access_enforced'] = true;
    return $resolved;
}

function customFieldAllSurfaceLayoutsForUser(
    string $entityType,
    int $tenantId,
    array $user,
    bool $includeRestricted = false
): array {
    $entity = customFieldEntity($entityType);
    if (!$entity) throw new InvalidArgumentException("Unknown custom field entity: {$entityType}");
    $out = [];
    foreach (($entity['surfaces'] ?? ['forms', 'detail', 'lists', 'exports', 'reports']) as $surface) {
        $out[(string) $surface] = customFieldSurfaceLayoutForUser(
            $entityType,
            (string) $surface,
            $tenantId,
            $user,
            $includeRestricted
        );
    }
    return $out;
}

function customFieldSurfaceLayoutSave(
    int $tenantId,
    string $entityType,
    string $surface,
    array $layout,
    ?int $actorUserId = null
): array {
    $resolved = customFieldSurfaceLayout($entityType, $surface, null);
    if (empty($resolved['enabled'])) throw new InvalidArgumentException("Surface '{$surface}' is not enabled for {$entityType}");
    $surface = (string) $resolved['surface'];
    $normalized = customFieldValidateSurfaceLayout($tenantId, $entityType, $surface, $layout);
    $json = json_encode($normalized, JSON_UNESCAPED_SLASHES);
    if ($json === false) throw new InvalidArgumentException('layout could not be encoded');

    getDB()->prepare(
        'INSERT INTO custom_field_layout_overrides
            (tenant_id, entity_type, surface, layout_json, created_by_user_id, updated_by_user_id, created_at, updated_at)
         VALUES
            (:tenant_id, :entity_type, :surface, :layout_json, :created_by, :updated_by, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            layout_json = VALUES(layout_json),
            updated_by_user_id = VALUES(updated_by_user_id),
            updated_at = NOW()'
    )->execute([
        'tenant_id' => $tenantId,
        'entity_type' => $entityType,
        'surface' => $surface,
        'layout_json' => $json,
        'created_by' => $actorUserId,
        'updated_by' => $actorUserId,
    ]);

    return customFieldSurfaceLayout($entityType, $surface, $tenantId);
}

function customFieldSurfaceLayoutReset(int $tenantId, string $entityType, string $surface): void
{
    $resolved = customFieldSurfaceLayout($entityType, $surface, null);
    if (empty($resolved['enabled'])) throw new InvalidArgumentException("Surface '{$surface}' is not enabled for {$entityType}");
    getDB()->prepare(
        'DELETE FROM custom_field_layout_overrides
          WHERE tenant_id = :tenant_id
            AND entity_type = :entity_type
            AND surface = :surface'
    )->execute([
        'tenant_id' => $tenantId,
        'entity_type' => $entityType,
        'surface' => (string) $resolved['surface'],
    ]);
}

function customFieldLegacyColumn(array $cols, string $column, string $fallback, string $alias = ''): string
{
    if (!in_array($column, $cols, true)) return $fallback;
    return ($alias !== '' ? $alias . '.' : '') . $column;
}

function customFieldLegacyActiveWhere(array $cols, string $alias = ''): string
{
    $prefix = $alias !== '' ? $alias . '.' : '';
    $clauses = [];
    if (in_array('deleted_at', $cols, true)) $clauses[] = "{$prefix}deleted_at IS NULL";
    if (in_array('is_active', $cols, true)) $clauses[] = "COALESCE({$prefix}is_active, 1) = 1";
    return $clauses ? ' AND ' . implode(' AND ', $clauses) : '';
}

function customFieldPeopleDefinitionColumns(): array
{
    static $cols = null;
    if ($cols !== null) return $cols;
    try {
        $rows = getDB()->query('SHOW COLUMNS FROM people_custom_field_defs')->fetchAll(PDO::FETCH_ASSOC);
        $cols = array_map(static fn($r) => (string) $r['Field'], $rows ?: []);
    } catch (Throwable $e) {
        $cols = [
            'id', 'tenant_id', 'field_key', 'field_label', 'field_type',
            'options_json', 'required', 'pii', 'order_index', 'deleted_at',
        ];
    }
    return $cols;
}

function customFieldDefinitions(int $tenantId, string $entityType, bool $includeArchived = false): array
{
    $entity = customFieldEntity($entityType);
    if (!$entity) throw new InvalidArgumentException("Unknown custom field entity: {$entityType}");

    if (($entity['definition_table'] ?? null) === 'people_custom_field_defs') {
        $cols = customFieldPeopleDefinitionColumns();
        $visibleExpr = customFieldLegacyColumn($cols, 'visible_to_roles_json', 'NULL');
        $editableExpr = customFieldLegacyColumn($cols, 'editable_by_roles_json', 'NULL');
        $where = 'tenant_id = :tenant_id';
        if (!$includeArchived) $where .= ' AND deleted_at IS NULL';
        $stmt = getDB()->prepare(
            "SELECT id, field_key, field_label, field_type, options_json, required, pii,
                    {$visibleExpr} AS visible_to_roles_json,
                    {$editableExpr} AS editable_by_roles_json,
                    order_index, deleted_at
               FROM people_custom_field_defs
              WHERE " . $where . "
              ORDER BY CASE WHEN deleted_at IS NULL THEN 0 ELSE 1 END, order_index, field_label"
        );
        $stmt->execute(['tenant_id' => $tenantId]);
        return array_map('customFieldNormalizeDefinitionRow', $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    $cols = customFieldLegacyColumns();
    $keyCol = in_array('field_name', $cols, true) ? 'field_name' : 'label';
    $labelCol = in_array('field_label', $cols, true) ? 'field_label' : 'label';
    $typeCol = in_array('field_type', $cols, true) ? 'field_type' : 'type';
    $requiredExpr = in_array('is_required', $cols, true) ? 'is_required' : '0';
    $optionsExpr = customFieldLegacyColumn($cols, 'options', 'NULL');
    $piiExpr = customFieldLegacyColumn($cols, 'pii', '0');
    $visibleExpr = customFieldLegacyColumn($cols, 'visible_to_roles_json', 'NULL');
    $editableExpr = customFieldLegacyColumn($cols, 'editable_by_roles_json', 'NULL');
    $orderExpr = customFieldLegacyColumn($cols, 'order_index', '0');
    $deletedAtExpr = customFieldLegacyColumn($cols, 'deleted_at', 'NULL');
    $activeExpr = customFieldLegacyColumn($cols, 'is_active', '1');
    $orderBy = in_array('order_index', $cols, true) ? 'order_index, ' : '';
    $stmt = getDB()->prepare(
        "SELECT id,
                {$keyCol} AS field_key,
                {$labelCol} AS field_label,
                {$typeCol} AS field_type,
                {$optionsExpr} AS options_json,
                {$optionsExpr} AS options,
                {$requiredExpr} AS required,
                {$piiExpr} AS pii,
                {$visibleExpr} AS visible_to_roles_json,
                {$editableExpr} AS editable_by_roles_json,
                {$orderExpr} AS order_index,
                {$deletedAtExpr} AS deleted_at,
                {$activeExpr} AS is_active
           FROM custom_fields
          WHERE tenant_id = :tenant_id AND module = :module
          " . ($includeArchived ? '' : customFieldLegacyActiveWhere($cols)) . "
          ORDER BY CASE WHEN {$deletedAtExpr} IS NULL AND COALESCE({$activeExpr}, 1) = 1 THEN 0 ELSE 1 END,
                   {$orderBy}{$labelCol}"
    );
    $stmt->execute(['tenant_id' => $tenantId, 'module' => $entityType]);
    return array_map('customFieldNormalizeDefinitionRow', $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

function customFieldNormalizeDefinitionRow(array $row): array
{
    $deletedAt = $row['deleted_at'] ?? null;
    $isActive = array_key_exists('is_active', $row) ? (int) $row['is_active'] : 1;
    $archived = ($deletedAt !== null && (string) $deletedAt !== '') || $isActive === 0;
    $row['deleted_at'] = $deletedAt;
    $row['is_active'] = $isActive;
    $row['archived'] = $archived;
    $row['is_archived'] = $archived ? 1 : 0;
    $row['visible_to'] = customFieldRoleListFromRaw($row['visible_to_roles_json'] ?? null);
    $row['editable_by'] = customFieldRoleListFromRaw($row['editable_by_roles_json'] ?? null);
    $row['visible_to_roles'] = $row['visible_to'];
    $row['editable_by_roles'] = $row['editable_by'];
    return $row;
}

function customFieldDefinitionMap(int $tenantId, string $entityType, bool $includeArchived = false): array
{
    $out = [];
    foreach (customFieldDefinitions($tenantId, $entityType, $includeArchived) as $def) {
        $key = (string) ($def['field_key'] ?? '');
        if ($key !== '') $out[$key] = $def;
    }
    return $out;
}

function customFieldDefinitionCreate(int $tenantId, string $entityType, array $args): int
{
    $entity = customFieldEntity($entityType);
    if (!$entity) throw new InvalidArgumentException("Unknown custom field entity: {$entityType}");
    $data = customFieldNormalizeDefinitionPayload($args, true);

    if (($entity['definition_table'] ?? null) === 'people_custom_field_defs') {
        $cols = customFieldPeopleDefinitionColumns();
        $insert = [
            'tenant_id' => $tenantId,
            'field_key' => $data['field_key'],
            'field_label' => $data['field_label'],
            'field_type' => $data['field_type'],
            'options_json' => $data['options_json'],
            'required' => $data['required'],
            'pii' => $data['pii'],
            'order_index' => $data['order_index'],
        ];
        if (in_array('visible_to_roles_json', $cols, true)) {
            $insert['visible_to_roles_json'] = $data['visible_to_roles_json'];
        }
        if (in_array('editable_by_roles_json', $cols, true)) {
            $insert['editable_by_roles_json'] = $data['editable_by_roles_json'];
        }

        $names = array_keys($insert);
        $placeholders = array_map(static fn($col) => ':' . $col, $names);
        $stmt = getDB()->prepare(
            'INSERT INTO people_custom_field_defs (' . implode(', ', $names) . ')
             VALUES (' . implode(', ', $placeholders) . ')'
        );
        $stmt->execute($insert);
        return (int) getDB()->lastInsertId();
    }

    $cols = customFieldLegacyColumns();
    $insert = ['tenant_id' => $tenantId, 'module' => $entityType];
    $insert[in_array('field_name', $cols, true) ? 'field_name' : 'label'] = $data['field_key'];
    $insert[in_array('field_label', $cols, true) ? 'field_label' : 'label'] = $data['field_label'];
    $insert[in_array('field_type', $cols, true) ? 'field_type' : 'type'] = $data['field_type'];
    if (in_array('is_required', $cols, true)) $insert['is_required'] = $data['required'];
    if (in_array('options', $cols, true)) $insert['options'] = $data['options_json'];
    if (in_array('pii', $cols, true)) $insert['pii'] = $data['pii'];
    if (in_array('visible_to_roles_json', $cols, true)) $insert['visible_to_roles_json'] = $data['visible_to_roles_json'];
    if (in_array('editable_by_roles_json', $cols, true)) $insert['editable_by_roles_json'] = $data['editable_by_roles_json'];
    if (in_array('order_index', $cols, true)) $insert['order_index'] = $data['order_index'];
    if (in_array('is_active', $cols, true)) $insert['is_active'] = 1;

    $names = array_keys($insert);
    $placeholders = array_map(static fn($col) => ':' . $col, $names);
    $stmt = getDB()->prepare(
        'INSERT INTO custom_fields (' . implode(', ', $names) . ')
         VALUES (' . implode(', ', $placeholders) . ')'
    );
    $stmt->execute($insert);
    return (int) getDB()->lastInsertId();
}

function customFieldDefinitionUpdate(int $tenantId, string $entityType, int $id, array $args): void
{
    if ($id <= 0) throw new InvalidArgumentException('id is required');
    $entity = customFieldEntity($entityType);
    if (!$entity) throw new InvalidArgumentException("Unknown custom field entity: {$entityType}");
    $data = customFieldNormalizeDefinitionPayload($args, false);
    unset($data['field_key']);
    if (!$data) throw new InvalidArgumentException('No fields to update');

    if (($entity['definition_table'] ?? null) === 'people_custom_field_defs') {
        $cols = customFieldPeopleDefinitionColumns();
        $allowed = ['field_label', 'field_type', 'options_json', 'required', 'pii', 'order_index'];
        if (in_array('visible_to_roles_json', $cols, true)) $allowed[] = 'visible_to_roles_json';
        if (in_array('editable_by_roles_json', $cols, true)) $allowed[] = 'editable_by_roles_json';
        $update = array_intersect_key($data, array_flip($allowed));
        if (!$update) throw new InvalidArgumentException('No supported fields to update');
        $sets = [];
        foreach (array_keys($update) as $col) $sets[] = "{$col} = :{$col}";
        $update['id'] = $id;
        $update['tenant_id'] = $tenantId;
        $stmt = getDB()->prepare(
            'UPDATE people_custom_field_defs SET ' . implode(', ', $sets) .
            ' WHERE id = :id AND tenant_id = :tenant_id AND deleted_at IS NULL'
        );
        $stmt->execute($update);
        return;
    }

    $cols = customFieldLegacyColumns();
    $update = [];
    if (isset($data['field_label'])) $update[in_array('field_label', $cols, true) ? 'field_label' : 'label'] = $data['field_label'];
    if (isset($data['field_type'])) $update[in_array('field_type', $cols, true) ? 'field_type' : 'type'] = $data['field_type'];
    if (isset($data['required']) && in_array('is_required', $cols, true)) $update['is_required'] = $data['required'];
    if (array_key_exists('options_json', $data) && in_array('options', $cols, true)) $update['options'] = $data['options_json'];
    if (isset($data['pii']) && in_array('pii', $cols, true)) $update['pii'] = $data['pii'];
    if (array_key_exists('visible_to_roles_json', $data) && in_array('visible_to_roles_json', $cols, true)) {
        $update['visible_to_roles_json'] = $data['visible_to_roles_json'];
    }
    if (array_key_exists('editable_by_roles_json', $data) && in_array('editable_by_roles_json', $cols, true)) {
        $update['editable_by_roles_json'] = $data['editable_by_roles_json'];
    }
    if (isset($data['order_index']) && in_array('order_index', $cols, true)) $update['order_index'] = $data['order_index'];
    if (!$update) throw new InvalidArgumentException('No supported fields to update');

    $sets = [];
    foreach (array_keys($update) as $col) $sets[] = "{$col} = :{$col}";
    $update['id'] = $id;
    $update['tenant_id'] = $tenantId;
    $update['module'] = $entityType;
    $stmt = getDB()->prepare(
        'UPDATE custom_fields SET ' . implode(', ', $sets) .
        ' WHERE id = :id AND tenant_id = :tenant_id AND module = :module' .
        customFieldLegacyActiveWhere($cols)
    );
    $stmt->execute($update);
}

function customFieldDefinitionDelete(int $tenantId, string $entityType, int $id): void
{
    if ($id <= 0) throw new InvalidArgumentException('id is required');
    $entity = customFieldEntity($entityType);
    if (!$entity) throw new InvalidArgumentException("Unknown custom field entity: {$entityType}");

    if (($entity['definition_table'] ?? null) === 'people_custom_field_defs') {
        getDB()->prepare(
            'UPDATE people_custom_field_defs
                SET deleted_at = NOW()
              WHERE id = :id AND tenant_id = :tenant_id'
        )->execute(['id' => $id, 'tenant_id' => $tenantId]);
        return;
    }

    $cols = customFieldLegacyColumns();
    if (in_array('deleted_at', $cols, true)) {
        getDB()->prepare(
            'UPDATE custom_fields SET deleted_at = NOW()
              WHERE id = :id AND tenant_id = :tenant_id AND module = :module'
        )->execute(['id' => $id, 'tenant_id' => $tenantId, 'module' => $entityType]);
        return;
    }
    if (in_array('is_active', $cols, true)) {
        getDB()->prepare(
            'UPDATE custom_fields SET is_active = 0
              WHERE id = :id AND tenant_id = :tenant_id AND module = :module'
        )->execute(['id' => $id, 'tenant_id' => $tenantId, 'module' => $entityType]);
        return;
    }
    getDB()->prepare('DELETE FROM custom_values WHERE tenant_id = :tenant_id AND field_id = :id')
        ->execute(['tenant_id' => $tenantId, 'id' => $id]);
    getDB()->prepare('DELETE FROM custom_fields WHERE id = :id AND tenant_id = :tenant_id AND module = :module')
        ->execute(['id' => $id, 'tenant_id' => $tenantId, 'module' => $entityType]);
}

function customFieldAudit(int $tenantId, ?int $actorUserId, string $event, ?int $targetId, array $meta = []): void
{
    platformAuditLogWrite($tenantId, $actorUserId, $event, $targetId, $meta, [
        'object_type' => customFieldAuditObjectType($event),
        'source' => 'custom_fields',
    ]);
}

function customFieldAuditObjectType(string $event): string
{
    if (str_contains($event, '.definition.')) return 'custom_field_definition';
    if (str_contains($event, '.layout.')) return 'custom_field_layout';
    if (str_contains($event, '.value.')) return 'custom_field_value';
    return 'custom_field';
}

function customFieldNormalizeDefinitionPayload(array $args, bool $creating): array
{
    $out = [];
    if ($creating || array_key_exists('field_key', $args)) {
        $key = trim((string) ($args['field_key'] ?? ''));
        if (!preg_match('/^[a-z][a-z0-9_]{0,79}$/', $key)) {
            throw new InvalidArgumentException('field_key must be snake_case ([a-z][a-z0-9_]*)');
        }
        $out['field_key'] = $key;
    }
    if ($creating || array_key_exists('field_label', $args)) {
        $label = trim((string) ($args['field_label'] ?? ''));
        if ($label === '') throw new InvalidArgumentException('field_label is required');
        $out['field_label'] = $label;
    }
    if ($creating || array_key_exists('field_type', $args)) {
        $type = strtolower(trim((string) ($args['field_type'] ?? 'text')));
        if ($type === 'dropdown') $type = 'select';
        $allowed = ['text', 'number', 'date', 'boolean', 'select', 'multiselect'];
        if (!in_array($type, $allowed, true)) throw new InvalidArgumentException('Invalid field_type');
        $out['field_type'] = $type;
    }
    if (array_key_exists('options', $args) || array_key_exists('options_json', $args) || $creating) {
        $options = $args['options'] ?? null;
        $out['options_json'] = is_array($options)
            ? json_encode($options, JSON_UNESCAPED_SLASHES)
            : ($args['options_json'] ?? $options);
    }
    if (array_key_exists('required', $args) || $creating) $out['required'] = !empty($args['required']) ? 1 : 0;
    if (array_key_exists('pii', $args) || $creating) $out['pii'] = !empty($args['pii']) ? 1 : 0;
    if ($creating || customFieldPayloadHasAnyKey($args, ['visible_to', 'visible_to_roles', 'visible_to_roles_json'])) {
        $out['visible_to_roles_json'] = customFieldNormalizeRoleSetPayload($args, [
            'visible_to',
            'visible_to_roles',
            'visible_to_roles_json',
        ]);
    }
    if ($creating || customFieldPayloadHasAnyKey($args, ['editable_by', 'editable_by_roles', 'editable_by_roles_json'])) {
        $out['editable_by_roles_json'] = customFieldNormalizeRoleSetPayload($args, [
            'editable_by',
            'editable_by_roles',
            'editable_by_roles_json',
        ]);
    }
    if (array_key_exists('order_index', $args) || $creating) $out['order_index'] = (int) ($args['order_index'] ?? 0);
    return $out;
}

function customFieldPayloadHasAnyKey(array $args, array $keys): bool
{
    foreach ($keys as $key) {
        if (array_key_exists((string) $key, $args)) return true;
    }
    return false;
}

function customFieldNormalizeRoleSetPayload(array $args, array $keys): ?string
{
    $raw = null;
    foreach ($keys as $key) {
        if (array_key_exists((string) $key, $args)) {
            $raw = $args[(string) $key];
            break;
        }
    }

    $roles = customFieldRoleListFromRaw($raw);
    if (!$roles) return null;
    $json = json_encode($roles, JSON_UNESCAPED_SLASHES);
    if ($json === false) throw new InvalidArgumentException('role set could not be encoded');
    return $json;
}

function customFieldRoleListFromRaw($raw): array
{
    if ($raw === null || $raw === false) return [];
    if (is_string($raw)) {
        $text = trim($raw);
        if ($text === '') return [];
        $decoded = json_decode($text, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $raw = $decoded;
        } else {
            $raw = preg_split('/\s*,\s*/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
    }

    $candidates = [];
    $collect = static function ($value, $key = null) use (&$candidates, &$collect): void {
        if (is_string($key) && !is_int($key)) {
            if ($value === true || $value === 1 || $value === '1') $candidates[] = $key;
            if (is_string($value) || is_int($value)) $candidates[] = (string) $value;
            return;
        }
        if (is_array($value)) {
            $matchedObject = false;
            foreach (['role', 'role_key', 'key', 'name', 'persona_type'] as $candidateKey) {
                if (!empty($value[$candidateKey])) {
                    $candidates[] = (string) $value[$candidateKey];
                    $matchedObject = true;
                }
            }
            if (!$matchedObject) {
                foreach ($value as $childKey => $childValue) {
                    $collect($childValue, $childKey);
                }
            }
            return;
        }
        if (is_string($value) || is_int($value)) $candidates[] = (string) $value;
    };
    if (is_array($raw)) {
        foreach ($raw as $key => $value) $collect($value, $key);
    } elseif (is_string($raw) || is_int($raw)) {
        $candidates[] = (string) $raw;
    }

    $out = [];
    foreach ($candidates as $candidate) {
        $role = strtolower(trim((string) $candidate));
        if ($role === '') continue;
        if (!preg_match('/^[a-z0-9_.:-]{1,80}$/', $role)) {
            throw new InvalidArgumentException("Invalid role key '{$candidate}'");
        }
        $out[$role] = $role;
    }
    return array_values($out);
}

function customFieldUserRoleKeys(array $user): array
{
    $roles = [
        $user['global_role'] ?? null,
        $user['tenant_role'] ?? null,
        $user['persona_type'] ?? null,
        $user['role'] ?? null,
    ];
    if (!empty($user['is_global_admin'])) $roles[] = 'master_admin';
    if (isset($user['roles'])) {
        $roles[] = $user['roles'];
    }
    return customFieldRoleListFromRaw($roles);
}

function customFieldDefinitionRoleList(array $definition, string $access): array
{
    $jsonKey = $access === 'editable' ? 'editable_by_roles_json' : 'visible_to_roles_json';
    $arrayKey = $access === 'editable' ? 'editable_by' : 'visible_to';
    $aliasKey = $access === 'editable' ? 'editable_by_roles' : 'visible_to_roles';
    return customFieldRoleListFromRaw(
        $definition[$arrayKey]
        ?? $definition[$aliasKey]
        ?? $definition[$jsonKey]
        ?? null
    );
}

function customFieldUserCanViewDefinition(array $user, array $definition): bool
{
    return customFieldUserMatchesRoleSet($user, customFieldDefinitionRoleList($definition, 'visible'));
}

function customFieldUserCanEditDefinition(array $user, array $definition): bool
{
    return customFieldUserMatchesRoleSet($user, customFieldDefinitionRoleList($definition, 'editable'));
}

function customFieldUserMatchesRoleSet(array $user, array $roleSet): bool
{
    if (!$roleSet) return true;
    $userRoles = customFieldUserRoleKeys($user);
    if (in_array('master_admin', $userRoles, true)) return true;
    foreach ($roleSet as $role) {
        if (in_array((string) $role, $userRoles, true)) return true;
    }
    return false;
}

function customFieldDefinitionAccess(array $user, array $definition): array
{
    return [
        'visible' => customFieldUserCanViewDefinition($user, $definition),
        'editable' => customFieldUserCanEditDefinition($user, $definition),
        'visible_to' => customFieldDefinitionRoleList($definition, 'visible'),
        'editable_by' => customFieldDefinitionRoleList($definition, 'editable'),
    ];
}

function customFieldAnnotateDefinitionAccess(array $definition, array $user): array
{
    $definition['field_access'] = customFieldDefinitionAccess($user, $definition);
    return $definition;
}

function customFieldFilterDefinitionsForUser(array $definitions, array $user, bool $includeRestricted = false): array
{
    $out = [];
    foreach ($definitions as $definition) {
        $definition = customFieldAnnotateDefinitionAccess($definition, $user);
        if ($includeRestricted || !empty($definition['field_access']['visible'])) {
            $out[] = $definition;
        }
    }
    return $out;
}

function customFieldFilterValuesForUser(array $values, array $user): array
{
    $out = [];
    foreach ($values as $value) {
        if (customFieldUserCanViewDefinition($user, $value)) {
            $out[] = customFieldAnnotateDefinitionAccess($value, $user);
        }
    }
    return $out;
}

function customFieldValues(
    int $tenantId,
    string $entityType,
    int $recordId,
    bool $includeSensitive = false,
    bool $includeArchived = false
): array
{
    $entity = customFieldEntity($entityType);
    if (!$entity) throw new InvalidArgumentException("Unknown custom field entity: {$entityType}");
    if ($recordId <= 0) throw new InvalidArgumentException('record_id is required');

    if (($entity['definition_table'] ?? null) === 'people_custom_field_defs') {
        return customFieldPeopleValues($tenantId, $recordId, $includeSensitive, $includeArchived);
    }

    return customFieldLegacyValues($tenantId, $entityType, $recordId, $includeSensitive, $includeArchived);
}

function customFieldPeopleValues(
    int $tenantId,
    int $personId,
    bool $includeSensitive = false,
    bool $includeArchived = false
): array
{
    $cols = customFieldPeopleDefinitionColumns();
    $visibleExpr = customFieldLegacyColumn($cols, 'visible_to_roles_json', 'NULL', 'd');
    $editableExpr = customFieldLegacyColumn($cols, 'editable_by_roles_json', 'NULL', 'd');
    $sql = "SELECT d.id AS field_def_id, d.field_key, d.field_label, d.field_type,
                   d.options_json, d.required, d.pii,
                   {$visibleExpr} AS visible_to_roles_json,
                   {$editableExpr} AS editable_by_roles_json,
                   d.deleted_at,
                   v.value_text, v.value_number, v.value_date, v.value_boolean, v.updated_at
              FROM people_custom_field_defs d
         LEFT JOIN people_custom_field_values v
                ON v.field_def_id = d.id
               AND v.tenant_id = d.tenant_id
               AND v.person_id = :person_id
             WHERE d.tenant_id = :tenant_id";
    if (!$includeArchived) $sql .= ' AND d.deleted_at IS NULL';
    if (!$includeSensitive) $sql .= ' AND COALESCE(d.pii, 0) = 0';
    $sql .= ' ORDER BY CASE WHEN d.deleted_at IS NULL THEN 0 ELSE 1 END, d.order_index, d.field_label';
    $stmt = getDB()->prepare($sql);
    $stmt->execute(['tenant_id' => $tenantId, 'person_id' => $personId]);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $rows[] = customFieldNormalizeValueRow($row);
    }
    return $rows;
}

function customFieldLegacyValues(
    int $tenantId,
    string $entityType,
    int $recordId,
    bool $includeSensitive = false,
    bool $includeArchived = false
): array
{
    $cols = customFieldLegacyColumns();
    $keyCol = in_array('field_name', $cols, true) ? 'field_name' : 'label';
    $labelCol = in_array('field_label', $cols, true) ? 'field_label' : 'label';
    $typeCol = in_array('field_type', $cols, true) ? 'field_type' : 'type';
    $requiredExpr = in_array('is_required', $cols, true) ? 'f.is_required' : '0';
    $piiExpr = in_array('pii', $cols, true) ? 'f.pii' : '0';
    $visibleExpr = customFieldLegacyColumn($cols, 'visible_to_roles_json', 'NULL', 'f');
    $editableExpr = customFieldLegacyColumn($cols, 'editable_by_roles_json', 'NULL', 'f');
    $optionsExpr = customFieldLegacyColumn($cols, 'options', 'NULL', 'f');
    $orderExpr = customFieldLegacyColumn($cols, 'order_index', '0', 'f');
    $deletedAtExpr = customFieldLegacyColumn($cols, 'deleted_at', 'NULL', 'f');
    $activeExpr = customFieldLegacyColumn($cols, 'is_active', '1', 'f');
    $orderBy = in_array('order_index', $cols, true) ? 'f.order_index, ' : '';
    $sql = "SELECT f.id AS field_def_id,
                   f.{$keyCol} AS field_key,
                   f.{$labelCol} AS field_label,
                   f.{$typeCol} AS field_type,
                   {$optionsExpr} AS options_json,
                   {$requiredExpr} AS required,
                   {$piiExpr} AS pii,
                   {$visibleExpr} AS visible_to_roles_json,
                   {$editableExpr} AS editable_by_roles_json,
                   {$deletedAtExpr} AS deleted_at,
                   {$activeExpr} AS is_active,
                   v.value AS value_text,
                   NULL AS value_number,
                   NULL AS value_date,
                   NULL AS value_boolean,
                   NULL AS updated_at
              FROM custom_fields f
         LEFT JOIN custom_values v
                ON v.field_id = f.id
               AND v.tenant_id = f.tenant_id
               AND v.record_id = :record_id
             WHERE f.tenant_id = :tenant_id
               AND f.module = :module";
    if (!$includeArchived) $sql .= customFieldLegacyActiveWhere($cols, 'f');
    if (!$includeSensitive && in_array('pii', $cols, true)) $sql .= ' AND COALESCE(f.pii, 0) = 0';
    $sql .= " ORDER BY CASE WHEN {$deletedAtExpr} IS NULL AND COALESCE({$activeExpr}, 1) = 1 THEN 0 ELSE 1 END,
              {$orderBy}f.{$labelCol}";
    $stmt = getDB()->prepare($sql);
    $stmt->execute(['tenant_id' => $tenantId, 'module' => $entityType, 'record_id' => $recordId]);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $rows[] = customFieldNormalizeValueRow($row);
    }
    return $rows;
}

function customFieldNormalizeValueRow(array $row): array
{
    $type = (string) ($row['field_type'] ?? 'text');
    $deletedAt = $row['deleted_at'] ?? null;
    $isActive = array_key_exists('is_active', $row) ? (int) $row['is_active'] : 1;
    $archived = ($deletedAt !== null && (string) $deletedAt !== '') || $isActive === 0;
    $value = match ($type) {
        'number'  => $row['value_number'] !== null ? (float) $row['value_number'] : null,
        'date'    => $row['value_date'],
        'boolean' => $row['value_boolean'] === null ? null : (bool) $row['value_boolean'],
        default   => $row['value_text'],
    };
    if ($type === 'multiselect' && is_string($value) && $value !== '') {
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) $value = $decoded;
    }
    return [
        'field_def_id' => (int) ($row['field_def_id'] ?? 0),
        'field_key'    => (string) ($row['field_key'] ?? ''),
        'field_label'  => (string) ($row['field_label'] ?? ''),
        'field_type'   => $type,
        'options_json' => $row['options_json'] ?? null,
        'required'     => (int) ($row['required'] ?? 0),
        'pii'          => (int) ($row['pii'] ?? 0),
        'visible_to'   => customFieldRoleListFromRaw($row['visible_to_roles_json'] ?? null),
        'editable_by'  => customFieldRoleListFromRaw($row['editable_by_roles_json'] ?? null),
        'visible_to_roles' => customFieldRoleListFromRaw($row['visible_to_roles_json'] ?? null),
        'editable_by_roles' => customFieldRoleListFromRaw($row['editable_by_roles_json'] ?? null),
        'visible_to_roles_json' => $row['visible_to_roles_json'] ?? null,
        'editable_by_roles_json' => $row['editable_by_roles_json'] ?? null,
        'archived'     => $archived,
        'is_archived'  => $archived ? 1 : 0,
        'deleted_at'   => $deletedAt,
        'value'        => $value,
        'updated_at'   => $row['updated_at'] ?? null,
    ];
}

function customFieldValueUpsert(int $tenantId, string $entityType, int $recordId, string $fieldKey, $value): void
{
    $entity = customFieldEntity($entityType);
    if (!$entity) throw new InvalidArgumentException("Unknown custom field entity: {$entityType}");

    if (($entity['definition_table'] ?? null) === 'people_custom_field_defs') {
        customFieldPeopleValueUpsert($tenantId, $recordId, $fieldKey, $value);
        return;
    }

    customFieldLegacyValueUpsert($tenantId, $entityType, $recordId, $fieldKey, $value);
}

function customFieldPeopleValueUpsert(int $tenantId, int $personId, string $fieldKey, $value): void
{
    $pdo = getDB();
    $defStmt = $pdo->prepare(
        'SELECT id, field_type
           FROM people_custom_field_defs
          WHERE tenant_id = :tenant_id AND field_key = :field_key AND deleted_at IS NULL
          LIMIT 1'
    );
    $defStmt->execute(['tenant_id' => $tenantId, 'field_key' => $fieldKey]);
    $def = $defStmt->fetch(PDO::FETCH_ASSOC);
    if (!$def) throw new InvalidArgumentException("Unknown people custom field: {$fieldKey}");

    $fieldType = (string) $def['field_type'];
    $column = match ($fieldType) {
        'number'  => 'value_number',
        'date'    => 'value_date',
        'boolean' => 'value_boolean',
        default   => 'value_text',
    };

    $coerced = $value;
    if ($fieldType === 'multiselect' && is_array($value)) $coerced = json_encode($value);
    if ($fieldType === 'boolean') $coerced = $value === null ? null : (int) (bool) $value;
    if ($fieldType === 'number') $coerced = $value === null ? null : (float) $value;

    $insert = $pdo->prepare(
        'INSERT INTO people_custom_field_values
            (tenant_id, person_id, field_def_id, value_text, value_number, value_date, value_boolean, updated_at)
         VALUES (:tenant_id, :person_id, :field_def_id, NULL, NULL, NULL, NULL, NOW())
         ON DUPLICATE KEY UPDATE
            value_text = NULL,
            value_number = NULL,
            value_date = NULL,
            value_boolean = NULL,
            updated_at = NOW()'
    );
    $insert->execute([
        'tenant_id'    => $tenantId,
        'person_id'    => $personId,
        'field_def_id' => (int) $def['id'],
    ]);

    $update = $pdo->prepare(
        "UPDATE people_custom_field_values
            SET {$column} = :value, updated_at = NOW()
          WHERE tenant_id = :tenant_id AND person_id = :person_id AND field_def_id = :field_def_id"
    );
    $update->execute([
        'value'        => $coerced,
        'tenant_id'    => $tenantId,
        'person_id'    => $personId,
        'field_def_id' => (int) $def['id'],
    ]);
}

function customFieldLegacyValueUpsert(int $tenantId, string $module, int $recordId, string $fieldKey, $value): void
{
    $pdo = getDB();
    $cols = customFieldLegacyColumns();
    $keyCol = in_array('field_name', $cols, true) ? 'field_name' : 'label';
    $defStmt = $pdo->prepare(
        "SELECT id
           FROM custom_fields
          WHERE tenant_id = :tenant_id
            AND module = :module
            AND {$keyCol} = :field_key
            " . customFieldLegacyActiveWhere($cols) . "
          LIMIT 1"
    );
    $defStmt->execute([
        'tenant_id' => $tenantId,
        'module'    => $module,
        'field_key' => $fieldKey,
    ]);
    $fieldId = (int) ($defStmt->fetchColumn() ?: 0);
    if ($fieldId <= 0) throw new InvalidArgumentException("Unknown {$module} custom field: {$fieldKey}");

    $existing = $pdo->prepare(
        'SELECT id FROM custom_values
          WHERE tenant_id = :tenant_id AND field_id = :field_id AND record_id = :record_id
          LIMIT 1'
    );
    $existing->execute([
        'tenant_id' => $tenantId,
        'field_id'  => $fieldId,
        'record_id' => $recordId,
    ]);
    $valueToStore = is_array($value) ? json_encode($value) : $value;
    $valueId = (int) ($existing->fetchColumn() ?: 0);
    if ($valueId > 0) {
        $stmt = $pdo->prepare(
            'UPDATE custom_values
                SET value = :value
              WHERE tenant_id = :tenant_id AND id = :id'
        );
        $stmt->execute(['value' => $valueToStore, 'tenant_id' => $tenantId, 'id' => $valueId]);
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO custom_values (tenant_id, field_id, record_id, value)
         VALUES (:tenant_id, :field_id, :record_id, :value)'
    );
    $stmt->execute([
        'tenant_id' => $tenantId,
        'field_id'  => $fieldId,
        'record_id' => $recordId,
        'value'     => $valueToStore,
    ]);
}

function customFieldLegacyColumns(): array
{
    static $cols = null;
    if ($cols !== null) return $cols;
    try {
        $rows = getDB()->query('SHOW COLUMNS FROM custom_fields')->fetchAll(PDO::FETCH_ASSOC);
        $cols = array_map(static fn($r) => (string) $r['Field'], $rows ?: []);
    } catch (Throwable $e) {
        $cols = ['field_name', 'field_label', 'field_type', 'is_required', 'options'];
    }
    return $cols;
}
