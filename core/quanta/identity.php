<?php
/** Reviewed Quanta worker -> canonical CoreFlux person identity. */
declare(strict_types=1);

require_once __DIR__ . '/../sub_tenants.php';

function quantaWorkerLinks(int $tenantId, bool $lock = false): array
{
    $peopleTenant = effectiveTenantIdForModule('people', $tenantId) ?? $tenantId;
    $stmt = getDB()->prepare(
        'SELECT l.id, l.worker_id, l.person_id, l.created_at,
                CONCAT_WS(" ", p.first_name, p.last_name) AS person_name,
                p.email_primary AS person_email
           FROM quanta_worker_links l
      LEFT JOIN people p ON p.id = l.person_id AND p.tenant_id = :pt AND p.deleted_at IS NULL
          WHERE l.tenant_id = :t ORDER BY l.worker_id' . ($lock ? ' FOR UPDATE' : '')
    );
    $stmt->execute(['pt' => $peopleTenant, 't' => $tenantId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function quantaWorkerPersonMap(array $links): array
{
    $map = [];
    foreach ($links as $link) {
        $map[(string) $link['worker_id']] = (int) $link['person_id'];
    }
    return $map;
}

function quantaAssertWorkerPerson(string $workerId, int $personId, array $workerPeople): void
{
    if (!isset($workerPeople[$workerId])) {
        throw new RuntimeException('Link this Quanta worker to a CoreFlux person before routing time');
    }
    if ($workerPeople[$workerId] !== $personId) {
        throw new RuntimeException('Quanta worker identity does not match the placement person');
    }
}
