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
require_once __DIR__ . '/../core/memberships.php';

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
        "u.id IN (SELECT DISTINCT ut.user_id FROM " . membershipReadSourceSql() . " ut
                   WHERE ut.tenant_id = :scope_t)",
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

/**
 * Bootstrap a freshly-created user onto the new tenant_memberships +
 * membership_module_access grid so the dual-check bridge can satisfy
 * `api_can()` calls for them immediately.
 *
 * Without this, /api/users.php POST would leave the new user "orphaned"
 * on the new RBAC model — they'd be able to log in but the new resolver
 * would deny every gated action because there's no membership row, and
 * the dual-check bridge (legacy AND new) would silently lock them out.
 *
 * Best-effort: every step is wrapped in try/catch so creation succeeds
 * even if migration 055/058 hasn't been applied yet on this instance.
 *
 * @param \PDO     $pdo
 * @param int      $userId      newly-inserted user id
 * @param int      $tenantId    tenant the user was assigned to
 * @param string   $role        legacy role string (matches persona_type vocab)
 * @param int|null $actorUserId who created them — recorded on grants for audit
 */
function _usersBootstrapMembership(\PDO $pdo, int $userId, int $tenantId, string $role, ?int $actorUserId): void {
    // Step 1 — provision the canonical membership rows via the central helper
    // (dual-writes user_tenants + tenant_memberships so the legacy bridge keeps
    // working until user_tenants is fully retired). This is the row a future
    // refactor can use to drop user_tenants in one place.
    try {
        provisionMembership($userId, $tenantId, $role, [
            'is_primary'    => true,
            'persona_label' => 'Primary',
            'status'        => 'active',
        ]);
    } catch (\Throwable $e) {
        // Never block user creation on a membership provisioning hiccup —
        // the resolver will fall back to the legacy table.
        error_log('[users] provisionMembership failed: ' . $e->getMessage());
        return;
    }

    // Step 2 — backfill default module_access rows for this membership.
    // Mirrors the rules used by scripts/backfill_memberships.php so newly-
    // created users land in the exact same shape as backfilled ones.
    $level = match ($role) {
        'master_admin', 'tenant_admin', 'admin' => 'admin',
        'manager'                               => 'write',
        'employee', 'contractor'                => 'read',
        default                                 => 'read',
    };
    $synthRead = $level === 'admin' ? 'admin' : ($level === 'write' ? 'read' : 'none');

    // Resolve the membership id we just provisioned.
    try {
        $stmt = $pdo->prepare(
            'SELECT id FROM tenant_memberships
              WHERE user_id = :u AND tenant_id = :t AND persona_label = "Primary" LIMIT 1'
        );
        $stmt->execute(['u' => $userId, 't' => $tenantId]);
        $membershipId = (int) $stmt->fetchColumn();
        if (!$membershipId) return;
    } catch (\Throwable $_) { return; }

    // Operational modules — mirror canonical module list from /core/modules.php.
    $modules = ['people','placements','time','billing','ap','accounting','payroll','treasury','reports'];
    // Synthetic modules — match migration 058 levels.
    $synthetic = ['integrations' => $synthRead, 'ai' => ($level === 'admin' ? 'admin' : 'none'), 'staffing' => ($level === 'none' ? 'none' : 'read')];

    try {
        $ins = $pdo->prepare(
            'INSERT IGNORE INTO membership_module_access
                (membership_id, module_key, access_level, granted_by_user_id)
             VALUES (:m, :k, :l, :ab)'
        );
        foreach ($modules as $m) {
            $ins->execute(['m' => $membershipId, 'k' => $m, 'l' => $level, 'ab' => $actorUserId]);
        }
        foreach ($synthetic as $m => $l) {
            $ins->execute(['m' => $membershipId, 'k' => $m, 'l' => $l, 'ab' => $actorUserId]);
        }
    } catch (\Throwable $_) { /* skip — migration missing or DB hiccup */ }
}

$action = api_query('action', '');
$id     = (int) api_query('id', 0);

