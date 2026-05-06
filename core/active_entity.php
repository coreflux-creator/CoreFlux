<?php
/**
 * Active-entity session helpers (Sprint 4 / B1).
 *
 * Tenants with multiple `accounting_entities` need a global scope toggle
 * — pick "ACME-US" once and the rest of the SPA filters every list/report
 * to that entity. Persisted in the PHP session per user.
 *
 * Public surface:
 *   activeEntityGet(int $tenantId): ?int
 *   activeEntitySet(int $tenantId, int $entityId): array  // returns entity row
 *   activeEntityAvailable(int $tenantId): list<array>     // dropdown options
 *
 * VERTICAL-AGNOSTIC.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

function activeEntityGet(int $tenantId): ?int {
    initSession();
    $key = "active_entity_id__t{$tenantId}";
    return isset($_SESSION[$key]) ? (int) $_SESSION[$key] : null;
}

function activeEntitySet(int $tenantId, int $entityId): array {
    $pdo = getDB();
    if (!$pdo) throw new \RuntimeException('No DB');
    $stmt = $pdo->prepare(
        "SELECT id, code, legal_name, base_currency, country, active
           FROM accounting_entities
          WHERE tenant_id = :t AND id = :id LIMIT 1"
    );
    $stmt->execute(['t' => $tenantId, 'id' => $entityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !$row['active']) throw new \RuntimeException("Entity not found / inactive");

    initSession();
    $_SESSION["active_entity_id__t{$tenantId}"] = (int) $row['id'];
    return $row;
}

function activeEntityAvailable(int $tenantId): array {
    $pdo = getDB();
    if (!$pdo) return [];
    $stmt = $pdo->prepare(
        "SELECT id, code, legal_name, base_currency, country
           FROM accounting_entities
          WHERE tenant_id = :t AND active = 1
          ORDER BY code"
    );
    $stmt->execute(['t' => $tenantId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
