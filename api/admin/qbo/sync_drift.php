<?php
/**
 * GET  /api/admin/qbo/sync_drift.php
 *      → List unresolved (or filtered) drift rows for the tenant.
 *        Filters: status (open|acknowledged|reconciled|dismissed|all),
 *                 severity (info|warn|critical|all),
 *                 entity_type (invoice|bill|payment_paid_out_of_band|all).
 *
 * POST /api/admin/qbo/sync_drift.php
 *      → Resolve a drift row. Body:
 *           { drift_id, resolution ('acknowledged'|'reconciled'|'dismissed'),
 *             note? }
 *      → Stamps resolved_by_user_id + resolved_at. The resolution choice
 *        does NOT auto-apply anything to CoreFlux — operator workflow is:
 *          - 'acknowledged' → "I see it, fix coming"
 *          - 'reconciled'   → "I've manually aligned CoreFlux to QBO"
 *          - 'dismissed'    → "Expected drift, don't show me again"
 *
 * RBAC: master_admin / tenant_admin / wildcard.
 * Mirrors the QBO DLQ + Mercury Failed-PI endpoints in shape so the
 * future unified Integration Triage page renders all three from one
 * component.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/rbac/legacy_map.php';

$ctx = api_require_auth();
rbac_legacy_require_any($currentUser ?? $ctx, ['master_admin', 'tenant_admin', '*']);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $tenantId = isset($_GET['tenant_id']) ? (int) $_GET['tenant_id'] : (int) ($ctx['tenant_id'] ?? 0);
    if ($tenantId <= 0) { http_response_code(400); api_error('tenant_id required', 400); }

    $status   = $_GET['status']      ?? 'open';
    $severity = $_GET['severity']    ?? 'all';
    $entity   = $_GET['entity_type'] ?? 'all';
    $limit    = max(1, min(500, (int) ($_GET['limit'] ?? 100)));

    $sql = 'SELECT id, entity_type, coreflux_id, qbo_id, drift_kind, severity,
                   coreflux_snapshot, qbo_snapshot, summary, status,
                   resolved_by_user_id, resolved_at, resolution_note,
                   detected_at, last_seen_at
              FROM qbo_sync_drift
             WHERE tenant_id = :t';
    $params = ['t' => $tenantId];
    if ($status !== 'all') {
        $sql .= ' AND status = :st';
        $params['st'] = $status;
    }
    if ($severity !== 'all') {
        $sql .= ' AND severity = :sv';
        $params['sv'] = $severity;
    }
    if ($entity !== 'all') {
        $sql .= ' AND entity_type = :et';
        $params['et'] = $entity;
    }
    $sql .= ' ORDER BY (severity = "critical") DESC, (severity = "warn") DESC, detected_at DESC LIMIT ' . (int) $limit;

    try {
        $stmt = getDB()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        $rows = []; // table missing → empty list
    }
    foreach ($rows as &$r) {
        $r['coreflux_snapshot'] = json_decode((string) $r['coreflux_snapshot'], true) ?: null;
        $r['qbo_snapshot']      = json_decode((string) $r['qbo_snapshot'],      true) ?: null;
    }
    unset($r);

    // Summary counts for the dashboard header pill.
    $counts = ['critical' => 0, 'warn' => 0, 'info' => 0, 'total_open' => 0];
    try {
        $cnt = getDB()->prepare(
            'SELECT severity, COUNT(*) AS c FROM qbo_sync_drift
              WHERE tenant_id = :t AND status = "open" GROUP BY severity'
        );
        $cnt->execute(['t' => $tenantId]);
        foreach ($cnt->fetchAll(\PDO::FETCH_ASSOC) as $c) {
            $counts[(string) $c['severity']] = (int) $c['c'];
            $counts['total_open'] += (int) $c['c'];
        }
    } catch (\Throwable $_) { /* table missing */ }

    api_ok([
        'items'   => $rows,
        'counts'  => $counts,
        'filters' => compact('tenantId', 'status', 'severity', 'entity', 'limit'),
        'generated_at' => gmdate('c'),
    ]);
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $driftId    = (int) ($body['drift_id'] ?? 0);
    $resolution = (string) ($body['resolution'] ?? '');
    $note       = trim((string) ($body['note'] ?? ''));

    if (!$driftId) {
        http_response_code(400);
        api_error('drift_id required', 400);
    }
    if (!in_array($resolution, ['acknowledged', 'reconciled', 'dismissed'], true)) {
        http_response_code(400);
        api_error('resolution must be acknowledged | reconciled | dismissed', 400);
    }
    $tenantId = (int) ($ctx['tenant_id'] ?? 0);
    $userId   = (int) ($ctx['user']['id'] ?? $ctx['user_id'] ?? 0);

    try {
        // tenant-leak-allow: drift_id alone is unique; we additionally
        // bind tenant_id in WHERE so cross-tenant writes are impossible.
        $stmt = getDB()->prepare(
            'UPDATE qbo_sync_drift
                SET status = :s, resolved_by_user_id = :u,
                    resolved_at = :ra, resolution_note = :n
              WHERE id = :id AND tenant_id = :t'
        );
        $stmt->execute([
            's' => $resolution, 'u' => $userId,
            'ra' => date('Y-m-d H:i:s'),
            'n'  => substr($note, 0, 500),
            'id' => $driftId, 't' => $tenantId,
        ]);
        $rows = $stmt->rowCount();
    } catch (\Throwable $e) {
        http_response_code(500);
        api_error('resolve failed: ' . substr($e->getMessage(), 0, 220), 500);
    }
    if ($rows === 0) {
        http_response_code(404);
        api_error('drift row not found or not in this tenant', 404);
    }
    api_ok(['resolved' => true, 'drift_id' => $driftId, 'resolution' => $resolution]);
}

http_response_code(405);
api_error('GET or POST only', 405);
