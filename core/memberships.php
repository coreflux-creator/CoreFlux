<?php
/**
 * core/memberships.php — single source of truth for membership writes.
 *
 * During the RBAC Phase B5 cut-over, both `user_tenants` (legacy) and
 * `tenant_memberships` (new) need to stay in sync. Rather than scatter
 * dual-write logic across half a dozen endpoints, every membership write
 * goes through this helper. When user_tenants finally gets dropped, only
 * one file needs to change.
 *
 * Column mapping:
 *   user_tenants.role         ←→ tenant_memberships.persona_type
 *   user_tenants.is_default   ←→ tenant_memberships.is_primary
 *   user_tenants.status       ←→ tenant_memberships.status
 *   user_tenants.last_active_at ←→ tenant_memberships.last_active_at
 *
 * `persona_label` is a UI string ("Primary", "Admin", "Employee") that
 * disambiguates multiple memberships of the same user in the same tenant.
 * Callers may omit it — we default to 'Primary'. Combined with persona_type
 * it forms the UNIQUE KEY on tenant_memberships.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Read-side UNION shim. While the platform finishes the user_tenants →
 * tenant_memberships migration, every read of "what tenants does a user
 * belong to / which users belong to a tenant" must look at BOTH tables —
 * production may not be fully backfilled yet, and a memberships-only read
 * silently strands every legacy row (= empty UI).
 *
 * Returns a SQL fragment (intended to be wrapped in `( ... ) AS alias`)
 * that yields a normalised, de-duplicated row set with these columns:
 *
 *   user_id, tenant_id, persona_type, is_primary, status, last_active_at
 *
 * Rows from `tenant_memberships` win when both tables hold the same
 * (user_id, tenant_id) pair. Legacy `user_tenants.status='inactive'` is
 * mapped to the new vocabulary 'suspended' on the fly.
 *
 * Centralising the SELECT here keeps `core/data.php`, `api/users.php`,
 * etc. free of direct user_tenants reads — preserving the read-sentry
 * (memberships.php is on its allow-list).
 *
 * tenant-leak-allow: returns a fragment used only inside tenant-scoped
 * queries; the caller is responsible for adding tenant_id / user_id
 * predicates.
 */
function membershipReadSourceSql(): string {
    return "(
        SELECT user_id, tenant_id, persona_type, is_primary, status, last_active_at
          FROM tenant_memberships
         WHERE status = 'active'
        UNION
        SELECT ut.user_id,
               ut.tenant_id,
               ut.role        AS persona_type,
               ut.is_default  AS is_primary,
               CASE WHEN ut.status = 'inactive' THEN 'suspended' ELSE ut.status END AS status,
               ut.last_active_at
          FROM user_tenants ut
         WHERE COALESCE(ut.status, 'active') = 'active'
           AND NOT EXISTS (
               SELECT 1 FROM tenant_memberships tm
                WHERE tm.user_id   = ut.user_id
                  AND tm.tenant_id = ut.tenant_id
           )
    )";
}

/**
 * Convenience: count distinct active tenants a user is a member of, across
 * both tables. Used by the master-admin user list to show a "tenants"
 * column even before the backfill runs.
 */
