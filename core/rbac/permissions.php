<?php
/**
 * RBACResolver — runtime permission resolver (B2).
 *
 * Reads the new tenant_memberships + membership_module_access grid that
 * B1 stood up. Provides the single source of truth for "can this user do
 * X in module M under sub-tenant S?".
 *
 * IMPORTANT — class naming:
 *   The legacy `class RBAC` in /app/core/RBAC.php is still wired into
 *   ~30 endpoints (rbac_legacy_can(), rbac_legacy_require()).
 *   We deliberately use a different class name here so both can be
 *   loaded in the same process without a PHP redeclaration fatal.
 *   New code should call `RBACResolver`; legacy callers keep using
 *   `RBAC` until the B5 sweep retires them.
 *
 * Strategy:
 *   1. Resolve the *active membership* for (user, tenant, persona):
 *      a. If $personaId is given AND it belongs to (user,tenant) → use it.
 *      b. Else fall back to the user's primary membership in that tenant.
 *      c. Else fall back to ANY active membership in that tenant.
 *   2. Look up membership_module_access for $module.
 *   3. Compare access_level against the requested action verb
 *      (read < write < admin). 'none' grants nothing.
 *   4. If sub_tenant_scope is set, require $subTenantId membership.
 *   5. users.is_global_admin = 1 short-circuits to TRUE for any check.
 *
 * Backward compat:
 *   - `RBACResolver::personaTypeOf()` mirrors the membership's persona_type
 *     back so legacy `$ctx['role'] === '...'` gates still work during the
 *     B5 sweep.
 *   - When NO membership exists for (user, tenant) we fall through to
 *     the legacy `user_tenants.role` check via `RBACResolver::legacyRole()`
 *     so untouched tenants keep operating until the backfill runs.
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

final class RBACResolver
{
    /** read < write < admin. 'none' satisfies nothing. */
    private const LEVEL_RANK = ['none' => 0, 'read' => 1, 'write' => 2, 'admin' => 3];

    /** Per-request memoisation. Keyed by user_id|tenant_id|persona_id. */
    private static array $membershipCache = [];

    /** Per-request memoisation. Keyed by membership_id|module_key. */
    private static array $moduleCache = [];

    /**
     * Primary check. Returns true when the user can perform $action on
     * $module, optionally scoped to $subTenantId.
     */
    public static function can(
        array|int $userOrId,
        int $tenantId,
        string $module,
        string $action = 'read',
        ?int $subTenantId = null,
        ?int $personaId = null
    ): bool {
        $userId = is_array($userOrId) ? (int) ($userOrId['id'] ?? 0) : (int) $userOrId;
        if ($userId <= 0) return false;
        if (!isset(self::LEVEL_RANK[$action])) return false;

        // Global admin bypass.
        if (self::isGlobalAdmin($userId)) return true;

        $membership = self::activeMembership($userId, $tenantId, $personaId);
        if (!$membership) {
            // Fall back to legacy role-based check while the sweep is in progress.
            return self::legacyCan($userId, $tenantId, $module, $action);
        }
        if (($membership['status'] ?? 'active') !== 'active') return false;

        // Persona-type wildcard. master_admin + tenant_admin historically had
        // wildcard access across every module via role-only checks; the new
        // `membership_module_access` table doesn't backfill rows for them on
        // the B1 sweep (they're the "everything" personas, not module-level
        // grants). Without this shortcut, master_admin / tenant_admin posts
        // to /api/staffing/timesheets, /api/accounting/journal_entries, etc.
        // hit `moduleAccessFor() === null → deny` and the operator is locked
        // out of every action that runs through the bridge.
        //
        // Sub-tenant scope check is skipped here because master_admin and
        // tenant_admin are tenant-wide by definition — they're not bounded
        // to a sub-tenant scope.
        $personaType = (string) ($membership['persona_type'] ?? '');
        if (in_array($personaType, ['master_admin', 'tenant_admin'], true)) {
            return true;
        }

        $access = self::moduleAccessFor((int) $membership['id'], $module);
        if (!$access) return false;
        $level = (string) ($access['access_level'] ?? 'none');
        if ((self::LEVEL_RANK[$level] ?? 0) < self::LEVEL_RANK[$action]) return false;

        // Sub-tenant scope. NULL = all sub-tenants under this tenant.
        if ($subTenantId !== null && $access['sub_tenant_scope'] !== null) {
            $scope = json_decode((string) $access['sub_tenant_scope'], true);
            if (!is_array($scope) || !in_array($subTenantId, array_map('intval', $scope), true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Helper for legacy `$ctx['role'] === '...'` callers. Returns the
     * persona_type of the active membership, falling back to user_tenants
     * role then users.role when the new model has nothing.
     */
    public static function personaTypeOf(int $userId, int $tenantId, ?int $personaId = null): string
    {
        if (self::isGlobalAdmin($userId)) return 'master_admin';
        $m = self::activeMembership($userId, $tenantId, $personaId);
        if ($m && isset($m['persona_type'])) return (string) $m['persona_type'];
        return self::legacyRole($userId, $tenantId);
    }

    /**
     * Every membership for $userId, optionally filtered to one tenant.
     * Used by the header persona toggle and the admin UI.
     */
    public static function memberships(int $userId, ?int $tenantId = null): array
    {
        $sql = 'SELECT id, user_id, tenant_id, persona_label, persona_type,
                       linked_entity_type, linked_entity_id, is_primary,
                       status, last_active_at
                  FROM tenant_memberships
                 WHERE user_id = ?
                   AND status IN ("active","pending")';
        $bind = [$userId];
        if ($tenantId !== null) { $sql .= ' AND tenant_id = ?'; $bind[] = $tenantId; }
        $sql .= ' ORDER BY is_primary DESC, last_active_at DESC, id ASC';
        try {
            $st = getDB()->prepare($sql);
            $st->execute($bind);
            return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $_) {
            return [];
        }
    }

    /** Hydrates the active membership row for (user, tenant, persona). */
    public static function activeMembership(int $userId, int $tenantId, ?int $personaId = null): ?array
    {
        $key = $userId . '|' . $tenantId . '|' . ($personaId ?? 'auto');
        if (array_key_exists($key, self::$membershipCache)) return self::$membershipCache[$key];

        $sql = 'SELECT id, user_id, tenant_id, persona_label, persona_type,
                       linked_entity_type, linked_entity_id, is_primary, status
                  FROM tenant_memberships
                 WHERE user_id = :u AND tenant_id = :t AND status = "active"';
        $bind = ['u' => $userId, 't' => $tenantId];
        if ($personaId !== null) { $sql .= ' AND id = :p'; $bind['p'] = $personaId; }
        $sql .= ' ORDER BY is_primary DESC, last_active_at DESC, id ASC LIMIT 1';
        try {
            $st = getDB()->prepare($sql);
            $st->execute($bind);
            $row = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
        } catch (\Throwable $_) {
            $row = null;
        }
        return self::$membershipCache[$key] = $row;
    }

    /** Module access row for a membership + module key. Memoised. */
    public static function moduleAccessFor(int $membershipId, string $module): ?array
    {
        $key = $membershipId . '|' . $module;
        if (array_key_exists($key, self::$moduleCache)) return self::$moduleCache[$key];
        try {
            $st = getDB()->prepare(
                'SELECT id, membership_id, module_key, access_level, sub_tenant_scope
                   FROM membership_module_access
                  WHERE membership_id = :m AND module_key = :k LIMIT 1'
            );
            $st->execute(['m' => $membershipId, 'k' => $module]);
            $row = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
        } catch (\Throwable $_) { $row = null; }
        return self::$moduleCache[$key] = $row;
    }

    /** Grant or upgrade a module's access level on a membership. */
    public static function grantModule(int $membershipId, string $module, string $level, ?array $subTenantScope = null, ?int $actorUserId = null): void
    {
        if (!isset(self::LEVEL_RANK[$level])) throw new \InvalidArgumentException("invalid level: {$level}");
        getDB()->prepare(
            'INSERT INTO membership_module_access
                (membership_id, module_key, access_level, sub_tenant_scope, granted_by_user_id)
             VALUES (:m, :k, :a, :s, :u)
             ON DUPLICATE KEY UPDATE
                access_level     = VALUES(access_level),
                sub_tenant_scope = VALUES(sub_tenant_scope),
                granted_by_user_id = VALUES(granted_by_user_id),
                granted_at = NOW()'
        )->execute([
            'm' => $membershipId, 'k' => $module, 'a' => $level,
            's' => $subTenantScope !== null ? json_encode(array_values(array_map('intval', $subTenantScope))) : null,
            'u' => $actorUserId,
        ]);
        self::auditMembership($membershipId, 'module_grant', $actorUserId, ['module' => $module, 'level' => $level, 'sub_tenant_scope' => $subTenantScope]);
        unset(self::$moduleCache[$membershipId . '|' . $module]);
    }

    public static function revokeModule(int $membershipId, string $module, ?int $actorUserId = null): void
    {
        getDB()->prepare('DELETE FROM membership_module_access WHERE membership_id = :m AND module_key = :k')
            ->execute(['m' => $membershipId, 'k' => $module]);
        self::auditMembership($membershipId, 'module_revoke', $actorUserId, ['module' => $module]);
        unset(self::$moduleCache[$membershipId . '|' . $module]);
    }

    /**
     * Clone every membership_module_access row from $fromMembershipId
     * onto $toMembershipId. Per the "Copy permissions from…" admin UX —
     * onboarding a second recruiter / third controller becomes one click.
     *
     * Both memberships must belong to the same tenant (otherwise the
     * sub_tenant_scope JSON points at unrelated sub-tenant IDs). Returns
     * the number of grants copied.
     */
    public static function copyPermissions(int $fromMembershipId, int $toMembershipId, ?int $actorUserId = null): int
    {
        if ($fromMembershipId === $toMembershipId) return 0;
        $pdo = getDB();
        $stmt = $pdo->prepare('SELECT tenant_id FROM tenant_memberships WHERE id IN (?, ?)');
        $stmt->execute([$fromMembershipId, $toMembershipId]);
        $tenants = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);
        if (count($tenants) !== 2) throw new \RuntimeException('both memberships must exist');
        if ($tenants[0] !== $tenants[1]) throw new \RuntimeException('memberships must belong to the same tenant');

        $stmt = $pdo->prepare(
            'SELECT module_key, access_level, sub_tenant_scope
               FROM membership_module_access
              WHERE membership_id = :m'
        );
        $stmt->execute(['m' => $fromMembershipId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $copied = 0;
        foreach ($rows as $r) {
            self::grantModule(
                $toMembershipId,
                (string) $r['module_key'],
                (string) $r['access_level'],
                $r['sub_tenant_scope'] !== null ? (json_decode((string) $r['sub_tenant_scope'], true) ?: []) : null,
                $actorUserId
            );
            $copied++;
        }
        self::auditMembership($toMembershipId, 'permissions_copied', $actorUserId, [
            'from_membership_id' => $fromMembershipId, 'grants_copied' => $copied,
        ]);
        return $copied;
    }

    public static function isGlobalAdmin(int $userId): bool
    {
        try {
            $st = getDB()->prepare('SELECT is_global_admin FROM users WHERE id = :u LIMIT 1');
            $st->execute(['u' => $userId]);
            return (int) ($st->fetchColumn() ?: 0) === 1;
        } catch (\Throwable $_) { return false; }
    }

    /** Best-effort audit. Never bubbles a logging failure into the caller. */
    public static function auditMembership(int $membershipId, string $action, ?int $actorUserId, array $detail = []): void
    {
        try {
            $tStmt = getDB()->prepare('SELECT tenant_id, user_id FROM tenant_memberships WHERE id = :id LIMIT 1');
            $tStmt->execute(['id' => $membershipId]);
            $row = $tStmt->fetch(\PDO::FETCH_ASSOC) ?: [];
            getDB()->prepare(
                'INSERT INTO membership_audit (tenant_id, membership_id, action, actor_user_id, target_user_id, detail)
                 VALUES (:t, :m, :a, :au, :tu, :d)'
            )->execute([
                't'  => $row['tenant_id'] ?? 0,
                'm'  => $membershipId,
                'a'  => $action,
                'au' => $actorUserId,
                'tu' => $row['user_id'] ?? null,
                'd'  => json_encode($detail),
            ]);
        } catch (\Throwable $_) { /* best effort */ }
    }

    // ----------------------------------------------------------------- legacy fall-throughs

    public static function legacyRole(int $userId, int $tenantId): string
    {
        try {
            $st = getDB()->prepare('SELECT role FROM user_tenants WHERE user_id = :u AND tenant_id = :t AND status = "active" LIMIT 1');
            $st->execute(['u' => $userId, 't' => $tenantId]);
            $r = $st->fetchColumn();
            if ($r) return (string) $r;
        } catch (\Throwable $_) {}
        try {
            $st = getDB()->prepare('SELECT role FROM users WHERE id = :u LIMIT 1');
            $st->execute(['u' => $userId]);
            $r = $st->fetchColumn();
            if ($r) return (string) $r;
        } catch (\Throwable $_) {}
        return 'employee';
    }

    private static function legacyCan(int $userId, int $tenantId, string $module, string $action): bool
    {
        // Mirror the previous role-based gating during the cut-over.
        // master_admin / tenant_admin → admin everywhere.
        // admin                       → write everywhere.
        // manager                     → write where modules grant it.
        // employee/contractor         → read only.
        $role = self::legacyRole($userId, $tenantId);
        $level = match ($role) {
            'master_admin', 'tenant_admin' => 'admin',
            'admin', 'manager'             => 'write',
            default                        => 'read',
        };
        return (self::LEVEL_RANK[$level] ?? 0) >= self::LEVEL_RANK[$action];
    }

    /** Test hook — clear memoisation between requests in long-running CLI. */
    public static function resetCache(): void
    {
        self::$membershipCache = [];
        self::$moduleCache    = [];
    }
}
