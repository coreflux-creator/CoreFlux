<?php
/** Stable business references. Authorization stays with the owning module. */
declare(strict_types=1);

function coreRecordNumberDefinitions(): array
{
    return [
        'person' => ['table' => 'people', 'field' => 'person_number', 'prefix' => 'P-', 'module' => 'people'],
        'placement' => ['table' => 'placements', 'field' => 'placement_number', 'prefix' => 'PL-', 'module' => 'placements'],
        'company' => ['table' => 'companies', 'field' => 'company_number', 'prefix' => 'CO-', 'module' => 'companies'],
        'client' => ['table' => 'staffing_clients', 'field' => 'client_number', 'prefix' => 'CL-', 'module' => 'placements'],
        'vendor' => ['table' => 'ap_vendors_index', 'field' => 'vendor_number', 'prefix' => 'V-', 'module' => 'ap'],
    ];
}

function coreRecordNumberDefinition(string $type): array
{
    return coreRecordNumberDefinitions()[$type] ?? throw new InvalidArgumentException('Unknown record number type');
}

function coreRecordNumberFields(): array
{
    return array_column(coreRecordNumberDefinitions(), 'field');
}

/** Numbers are system-owned; normal imports/mappings must not assign them. */
function coreRecordNumbersRejectWrite(array $data): void
{
    foreach (coreRecordNumberFields() as $field) {
        if (array_key_exists($field, $data)) {
            throw new InvalidArgumentException($field . ' is assigned by Core and cannot be changed');
        }
    }
}

/**
 * Enrich already-authorized rows in bounded batches. Never creates numbers on
 * GET, derives a substitute, follows a remote ID, or changes caller tenant scope.
 * The migration backfills existing records and INSERT triggers cover all writers.
 */
function coreRecordNumbersAttach(PDO $pdo, int $tenantId, string $type, array $rows, string $idField = 'id', bool $requireAll = true): array
{
    $definition = coreRecordNumberDefinition($type);
    if ($tenantId <= 0) throw new InvalidArgumentException('Record number owner required');
    $ids = [];
    foreach ($rows as $row) {
        if (isset($row['tenant_id']) && (int) $row['tenant_id'] !== $tenantId) {
            throw new InvalidArgumentException('Record number owner does not match row');
        }
        $id = (string) ($row[$idField] ?? '');
        if (!preg_match('/^[1-9][0-9]*$/D', $id)) throw new InvalidArgumentException('Valid record ID required');
        $ids[$id] = $id;
    }
    $numbers = [];
    foreach (array_chunk(array_values($ids), 500) as $batch) {
        $stmt = $pdo->prepare(
            'SELECT record_id, record_number FROM core_record_numbers
              WHERE tenant_id = ? AND entity_type = ? AND record_id IN (' . implode(',', array_fill(0, count($batch), '?')) . ')'
        );
        $stmt->execute(array_merge([$tenantId, $type], $batch));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $numbers[(string) $item['record_id']] = (string) $item['record_number'];
        }
    }
    foreach ($rows as &$row) {
        $id = (string) $row[$idField];
        if ($requireAll && !isset($numbers[$id])) {
            throw new RuntimeException('Core ' . $type . ' number is missing. Apply the record-number migrations before using this record.');
        }
        $row[$definition['field']] = $numbers[$id] ?? null;
    }
    unset($row);
    return $rows;
}

/** SQL fragment only accepts code-owned aliases/parameter names. Binds user input separately. */
function coreRecordNumberSearchSql(string $type, string $alias, string $parameter): string
{
    coreRecordNumberDefinition($type);
    foreach ([$alias, $parameter] as $identifier) {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $identifier)) throw new InvalidArgumentException('Invalid SQL identifier');
    }
    return "EXISTS (SELECT 1 FROM core_record_numbers nr WHERE nr.tenant_id = {$alias}.tenant_id"
        . " AND nr.entity_type = '{$type}' AND nr.canonical_record_id = {$alias}.id AND nr.record_number = :{$parameter})";
}

/** Attach optional references from a separately authorized directory (e.g. shared People). */
function coreRecordNumbersAttachRelated(PDO $pdo, int $ownerId, string $type, array $rows, string $idField, ?string $outputField = null): array
{
    $field = coreRecordNumberDefinition($type)['field'];
    $related = [];
    foreach ($rows as $row) {
        if (!empty($row[$idField])) $related[(string) $row[$idField]] = ['id' => $row[$idField]];
    }
    // Historical/dangling relationships remain visible as unresolved; never invent a number.
    $numbered = coreRecordNumbersAttach($pdo, $ownerId, $type, array_values($related), 'id', false);
    $numbers = array_column($numbered, $field, 'id');
    foreach ($rows as &$row) $row[$outputField ?? $field] = $numbers[$row[$idField] ?? ''] ?? null;
    unset($row);
    return $rows;
}

/** Called inside an authorized merge transaction, after both source records are validated. */
function coreRecordNumbersMerge(PDO $pdo, int $tenantId, string $type, int $survivorId, int $retiredId, ?int $actorId): void
{
    coreRecordNumberDefinition($type);
    if ($tenantId <= 0 || $survivorId <= 0 || $retiredId <= 0 || $survivorId === $retiredId) {
        throw new InvalidArgumentException('Distinct scoped records required');
    }
    if (!$pdo->inTransaction()) throw new LogicException('Record number merge requires a transaction');
    $stmt = $pdo->prepare(
        'SELECT record_id, canonical_record_id FROM core_record_numbers
          WHERE tenant_id = ? AND entity_type = ? AND record_id IN (?, ?) ORDER BY record_id FOR UPDATE'
    );
    $stmt->execute([$tenantId, $type, $survivorId, $retiredId]);
    $found = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $found[(int) $row['record_id']] = (int) $row['canonical_record_id'];
    if (($found[$survivorId] ?? null) !== $survivorId || ($found[$retiredId] ?? null) !== $retiredId) {
        throw new RuntimeException('Merge numbers are missing or already retired; refresh the records');
    }
    $pdo->prepare(
        'UPDATE core_record_numbers SET canonical_record_id = :survivor, aliased_at = CURRENT_TIMESTAMP, alias_actor_id = :actor
          WHERE tenant_id = :tenant AND entity_type = :type AND canonical_record_id = :retired'
    )->execute(['survivor' => $survivorId, 'actor' => $actorId, 'tenant' => $tenantId, 'type' => $type, 'retired' => $retiredId]);
}


/** Typed CSV lookup: a business reference identifies an existing row, never assigns its number. */
function coreRecordNumberInputId(string $type, string $input): int
{
    $prefix = coreRecordNumberDefinition($type)['prefix'];
    $value = strtoupper(trim($input, " \t\r\n"));
    // Accept the former company display prefix for existing operator files.
    if ($type === 'company' && str_starts_with($value, 'C-')) $value = 'CO-' . substr($value, 2);
    if (str_starts_with($value, $prefix)) $value = substr($value, strlen($prefix));
    if (!preg_match('/^[0-9]+$/D', $value)) {
        throw new InvalidArgumentException('Expected a positive ID or ' . $prefix . ' number');
    }
    $value = ltrim($value, '0');
    $max = (string) PHP_INT_MAX;
    if ($value === '' || strlen($value) > strlen($max) || (strlen($value) === strlen($max) && strcmp($value, $max) > 0)) {
        throw new InvalidArgumentException('Record ID is outside the supported range');
    }
    return (int) $value;
}
