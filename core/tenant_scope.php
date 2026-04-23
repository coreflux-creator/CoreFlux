<?php
/**
 * CoreFlux Tenant Scope Helpers
 *
 * Single source of truth for "which tenant is this request for?" and safe
 * SQL helpers that always bind tenant_id so modules cannot leak cross-tenant
 * data by accident.
 *
 * Usage:
 *     $tenantId = currentTenantId();                     // int|null
 *     $rows = scopedQuery('SELECT * FROM employees WHERE tenant_id = :tenant_id');
 *     $row  = scopedFind('SELECT * FROM employees WHERE tenant_id = :tenant_id AND id = :id',
 *                        ['id' => 42]);
 *     $id   = scopedInsert('employees', ['name' => 'Jane', 'email' => 'j@x.com']);
 *     scopedUpdate('employees', 42, ['name' => 'Janet']);
 *     scopedDelete('employees', 42);
 */

require_once __DIR__ . '/db.php';

/**
 * Resolve the active tenant id for this request.
 * Prefers explicit session state; falls back to the first membership on the user.
 */
function currentTenantId(): ?int {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!empty($_SESSION['tenant_id'])) {
        return (int) $_SESSION['tenant_id'];
    }
    $user = $_SESSION['user'] ?? null;
    if ($user && !empty($user['tenants'][0]['id'])) {
        return (int) $user['tenants'][0]['id'];
    }
    return null;
}

/**
 * Run a SELECT that MUST reference :tenant_id. Tenant binding is auto-injected.
 * Returns an array of rows (possibly empty).
 */
function scopedQuery(string $sql, array $params = []): array {
    $pdo = getDB();
    if (!$pdo) return [];
    $params['tenant_id'] = currentTenantId();
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Run a SELECT expected to return 0 or 1 row.
 */
function scopedFind(string $sql, array $params = []): ?array {
    $rows = scopedQuery($sql, $params);
    return $rows[0] ?? null;
}

/**
 * Insert a row into the given table, auto-setting tenant_id + timestamps when
 * the table has those columns (standard across CoreFlux modules).
 * Returns the newly created row id.
 */
function scopedInsert(string $table, array $data): int {
    $pdo = getDB();
    if (!$pdo) throw new RuntimeException('No database connection');

    $data['tenant_id']  = $data['tenant_id']  ?? currentTenantId();
    $data['created_at'] = $data['created_at'] ?? date('Y-m-d H:i:s');

    $table = _safeIdent($table);
    $cols  = array_keys($data);
    foreach ($cols as $c) _safeIdent($c);

    $placeholders = array_map(fn($c) => ":$c", $cols);
    $sql  = "INSERT INTO `$table` (`" . implode('`,`', $cols) . "`) "
          . "VALUES (" . implode(',', $placeholders) . ")";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($data);
    return (int) $pdo->lastInsertId();
}

/**
 * Update a row by id, scoped to the current tenant. Returns rows affected.
 */
function scopedUpdate(string $table, int $id, array $data): int {
    $pdo = getDB();
    if (!$pdo) throw new RuntimeException('No database connection');

    unset($data['id'], $data['tenant_id'], $data['created_at']);
    if (!$data) return 0;
    $data['updated_at'] = $data['updated_at'] ?? date('Y-m-d H:i:s');

    $table = _safeIdent($table);
    $sets  = [];
    foreach (array_keys($data) as $c) {
        _safeIdent($c);
        $sets[] = "`$c` = :$c";
    }

    $sql  = "UPDATE `$table` SET " . implode(',', $sets)
          . " WHERE id = :_id AND tenant_id = :tenant_id";
    $params = $data + ['_id' => $id, 'tenant_id' => currentTenantId()];

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

/**
 * Delete a row by id, scoped to the current tenant. Returns rows affected.
 */
function scopedDelete(string $table, int $id): int {
    $pdo = getDB();
    if (!$pdo) throw new RuntimeException('No database connection');
    $table = _safeIdent($table);

    $stmt = $pdo->prepare("DELETE FROM `$table` WHERE id = :id AND tenant_id = :tenant_id");
    $stmt->execute(['id' => $id, 'tenant_id' => currentTenantId()]);
    return $stmt->rowCount();
}

/**
 * Whitelist SQL identifiers so modules can't inject table/column names.
 */
function _safeIdent(string $name): string {
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
        throw new InvalidArgumentException("Unsafe SQL identifier: $name");
    }
    return $name;
}
