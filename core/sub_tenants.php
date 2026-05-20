<?php
/**
 * CoreFlux Sub-Tenant Provisioning Core
 *
 * Foundation for True Sub-Tenant architecture (migration 007). Modules call
 * `effectiveTenantIdForModule($moduleKey)` instead of `currentTenantId()` when
 * they want soft isolation: financial modules stay scoped to the active
 * sub-tenant, while shared catalogs (people/placements/companies) fall back
 * to the parent tenant_id.
 *
 *   $tid = effectiveTenantIdForModule('people');     // → parent_id (shared)
 *   $tid = effectiveTenantIdForModule('billing');    // → sub-tenant id
 *
 * Modules MAY override per-tenant via `tenant_module_scope` rows; otherwise
 * the global default below applies.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/tenant_scope.php';

/**
 * Default scope per module key. Edit this map (rather than every module's
 * SQL) when policy changes for the whole platform.
 *
 *   shared   = sub-tenant transparently reads/writes parent rows
 *   isolated = sub-tenant has its own data partition (default for $)
 */
const SUBTENANT_MODULE_SCOPE_DEFAULTS = [
    // Shared catalogs (the master controls; sub-tenants reuse)
    'people'     => 'shared',
    'placements' => 'shared',
    'companies'  => 'shared',
    'crm'        => 'shared',
    // Financial / per-entity modules (default isolated)
    'billing'    => 'isolated',
    'ap'         => 'isolated',
    'accounting' => 'isolated',
    'payroll'    => 'isolated',
    'treasury'   => 'isolated',
    'time'       => 'isolated',
    'tax'        => 'isolated',
];

/**
 * Resolve which tenant_id a module should use for SELECT/INSERT/UPDATE.
 * - If the active tenant is `master`, returns its own id.
 * - If the active tenant is `sub` and the module is configured `shared`,
 *   returns the parent id.
 * - Otherwise returns the active sub-tenant id (isolated).
 */
function effectiveTenantIdForModule(string $moduleKey, ?int $tenantId = null): ?int {
    $tenantId = $tenantId ?? currentTenantId();
    if (!$tenantId) return null;

    $tenant = subTenantLookup($tenantId);
    if (!$tenant)                              return $tenantId;
    if ($tenant['tenant_type'] !== 'sub')      return (int) $tenant['id'];
    if (empty($tenant['parent_id']))           return $tenantId;

    $mode = subTenantScopeMode((int) $tenant['id'], $moduleKey);
    return $mode === 'shared' ? (int) $tenant['parent_id'] : (int) $tenant['id'];
}

/**
 * Returns 'shared' or 'isolated' for (tenant_id, module_key).
 * Per-tenant override > module default > 'isolated'.
 */
function subTenantScopeMode(int $tenantId, string $moduleKey): string {
    $pdo = getDB();
    if ($pdo) {
        $stmt = $pdo->prepare(
            'SELECT scope_mode FROM tenant_module_scope
              WHERE tenant_id = :t AND module_key = :m LIMIT 1'
        );
        $stmt->execute(['t' => $tenantId, 'm' => $moduleKey]);
        $row = $stmt->fetch();
        if ($row && in_array($row['scope_mode'], ['shared', 'isolated'], true)) {
            return $row['scope_mode'];
        }
    }
    return SUBTENANT_MODULE_SCOPE_DEFAULTS[$moduleKey] ?? 'isolated';
}

/**
 * Cached tenant row lookup (id, parent_id, tenant_type, name, is_active).
 */
function subTenantLookup(int $tenantId): ?array {
    static $cache = [];
    if (isset($cache[$tenantId])) return $cache[$tenantId];

    $pdo = getDB();
    if (!$pdo) return null;
    $stmt = $pdo->prepare(
        'SELECT id, parent_id, tenant_type, name, is_active
           FROM tenants WHERE id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $tenantId]);
    $row = $stmt->fetch();
    return $cache[$tenantId] = ($row ?: null);
}

/**
 * Provision a new sub-tenant under a master.
 * Returns the new tenant id. Throws on validation/db error.
 *
 * $opts: name (required), slug, primary_color, logo_url, modules[],
 *        scope_overrides{module_key: 'shared'|'isolated'}
 */
