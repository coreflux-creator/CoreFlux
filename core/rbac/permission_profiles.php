<?php
/**
 * RBAC B6 — Permission profiles service.
 *
 * Named bundles of (module_key, access_level) grants that can be applied
 * to a membership in one click. Migration 100 seeds CoreFlux-standard
 * profiles for the 6 CPA-firm personas (cpa, cpa_partner, cpa_staff,
 * bookkeeper, client_advisor, external_auditor); tenants can add their
 * own profiles on top.
 *
 * Visibility rules:
 *   - SYSTEM profiles (`is_system = 1`, `tenant_id IS NULL`) are visible
 *     to every tenant and cannot be deleted or have their grants_json
 *     replaced (only the label/description can be tweaked). The seed
 *     row in migration 100 is the source of truth for system grants.
 *   - GLOBAL custom profiles (`is_system = 0`, `tenant_id IS NULL`) are
 *     visible to every tenant but writable only by a platform global
 *     admin. Used for cross-tenant org defaults (e.g. a multi-tenant
 *     CPA firm wanting a "Firm-standard senior" bundle).
 *   - TENANT profiles (`tenant_id = N`) are private to that tenant.
 *
 * Per-tenant override: a tenant may shadow a system profile by inserting
 * a row with the same `profile_key` under its own tenant_id. The list
 * helper surfaces the tenant-scoped row when both exist.
 *
 * @file core/rbac/permission_profiles.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/permissions.php';

final class PermissionProfileService
{
    /** Access level values that may appear in grants_json. */
    public const LEVELS = ['none', 'read', 'write', 'admin'];

    /**
     * List every profile visible to $tenantId (system + global custom +
     * tenant-private). Tenant-scoped rows shadow system rows that share
     * the same profile_key.
     *
     * @return array<int,array{
     *   id:int,
     *   profile_key:string,
     *   label:string,
     *   description:?string,
     *   applies_to_persona:?string,
     *   grants:array<int,array{module_key:string,access_level:string}>,
     *   is_system:bool,
     *   scope:string,
     *   tenant_id:?int
     * }>
     */
    public static function listForTenant(int $tenantId): array
    {
        $pdo = getDB();
        try {
            $st = $pdo->prepare(
                'SELECT id, profile_key, label, description, applies_to_persona,
                        grants_json, is_system, tenant_id
                   FROM rbac_permission_profiles
                  WHERE tenant_id IS NULL OR tenant_id = :t
                  ORDER BY tenant_id IS NULL ASC, applies_to_persona ASC, label ASC'
            );
            $st->execute(['t' => $tenantId]);
            $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $_) {
            return [];
        }

        // Dedupe: when a (tenant_id = N) row exists with the same
        // profile_key as a (tenant_id IS NULL) row, the tenant one wins.
        $byKey = [];
        foreach ($rows as $r) {
            $key = (string) $r['profile_key'];
            $isShadow = $r['tenant_id'] !== null;
            if (!isset($byKey[$key]) || $isShadow) $byKey[$key] = $r;
        }
        $out = [];
        foreach ($byKey as $r) $out[] = self::hydrate($r);
        return array_values($out);
    }

    /** Hydrate one profile row by id (visibility-checked to $tenantId). */
    public static function getForTenant(int $profileId, int $tenantId): ?array
    {
        try {
            $st = getDB()->prepare(
                'SELECT id, profile_key, label, description, applies_to_persona,
                        grants_json, is_system, tenant_id
                   FROM rbac_permission_profiles
                  WHERE id = :id
                    AND (tenant_id IS NULL OR tenant_id = :t)
                  LIMIT 1'
            );
            $st->execute(['id' => $profileId, 't' => $tenantId]);
            $r = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
        } catch (\Throwable $_) { return null; }
        return $r ? self::hydrate($r) : null;
    }

    /**
     * Hydrate one profile row by profile_key. Tenant-private shadow wins
     * over the system row when both exist for this $tenantId.
     */
    public static function getByKey(string $profileKey, int $tenantId): ?array
    {
        try {
            $st = getDB()->prepare(
                'SELECT id, profile_key, label, description, applies_to_persona,
                        grants_json, is_system, tenant_id
                   FROM rbac_permission_profiles
                  WHERE profile_key = :k
                    AND (tenant_id IS NULL OR tenant_id = :t)
                  ORDER BY tenant_id IS NULL ASC LIMIT 1'
            );
            $st->execute(['k' => $profileKey, 't' => $tenantId]);
            $r = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
        } catch (\Throwable $_) { return null; }
        return $r ? self::hydrate($r) : null;
    }

    /**
     * Create or update a tenant-private profile. Returns the upserted row id.
     *
     * SYSTEM rows: only `label` and `description` may be updated, and only
     * by a platform global admin (the gate lives in the endpoint, not here).
     * GLOBAL custom rows: writable only when `$tenantId === null` callers
     * (i.e. global admin contexts) supply it via the dedicated upsertGlobal()
     * helper; this method always writes a TENANT-private row.
     *
     * @param array{
     *   id?:int,
     *   profile_key:string,
     *   label:string,
     *   description?:?string,
     *   applies_to_persona?:?string,
     *   grants:array<int,array{module_key:string,access_level:string}>
     * } $input
     */
    public static function upsertForTenant(array $input, int $tenantId, ?int $actorUserId = null): int
    {
        $pk = trim((string) ($input['profile_key'] ?? ''));
        if ($pk === '' || !preg_match('/^[a-z0-9][a-z0-9._-]{0,58}$/', $pk)) {
            throw new \InvalidArgumentException('profile_key must be 1-59 chars, [a-z0-9._-]');
        }
        $label = trim((string) ($input['label'] ?? ''));
        if ($label === '') throw new \InvalidArgumentException('label is required');
        $desc        = isset($input['description']) ? (string) $input['description'] : null;
        $persona     = isset($input['applies_to_persona']) ? (string) $input['applies_to_persona'] : null;
        if ($persona !== null && $persona === '') $persona = null;
        $grants      = self::normaliseGrants($input['grants'] ?? []);
        if (!$grants) throw new \InvalidArgumentException('grants must contain at least one (module_key, access_level) row');

        $pdo = getDB();
        // Block accidentally shadowing a SYSTEM profile_key by a tenant
        // that wants the same key (silent shadow is allowed; corrupt is
        // not — the existing row's grants must be different enough that
        // listing them side-by-side is meaningful).
        $existsSys = $pdo->prepare(
            'SELECT id FROM rbac_permission_profiles
              WHERE profile_key = :k AND tenant_id IS NULL AND is_system = 1 LIMIT 1'
        );
        $existsSys->execute(['k' => $pk]);
        $shadows = (bool) $existsSys->fetchColumn();

        $st = $pdo->prepare(
            'INSERT INTO rbac_permission_profiles
                (profile_key, label, description, applies_to_persona,
                 grants_json, is_system, tenant_id, created_by_user_id)
             VALUES
                (:pk, :lb, :ds, :ap, :gj, 0, :t, :u)
             ON DUPLICATE KEY UPDATE
                label              = VALUES(label),
                description        = VALUES(description),
                applies_to_persona = VALUES(applies_to_persona),
                grants_json        = VALUES(grants_json),
                updated_at         = NOW()'
        );
        $st->execute([
            'pk' => $pk, 'lb' => $label, 'ds' => $desc, 'ap' => $persona,
            'gj' => json_encode(array_values($grants)),
            't'  => $tenantId, 'u'  => $actorUserId,
        ]);

        $find = $pdo->prepare(
            'SELECT id FROM rbac_permission_profiles
              WHERE profile_key = :k AND tenant_id = :t LIMIT 1'
        );
        $find->execute(['k' => $pk, 't' => $tenantId]);
        $id = (int) $find->fetchColumn();

        self::auditEvent($id, $tenantId, $actorUserId, 'profile_upsert', [
            'profile_key' => $pk, 'shadows_system' => $shadows, 'grants_count' => count($grants),
        ]);
        return $id;
    }

    /**
     * Delete a tenant-private profile. SYSTEM profiles cannot be deleted.
     * GLOBAL custom profiles cannot be deleted via this method either —
     * use the global-admin-only deleteGlobal() helper if a platform owner
     * needs to retire one.
     */
    public static function deleteForTenant(int $profileId, int $tenantId, ?int $actorUserId = null): bool
    {
        $pdo = getDB();
        $st = $pdo->prepare(
            'SELECT id, is_system, tenant_id, profile_key FROM rbac_permission_profiles WHERE id = :id LIMIT 1'
        );
        $st->execute(['id' => $profileId]);
        $row = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
        if (!$row) return false;
        if ((int) $row['is_system'] === 1) {
            throw new \RuntimeException('System profiles cannot be deleted');
        }
        if ((int) ($row['tenant_id'] ?? 0) !== $tenantId) {
            throw new \RuntimeException('Profile does not belong to this tenant');
        }
        // tenant-leak-allow: explicit tenant_id filter below + pre-check
        $del = $pdo->prepare('DELETE FROM rbac_permission_profiles WHERE id = :id AND tenant_id = :t');
        $del->execute(['id' => $profileId, 't' => $tenantId]);
        self::auditEvent($profileId, $tenantId, $actorUserId, 'profile_delete', [
            'profile_key' => (string) $row['profile_key'],
        ]);
        return true;
    }

    /**
     * Apply the profile's grants_json onto $membershipId by upserting one
     * `membership_module_access` row per grant via RBACResolver::grantModule().
     * Existing grants on modules NOT in the profile are left alone — operators
     * compose by stacking profiles (e.g. "cpa_staff.default" + a tenant-private
     * "industry_overlay" profile). To reset cleanly, the operator can use the
     * `overwrite=1` flag which revokes every other module first.
     *
     * Returns the number of module grants applied.
     */
    public static function apply(
        int $membershipId,
        int $profileId,
        int $tenantId,
        ?int $actorUserId = null,
        bool $overwrite = false,
        ?array $subTenantScope = null
    ): int {
        $profile = self::getForTenant($profileId, $tenantId);
        if (!$profile) throw new \RuntimeException('Profile not found for this tenant');

        // Make sure the membership belongs to this tenant.
        $check = getDB()->prepare(
            'SELECT id FROM tenant_memberships WHERE id = :m AND tenant_id = :t LIMIT 1'
        );
        $check->execute(['m' => $membershipId, 't' => $tenantId]);
        if (!$check->fetchColumn()) throw new \RuntimeException('Membership not found in this tenant');

        $grants = $profile['grants'];

        if ($overwrite) {
            $keepKeys = array_map(fn($g) => $g['module_key'], $grants);
            $rows = getDB()->prepare('SELECT module_key FROM membership_module_access WHERE membership_id = :m');
            $rows->execute(['m' => $membershipId]);
            foreach (($rows->fetchAll(\PDO::FETCH_COLUMN) ?: []) as $existingKey) {
                if (!in_array((string) $existingKey, $keepKeys, true)) {
                    RBACResolver::revokeModule($membershipId, (string) $existingKey, $actorUserId);
                }
            }
        }

        foreach ($grants as $g) {
            if (($g['access_level'] ?? 'none') === 'none') continue;
            RBACResolver::grantModule(
                $membershipId,
                (string) $g['module_key'],
                (string) $g['access_level'],
                $subTenantScope,
                $actorUserId
            );
        }

        RBACResolver::auditMembership($membershipId, 'profile_applied', $actorUserId, [
            'profile_key'      => $profile['profile_key'],
            'profile_id'       => $profile['id'],
            'overwrite'        => $overwrite,
            'grants_applied'   => count($grants),
            'sub_tenant_scope' => $subTenantScope,
        ]);
        return count($grants);
    }

    // ─────────────────────────────────────────────────────────── helpers

    /** Normalise the JSON shape we expect — drop unknowns + invalid levels. */
    private static function normaliseGrants($raw): array
    {
        if (!is_array($raw)) return [];
        $out = []; $seen = [];
        foreach ($raw as $g) {
            if (!is_array($g)) continue;
            $mk = trim((string) ($g['module_key'] ?? ''));
            $al = (string) ($g['access_level'] ?? 'none');
            if ($mk === '' || !in_array($al, self::LEVELS, true)) continue;
            if (isset($seen[$mk])) continue;
            $seen[$mk] = true;
            $out[] = ['module_key' => $mk, 'access_level' => $al];
        }
        return $out;
    }

    /** Hydrate the DB row into the public payload shape. */
    private static function hydrate(array $r): array
    {
        $grants = json_decode((string) ($r['grants_json'] ?? '[]'), true);
        if (!is_array($grants)) $grants = [];
        // Coerce to canonical {module_key, access_level} pairs in case the
        // stored JSON came from JSON_OBJECT() with extra keys.
        $clean = [];
        foreach ($grants as $g) {
            if (!is_array($g)) continue;
            $mk = (string) ($g['module_key'] ?? '');
            $al = (string) ($g['access_level'] ?? 'none');
            if ($mk === '' || !in_array($al, self::LEVELS, true)) continue;
            $clean[] = ['module_key' => $mk, 'access_level' => $al];
        }
        return [
            'id'                  => (int) $r['id'],
            'profile_key'         => (string) $r['profile_key'],
            'label'               => (string) $r['label'],
            'description'         => $r['description'] !== null ? (string) $r['description'] : null,
            'applies_to_persona'  => $r['applies_to_persona'] !== null ? (string) $r['applies_to_persona'] : null,
            'grants'              => $clean,
            'is_system'           => (int) ($r['is_system'] ?? 0) === 1,
            'scope'               => $r['tenant_id'] === null ? 'global' : 'tenant',
            'tenant_id'           => $r['tenant_id'] !== null ? (int) $r['tenant_id'] : null,
        ];
    }

    /** Best-effort audit. Mirrors RBACResolver::auditMembership() shape. */
    private static function auditEvent(int $profileId, int $tenantId, ?int $actorUserId, string $action, array $detail): void
    {
        try {
            getDB()->prepare(
                'INSERT INTO membership_audit (tenant_id, membership_id, action, actor_user_id, target_user_id, detail)
                 VALUES (:t, :m, :a, :au, NULL, :d)'
            )->execute([
                't'  => $tenantId,
                // membership_audit.membership_id is NULL-able; we use it for
                // profile-scoped events too so admins see them in the same
                // recent-changes panel without a second log table.
                'm'  => null,
                'a'  => $action,
                'au' => $actorUserId,
                'd'  => json_encode(array_merge($detail, ['profile_id' => $profileId])),
            ]);
        } catch (\Throwable $_) { /* best effort */ }
    }
}
