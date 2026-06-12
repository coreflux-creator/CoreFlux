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
    $normalized = customFieldNormalizeSurfaceLayout([$surface => $layout], $surface);
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

function customFieldDefinitions(int $tenantId, string $entityType, bool $includeArchived = false): array
{
    $entity = customFieldEntity($entityType);
    if (!$entity) throw new InvalidArgumentException("Unknown custom field entity: {$entityType}");

    if (($entity['definition_table'] ?? null) === 'people_custom_field_defs') {
        $where = 'tenant_id = :tenant_id';
        if (!$includeArchived) $where .= ' AND deleted_at IS NULL';
        $stmt = getDB()->prepare(
            'SELECT id, field_key, field_label, field_type, options_json, required, pii, order_index, deleted_at
               FROM people_custom_field_defs
              WHERE ' . $where . '
              ORDER BY CASE WHEN deleted_at IS NULL THEN 0 ELSE 1 END, order_index, field_label'
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
        $stmt = getDB()->prepare(
            'INSERT INTO people_custom_field_defs
                (tenant_id, field_key, field_label, field_type, options_json, required, pii, order_index)
             VALUES
                (:tenant_id, :field_key, :field_label, :field_type, :options_json, :required, :pii, :order_index)'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'field_key' => $data['field_key'],
            'field_label' => $data['field_label'],
            'field_type' => $data['field_type'],
            'options_json' => $data['options_json'],
            'required' => $data['required'],
            'pii' => $data['pii'],
            'order_index' => $data['order_index'],
        ]);
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
        $allowed = ['field_label', 'field_type', 'options_json', 'required', 'pii', 'order_index'];
        $update = array_intersect_key($data, array_flip($allowed));
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
    try {
        $pdo = getDB();
        if (!$pdo) return;
        $pdo->prepare(
            'INSERT INTO audit_log
                (tenant_id, actor_user_id, event, target_id, meta_json, ip_address, created_at)
             VALUES
                (:tenant_id, :actor_user_id, :event, :target_id, :meta_json, :ip_address, NOW())'
        )->execute([
            'tenant_id' => $tenantId,
            'actor_user_id' => $actorUserId,
            'event' => $event,
            'target_id' => $targetId,
            'meta_json' => $meta ? json_encode($meta, JSON_UNESCAPED_SLASHES) : null,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
        error_log('[custom_fields.audit] ' . $event . ' failed: ' . $e->getMessage());
    }
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
    if (array_key_exists('order_index', $args) || $creating) $out['order_index'] = (int) ($args['order_index'] ?? 0);
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
    $sql = 'SELECT d.id AS field_def_id, d.field_key, d.field_label, d.field_type,
                   d.options_json, d.required, d.pii, d.deleted_at,
                   v.value_text, v.value_number, v.value_date, v.value_boolean, v.updated_at
              FROM people_custom_field_defs d
         LEFT JOIN people_custom_field_values v
                ON v.field_def_id = d.id
               AND v.tenant_id = d.tenant_id
               AND v.person_id = :person_id
             WHERE d.tenant_id = :tenant_id';
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
