<?php
/**
 * /api/users.php — user lifecycle for the active tenant.
 *
 *   GET    /api/users.php                     list users in active tenant
 *   GET    /api/users.php?id=N                fetch one (with tenant memberships)
 *   POST   /api/users.php                     create user + assign to active tenant
 *   PATCH  /api/users.php?id=N                update name/email/role/active
 *   PATCH  /api/users.php?id=N&action=password  reset password
 *   DELETE /api/users.php?id=N                soft-deactivate (is_active=0 globally)
 *   PATCH  /api/users.php?id=N&action=tenant   { tenant_id, role, is_default }
 *                                              upsert/remove (role='') tenant assignment
 *
 * Permission gates:
 *   - master_admin: full access across all tenants.
 *   - tenant_admin: limited to users that are members of the active tenant
 *     (or its sub-tenants) and to roles excluding `master_admin`.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/sub_tenants.php';

$ctx       = api_require_auth();
$user      = $ctx['user'];
$actorId   = (int) ($user['id'] ?? 0);
$role      = $ctx['role'] ?? 'employee';
$activeTid = currentTenantId() ?: null;

if (!in_array($role, ['master_admin', 'tenant_admin', 'admin'], true)) {
    api_error('Forbidden — only master_admin or tenant_admin can manage users', 403);
}

$method = api_method();
$pdo    = getDB();
if (!$pdo) api_error('No database connection', 500);

/** Roles a non-master can assign. master_admin is platform-only. */
const _USERS_ASSIGNABLE_ROLES = ['tenant_admin','admin','manager','employee','approver','viewer','user'];
const _USERS_GLOBAL_ROLES     = ['tenant_admin','admin','manager','employee','user'];

function _usersScopeWhere(string $role, ?int $activeTid): array {
    // Returns [sql_fragment, params] limiting visible users by membership.
    if ($role === 'master_admin') return ['', []];
    if (!$activeTid) return ['1=0', []];
    return [
        "u.id IN (SELECT ut.user_id FROM user_tenants ut
                   WHERE ut.tenant_id = :scope_t AND ut.status = 'active')",
        ['scope_t' => $activeTid],
    ];
}

function _usersValidateRole(string $r, string $callerRole): void {
    if (!in_array($r, _USERS_ASSIGNABLE_ROLES, true)) {
        if ($r === 'master_admin' && $callerRole !== 'master_admin') {
            api_error('Only master_admin can assign master_admin role', 403);
        }
        if (!in_array($r, _USERS_GLOBAL_ROLES, true)) {
            api_error("Invalid role '{$r}'", 422);
        }
    }
}

$action = api_query('action', '');
$id     = (int) api_query('id', 0);

