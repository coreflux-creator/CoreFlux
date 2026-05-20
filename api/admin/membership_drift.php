<?php
/**
 * /api/admin/membership_drift.php — Backfill drift inspector.
 *
 * Surfaces accounts that still live only in the legacy `user_tenants`
 * table (= haven't been migrated into `tenant_memberships` yet). Master
 * admins use this widget on /admin/users to track backfill progress and
 * trigger one-click heals ahead of the eventual `user_tenants` drop.
 *
 *   GET  /api/admin/membership_drift.php
 *        → { summary, drifting: [...up to 100 user rows...] }
 *
 *   POST /api/admin/membership_drift.php?action=heal&user_id=N
 *        → { healed: <int> }       — runs healMembershipsForUser($id)
 *
 *   POST /api/admin/membership_drift.php?action=heal_all
 *        → { users_processed, rows_healed }
 *        (capped at 250 users per call to keep the request snappy; the
 *         widget calls this in a loop until summary.drifting === 0.)
 *
 * Auth: master_admin only — this is a platform integrity tool.
 *
 * tenant-leak-allow: cross-tenant by design — surfaces drift across the
 * entire platform; only master_admin can call it.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/memberships.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$role = $ctx['role'] ?? 'employee';

if ($role !== 'master_admin' && empty($user['is_global_admin'])) {
    api_error('Forbidden — master_admin only', 403);
}

$pdo = getDB();
if (!$pdo) api_error('No database connection', 500);

$method = api_method();
$action = (string) api_query('action', '');

// ---------------------------------------------------------------------- GET
if ($method === 'GET') {
    // Snapshot counts. Each query is cheap (PRIMARY/UNIQUE-key lookups).
    $totalUsers = (int) $pdo->query(
        "SELECT COUNT(*) FROM users WHERE is_active = 1"
    )->fetchColumn();

    $usersInLegacy = (int) $pdo->query(
        "SELECT COUNT(DISTINCT user_id) FROM user_tenants
          WHERE COALESCE(status,'active') = 'active'"
    )->fetchColumn();

    $usersInNew = (int) $pdo->query(
        "SELECT COUNT(DISTINCT user_id) FROM tenant_memberships
          WHERE status = 'active'"
    )->fetchColumn();

    // "Drifting" = a (user_id, tenant_id) row exists in user_tenants but
    // not in tenant_memberships. Aggregate at the user level so the UI
    // shows one row per account.
    $driftStmt = $pdo->query(
        "SELECT COUNT(DISTINCT ut.user_id) FROM user_tenants ut
          WHERE COALESCE(ut.status,'active') = 'active'
            AND NOT EXISTS (
                SELECT 1 FROM tenant_memberships tm
                 WHERE tm.user_id   = ut.user_id
                   AND tm.tenant_id = ut.tenant_id
            )"
    );
    $driftingCount = (int) $driftStmt->fetchColumn();

    // Detail rows (capped) — only fetched when there's drift to show.
    $rows = [];
    if ($driftingCount > 0) {
        $detail = $pdo->query(
            "SELECT u.id, u.name, u.email, u.role, u.is_active,
                    COUNT(DISTINCT ut.tenant_id) AS legacy_tenants,
                    COUNT(DISTINCT
                        CASE WHEN NOT EXISTS (
                            SELECT 1 FROM tenant_memberships tm
                             WHERE tm.user_id   = ut.user_id
                               AND tm.tenant_id = ut.tenant_id
                        ) THEN ut.tenant_id END
                    ) AS unhealed_tenants,
                    MAX(ut.last_active_at)         AS last_active_at,
                    GROUP_CONCAT(DISTINCT t.name ORDER BY t.name SEPARATOR ', ') AS tenant_names
               FROM user_tenants ut
               JOIN users   u ON u.id = ut.user_id AND u.is_active = 1
               JOIN tenants t ON t.id = ut.tenant_id
              WHERE COALESCE(ut.status,'active') = 'active'
                AND NOT EXISTS (
                    SELECT 1 FROM tenant_memberships tm
                     WHERE tm.user_id   = ut.user_id
                       AND tm.tenant_id = ut.tenant_id
                )
           GROUP BY u.id, u.name, u.email, u.role, u.is_active
           ORDER BY MAX(ut.last_active_at) DESC, u.name ASC
              LIMIT 100"
        );
        $rows = $detail->fetchAll(PDO::FETCH_ASSOC) ?: [];
        // Cast types for a clean JSON shape.
        foreach ($rows as &$r) {
            $r['id']                = (int) $r['id'];
            $r['is_active']         = (int) $r['is_active'];
            $r['legacy_tenants']    = (int) $r['legacy_tenants'];
            $r['unhealed_tenants']  = (int) $r['unhealed_tenants'];
        }
        unset($r);
    }

    api_ok([
        'summary' => [
            'total_active_users' => $totalUsers,
            'in_legacy_only'     => max(0, $usersInLegacy - $usersInNew),
            'in_new_table'       => $usersInNew,
            'drifting_users'     => $driftingCount,
            'returned'           => count($rows),
            'detail_capped_at'   => 100,
        ],
        'drifting' => $rows,
    ]);
}

// --------------------------------------------------------------------- POST
if ($method === 'POST') {
    if ($action === 'heal') {
        $uid = (int) api_query('user_id', 0);
        if ($uid <= 0) api_error('user_id required', 422);
        $healed = healMembershipsForUser($uid);
        api_ok(['user_id' => $uid, 'rows_healed' => $healed]);
    }

    if ($action === 'heal_all') {
        // Cap one batch at 250 users to keep the round-trip snappy.
        // The frontend re-polls and re-runs until summary.drifting_users == 0.
        $stmt = $pdo->query(
            "SELECT DISTINCT ut.user_id
               FROM user_tenants ut
              WHERE COALESCE(ut.status,'active') = 'active'
                AND NOT EXISTS (
                    SELECT 1 FROM tenant_memberships tm
                     WHERE tm.user_id   = ut.user_id
                       AND tm.tenant_id = ut.tenant_id
                )
              LIMIT 250"
        );
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $totalHealed = 0;
        foreach ($ids as $id) {
            try { $totalHealed += healMembershipsForUser((int) $id); }
            catch (\Throwable $e) {
                error_log('[membership_drift.heal_all] uid=' . $id . ' ' . $e->getMessage());
            }
        }
        api_ok([
            'users_processed' => count($ids),
            'rows_healed'     => $totalHealed,
            'batch_capped_at' => 250,
        ]);
    }

    api_error("Unknown action '{$action}'", 422);
}

api_error('Method not allowed', 405);
