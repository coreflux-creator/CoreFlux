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
    // Stamp the integration-sync rows with a kind discriminator so the
    // drawer can render them in the same timeline as CoreFlux audit
    // events (added below) without confusing the two row shapes.
    $r['kind'] = 'sync';
}
unset($r);

// ---------------------------------------------------------------------
// CoreFlux audit events for the same entity. These are operator edits
// (placement.updated, placement.override_cleared, placement.created,
// etc.) — *not* integration syncs. We surface them in the same drawer so
// operators see a single chronological timeline of "what changed and
// who/what changed it".
//
// Only enabled for `placement` today; person/company can be added by
// extending $AUDIT_EVENT_MAP. The map serves two purposes: it is the
// allow-list (so a wrong entity_type doesn't dump unrelated events into
// the drawer), AND it controls which events show up where.
// ---------------------------------------------------------------------
$AUDIT_EVENT_MAP = [
    'placement' => [
        'placement.created',
        'placement.updated',
        'placement.status_changed',
        'placement.ended',
        'placement.override_cleared',
        'placement.rate.drafted',
        'placement.rate.approved',
        'placement.rate.superseded',
    ],
];

$auditRows = [];
if (isset($AUDIT_EVENT_MAP[$entityType])) {
    $events = $AUDIT_EVENT_MAP[$entityType];
    $placeholders = implode(',', array_fill(0, count($events), '?'));
    $params = array_merge([$tid, $internalId], $events);
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare(
            "SELECT id, actor_user_id, event, meta_json, created_at
               FROM audit_log
              WHERE tenant_id = ? AND target_id = ? AND event IN ({$placeholders})
              ORDER BY created_at DESC, id DESC
              LIMIT " . max(1, min(500, $limit))
        );
        $stmt->execute($params);
        $auditRows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\PDOException $e) {
        // audit_log absent? Don't break the drawer; just skip the merge.
        error_log('sync_history: audit_log read failed (non-fatal): ' . $e->getMessage());
    }
}

// Backfill actor map with any audit actors not already resolved.
if (!empty($auditRows)) {
    $missing = [];
    foreach ($auditRows as $r) {
        $aid = $r['actor_user_id'] !== null ? (int) $r['actor_user_id'] : null;
        if ($aid !== null && !isset($actorMap[$aid])) $missing[$aid] = true;
    }
    if (!empty($missing)) {
        $ids = array_keys($missing);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT id, email FROM users WHERE id IN ({$placeholders})");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $u) {
            $actorMap[(int) $u['id']] = ['id' => (int) $u['id'], 'email' => (string) $u['email']];
        }
    }
}

// Shape audit rows so they slot into the same timeline as sync rows.
// We don't have a payload_before/payload_after on audit events — they
// represent atomic user actions — so the drawer renders the meta_json
// directly instead of a diff.
$shapedAudit = [];
foreach ($auditRows as $r) {
    $meta = null;
    if (is_string($r['meta_json']) && $r['meta_json'] !== '') {
        $decoded = json_decode($r['meta_json'], true);
        if (is_array($decoded)) $meta = $decoded;
    }
    $actorId = $r['actor_user_id'] !== null ? (int) $r['actor_user_id'] : null;
    $shapedAudit[] = [
        'id'                   => (int) $r['id'],
        'kind'                 => 'audit',
        'source_system'        => 'coreflux',
        'internal_entity_type' => $entityType,
        'internal_entity_id'   => $internalId,
        'external_id'          => null,
        'direction'            => 'internal',
        'payload_before'       => null,
        'payload_after'        => null,
        'content_hash_before'  => null,
        'content_hash_after'   => null,
        'actor_user_id'        => $actorId,
        'actor'                => ($actorId !== null && isset($actorMap[$actorId])) ? $actorMap[$actorId] : null,
        'created_at'           => $r['created_at'],
        // Audit-only fields below — drawer reads these when kind === 'audit'.
        'event'                => (string) $r['event'],
        'meta'                 => $meta,
    ];
}

// Merge + sort newest-first across BOTH sources, then truncate to limit.
$merged = array_merge($rows, $shapedAudit);
usort($merged, static function ($a, $b) {
    $ad = strtotime((string) ($a['created_at'] ?? ''));
    $bd = strtotime((string) ($b['created_at'] ?? ''));
    if ($ad !== $bd) return $bd <=> $ad;
    // Tie-break on id within the same source so the order is deterministic.
    return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
});
$merged = array_slice($merged, 0, $limit);

api_ok(['rows' => $merged]);