function subTenantProvision(int $parentTenantId, array $opts, ?int $actorUserId = null): int {
    $pdo = getDB();
    if (!$pdo) throw new RuntimeException('No database connection');

    $name = trim((string)($opts['name'] ?? ''));
    if ($name === '') throw new InvalidArgumentException('name is required');

    $parent = subTenantLookup($parentTenantId);
    if (!$parent)                            throw new InvalidArgumentException('parent tenant not found');
    if ($parent['tenant_type'] !== 'master') throw new InvalidArgumentException('parent must be a master tenant');

    $slug = trim((string)($opts['slug'] ?? '')) ?: subTenantSlugify($name);
    // The `tenants.subdomain` column is NOT NULL with no default on some
    // installs (it predates this codebase's migrations). Auto-derive from
    // slug so the wizard doesn't fail with "Field 'subdomain' doesn't have
    // a default value" — caller can still override via $opts['subdomain']
    // when they need a distinct subdomain.
    $subdomain = trim((string)($opts['subdomain'] ?? '')) ?: $slug;

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO tenants (name, slug, subdomain, parent_id, tenant_type, is_active,
                                  primary_color, logo_url, created_at)
             VALUES (:n, :s, :sd, :p, 'sub', 1, :pc, :lu, NOW())"
        );
        $stmt->execute([
            'n'  => $name,
            's'  => $slug,
            'sd' => $subdomain,
            'p'  => $parentTenantId,
            'pc' => $opts['primary_color'] ?? null,
            'lu' => $opts['logo_url'] ?? null,
        ]);
        $newId = (int) $pdo->lastInsertId();

        // Apply scope overrides up-front so audit reflects the as-provisioned state.
        $overrides = $opts['scope_overrides'] ?? [];
        foreach ($overrides as $moduleKey => $mode) {
            if (!preg_match('/^[a-z_][a-z0-9_]*$/', (string)$moduleKey)) continue;
            if (!in_array($mode, ['shared', 'isolated'], true)) continue;
            subTenantScopeSet($newId, (string)$moduleKey, $mode, $actorUserId, $pdo);
        }

        // Optionally enable modules.
        $modules = $opts['modules'] ?? null;
        if (is_array($modules)) {
            foreach ($modules as $mk) {
                if (!preg_match('/^[a-z_][a-z0-9_]*$/', (string)$mk)) continue;
                $up = $pdo->prepare(
                    'INSERT INTO tenant_modules (tenant_id, module_key, is_enabled)
                     VALUES (:t, :m, 1)
                     ON DUPLICATE KEY UPDATE is_enabled = 1'
                );
                $up->execute(['t' => $newId, 'm' => $mk]);
            }
        }

        // Optionally invite users (master tenant_admin doing onboarding).
        // We only honour pre-existing platform users here — net-new users
        // require email-based invitation flow which is a separate sprint.
        // Each entry: { email, role }. Role defaults to 'user' if absent.
        $invites = $opts['invites'] ?? [];
        $invited = [];
        if (is_array($invites) && $invites) {
            require_once __DIR__ . '/memberships.php';
            $find = $pdo->prepare('SELECT id FROM users WHERE email = :e LIMIT 1');
            foreach ($invites as $inv) {
                $email = strtolower(trim((string)($inv['email'] ?? '')));
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
                $role  = (string) ($inv['role'] ?? 'user');
                if (!in_array($role, ['user','manager','admin','tenant_admin','approver','employee'], true)) {
                    $role = 'user';
                }
                $find->execute(['e' => $email]);
                $row = $find->fetch();
                if (!$row) continue; // skip non-existent users for v1
                provisionMembership((int) $row['id'], (int) $newId, $role, [
                    'persona_label' => 'Primary',
                    'status'        => 'active',
                ]);
                $invited[] = ['email' => $email, 'user_id' => (int)$row['id'], 'role' => $role];
            }
        }

        subTenantAudit($parentTenantId, $newId, $actorUserId, 'sub_tenant.provisioned', [
            'name' => $name, 'slug' => $slug,
            'modules' => $modules, 'scope_overrides' => $overrides,
            'invited' => $invited,
        ], $pdo);

        $pdo->commit();
        return $newId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Soft-deactivate a sub-tenant. Data preserved; access blocked at session
 * resolution.
 */
function subTenantDeactivate(int $tenantId, ?int $actorUserId = null): void {
    $pdo = getDB();
    if (!$pdo) throw new RuntimeException('No database connection');
    $t = subTenantLookup($tenantId);
    if (!$t) throw new InvalidArgumentException('tenant not found');
    if ($t['tenant_type'] !== 'sub') throw new InvalidArgumentException('only sub-tenants can be deactivated this way');

    $pdo->prepare('UPDATE tenants SET is_active = 0 WHERE id = :id')
        ->execute(['id' => $tenantId]);

    subTenantAudit((int)$t['parent_id'], $tenantId, $actorUserId, 'sub_tenant.deactivated', null);
}

/**
 * Set scope_mode for one module on one sub-tenant. Idempotent upsert.
 */