// ---------- GET (list / single) ----------
if ($method === 'GET' && !$id) {
    [$where, $params] = _usersScopeWhere($role, $activeTid);
    $whereSql = $where ? "WHERE $where" : '';

    $sql = "SELECT u.id, u.name, u.email, u.role, u.is_active, u.created_at,
                   (SELECT COUNT(DISTINCT ut2.tenant_id) FROM " . membershipReadSourceSql() . " ut2
                     WHERE ut2.user_id = u.id) AS tenant_count
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
            "SELECT 1 FROM " . membershipReadSourceSql() . " src
              WHERE src.user_id = :u AND src.tenant_id = :t LIMIT 1"
        );
        $stmt->execute(['u' => $id, 't' => $activeTid]);
        if (!$stmt->fetchColumn()) api_error('Forbidden', 403);
    }

    $stmt = $pdo->prepare(
        "SELECT ut.tenant_id, t.name AS tenant_name,
                MIN(ut.persona_type) AS role,
                MAX(ut.is_primary)   AS is_default,
                MIN(ut.status)       AS status,
                MAX(ut.last_active_at) AS last_active_at
           FROM " . membershipReadSourceSql() . " ut
           JOIN tenants t ON ut.tenant_id = t.id
          WHERE ut.user_id = :u
       GROUP BY ut.tenant_id, t.name
       ORDER BY is_default DESC, t.name ASC"
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
    // Detect whether the live `users` table has a tenant_id column (some
    // production envs have a legacy NOT-NULL tenant_id that's not captured
    // in /app/core/migrations/). Include it only when present, so this
    // INSERT works against both schemas without forcing a migration.
    $hasTenantCol = false;
    try {
        $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'tenant_id'")->fetchColumn();
        $hasTenantCol = (bool) $col;
    } catch (\Throwable $_) { $hasTenantCol = false; }

    $cols     = ['name', 'email', 'password', 'password_hash', 'role', 'is_active', 'created_at'];
    $vals     = [':n',   ':e',   ':pw1',     ':pw2',         ':r',   '1',         'NOW()'];
    $params   = ['n' => $name, 'e' => $email, 'pw1' => $hash, 'pw2' => $hash, 'r' => $newRole];
    if ($hasTenantCol) {
        $cols[]   = 'tenant_id';
        $vals[]   = ':tid';
        $params['tid'] = $tenantId;
    }
    $pdo->prepare(
        'INSERT INTO users (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ')'
    )->execute($params);
    $newId = (int) $pdo->lastInsertId();

    // Default tenant assignment — provisionMembership() dual-writes the
    // legacy user_tenants + new tenant_memberships rows.
    _usersBootstrapMembership($pdo, $newId, $tenantId, $tenantRole, $actorId);

    // Optional caller-provided global-admin flag. Only honored when the
    // caller is themselves a master_admin / is_global_admin — prevents
    // privilege escalation by lower-tier admins.
    if (!empty($body['is_global_admin']) && $role === 'master_admin') {
        try {
            $pdo->prepare('UPDATE users SET is_global_admin = 1 WHERE id = :id')
                ->execute(['id' => $newId]);
        } catch (\Throwable $_) { /* column may not exist yet; safe to skip */ }
    }

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
            "SELECT 1 FROM " . membershipReadSourceSql() . " src
              WHERE src.user_id = :u AND src.tenant_id = :t LIMIT 1"
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
            "UPDATE users SET password = :pw1, password_hash = :pw2, updated_at = NOW() WHERE id = :id"
        )->execute(['pw1' => $hash, 'pw2' => $hash, 'id' => $id]);
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
            // Remove assignment — dual-writes deactivation to both tables.
            deactivateMembership($id, $tenantId);
        } else {
            _usersValidateRole($tenantRole, $role);
            // Upsert via the central helper — dual-writes user_tenants +
            // tenant_memberships and, when is_primary is on, demotes siblings.
            provisionMembership($id, $tenantId, $tenantRole, [
                'is_primary'    => (bool) $isDefault,
                'persona_label' => 'Primary',
                'status'        => 'active',
            ]);
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
