<?php
/**
 * CPA-firm ↔ client-tenant link service (RBAC B6 / CPA-layer kickoff).
 *
 * Wraps the `cpa_firm_client_links` table that migration 100 stood up.
 *
 * Three audiences:
 *   - **Firm admin UI** — list/create/update/delete every link the firm
 *     manages. Gated to master_admin/tenant_admin OF THE FIRM TENANT
 *     (and platform global admins). See `/api/admin/cpa_firms.php`.
 *   - **Portfolio landing page** — given a $userId, return every client
 *     tenant they can access via any firm they're a member of. Powers
 *     the "My CPA clients" page. See `cpaPortfolioForUser()`.
 *   - **Onboarding flow** — `linkAndProvisionMembership()` creates the
 *     link AND seats a `tenant_memberships` row on the client tenant for
 *     each CPA user the firm wants to have access (Phase 2; not in MVP).
 *
 * Conventions:
 *   - Every method takes integer ids; we never reach for $_SESSION here.
 *   - All writes append a `membership_audit` row so the existing Recent
 *     Access Changes panel on the admin overview shows them inline.
 *   - SELECTs filter on `firm_tenant_id` or `client_tenant_id` so the
 *     tenant-leak sentry stays green by construction.
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/permissions.php';

final class CpaFirmService
{
    public const RELATIONSHIP_TYPES = ['books_full', 'books_review_only', 'tax_only', 'advisory_only', 'custom'];
    public const STATUSES           = ['active', 'pending', 'paused', 'ended'];

    /**
     * List every client tenant the firm currently manages.
     * Joined to `tenants` so the UI gets human-readable names + active flags.
     *
     * @return array<int, array<string,mixed>>
     */
    public static function listClientsForFirm(int $firmTenantId, ?string $statusFilter = null): array
    {
        $sql = 'SELECT l.id, l.firm_tenant_id, l.client_tenant_id, l.relationship_type,
                       l.status, l.primary_cpa_user_id, l.engagement_start_date,
                       l.engagement_end_date, l.notes, l.created_at, l.updated_at,
                       c.name AS client_name, c.slug AS client_slug, c.is_active AS client_is_active,
                       u.email AS primary_cpa_email, u.name AS primary_cpa_name
                  FROM cpa_firm_client_links l
                  LEFT JOIN tenants c ON c.id = l.client_tenant_id
                  LEFT JOIN users   u ON u.id = l.primary_cpa_user_id
                 WHERE l.firm_tenant_id = :t';
        $bind = ['t' => $firmTenantId];
        if ($statusFilter !== null && in_array($statusFilter, self::STATUSES, true)) {
            $sql .= ' AND l.status = :s';
            $bind['s'] = $statusFilter;
        }
        $sql .= ' ORDER BY l.status = "active" DESC, c.name ASC, l.id ASC';
        try {
            $st = getDB()->prepare($sql);
            $st->execute($bind);
            $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $_) { return []; }
        return array_map([self::class, 'hydrateLink'], $rows);
    }

    /** One row by id, visibility-checked to $firmTenantId. */
    public static function getForFirm(int $linkId, int $firmTenantId): ?array
    {
        try {
            $st = getDB()->prepare(
                'SELECT l.*, c.name AS client_name, c.slug AS client_slug, c.is_active AS client_is_active,
                        u.email AS primary_cpa_email, u.name AS primary_cpa_name
                   FROM cpa_firm_client_links l
                   LEFT JOIN tenants c ON c.id = l.client_tenant_id
                   LEFT JOIN users   u ON u.id = l.primary_cpa_user_id
                  WHERE l.id = :id AND l.firm_tenant_id = :t LIMIT 1'
            );
            $st->execute(['id' => $linkId, 't' => $firmTenantId]);
            $r = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
        } catch (\Throwable $_) { return null; }
        return $r ? self::hydrateLink($r) : null;
    }

    /**
     * Upsert a firm↔client link. INSERT … ON DUPLICATE KEY UPDATE on the
     * `uq_firm_client` unique constraint. Returns the link id.
     *
     * @param array{
     *   client_tenant_id:int,
     *   relationship_type?:string,
     *   status?:string,
     *   primary_cpa_user_id?:?int,
     *   engagement_start_date?:?string,
     *   engagement_end_date?:?string,
     *   notes?:?string
     * } $input
     */
    public static function upsert(array $input, int $firmTenantId, ?int $actorUserId = null): int
    {
        $clientId = (int) ($input['client_tenant_id'] ?? 0);
        if ($clientId <= 0) throw new \InvalidArgumentException('client_tenant_id is required');
        if ($clientId === $firmTenantId) throw new \InvalidArgumentException('A firm cannot link to itself');

        $rt = (string) ($input['relationship_type'] ?? 'books_full');
        if (!in_array($rt, self::RELATIONSHIP_TYPES, true)) {
            throw new \InvalidArgumentException('Invalid relationship_type');
        }
        $st = (string) ($input['status'] ?? 'active');
        if (!in_array($st, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Invalid status');
        }

        $pdo = getDB();
        // Confirm the client tenant exists + is reachable.
        $tCheck = $pdo->prepare('SELECT id FROM tenants WHERE id = :id LIMIT 1');
        $tCheck->execute(['id' => $clientId]);
        if (!$tCheck->fetchColumn()) throw new \InvalidArgumentException('client_tenant_id not found');

        $bind = [
            'ft' => $firmTenantId, 'ct' => $clientId, 'rt' => $rt, 'st' => $st,
            'pc' => isset($input['primary_cpa_user_id']) && $input['primary_cpa_user_id'] !== null
                    ? (int) $input['primary_cpa_user_id'] : null,
            'sd' => $input['engagement_start_date'] ?? null,
            'ed' => $input['engagement_end_date']   ?? null,
            'no' => isset($input['notes']) ? (string) $input['notes'] : null,
            'cu' => $actorUserId,
        ];
        $sql = 'INSERT INTO cpa_firm_client_links
                    (firm_tenant_id, client_tenant_id, relationship_type, status,
                     primary_cpa_user_id, engagement_start_date, engagement_end_date,
                     notes, created_by_user_id)
                 VALUES (:ft, :ct, :rt, :st, :pc, :sd, :ed, :no, :cu)
                 ON DUPLICATE KEY UPDATE
                    relationship_type     = VALUES(relationship_type),
                    status                = VALUES(status),
                    primary_cpa_user_id   = VALUES(primary_cpa_user_id),
                    engagement_start_date = VALUES(engagement_start_date),
                    engagement_end_date   = VALUES(engagement_end_date),
                    notes                 = VALUES(notes),
                    updated_at            = NOW()';
        $pdo->prepare($sql)->execute($bind);

        $find = $pdo->prepare(
            'SELECT id FROM cpa_firm_client_links
              WHERE firm_tenant_id = :ft AND client_tenant_id = :ct LIMIT 1'
        );
        $find->execute(['ft' => $firmTenantId, 'ct' => $clientId]);
        $linkId = (int) $find->fetchColumn();

        self::audit($firmTenantId, $actorUserId, 'cpa_link_upsert', [
            'link_id' => $linkId, 'client_tenant_id' => $clientId,
            'relationship_type' => $rt, 'status' => $st,
        ]);
        return $linkId;
    }

    /** Soft-end a link (status='ended') — keeps the row for audit history. */
    public static function endLink(int $linkId, int $firmTenantId, ?int $actorUserId = null): bool
    {
        $pdo = getDB();
        $check = $pdo->prepare(
            'SELECT id, client_tenant_id FROM cpa_firm_client_links
              WHERE id = :id AND firm_tenant_id = :t LIMIT 1'
        );
        $check->execute(['id' => $linkId, 't' => $firmTenantId]);
        $row = $check->fetch(\PDO::FETCH_ASSOC) ?: null;
        if (!$row) return false;

        // tenant-leak-allow: scoped to firm_tenant_id below
        $upd = $pdo->prepare(
            'UPDATE cpa_firm_client_links
                SET status = "ended", engagement_end_date = COALESCE(engagement_end_date, CURDATE())
              WHERE id = :id AND firm_tenant_id = :t'
        );
        $upd->execute(['id' => $linkId, 't' => $firmTenantId]);

        self::audit($firmTenantId, $actorUserId, 'cpa_link_ended', [
            'link_id' => $linkId, 'client_tenant_id' => (int) ($row['client_tenant_id'] ?? 0),
        ]);
        return true;
    }

    /**
     * Hard-delete a link (admin-only escape hatch). Used when an operator
     * created the wrong link by mistake and wants it gone from the audit
     * record entirely. Normal lifecycle is `endLink()` above.
     */
    public static function deleteLink(int $linkId, int $firmTenantId, ?int $actorUserId = null): bool
    {
        $pdo = getDB();
        $check = $pdo->prepare(
            'SELECT id, client_tenant_id FROM cpa_firm_client_links
              WHERE id = :id AND firm_tenant_id = :t LIMIT 1'
        );
        $check->execute(['id' => $linkId, 't' => $firmTenantId]);
        $row = $check->fetch(\PDO::FETCH_ASSOC) ?: null;
        if (!$row) return false;
        // tenant-leak-allow: scoped to firm_tenant_id below
        $del = $pdo->prepare(
            'DELETE FROM cpa_firm_client_links WHERE id = :id AND firm_tenant_id = :t'
        );
        $del->execute(['id' => $linkId, 't' => $firmTenantId]);
        self::audit($firmTenantId, $actorUserId, 'cpa_link_deleted', [
            'link_id' => $linkId, 'client_tenant_id' => (int) ($row['client_tenant_id'] ?? 0),
        ]);
        return true;
    }

    /**
     * Portfolio view — return every client tenant the user can access via
     * any firm they're a member of (master_admin / tenant_admin / cpa* /
     * bookkeeper / client_advisor / external_auditor membership on the
     * firm tenant).
     *
     * The portfolio is intentionally OR-of-memberships: a user might be
     * tenant_admin at firm A and a CPA staffer at firm B; this surface
     * unions both client lists. The caller's UI groups by `firm_tenant_id`.
     *
     * @return array<int, array<string,mixed>>
     */
    public static function portfolioForUser(int $userId): array
    {
        if ($userId <= 0) return [];
        // Personas considered "firm-side" for the purposes of seeing the firm's
        // client portfolio. Keep this list in sync with migration 100.
        $firmPersonas = "('master_admin','tenant_admin','admin','cpa','cpa_partner','cpa_staff','bookkeeper','client_advisor')";

        $sql = 'SELECT l.id AS link_id, l.firm_tenant_id, l.client_tenant_id,
                       l.relationship_type, l.status, l.engagement_start_date,
                       l.engagement_end_date, l.notes,
                       f.name AS firm_name,    f.slug AS firm_slug,
                       c.name AS client_name,  c.slug AS client_slug,  c.is_active AS client_is_active,
                       (SELECT persona_type FROM tenant_memberships
                          WHERE user_id = :u AND tenant_id = l.firm_tenant_id
                            AND status = "active" ORDER BY is_primary DESC LIMIT 1) AS firm_persona,
                       (SELECT persona_type FROM tenant_memberships
                          WHERE user_id = :u2 AND tenant_id = l.client_tenant_id
                            AND status = "active" ORDER BY is_primary DESC LIMIT 1) AS client_persona
                  FROM cpa_firm_client_links l
                  JOIN tenants f ON f.id = l.firm_tenant_id
                  LEFT JOIN tenants c ON c.id = l.client_tenant_id
                 WHERE l.status IN ("active","paused","pending")
                   AND l.firm_tenant_id IN (
                        SELECT tenant_id FROM tenant_memberships
                         WHERE user_id = :u3 AND status = "active"
                           AND persona_type IN ' . $firmPersonas . '
                   )
                 ORDER BY l.status = "active" DESC, f.name ASC, c.name ASC';
        try {
            $st = getDB()->prepare($sql);
            $st->execute(['u' => $userId, 'u2' => $userId, 'u3' => $userId]);
            $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $_) { return []; }

        $out = [];
        foreach ($rows as $r) {
            $r['link_id']           = (int) $r['link_id'];
            $r['firm_tenant_id']    = (int) $r['firm_tenant_id'];
            $r['client_tenant_id'] = (int) $r['client_tenant_id'];
            $r['client_is_active']  = (int) ($r['client_is_active'] ?? 0) === 1;
            $r['has_client_membership'] = $r['client_persona'] !== null && $r['client_persona'] !== '';
            $out[] = $r;
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────── helpers

    private static function hydrateLink(array $r): array
    {
        $r['id']                    = (int) $r['id'];
        $r['firm_tenant_id']        = (int) $r['firm_tenant_id'];
        $r['client_tenant_id']      = (int) $r['client_tenant_id'];
        $r['primary_cpa_user_id']   = $r['primary_cpa_user_id'] !== null ? (int) $r['primary_cpa_user_id'] : null;
        $r['client_is_active']      = (int) ($r['client_is_active'] ?? 0) === 1;
        return $r;
    }

    /**
     * Best-effort audit. Writes to `membership_audit` with
     * membership_id=NULL so the same Recent Access Changes panel that
     * already powers the RBAC admin overview surfaces these events
     * without a second log table.
     */
    private static function audit(int $tenantId, ?int $actorUserId, string $action, array $detail): void
    {
        try {
            getDB()->prepare(
                'INSERT INTO membership_audit
                    (tenant_id, membership_id, action, actor_user_id, target_user_id, detail)
                 VALUES (:t, NULL, :a, :au, NULL, :d)'
            )->execute([
                't'  => $tenantId,
                'a'  => $action,
                'au' => $actorUserId,
                'd'  => json_encode($detail),
            ]);
        } catch (\Throwable $_) { /* best effort */ }
    }
}
