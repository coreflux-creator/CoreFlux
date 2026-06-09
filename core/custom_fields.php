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

function customFieldDefinitions(int $tenantId, string $entityType): array
{
    $entity = customFieldEntity($entityType);
    if (!$entity) throw new InvalidArgumentException("Unknown custom field entity: {$entityType}");

    if (($entity['definition_table'] ?? null) === 'people_custom_field_defs') {
        $stmt = getDB()->prepare(
            'SELECT id, field_key, field_label, field_type, options_json, required, pii, order_index
               FROM people_custom_field_defs
              WHERE tenant_id = :tenant_id AND deleted_at IS NULL
              ORDER BY order_index, field_label'
        );
        $stmt->execute(['tenant_id' => $tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $cols = customFieldLegacyColumns();
    $keyCol = in_array('field_name', $cols, true) ? 'field_name' : 'label';
    $labelCol = in_array('field_label', $cols, true) ? 'field_label' : 'label';
    $typeCol = in_array('field_type', $cols, true) ? 'field_type' : 'type';
    $requiredExpr = in_array('is_required', $cols, true) ? 'is_required' : '0';
    $stmt = getDB()->prepare(
        "SELECT id,
                {$keyCol} AS field_key,
                {$labelCol} AS field_label,
                {$typeCol} AS field_type,
                options,
                {$requiredExpr} AS required
           FROM custom_fields
          WHERE tenant_id = :tenant_id AND module = :module
          ORDER BY {$labelCol}"
    );
    $stmt->execute(['tenant_id' => $tenantId, 'module' => $entityType]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
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
