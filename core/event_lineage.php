<?php
/**
 * Event Lineage helper (Phase 1c — 2026-02-14).
 *
 * Causal chain over `accounting_events`. Backed by `event_lineage` (M:N).
 *
 * Public API:
 *   eventLineageLink($tenantId, $parentEventId, $childEventId, $relationshipType='spawned_by', $actorUserId=null)
 *     → idempotent INSERT IGNORE
 *
 *   eventLineageGetParents($tenantId, $eventId)         → direct parents only
 *   eventLineageGetChildren($tenantId, $eventId)        → direct children only
 *
 *   eventLineageGetAncestors($tenantId, $eventId, $maxDepth=10)
 *     → full ancestor chain (BFS upward). Each row carries depth + event row data.
 *
 *   eventLineageGetDescendants($tenantId, $eventId, $maxDepth=10)
 *     → full descendant tree (BFS downward).
 *
 *   eventLineageGetRoot($tenantId, $eventId)
 *     → the originating event of this causal chain (deepest ancestor).
 *
 *   eventLineageValidateParentType($childEventType, $parentEventType)
 *     → bool — does the registry allow this parent? Used at link time.
 *
 * All readers degrade gracefully when `event_lineage` is missing
 * (returns [] / null).
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/event_registry.php';

function _eventLineageTableExists(?\PDO $pdo = null): bool {
    static $cache = null;
    if ($cache !== null) return $cache;
    $pdo = $pdo ?: getDB();
    try {
        $pdo->query('SELECT 1 FROM event_lineage LIMIT 0');
        return $cache = true;
    } catch (\Throwable $_) {
        return $cache = false;
    }
}

function eventLineageLink(int $tenantId, int $parentEventId, int $childEventId,
                          string $relationshipType = 'spawned_by',
                          ?int $actorUserId = null): bool {
    if (!_eventLineageTableExists())                return false;
    if ($parentEventId <= 0 || $childEventId <= 0) return false;
    if ($parentEventId === $childEventId)          return false; // no self-loops

    $pdo  = getDB();
    $stmt = $pdo->prepare(
        "INSERT IGNORE INTO event_lineage
            (tenant_id, parent_event_id, child_event_id, relationship_type, created_by_user_id)
         VALUES (:t, :p, :c, :r, :u)"
    );
    $stmt->execute([
        't' => $tenantId, 'p' => $parentEventId, 'c' => $childEventId,
        'r' => $relationshipType, 'u' => $actorUserId,
    ]);
    return $stmt->rowCount() > 0;
}

function eventLineageGetParents(int $tenantId, int $eventId): array {
    return _eventLineageEdgeFetch($tenantId, $eventId, 'parent');
}

function eventLineageGetChildren(int $tenantId, int $eventId): array {
    return _eventLineageEdgeFetch($tenantId, $eventId, 'child');
}

function _eventLineageEdgeFetch(int $tenantId, int $eventId, string $direction): array {
    if (!_eventLineageTableExists()) return [];
    $pdo = getDB();
    if ($direction === 'parent') {
        // I am the child; find my parents.
        $sql = "SELECT el.parent_event_id AS related_event_id,
                       el.relationship_type,
                       ae.event_type, ae.source_module, ae.source_record_id,
                       ae.event_date, ae.status, ae.journal_entry_id
                  FROM event_lineage el
                  JOIN accounting_events ae
                    ON ae.id = el.parent_event_id AND ae.tenant_id = el.tenant_id
                 WHERE el.tenant_id = :t AND el.child_event_id = :e
                 ORDER BY ae.event_date ASC, el.id ASC";
    } else {
        $sql = "SELECT el.child_event_id AS related_event_id,
                       el.relationship_type,
                       ae.event_type, ae.source_module, ae.source_record_id,
                       ae.event_date, ae.status, ae.journal_entry_id
                  FROM event_lineage el
                  JOIN accounting_events ae
                    ON ae.id = el.child_event_id AND ae.tenant_id = el.tenant_id
                 WHERE el.tenant_id = :t AND el.parent_event_id = :e
                 ORDER BY ae.event_date ASC, el.id ASC";
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['t' => $tenantId, 'e' => $eventId]);
    return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
}

/**
 * BFS traversal. Returns rows ordered by depth ascending, with
 * `depth`, `path[]` (event_ids walked to get here), and `relationship_type`.
 * Cycle-safe (skips already-visited event ids).
 */
function _eventLineageWalk(int $tenantId, int $startEventId, string $direction, int $maxDepth): array {
    if (!_eventLineageTableExists()) return [];
    $visited = [$startEventId => true];
    $frontier = [[$startEventId, [], null]];
    $out      = [];

    for ($depth = 1; $depth <= $maxDepth && !empty($frontier); $depth++) {
        $nextFrontier = [];
        foreach ($frontier as [$nodeId, $path, $_rel]) {
            $neighbors = $direction === 'up'
                ? eventLineageGetParents($tenantId, $nodeId)
                : eventLineageGetChildren($tenantId, $nodeId);
            foreach ($neighbors as $n) {
                $nid = (int) $n['related_event_id'];
                if (isset($visited[$nid])) continue;
                $visited[$nid] = true;
                $newPath = array_merge($path, [$nodeId]);
                $row = $n;
                $row['depth']    = $depth;
                $row['path_ids'] = $newPath;
                $out[]           = $row;
                $nextFrontier[]  = [$nid, $newPath, $n['relationship_type']];
            }
        }
        $frontier = $nextFrontier;
    }
    return $out;
}

function eventLineageGetAncestors(int $tenantId, int $eventId, int $maxDepth = 10): array {
    return _eventLineageWalk($tenantId, $eventId, 'up', $maxDepth);
}

function eventLineageGetDescendants(int $tenantId, int $eventId, int $maxDepth = 10): array {
    return _eventLineageWalk($tenantId, $eventId, 'down', $maxDepth);
}

function eventLineageGetRoot(int $tenantId, int $eventId): ?array {
    $ancestors = eventLineageGetAncestors($tenantId, $eventId, 32);
    if (empty($ancestors)) return null;
    // Return the deepest ancestor (highest depth value).
    usort($ancestors, fn ($a, $b) => $b['depth'] <=> $a['depth']);
    return $ancestors[0];
}

/**
 * Registry-enforced lineage validation. If the child event_type declares
 * `parent_event_types` in the registry and the proposed parent's type is
 * NOT in that list, link should be flagged (but not necessarily blocked —
 * the architecture doc allows unusual lineage with explicit override).
 */
function eventLineageValidateParentType(string $childEventType, string $parentEventType): bool {
    $childRow = eventRegistryGet($childEventType);
    if (!$childRow) return true; // Registry not seeded — allow
    $allowed  = $childRow['parent_event_types'] ?? [];
    if (empty($allowed)) return true; // No constraints declared — allow
    return in_array($parentEventType, $allowed, true);
}
