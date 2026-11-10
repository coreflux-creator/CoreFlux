<?php
/** Resolve an optional tenant-local legal-entity filter for Billing lists and exports. */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/db.php';

function billingEntityFilterId(int $tenantId, ?string $requested): ?int
{
    $value = trim((string) $requested);
    if ($value === '' || $value === 'all') return null;
    if (!preg_match('/^[1-9][0-9]*$/', $value)) {
        throw new InvalidArgumentException('Select a valid legal entity or All entities.');
    }
    $entityId = (int) $value;
    $pdo = getDB();
    if (!$pdo) throw new RuntimeException('Database unavailable.');
    $stmt = $pdo->prepare('SELECT id FROM accounting_entities
        WHERE tenant_id = :tenant_id AND id = :entity_id LIMIT 1');
    $stmt->execute(['tenant_id' => $tenantId, 'entity_id' => $entityId]);
    if (!$stmt->fetchColumn()) throw new OutOfBoundsException('Legal entity not found in this workspace.');
    return $entityId;
}
