<?php
/**
 * /api/admin/membership_audit.php — recent access-change log for SoD review.
 *
 * Feeds the "Recent access changes" panel on the tenant-admin landing.
 * Pulls the last N rows from membership_audit for the active tenant and
 * left-joins user + membership context so the UI can render
 *   "Kunal granted Accounting:admin to Sarah at 14:02"
 * without a second round-trip.
 *
 *   GET /api/admin/membership_audit.php
 *     ?limit=10            (default 10, max 100)
 *     &membership_id=N     (optional — filter to one membership)
 *
 * Auth: tenant_admin, master_admin, or platform global admin.
 * Read-only.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';

$ctx      = api_require_auth();
$tenantId = (int) ($ctx['tenant_id'] ?? 0);
if (!$tenantId) api_error('No active tenant', 400);
if (api_method() !== 'GET') api_error('Method not allowed', 405);

$role           = (string) ($ctx['role'] ?? 'employee');
$isGlobalAdmin  = (bool) ($ctx['is_global_admin'] ?? false);
if (!$isGlobalAdmin && !in_array($role, ['master_admin', 'tenant_admin'], true)) {
    api_error('Forbidden — admin only', 403);
}

$pdo = getDB();
if (!$pdo) api_error('No database connection', 500);

try {
    $pdo->query('SELECT 1 FROM membership_audit LIMIT 0');
} catch (\Throwable $_) {
    api_ok(['configured' => false, 'entries' => [],
            'reason' => 'Migration 055_rbac_memberships.sql has not been applied.']);
}

$limit = max(1, min(100, (int) (api_query('limit') ?? 10)));
$membershipId = api_query('membership_id') !== null ? (int) api_query('membership_id') : null;

$sql = 'SELECT ma.id, ma.tenant_id, ma.membership_id, ma.action,
               ma.actor_user_id, ma.target_user_id, ma.detail, ma.occurred_at,
               au.name  AS actor_name,  au.email AS actor_email,
               tu.name  AS target_name, tu.email AS target_email,
               tm.persona_label, tm.persona_type
          FROM membership_audit ma
     LEFT JOIN users au ON au.id = ma.actor_user_id
     LEFT JOIN users tu ON tu.id = ma.target_user_id
     LEFT JOIN tenant_memberships tm ON tm.id = ma.membership_id
         WHERE ma.tenant_id = :t';
$bind = ['t' => $tenantId];
if ($membershipId !== null) {
    $sql .= ' AND ma.membership_id = :m';
    $bind['m'] = $membershipId;
}
$sql .= ' ORDER BY ma.occurred_at DESC, ma.id DESC LIMIT ' . $limit;

$stmt = $pdo->prepare($sql);
$stmt->execute($bind);
$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

$entries = array_map(static function (array $r): array {
    $detail = $r['detail'] !== null ? (json_decode((string) $r['detail'], true) ?: null) : null;
    return [
        'id'             => (int) $r['id'],
        'membership_id'  => $r['membership_id'] !== null ? (int) $r['membership_id'] : null,
        'action'         => (string) $r['action'],
        'actor'          => [
            'user_id' => $r['actor_user_id'] !== null ? (int) $r['actor_user_id'] : null,
            'name'    => $r['actor_name']  ?? null,
            'email'   => $r['actor_email'] ?? null,
        ],
        'target'         => [
            'user_id' => $r['target_user_id'] !== null ? (int) $r['target_user_id'] : null,
            'name'    => $r['target_name']  ?? null,
            'email'   => $r['target_email'] ?? null,
        ],
        'persona_label'  => $r['persona_label'] ?? null,
        'persona_type'   => $r['persona_type'] ?? null,
        'detail'         => $detail,
        'occurred_at'    => (string) $r['occurred_at'],
    ];
}, $rows);

api_ok([
    'configured' => true,
    'tenant_id'  => $tenantId,
    'limit'      => $limit,
    'entries'    => $entries,
]);
