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
 * Detect the active module key from the current request URL.
 * Pattern: `/modules/<key>/api/...` or `/modules/<key>/...`
 * Returns null for core endpoints (`/api/...`) and CLI scripts.
 *
 * Used by `scopedQuery/Insert/Update/Delete` to route a sub-tenant's
 * request to its parent (shared) or itself (isolated) based on
 * `tenant_module_scope` policy.
 */
function currentModuleKey(): ?string {
    // Explicit override — non-module URL endpoints can declare which module's
    // scope rules they read against by calling setRequestModuleScope('foo')
    // before issuing any scopedQuery / scopedFind / etc. Crucial for the
    // core /api/reports_* endpoints which are URL-flat but logically belong
    // to a module (staffing, accounting, etc.).
    if (!empty($GLOBALS['__cf_request_module_scope'])) {
        return (string) $GLOBALS['__cf_request_module_scope'];
    }
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (!$uri) return null;
    if (preg_match('#/modules/([a-z][a-z0-9_-]*)/#', $uri, $m)) {
        return strtolower($m[1]);
    }
    return null;
}

/**
 * Pin this request's module scope so subsequent scopedQuery/Insert/Update/
 * Delete calls resolve tenant_id using `effectiveTenantIdForModule($key)`.
 * Must be called BEFORE any scoped* helper. Idempotent — calling twice is
 * fine; the second call wins. The override is process-local + request-scoped
 * (lives in $GLOBALS), so it can't bleed across requests in fpm/cli.
 */
function setRequestModuleScope(string $moduleKey): void {
    if (!preg_match('/^[a-z][a-z0-9_-]*$/', $moduleKey)) {
        throw new InvalidArgumentException("Invalid module key: {$moduleKey}");
    }
    $GLOBALS['__cf_request_module_scope'] = $moduleKey;
}

/**
 * Clear a request-scoped module override (test cleanup).
 */
function clearRequestModuleScope(): void {
    unset($GLOBALS['__cf_request_module_scope']);
}

/**
 * Return the tenant explicitly pinned by this browser tab, if present.
 * The header is never trusted on its own; api_require_auth() and session.php
 * validate access before installing it as the request-local tenant.
 */
function requestedTenantHeaderId(): ?int {
    $raw = $_SERVER['HTTP_X_COREFLUX_TENANT_ID'] ?? null;
    if ($raw === null || $raw === '') return null;
    if (!is_string($raw) || !preg_match('/^[1-9][0-9]*$/', $raw)) {
        throw new InvalidArgumentException('Invalid CoreFlux tenant header');
    }
    return (int) $raw;
}

/** Install a validated, request-local tenant without changing PHP session state. */
function setRequestTenantId(?int $tenantId): void {
    if ($tenantId === null) {
        unset($GLOBALS['__cf_request_tenant_id']);
        return;
    }
    if ($tenantId <= 0) throw new InvalidArgumentException('Invalid tenant id');
    $GLOBALS['__cf_request_tenant_id'] = $tenantId;
}

/**
 * Resolve the user's access to a tenant. Direct membership, inherited access
 * from an administered parent, and platform-admin access mirror the tenant
 * switcher rules. Returns the tenant row plus the effective role, or null.
 */
function tenantAccessContextForUser(array $user, int $tenantId): ?array {
    if ($tenantId <= 0 || empty($user['id'])) return null;
    $pdo = getDB();
    if (!$pdo) return null;

    $tenantStmt = $pdo->prepare(
        'SELECT id, parent_id, tenant_type, name, is_active
           FROM tenants WHERE id = :id AND is_active = 1 LIMIT 1'
    );
    $tenantStmt->execute(['id' => $tenantId]);
    $tenant = $tenantStmt->fetch(PDO::FETCH_ASSOC);
    if (!$tenant) return null;

    $globalRole = (string) ($user['global_role'] ?? $_SESSION['global_role'] ?? $user['role'] ?? '');
    $isPlatformAdmin = $globalRole === 'master_admin' || (int) ($user['is_global_admin'] ?? 0) === 1;
    if ($isPlatformAdmin) {
        $tenant['role'] = 'master_admin';
        $tenant['access'] = 'platform';
        return $tenant;
    }

    require_once __DIR__ . '/memberships.php';
    $membership = $pdo->prepare(
        'SELECT persona_type AS role
           FROM ' . membershipReadSourceSql() . ' src
          WHERE src.user_id = :u AND src.tenant_id = :t
          LIMIT 1'
    );
    $membership->execute(['u' => (int) $user['id'], 't' => $tenantId]);
    $role = $membership->fetchColumn();
    if ($role !== false && $role !== null && $role !== '') {
        $tenant['role'] = (string) $role;
        $tenant['access'] = 'direct';
        return $tenant;
    }

    $parentId = (int) ($tenant['parent_id'] ?? 0);
    if (($tenant['tenant_type'] ?? '') === 'sub' && $parentId > 0) {
        $membership->execute(['u' => (int) $user['id'], 't' => $parentId]);
        $parentRole = (string) ($membership->fetchColumn() ?: '');
        if (in_array($parentRole, ['tenant_admin', 'admin', 'master_admin'], true)) {
            $tenant['role'] = $parentRole;
            $tenant['access'] = 'via_parent';
            return $tenant;
        }
    }
    return null;
}

/**
 * Resolve the tenant_id to bind on the current request, honouring
 * `tenant_module_scope` when a module context is detected. Falls back to
 * `currentTenantId()` when there's no module context (core endpoints,
 * CLI, master tenants).
 */
function effectiveTenantIdForRequest(): ?int {
    $tid = currentTenantId();
    if (!$tid) return null;
    $module = currentModuleKey();
    if (!$module) return $tid;
    // sub_tenants.php lives in core but provides effectiveTenantIdForModule.
    // Lazy-require so this file stays usable even if sub_tenants.php is
    // missing on a tenant that hasn't deployed the migration yet.
    $fn = __DIR__ . '/sub_tenants.php';
    if (is_file($fn)) {
        require_once $fn;
        if (function_exists('effectiveTenantIdForModule')) {
            // Wrap in try/catch so a tenant DB that hasn't run migration 007
            // (no `parent_id`/`tenant_type` columns on `tenants`) doesn't 500
            // the entire request — fall back to plain `currentTenantId()`.
            try {
                $resolved = effectiveTenantIdForModule($module, $tid);
                if ($resolved) return $resolved;
            } catch (\Throwable $_) { /* legacy schema; fall through */ }
        }
    }
    return $tid;
}

/**
 * Resolve the active tenant id for this request.
 * Prefers explicit session state; falls back to the first membership on the user.
 */
function currentTenantId(): ?int {
    if (!empty($GLOBALS['__cf_request_tenant_id'])) {
        return (int) $GLOBALS['__cf_request_tenant_id'];
    }
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
    $params['tenant_id'] = effectiveTenantIdForRequest();
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

    $data['tenant_id']  = $data['tenant_id']  ?? effectiveTenantIdForRequest();
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
    $params = $data + ['_id' => $id, 'tenant_id' => effectiveTenantIdForRequest()];

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
    $stmt->execute(['id' => $id, 'tenant_id' => effectiveTenantIdForRequest()]);
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
