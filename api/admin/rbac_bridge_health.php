<?php
/**
 * /api/admin/rbac_bridge_health.php — RBAC dual-check disagreement monitor.
 *
 * Tells the admin where the legacy RBAC config and the new RBACResolver
 * disagree about a permission. While the bridge runs in dual-check mode
 * (the default), disagreements are harmless at runtime — they just mean
 * the more-restrictive layer wins. But they show us exactly which perms
 * still need attention before we'd be safe to flip CF_RBAC_BRIDGE_MODE
 * to `new`.
 *
 *   GET /api/admin/rbac_bridge_health.php
 *     ?window_hours=24       (default 24, max 168 = 1 week)
 *
 *   Response:
 *     {
 *       configured: bool,
 *       window_hours: int,
 *       total_disagreements: int,
 *       legacy_only_grants: int,   // legacy said yes, new said no  → widening risk if we flip
 *       new_only_grants:    int,   // new said yes, legacy said no  → narrowing risk if we flip
 *       top_perms: [{ perm, module, action, count, legacy_ok, new_ok }, ...],
 *       recent:    [{ id, perm, module, action, legacy_ok, new_ok, occurred_at, user_id }, ...]
 *     }
 *
 * Auth: master_admin / tenant_admin / is_global_admin only.
 * Read-only.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';

$ctx      = api_require_auth();
$tenantId = (int) ($ctx['tenant_id'] ?? 0);
if (!$tenantId) api_error('No active tenant', 400);
if (api_method() !== 'GET') api_error('Method not allowed', 405);

$role          = (string) ($ctx['role'] ?? 'employee');
$isGlobalAdmin = (bool) ($ctx['is_global_admin'] ?? false);
if (!$isGlobalAdmin && !in_array($role, ['master_admin', 'tenant_admin'], true)) {
    api_error('Forbidden — admin only', 403);
}

$pdo = getDB();
if (!$pdo) api_error('No database connection', 500);

try {
    $pdo->query('SELECT 1 FROM rbac_bridge_audit LIMIT 0');
} catch (\Throwable $_) {
    api_ok([
        'configured'          => false,
        'window_hours'        => 24,
        'total_disagreements' => 0,
        'legacy_only_grants'  => 0,
        'new_only_grants'     => 0,
        'top_perms'           => [],
        'recent'              => [],
        'reason'              => 'Migration 056_rbac_bridge_audit.sql has not been applied yet.',
    ]);
}

$windowHours = max(1, min(168, (int) (api_query('window_hours') ?? 24)));

// Totals + directional split.
$st = $pdo->prepare(
    'SELECT
        COUNT(*)                                          AS total,
        SUM(CASE WHEN legacy_ok = 1 AND new_ok = 0 THEN 1 ELSE 0 END) AS legacy_only,
        SUM(CASE WHEN legacy_ok = 0 AND new_ok = 1 THEN 1 ELSE 0 END) AS new_only
       FROM rbac_bridge_audit
      WHERE tenant_id = :t
        AND occurred_at >= (NOW() - INTERVAL :h HOUR)'
);
$st->execute(['t' => $tenantId, 'h' => $windowHours]);
$tot = $st->fetch(\PDO::FETCH_ASSOC) ?: ['total' => 0, 'legacy_only' => 0, 'new_only' => 0];

// Top-N perms by disagreement count.
$st = $pdo->prepare(
    'SELECT perm, module_key, action, legacy_ok, new_ok, COUNT(*) AS cnt
       FROM rbac_bridge_audit
      WHERE tenant_id = :t
        AND occurred_at >= (NOW() - INTERVAL :h HOUR)
   GROUP BY perm, module_key, action, legacy_ok, new_ok
   ORDER BY cnt DESC, perm ASC
      LIMIT 10'
);
$st->execute(['t' => $tenantId, 'h' => $windowHours]);
$top = array_map(static function (array $r): array {
    return [
        'perm'      => (string) $r['perm'],
        'module'    => (string) $r['module_key'],
        'action'    => (string) $r['action'],
        'legacy_ok' => (int) $r['legacy_ok'] === 1,
        'new_ok'    => (int) $r['new_ok']    === 1,
        'count'     => (int) $r['cnt'],
    ];
}, $st->fetchAll(\PDO::FETCH_ASSOC) ?: []);

// Recent samples (just the latest 20, useful for a "show me an example" link).
$st = $pdo->prepare(
    'SELECT id, perm, module_key, action, legacy_ok, new_ok, user_id, occurred_at
       FROM rbac_bridge_audit
      WHERE tenant_id = :t
        AND occurred_at >= (NOW() - INTERVAL :h HOUR)
   ORDER BY occurred_at DESC, id DESC
      LIMIT 20'
);
$st->execute(['t' => $tenantId, 'h' => $windowHours]);
$recent = array_map(static function (array $r): array {
    return [
        'id'          => (int) $r['id'],
        'perm'        => (string) $r['perm'],
        'module'      => (string) $r['module_key'],
        'action'      => (string) $r['action'],
        'legacy_ok'   => (int) $r['legacy_ok'] === 1,
        'new_ok'      => (int) $r['new_ok']    === 1,
        'user_id'     => $r['user_id'] !== null ? (int) $r['user_id'] : null,
        'occurred_at' => (string) $r['occurred_at'],
    ];
}, $st->fetchAll(\PDO::FETCH_ASSOC) ?: []);

api_ok([
    'configured'          => true,
    'window_hours'        => $windowHours,
    'total_disagreements' => (int) $tot['total'],
    'legacy_only_grants'  => (int) ($tot['legacy_only'] ?? 0),
    'new_only_grants'     => (int) ($tot['new_only']    ?? 0),
    'top_perms'           => $top,
    'recent'              => $recent,
]);
