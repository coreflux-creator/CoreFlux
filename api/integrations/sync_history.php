<?php
/**
 * GET /api/integrations/sync_history.php?entity_type=placement&internal_id=N[&limit=50]
 *
 * Returns the most recent change rows for a CoreFlux entity across all
 * source integrations. Powers the "Sync history" drawer on
 * placement / person / company detail pages — see
 * /app/dashboard/src/components/SyncHistoryDrawer.jsx.
 *
 * Each row carries `payload_before` + `payload_after` decoded as
 * objects so the drawer can diff them client-side and surface
 * field-level changes (e.g. "title: 'Service Desk Analyst' →
 * 'Senior Service Desk Analyst'").
 *
 * RBAC: same `integrations.read` permission used by the existing
 * list_for_internal endpoint — anyone who can see the linked-systems
 * panel can see what changed.
 *
 * Response envelope:
 *   { rows: [ { ...history row..., actor: {id, email}? } ] }
 *
 * Actors are resolved server-side so the UI doesn't need to round-trip
 * for user names.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/RBAC.php';
require_once __DIR__ . '/../../core/integrations/entity_mappings.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];
rbac_legacy_require($user, 'integrations.read');

if (api_method() !== 'GET') api_error('Method not allowed', 405);

$entityType = trim((string) (api_query('entity_type') ?? ''));
$internalId = (int) (api_query('internal_id') ?? 0);
$limit      = (int) (api_query('limit') ?? 50);

if ($entityType === '') api_error('entity_type required', 422);
if ($internalId <= 0)   api_error('internal_id required', 422);

// Graceful handling for the deploy-window gap where the endpoint code is
// live but migration 069 hasn't applied yet (e.g. PHP-FPM workers still
// holding a stale $ranOnce cache). Return empty rows + a hint flag so
// the drawer renders the empty state instead of a 500.
try {
    $rows = entitySyncHistoryList($tid, $entityType, $internalId, $limit);
} catch (\PDOException $e) {
    if (str_contains($e->getMessage(), 'entity_sync_history') && str_contains($e->getMessage(), "doesn't exist")) {
        api_ok([
            'rows' => [],
            'migration_pending' => true,
            'hint' => 'Migration 069_entity_sync_history.sql has not applied yet. '
                . 'Master admin can POST /api/admin/migrate.php to force-rerun.',
        ]);
    }
    throw $e;
}

// Resolve actor emails in one query (avoid N+1 for the drawer load).
$actorIds = [];
foreach ($rows as $r) {
    if ($r['actor_user_id'] !== null) $actorIds[(int) $r['actor_user_id']] = true;
}
$actorMap = [];
if (!empty($actorIds)) {
    $ids = array_keys($actorIds);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $pdo = getDB();
    // Bounded by the rows we already filtered by tenant; users table
    // does not vary by tenant on emergent schema. If a tighter scope
    // is needed later, JOIN against user_tenants / tenant_memberships.
    $stmt = $pdo->prepare("SELECT id, email FROM users WHERE id IN ({$placeholders})");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $u) {
        $actorMap[(int) $u['id']] = ['id' => (int) $u['id'], 'email' => (string) $u['email']];
    }
}
foreach ($rows as &$r) {
    $r['actor'] = ($r['actor_user_id'] !== null && isset($actorMap[(int) $r['actor_user_id']]))
        ? $actorMap[(int) $r['actor_user_id']]
        : null;
}

api_ok(['rows' => $rows]);
