<?php
/**
 * QBO Slice 4b — Item mirror (pull) + default-item helper.
 *
 * QBO requires every Invoice line to reference an Item (Product/Service),
 * not just an account. We pull the QBO Item list into
 * external_entity_mappings (entity_type='item') so the Invoice pusher
 * can map CoreFlux placements / line types to a stable QBO Item.
 *
 * `qboDefaultItemRef()` returns a fallback for tenants who haven't yet
 * built per-line mappings: the first Service-type Active item, otherwise
 * the first Active item, otherwise null (which forces the Invoice to be
 * skipped with a clear reason).
 *
 * Public surface:
 *   qboSyncItems(int $tid, ?int $userId, array $opts=[]): array
 *   qboDefaultItemRef(int $tid): ?array  // {value, name}
 *   qboItemRefForPlacement(int $tid, ?int $placementId): ?array
 */
declare(strict_types=1);

require_once __DIR__ . '/client.php';
require_once __DIR__ . '/sync_je.php'; // for QBO_SOURCE
require_once __DIR__ . '/../integrations/entity_mappings.php';

function qboSyncItems(int $tenantId, ?int $userId, array $opts = []): array
{
    $start = microtime(true);
    $limit    = max(1, min(5000, (int) ($opts['limit'] ?? 1000)));
    $maxPages = max(1, min(50,   (int) ($opts['max_pages'] ?? 10)));

    $conn = qboConnection($tenantId);
    if (!$conn || $conn['status'] !== 'active') {
        throw new \RuntimeException('QuickBooks is not connected for this tenant');
    }
    $realm = (string) $conn['realm_id'];

    $startPos = 1; $pulled = 0; $pages = 0;
    $newlyMapped = 0; $unchanged = 0;
    $services = 0; $other = 0;

    while ($pulled < $limit && $pages < $maxPages) {
        $pages++;
        $pageSize = min(100, $limit - $pulled);
        $query = sprintf('SELECT * FROM Item STARTPOSITION %d MAXRESULTS %d', $startPos, $pageSize);
        $resp = qboCall($tenantId, 'GET', '/v3/company/' . $realm . '/query', null, [
            'query'        => $query,
            'minorversion' => 65,
        ]);
        $rows = $resp['QueryResponse']['Item'] ?? [];
        if (!is_array($rows) || count($rows) === 0) break;
        foreach ($rows as $qbo) {
            $qboId = (string) ($qbo['Id'] ?? '');
            if ($qboId === '') continue;
            $type = strtolower((string) ($qbo['Type'] ?? ''));
            if ($type === 'service') $services++; else $other++;
            // Items map to QBO Id but have no natural CoreFlux counterpart
            // (we don't model Products/Services as first-class rows). Use
            // internal_entity_id = 0 as a sentinel meaning "QBO-only".
            $up = mappingUpsert($tenantId, QBO_SOURCE, 'item', $qboId, 0, $qbo, 'pull');
            if ($up['changed']) $newlyMapped++; else $unchanged++;
        }
        $pulled += count($rows);
        if (count($rows) < $pageSize) break;
        $startPos += count($rows);
    }
    $latency = (int) round((microtime(true) - $start) * 1000);
    qboAudit($tenantId, 'sync_items', [
        'entity_type' => 'item', 'direction' => 'pull', 'ok' => true,
        'actor_user_id' => $userId,
        'items_processed' => $newlyMapped, 'items_skipped' => $unchanged,
        'detail' => ['pulled' => $pulled, 'pages' => $pages, 'services' => $services, 'other' => $other, 'latency_ms' => $latency],
    ]);
    return [
        'pulled' => $pulled, 'pages' => $pages,
        'newly_mapped' => $newlyMapped, 'unchanged' => $unchanged,
        'services' => $services, 'other' => $other,
        'latency_ms' => $latency,
    ];
}

/**
 * Pick a sensible default item for invoice lines when no per-placement
 * mapping exists. Caches the choice per request.
 */
function qboDefaultItemRef(int $tenantId): ?array
{
    static $cache = [];
    if (array_key_exists($tenantId, $cache)) return $cache[$tenantId];
    $pdo = getDB();
    $stmt = $pdo->prepare(
        "SELECT external_id, payload_snapshot
           FROM external_entity_mappings
          WHERE tenant_id = :t
            AND source_system = :s
            AND internal_entity_type = 'item'
          ORDER BY id ASC"
    );
    $stmt->execute(['t' => $tenantId, 's' => QBO_SOURCE]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    $serviceHit = null; $anyActive = null;
    foreach ($rows as $r) {
        $snap = $r['payload_snapshot'] ? json_decode((string) $r['payload_snapshot'], true) : null;
        if (!is_array($snap)) continue;
        if (isset($snap['Active']) && !$snap['Active']) continue;
        $ref = ['value' => (string) $r['external_id'], 'name' => (string) ($snap['Name'] ?? '')];
        if (strtolower((string) ($snap['Type'] ?? '')) === 'service' && $serviceHit === null) $serviceHit = $ref;
        if ($anyActive === null) $anyActive = $ref;
    }
    return $cache[$tenantId] = $serviceHit ?? $anyActive;
}

/**
 * Per-placement override: if a tenant has explicitly mapped a CoreFlux
 * placement to a QBO Item we use that, otherwise fall back to the default.
 */
function qboItemRefForPlacement(int $tenantId, ?int $placementId): ?array
{
    if ($placementId) {
        $existing = mappingFindExternal($tenantId, QBO_SOURCE, 'placement_item', $placementId);
        if ($existing) {
            $snap = $existing['payload_snapshot'] ? json_decode((string) $existing['payload_snapshot'], true) : null;
            return ['value' => (string) $existing['external_id'], 'name' => is_array($snap) ? (string) ($snap['Name'] ?? '') : ''];
        }
    }
    return qboDefaultItemRef($tenantId);
}