// ---------- GET (list / single) ----------
if ($method === 'GET' && !$id) {
    [$where, $params] = _usersScopeWhere($role, $activeTid);
    $whereSql = $where ? "WHERE $where" : '';

    $sql = "SELECT u.id, u.name, u.email, u.role, u.is_active, u.created_at,
                   (SELECT COUNT(*) FROM user_tenants ut2
                     WHERE ut2.user_id = u.id AND ut2.status = 'active') AS tenant_count
              FROM users u
              $whereSql
          ORDER BY u.is_active DESC, u.name ASC
             LIMIT 500";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    api_ok(['users' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

if ($method === 'GET' && $id) {
    $stmt = $pdo->prepare(
        "SELECT u.id, u.name, u.email, u.role, u.is_active, u.created_at, u.updated_at
           FROM users u WHERE u.id = :id LIMIT 1"
    );
    $stmt->execute(['id' => $id]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u) api_error('User not found', 404);

    // Scope check for tenant_admin: must share at least one tenant with target.
    if ($role !== 'master_admin') {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM user_tenants
              WHERE user_id = :u AND tenant_id = :t AND status = 'active' LIMIT 1"
        );
        $stmt->execute(['u' => $id, 't' => $activeTid]);
        if (!$stmt->fetchColumn()) api_error('Forbidden', 403);
    }

    $stmt = $pdo->prepare(
        "SELECT ut.tenant_id, t.name AS tenant_name, ut.role, ut.is_default,
                ut.status, ut.last_active_at
           FROM user_tenants ut
           JOIN tenants t ON ut.tenant_id = t.id
          WHERE ut.user_id = :u
       ORDER BY ut.is_default DESC, t.name ASC"
    );
    $stmt->execute(['u' => $id]);
    $u['tenants'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    api_ok(['user' => $u]);
}

// ---------- POST (create) ----------
if ($method === 'POST') {
    $body = api_json_body();
    api_require_fields($body, ['name', 'email', 'password']);

    $name  = trim((string) $body['name']);
    $email = strtolower(trim((string) $body['email']));
    $pwd   = (string) $body['password'];
    $newRole = (string) ($body['role'] ?? 'employee');
    $tenantRole = (string) ($body['tenant_role'] ?? $newRole);
    $tenantId = (int) ($body['tenant_id'] ?? $activeTid ?? 0);

    if ($name === '')                           api_error('name required', 422);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) api_error('Invalid email', 422);
    if (strlen($pwd) < 8)                       api_error('Password must be at least 8 characters', 422);
    _usersValidateRole($newRole, $role);
    _usersValidateRole($tenantRole, $role);
    if (!$tenantId) api_error('tenant_id required (no active tenant)', 422);

    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :e LIMIT 1");
    $stmt->execute(['e' => $email]);
    if ($stmt->fetchColumn()) api_error('Email already in use', 409);

    // Scope guard for tenant_admin — can only create users into tenants they
    // can manage (the active tenant or its sub-tenants).
    if ($role !== 'master_admin') {
        if ($tenantId !== $activeTid) {
            // Allow a tenant_admin of the master to seed sub-tenants.
            $t = subTenantLookup($tenantId);
            if (!$t || (int)($t['parent_id'] ?? 0) !== $activeTid) {
                api_error('Cannot create user in a tenant you do not manage', 403);
            }
        }
    }

    $hash = password_hash($pwd, PASSWORD_DEFAULT);
    $pdo->prepare(
        "INSERT INTO users (name, email, password, password_hash, role, is_active, created_at)
         VALUES (:n, :e, :p, :p, :r, 1, NOW())"
    )->execute(['n' => $name, 'e' => $email, 'p' => $hash, 'r' => $newRole]);
    $newId = (int) $pdo->lastInsertId();

    // Default tenant assignment.
    $pdo->prepare(
        "INSERT INTO user_tenants (user_id, tenant_id, role, is_default, status, created_at)
         VALUES (:u, :t, :r, 1, 'active', NOW())"
    )->execute(['u' => $newId, 't' => $tenantId, 'r' => $tenantRole]);

    subTenantAudit(0, $tenantId, $actorId, 'user.created', [
        'user_id' => $newId, 'email' => $email, 'role' => $newRole,
    ]);

    api_ok(['id' => $newId], 201);
}