function subTenantScopeSet(int $tenantId, string $moduleKey, string $mode,
                           ?int $actorUserId = null, ?PDO $pdo = null): void {
    if (!in_array($mode, ['shared', 'isolated'], true)) {
        throw new InvalidArgumentException('mode must be shared or isolated');
    }
    $pdo = $pdo ?? getDB();
    if (!$pdo) throw new RuntimeException('No database connection');

    $stmt = $pdo->prepare(
        'INSERT INTO tenant_module_scope (tenant_id, module_key, scope_mode, updated_by_user_id)
         VALUES (:t, :m, :s, :u)
         ON DUPLICATE KEY UPDATE scope_mode = VALUES(scope_mode),
                                 updated_by_user_id = VALUES(updated_by_user_id)'
    );
    $stmt->execute([
        't' => $tenantId, 'm' => $moduleKey, 's' => $mode, 'u' => $actorUserId,
    ]);

    $t = subTenantLookup($tenantId);
    subTenantAudit(
        (int)($t['parent_id'] ?? 0), $tenantId, $actorUserId,
        'sub_tenant.scope.updated',
        ['module' => $moduleKey, 'mode' => $mode],
        $pdo
    );
}

/**
 * Returns full scope map: { module_key => 'shared'|'isolated' }
 * Includes defaults overlaid with per-tenant overrides.
 */
function subTenantScopeMap(int $tenantId): array {
    $map = SUBTENANT_MODULE_SCOPE_DEFAULTS;
    $pdo = getDB();
    if ($pdo) {
        $stmt = $pdo->prepare(
            'SELECT module_key, scope_mode FROM tenant_module_scope WHERE tenant_id = :t'
        );
        $stmt->execute(['t' => $tenantId]);
        foreach ($stmt->fetchAll() as $row) {
            $map[$row['module_key']] = $row['scope_mode'];
        }
    }
    return $map;
}

/**
 * Mark this user's "last used" tenant. Powers auto-pick on next login.
 */
function subTenantTouchLastActive(int $userId, int $tenantId): void {
    $pdo = getDB();
    if (!$pdo) return;
    // Touch both tables: the legacy bridge still resolves last-active off
    // user_tenants on some code paths, but tenant_memberships has its own
    // last_active_at column and should also stay current.
    $pdo->prepare(
        'UPDATE user_tenants SET last_active_at = NOW()
          WHERE user_id = :u AND tenant_id = :t'
    )->execute(['u' => $userId, 't' => $tenantId]);
    try {
        $pdo->prepare(
            'UPDATE tenant_memberships SET last_active_at = NOW()
              WHERE user_id = :u AND tenant_id = :t'
        )->execute(['u' => $userId, 't' => $tenantId]);
    } catch (\Throwable $_) { /* migration 055 missing — skip */ }
}

/**
 * Resolve the user's last-active tenant id for auto-redirect. Falls back to
 * `is_default = 1`, else the first active membership.
 */
function subTenantLastActiveFor(int $userId): ?int {
    $pdo = getDB();
    if (!$pdo) return null;
    $stmt = $pdo->prepare(
        "SELECT ut.tenant_id
           FROM user_tenants ut
           JOIN tenants t ON t.id = ut.tenant_id
          WHERE ut.user_id = :u AND ut.status = 'active' AND t.is_active = 1
       ORDER BY (ut.last_active_at IS NULL),
                ut.last_active_at DESC,
                ut.is_default DESC,
                t.id ASC
          LIMIT 1"
    );
    $stmt->execute(['u' => $userId]);
    $row = $stmt->fetch();
    return $row ? (int) $row['tenant_id'] : null;
}

function subTenantSlugify(string $name): string {
    $s = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $name), '-'));
    return $s !== '' ? $s : 'sub-' . substr(bin2hex(random_bytes(3)), 0, 6);
}

function subTenantAudit(int $parentTenantId, int $tenantId, ?int $actorUserId,
                        string $event, $detail = null, ?PDO $pdo = null): void {
    $pdo = $pdo ?? getDB();
    if (!$pdo) return;
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO tenant_provisioning_log
                (parent_tenant_id, tenant_id, actor_user_id, event, detail_json)
             VALUES (:p, :t, :u, :e, :d)'
        );
        $stmt->execute([
            'p' => $parentTenantId ?: null,
            't' => $tenantId,
            'u' => $actorUserId,
            'e' => $event,
            'd' => $detail === null ? null : json_encode($detail, JSON_UNESCAPED_SLASHES),
        ]);
    } catch (Throwable $e) {
        // Never let audit failure block the parent transaction.
    }
}
