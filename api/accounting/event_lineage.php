<?php
/**
 * /api/accounting/event_lineage — drill the causal chain.
 *
 *   GET ?event_id=N&direction=ancestors|descendants|both[&max_depth=10]
 *       → tree of events related to N (BFS, cycle-safe)
 *   GET ?event_id=N&root=1
 *       → just the originating event of this chain
 *
 *   POST { parent_event_id, child_event_id, relationship_type? }
 *       → manual link (idempotent). Used by AI agents OR humans correcting
 *         missed lineage. Validates against event_registry.parent_event_types
 *         if both events are typed — warns when the link breaks declared
 *         lineage but does NOT block (architecture doc allows override).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/event_lineage.php';

$ctx      = api_require_auth();
$user     = $ctx['user'];
$userId   = (int) ($user['id'] ?? 0);
$tenantId = (int) (currentTenantId() ?? 0);
if (!$tenantId) api_error('No active tenant', 400);

$method = api_method();

if ($method === 'GET') {
    $eventId   = (int) api_query('event_id', 0);
    $direction = (string) api_query('direction', 'both');
    $maxDepth  = max(1, min(32, (int) api_query('max_depth', 10)));
    $rootOnly  = (int) api_query('root', 0);
    if (!$eventId) api_error('event_id required', 422);

    if ($rootOnly) {
        api_ok(['root' => eventLineageGetRoot($tenantId, $eventId)]);
    }

    $payload = ['event_id' => $eventId];
    if ($direction === 'ancestors' || $direction === 'both') {
        $payload['ancestors']   = eventLineageGetAncestors($tenantId, $eventId, $maxDepth);
    }
    if ($direction === 'descendants' || $direction === 'both') {
        $payload['descendants'] = eventLineageGetDescendants($tenantId, $eventId, $maxDepth);
    }
    $payload['direct_parents']  = eventLineageGetParents($tenantId, $eventId);
    $payload['direct_children'] = eventLineageGetChildren($tenantId, $eventId);
    api_ok($payload);
}

if ($method === 'POST') {
    $body         = api_json_body();
    $parentId     = (int) ($body['parent_event_id'] ?? 0);
    $childId      = (int) ($body['child_event_id']  ?? 0);
    $relationship = trim((string) ($body['relationship_type'] ?? 'spawned_by'));
    if (!$parentId || !$childId) api_error('parent_event_id + child_event_id required', 422);

    // Optional registry validation — warn but never block.
    $warnings = [];
    $pdo = getDB();
    try {
        $stmt = $pdo->prepare(
            "SELECT id, event_type FROM accounting_events
              WHERE tenant_id = :t AND id IN (:p, :c) LIMIT 2"
        );
        // Named placeholders can't be repeated for IN — fall back to positional.
        $stmt = $pdo->prepare(
            "SELECT id, event_type FROM accounting_events
              WHERE tenant_id = ? AND id IN (?, ?) LIMIT 2"
        );
        $stmt->execute([$tenantId, $parentId, $childId]);
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        $childType  = (string) ($rows[$childId]  ?? '');
        $parentType = (string) ($rows[$parentId] ?? '');
        if ($childType !== '' && $parentType !== ''
            && !eventLineageValidateParentType($childType, $parentType)) {
            $warnings[] = "Registry does not list '{$parentType}' as a valid parent of '{$childType}' — link recorded anyway";
        }
    } catch (\Throwable $_) { /* best-effort */ }

    $created = eventLineageLink($tenantId, $parentId, $childId, $relationship, $userId);
    api_ok([
        'linked'   => $created,
        'warnings' => $warnings,
    ], $created ? 201 : 200);
}

api_error('Method not allowed', 405);