// ---------- PATCH (update / password / tenant assignment) ----------
if ($method === 'PATCH' && $id) {
    $stmt = $pdo->prepare("SELECT id, role FROM users WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $id]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$existing) api_error('User not found', 404);

    if ($role !== 'master_admin') {
        // Same-tenant scope guard.
        $stmt = $pdo->prepare(
            "SELECT 1 FROM user_tenants
              WHERE user_id = :u AND tenant_id = :t AND status = 'active' LIMIT 1"
        );
        $stmt->execute(['u' => $id, 't' => $activeTid]);
        if (!$stmt->fetchColumn()) api_error('Forbidden', 403);
        if ($existing['role'] === 'master_admin') {
            api_error('Cannot edit a master_admin', 403);
        }
    }

    $body = api_json_body();

    if ($action === 'password') {
        $pwd = (string) ($body['password'] ?? '');
        if (strlen($pwd) < 8) api_error('Password must be at least 8 characters', 422);
        $hash = password_hash($pwd, PASSWORD_DEFAULT);
        $pdo->prepare(
            "UPDATE users SET password = :p, password_hash = :p, updated_at = NOW() WHERE id = :id"
        )->execute(['p' => $hash, 'id' => $id]);
        subTenantAudit(0, $activeTid ?? 0, $actorId, 'user.password_reset', ['user_id' => $id]);
        api_ok(['id' => $id]);
    }

    if ($action === 'tenant') {
        api_require_fields($body, ['tenant_id']);
        $tenantId   = (int) $body['tenant_id'];
        $tenantRole = (string) ($body['role'] ?? '');
        $isDefault  = (int) (bool) ($body['is_default'] ?? 0);

        if ($role !== 'master_admin') {
            if ($tenantId !== $activeTid) {
                $t = subTenantLookup($tenantId);
                if (!$t || (int)($t['parent_id'] ?? 0) !== $activeTid) {
                    api_error('Cannot manage assignment in a tenant you do not manage', 403);
                }
            }
        }

        if ($tenantRole === '') {
            // Remove assignment (soft).
            $pdo->prepare(
                "UPDATE user_tenants SET status = 'inactive', updated_at = NOW()
                  WHERE user_id = :u AND tenant_id = :t"
            )->execute(['u' => $id, 't' => $tenantId]);
        } else {
            _usersValidateRole($tenantRole, $role);
            $pdo->prepare(
                "INSERT INTO user_tenants (user_id, tenant_id, role, is_default, status, created_at)
                 VALUES (:u, :t, :r, :d, 'active', NOW())
                 ON DUPLICATE KEY UPDATE role = :r, is_default = :d, status = 'active', updated_at = NOW()"
            )->execute(['u' => $id, 't' => $tenantId, 'r' => $tenantRole, 'd' => $isDefault]);
            if ($isDefault) {
                $pdo->prepare(
                    "UPDATE user_tenants SET is_default = 0
                      WHERE user_id = :u AND tenant_id != :t"
                )->execute(['u' => $id, 't' => $tenantId]);
            }
        }
        subTenantAudit(0, $tenantId, $actorId, 'user.tenant_assignment', [
            'user_id' => $id, 'tenant_id' => $tenantId, 'role' => $tenantRole,
        ]);
        api_ok(['id' => $id]);
    }

    // Generic profile update.
    $sets = []; $params = ['id' => $id];
    if (array_key_exists('name', $body)) {
        $sets[] = "name = :name"; $params['name'] = trim((string) $body['name']);
    }
    if (array_key_exists('email', $body)) {
        $email = strtolower(trim((string) $body['email']));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) api_error('Invalid email', 422);
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :e AND id != :id LIMIT 1");
        $stmt->execute(['e' => $email, 'id' => $id]);
        if ($stmt->fetchColumn()) api_error('Email already in use', 409);
        $sets[] = "email = :email"; $params['email'] = $email;
    }
    if (array_key_exists('role', $body)) {
        _usersValidateRole((string) $body['role'], $role);
        $sets[] = "role = :role"; $params['role'] = (string) $body['role'];
    }
    if (array_key_exists('is_active', $body)) {
        $sets[] = "is_active = :ia"; $params['ia'] = (int) (bool) $body['is_active'];
    }
    if (!$sets) api_error('No updatable fields', 422);

    $pdo->prepare(
        "UPDATE users SET " . implode(', ', $sets) . ", updated_at = NOW() WHERE id = :id"
    )->execute($params);

    subTenantAudit(0, $activeTid ?? 0, $actorId, 'user.updated', array_merge(['user_id' => $id], $body));
    api_ok(['id' => $id]);
}

// ---------- DELETE (soft-deactivate) ----------
if ($method === 'DELETE' && $id) {
    if ($id === $actorId) api_error('Cannot deactivate yourself', 422);

    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $id]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r) api_error('User not found', 404);

    if ($role !== 'master_admin' && $r['role'] === 'master_admin') {
        api_error('Cannot deactivate a master_admin', 403);
    }

    $pdo->prepare("UPDATE users SET is_active = 0, updated_at = NOW() WHERE id = :id")
        ->execute(['id' => $id]);

    subTenantAudit(0, $activeTid ?? 0, $actorId, 'user.deactivated', ['user_id' => $id]);
    api_ok(['id' => $id, 'is_active' => 0]);
}

api_error('Method not allowed', 405);