function membershipTenantCountForUser(int $userId): int {
    $pdo = getDB();
    if (!$pdo) return 0;
    $sql = 'SELECT COUNT(DISTINCT tenant_id) FROM ' . membershipReadSourceSql() . ' src WHERE src.user_id = :u';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['u' => $userId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Self-healing backfill: when a user logs in, any rows that only exist in
 * legacy `user_tenants` (= not yet migrated) are dual-written via
 * provisionMembership() so the new RBAC resolver starts seeing them.
 *
 * Best-effort, idempotent, and silent — login must succeed even if a
 * single membership write trips. Returns the number of rows healed.
 *
 * tenant-leak-allow: heals every tenant the user already legitimately
 * belongs to in the legacy table; tenant scope is the user's own data.
 */
function healMembershipsForUser(int $userId): int {
    if ($userId <= 0) return 0;
    $pdo = getDB();
    if (!$pdo) return 0;

    try {
        $stmt = $pdo->prepare(
            "SELECT ut.tenant_id, ut.role, ut.is_default
               FROM user_tenants ut
              WHERE ut.user_id = :u
                AND COALESCE(ut.status,'active') = 'active'
                AND NOT EXISTS (
                    SELECT 1 FROM tenant_memberships tm
                     WHERE tm.user_id = ut.user_id
                       AND tm.tenant_id = ut.tenant_id
                )"
        );
        $stmt->execute(['u' => $userId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        // Table missing / migration not applied — nothing to heal.
        return 0;
    }

    $healed = 0;
    foreach ($rows as $r) {
        try {
            provisionMembership(
                $userId,
                (int) $r['tenant_id'],
                (string) ($r['role'] ?? 'employee'),
                [
                    'is_primary'    => (int) ($r['is_default'] ?? 0) === 1,
                    'persona_label' => 'Primary',
                    'status'        => 'active',
                ]
            );
            $healed++;
        } catch (\Throwable $e) {
            error_log('[memberships.heal] ' . $e->getMessage());
        }
    }
    return $healed;
}

/**
 * tenant_memberships.persona_type is an ENUM. Map legacy role strings to a
 * value the new column will accept. Unknown / custom roles become 'custom'.
 */
function _membershipPersonaTypeForRole(?string $role): string {
    $r = strtolower(trim((string) $role));
    $known = ['master_admin','tenant_admin','admin','manager',
              'employee','contractor','client','vendor','platform_staff','custom'];
    if (in_array($r, $known, true)) return $r;
    // common legacy aliases
    if ($r === 'user' || $r === '')     return 'employee';
    if ($r === 'owner')                 return 'tenant_admin';
    if ($r === 'consultant')            return 'contractor';
    return 'custom';
}

/**
 * Provision (or update) a membership for ($userId, $tenantId, $role).
 *
 * Idempotent: re-calling with the same args updates status/is_primary on
 * the existing row rather than failing on the unique key.
 *
 * Options:
 *   - is_primary    (bool, default false) — when true, also clears
 *                   is_primary on this user's other memberships in the
 *                   SAME tenant so exactly one is the default.
 *   - persona_label (string, default 'Primary') — UI-visible disambiguator.
 *   - status        ('active'|'pending'|'suspended'|'revoked', default 'active')
 *   - linked_entity_type / linked_entity_id (optional) — link to a people
 *                   row, vendor row, etc.
 *
 * Returns: ['membership_id' => int, 'user_tenants_id' => int, 'created' => bool]
 *
 * tenant-leak-allow: membership row writes — tenant_id is the explicit parameter we're scoping to
 */
function provisionMembership(int $userId, int $tenantId, string $role, array $opts = []): array {
    if ($userId <= 0 || $tenantId <= 0) {
        throw new \InvalidArgumentException('user_id and tenant_id must be > 0');
    }

    $personaType  = _membershipPersonaTypeForRole($role);
    $personaLabel = (string) ($opts['persona_label'] ?? 'Primary');
    $status       = (string) ($opts['status'] ?? 'active');
    $isPrimary    = !empty($opts['is_primary']);

    $pdo = getDB();
    if (!$pdo) throw new \RuntimeException('No DB connection');

    $ownsTxn = !$pdo->inTransaction();
    if ($ownsTxn) $pdo->beginTransaction();
    try {
        // 1) tenant_memberships upsert.
        $stmt = $pdo->prepare(
            'INSERT INTO tenant_memberships
                (user_id, tenant_id, persona_type, persona_label, is_primary, status,
                 linked_entity_type, linked_entity_id, created_at, updated_at)
             VALUES (:u, :t, :pt, :pl, :ip, :s, :let, :lei, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                 persona_type       = VALUES(persona_type),
                 is_primary         = VALUES(is_primary),
                 status             = VALUES(status),
                 linked_entity_type = VALUES(linked_entity_type),
                 linked_entity_id   = VALUES(linked_entity_id),
                 updated_at         = NOW()'
        );
        $stmt->execute([
            'u'   => $userId,
            't'   => $tenantId,
            'pt'  => $personaType,
            'pl'  => $personaLabel,
            'ip'  => $isPrimary ? 1 : 0,
            's'   => $status,
            'let' => $opts['linked_entity_type'] ?? null,
            'lei' => $opts['linked_entity_id']   ?? null,
        ]);
        $membershipId = (int) $pdo->lastInsertId();
        $created      = $membershipId > 0; // 0 → row already existed; ON DUPLICATE KEY UPDATE doesn't increment

        // Refetch the id on conflict (lastInsertId is 0).
        if ($membershipId === 0) {
            $f = $pdo->prepare(
                'SELECT id FROM tenant_memberships
                  WHERE user_id = :u AND tenant_id = :t AND persona_label = :pl LIMIT 1'
            );
            $f->execute(['u' => $userId, 't' => $tenantId, 'pl' => $personaLabel]);
            $membershipId = (int) $f->fetchColumn();
        }

        // 2) If is_primary, demote sibling memberships in the same tenant.
        if ($isPrimary && $membershipId > 0) {
            $pdo->prepare(
                'UPDATE tenant_memberships
                    SET is_primary = 0, updated_at = NOW()
                  WHERE user_id = :u AND tenant_id = :t AND id <> :keep'
            )->execute(['u' => $userId, 't' => $tenantId, 'keep' => $membershipId]);
        }

        // 3) Dual-write legacy user_tenants — keep schema in sync until
        //    the table is finally dropped. (Helper consumers no longer touch
        //    user_tenants directly; only this file does.)
        $isActive = $status === 'active' ? 'active' : 'inactive';
        $pdo->prepare(
            "INSERT INTO user_tenants (user_id, tenant_id, role, is_default, status, created_at, updated_at)
             VALUES (:u, :t, :r, :ip, :s, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                 role       = VALUES(role),
                 is_default = VALUES(is_default),
                 status     = VALUES(status),
                 updated_at = NOW()"
        )->execute([
            'u'  => $userId,
            't'  => $tenantId,
            'r'  => $role,                  // preserve original role string in legacy column
            'ip' => $isPrimary ? 1 : 0,
            's'  => $isActive,
        ]);

        // Sibling demotion in user_tenants too, since is_default has the
        // same uniqueness expectation as is_primary.
        if ($isPrimary) {
            $pdo->prepare(
                'UPDATE user_tenants
                    SET is_default = 0, updated_at = NOW()
                  WHERE user_id = :u AND tenant_id <> :t'
            )->execute(['u' => $userId, 't' => $tenantId]);
        }

        $utId = (int) $pdo->lastInsertId();

        // 4) Audit (best-effort) — append to membership_audit when present.
        try {
            $pdo->prepare(
                "INSERT INTO membership_audit
                    (tenant_id, membership_id, action, actor_user_id, target_user_id, detail, occurred_at)
                 VALUES (:t, :m, :a, :actor, :u, :d, NOW())"
            )->execute([
                't'     => $tenantId,
                'm'     => $membershipId ?: null,
                'a'     => $created ? 'created' : 'updated',
                'actor' => (int) ($_SESSION['user']['id'] ?? 0) ?: null,
                'u'     => $userId,
                'd'     => json_encode([
                    'role'          => $role,
                    'persona_type'  => $personaType,
                    'persona_label' => $personaLabel,
                    'is_primary'    => $isPrimary,
                    'status'        => $status,
                ], JSON_UNESCAPED_SLASHES),
            ]);
        } catch (\Throwable $auditErr) {
            // Audit failure must never block the write; log + continue.
            error_log('[memberships] audit insert failed: ' . $auditErr->getMessage());
        }

        if ($ownsTxn) $pdo->commit();

        return [
            'membership_id'   => $membershipId,
            'user_tenants_id' => $utId,
            'created'         => $created,
        ];
    } catch (\Throwable $e) {
        if ($ownsTxn && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Deactivate every membership a user has at a tenant (legacy + new tables).
 * Returns rows affected on tenant_memberships side.
 *
 * tenant-leak-allow: membership writes — tenant_id is the explicit scope argument
 */
function deactivateMembership(int $userId, int $tenantId): int {
    $pdo = getDB();
    if (!$pdo) return 0;
    $ownsTxn = !$pdo->inTransaction();
    if ($ownsTxn) $pdo->beginTransaction();
    try {
        $st1 = $pdo->prepare(
            "UPDATE tenant_memberships
                SET status = 'revoked', updated_at = NOW()
              WHERE user_id = :u AND tenant_id = :t"
        );
        $st1->execute(['u' => $userId, 't' => $tenantId]);
        $rows = $st1->rowCount();

        $pdo->prepare(
            "UPDATE user_tenants
                SET status = 'inactive', updated_at = NOW()
              WHERE user_id = :u AND tenant_id = :t"
        )->execute(['u' => $userId, 't' => $tenantId]);

        if ($ownsTxn) $pdo->commit();
        return $rows;
    } catch (\Throwable $e) {
        if ($ownsTxn && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Flip is_primary / is_default to the given (user, tenant), demoting any
 * other memberships the user holds (so exactly one default remains).
 *
 * tenant-leak-allow: membership writes — tenant_id is the explicit scope argument
 */
function setPrimaryMembership(int $userId, int $tenantId): void {
    $pdo = getDB();
    if (!$pdo) return;
    $ownsTxn = !$pdo->inTransaction();
    if ($ownsTxn) $pdo->beginTransaction();
    try {
        // tenant_memberships: target this tenant on, others off.
        $pdo->prepare(
            "UPDATE tenant_memberships
                SET is_primary = (tenant_id = :t), updated_at = NOW()
              WHERE user_id = :u"
        )->execute(['u' => $userId, 't' => $tenantId]);

        $pdo->prepare(
            "UPDATE user_tenants
                SET is_default = (tenant_id = :t), updated_at = NOW()
              WHERE user_id = :u"
        )->execute(['u' => $userId, 't' => $tenantId]);

        if ($ownsTxn) $pdo->commit();
    } catch (\Throwable $e) {
        if ($ownsTxn && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Remove all memberships for a user across all tenants. Used by hard-delete
 * flows (admin "purge user" button). Dual-deletes both tables.
 */
function purgeMembershipsForUser(int $userId): void {
    $pdo = getDB();
    if (!$pdo) return;
    $ownsTxn = !$pdo->inTransaction();
    if ($ownsTxn) $pdo->beginTransaction();
    try {
        // tenant-leak-allow: hard-delete purges ALL of a user's memberships across every tenant — cross-tenant by design
        $pdo->prepare('DELETE FROM tenant_memberships WHERE user_id = :u')->execute(['u' => $userId]);
        // tenant-leak-allow: hard-delete purges ALL of a user's memberships across every tenant — cross-tenant by design
        $pdo->prepare('DELETE FROM user_tenants WHERE user_id = :u')->execute(['u' => $userId]);
        if ($ownsTxn) $pdo->commit();
    } catch (\Throwable $e) {
        if ($ownsTxn && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
