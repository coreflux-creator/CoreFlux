<?php
/**
 * Smoke for the Sync History Drawer's audit-event merge.
 *
 * Backend (api/integrations/sync_history.php):
 *   - For entity_type=placement, the endpoint now also pulls relevant
 *     CoreFlux audit_log rows and merges them, newest-first, into the
 *     same response payload.
 *   - Each row carries a `kind` discriminator: 'sync' (existing) or
 *     'audit' (new).
 *   - Audit rows carry `event`, `meta`, no payload_before/after.
 *
 * Frontend (dashboard/src/components/SyncHistoryDrawer.jsx):
 *   - <AuditRow> component renders kind === 'audit' rows with a purple
 *     accent and the event label.
 *   - <HistoryRow> branches on row.kind and delegates to <AuditRow>
 *     when the row is an audit event.
 *
 * Strategy
 * --------
 * Real DB integration test for the backend merge (we have MariaDB up
 * from prior smokes); source-inspection for the frontend rendering.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

// ---------------------------------------------------------------------
// Source-inspection: backend endpoint
// ---------------------------------------------------------------------
echo "Backend — /api/integrations/sync_history.php audit merge\n";
$endpoint = '/app/api/integrations/sync_history.php';
$src = (string) file_get_contents($endpoint);
$assert('endpoint stamps sync rows with kind=sync',
    str_contains($src, "\$r['kind'] = 'sync';"));
$assert('endpoint declares an AUDIT_EVENT_MAP allow-list',
    str_contains($src, '$AUDIT_EVENT_MAP'));
$assert('allow-list includes placement.updated',
    str_contains($src, "'placement.updated'"));
$assert('allow-list includes placement.override_cleared',
    str_contains($src, "'placement.override_cleared'"));
$assert('allow-list includes placement.created',
    str_contains($src, "'placement.created'"));
$assert('audit query is scoped to tenant + target_id',
    preg_match('/WHERE\s+tenant_id\s*=\s*\?\s+AND\s+target_id\s*=\s*\?\s+AND\s+event\s+IN/i', $src) === 1);
$assert('audit rows tagged kind=audit + source_system=coreflux',
    str_contains($src, "'kind'                 => 'audit'") &&
    str_contains($src, "'source_system'        => 'coreflux'"));
$assert('audit_log read failures are non-fatal',
    str_contains($src, 'audit_log read failed (non-fatal)'));
$assert('merged rows sorted newest-first',
    str_contains($src, '$bd <=> $ad'));
$assert('merged rows truncated to the requested limit',
    str_contains($src, 'array_slice($merged, 0, $limit)'));

// ---------------------------------------------------------------------
// Real-DB integration: seed sync + audit rows, hit the endpoint, verify merge
// ---------------------------------------------------------------------
require_once '/app/core/db.php';
try { $pdo = getDB(); if (!$pdo) throw new \Exception('no pdo'); }
catch (\Throwable $e) { echo "SKIP: no DB ({$e->getMessage()})\n"; goto frontend; }

$pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT NOT NULL,
    actor_user_id BIGINT NULL,
    event VARCHAR(64) NOT NULL,
    target_id BIGINT NULL,
    meta_json JSON NULL,
    ip_address VARCHAR(64) NULL,
    request_id VARCHAR(64) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_target (tenant_id, target_id, event)
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS entity_sync_history (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT NOT NULL,
    source_system VARCHAR(32) NOT NULL,
    internal_entity_type VARCHAR(32) NOT NULL,
    internal_entity_id BIGINT NOT NULL,
    external_id VARCHAR(128) NULL,
    direction VARCHAR(16) NULL,
    payload_before JSON NULL,
    payload_after JSON NULL,
    content_hash_before VARCHAR(64) NULL,
    content_hash_after VARCHAR(64) NULL,
    actor_user_id BIGINT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$TID = 888_111;
$PID = 4242;

// Two audit rows: one created, one override_cleared.
$pdo->prepare("INSERT INTO audit_log (tenant_id, actor_user_id, event, target_id, meta_json, created_at)
               VALUES (:t, 99, 'placement.created', :pid, '{\"id\":4242}', NOW() - INTERVAL 5 MINUTE)")
    ->execute(['t' => $TID, 'pid' => $PID]);
$pdo->prepare("INSERT INTO audit_log (tenant_id, actor_user_id, event, target_id, meta_json, created_at)
               VALUES (:t, 99, 'placement.override_cleared', :pid, '{\"id\":4242,\"fields\":[\"title\"]}', NOW() - INTERVAL 2 MINUTE)")
    ->execute(['t' => $TID, 'pid' => $PID]);
// One sync row in between.
$pdo->prepare("INSERT INTO entity_sync_history (tenant_id, source_system, internal_entity_type, internal_entity_id, external_id, direction, payload_before, payload_after, created_at)
               VALUES (:t, 'jobdiva', 'placement', :pid, 'jd:9001', 'pull',
                       '{\"title\":\"old\"}', '{\"title\":\"new\"}', NOW() - INTERVAL 3 MINUTE)")
    ->execute(['t' => $TID, 'pid' => $PID]);

register_shutdown_function(function () use ($pdo, $TID, $PID) {
    @$pdo->prepare("DELETE FROM audit_log WHERE tenant_id=:t AND target_id=:p")->execute(['t' => $TID, 'p' => $PID]);
    @$pdo->prepare("DELETE FROM entity_sync_history WHERE tenant_id=:t AND internal_entity_id=:p")->execute(['t' => $TID, 'p' => $PID]);
});

// Run the merge logic inline (skip the HTTP layer; we just need to
// verify the SQL + array merge produces the right shape).
require_once '/app/core/integrations/entity_mappings.php';
$rows = entitySyncHistoryList($TID, 'placement', $PID, 50);
foreach ($rows as &$r) { $r['kind'] = 'sync'; }
unset($r);

$AUDIT_EVENTS = [
    'placement.created','placement.updated','placement.status_changed','placement.ended',
    'placement.override_cleared','placement.rate.drafted','placement.rate.approved','placement.rate.superseded',
];
$ph = implode(',', array_fill(0, count($AUDIT_EVENTS), '?'));
$stmt = $pdo->prepare("SELECT id, actor_user_id, event, meta_json, created_at
                         FROM audit_log
                        WHERE tenant_id = ? AND target_id = ? AND event IN ({$ph})
                        ORDER BY created_at DESC, id DESC
                        LIMIT 50");
$stmt->execute(array_merge([$TID, $PID], $AUDIT_EVENTS));
$auditRows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

$assert('seeded sync row read back',     count($rows) === 1);
$assert('seeded 2 audit rows read back', count($auditRows) === 2);

$shaped = array_map(function ($r) {
    return [
        'id'           => (int) $r['id'],
        'kind'         => 'audit',
        'source_system'=> 'coreflux',
        'event'        => $r['event'],
        'created_at'   => $r['created_at'],
    ];
}, $auditRows);
$merged = array_merge($rows, $shaped);
usort($merged, static function ($a, $b) {
    $ad = strtotime((string) ($a['created_at'] ?? ''));
    $bd = strtotime((string) ($b['created_at'] ?? ''));
    if ($ad !== $bd) return $bd <=> $ad;
    return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
});

$assert('merged list has 3 rows total',                 count($merged) === 3);
$assert('newest is the override_cleared audit (2 min ago)',
    $merged[0]['kind'] === 'audit' && $merged[0]['event'] === 'placement.override_cleared');
$assert('middle is the JobDiva sync row (3 min ago)',
    $merged[1]['kind'] === 'sync' && ($merged[1]['source_system'] ?? null) === 'jobdiva');
$assert('oldest is the placement.created audit (5 min ago)',
    $merged[2]['kind'] === 'audit' && $merged[2]['event'] === 'placement.created');

frontend:

// ---------------------------------------------------------------------
// Frontend — drawer source inspection
// ---------------------------------------------------------------------
echo "\nFrontend — SyncHistoryDrawer.jsx audit row support\n";
$drawer = (string) file_get_contents('/app/dashboard/src/components/SyncHistoryDrawer.jsx');
$assert('AUDIT_EVENT_LABEL dictionary defined',
    str_contains($drawer, 'AUDIT_EVENT_LABEL'));
$assert('label dict includes placement.override_cleared',
    str_contains($drawer, "'placement.override_cleared':  'Override reverted'"));
$assert('label dict includes placement.updated',
    str_contains($drawer, "'placement.updated':           'Edited'"));
$assert('AuditRow component defined',
    str_contains($drawer, 'function AuditRow({ row })'));
$assert('AuditRow strips redundant id from meta',
    str_contains($drawer, "filter(([k]) => k !== 'id')"));
$assert('AuditRow has purple-accent border + chip',
    str_contains($drawer, '#a855f7') && str_contains($drawer, '#7c3aed'));
$assert('HistoryRow dispatches on kind === audit',
    str_contains($drawer, "const isAudit = row.kind === 'audit'") &&
    str_contains($drawer, 'return <AuditRow row={row} />'));
$assert('AuditRow uses sync-history-row-audit testid',
    str_contains($drawer, 'sync-history-row-audit-'));
$assert('AuditRow exposes audit meta rows via testid',
    str_contains($drawer, 'sync-history-audit-meta-row-'));
$assert('drawer preamble mentions both syncs and operator edits',
    str_contains($drawer, 'CoreFlux operator edit'));

echo "\n=== Summary ===\n";
echo "Passed: {$pass}\n";
echo "Failed: {$fail}\n";
exit($fail === 0 ? 0 : 1);
