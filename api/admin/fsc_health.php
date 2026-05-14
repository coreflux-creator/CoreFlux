<?php
/**
 * /api/admin/fsc_health.php — Financial State Cache health snapshot.
 *
 * Powers the "Cache Health" section on the CFO Dashboard. Returns:
 *   - rows_cached         : total cache rows for this tenant
 *   - scopes_cached       : distinct (scope_key, scope_value) tuples
 *   - dirty_count         : pending dirty-log entries
 *   - oldest_dirty_age_s  : seconds since the oldest unprocessed dirty
 *   - last_rebuild_at     : timestamp of the most-recent cache write
 *   - newest_metric_age_s : seconds since the most-recent cache write
 *   - per_scope_breakdown : recent computed_ms p50/p95 per scope_key
 *   - top_dirty_reasons   : histogram of dirty reasons last 24h
 *
 * Read-only. Standard auth. No RBAC — same data the CFO Dashboard exposes.
 *
 * Graceful degradation: returns `{ configured: false }` if migration 045
 * hasn't run on this tenant yet.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';

$ctx      = api_require_auth();
$tenantId = (int) ($ctx['tenant_id'] ?? 0);
if (!$tenantId) api_error('No active tenant', 400);
if (api_method() !== 'GET') api_error('Method not allowed', 405);

$pdo = getDB();

try {
    $pdo->query('SELECT 1 FROM financial_state_cache LIMIT 0');
} catch (\Throwable $_) {
    api_ok([
        'configured' => false,
        'reason'     => 'Migration 045_financial_state_cache.sql has not been applied on this tenant.',
    ]);
}

// ── Counts ───────────────────────────────────────────────────────────
$rowsCached = (int) $pdo->prepare(
    'SELECT COUNT(*) FROM financial_state_cache WHERE tenant_id = :t'
)->execute(['t' => $tenantId]) ?: 0;
$stmt = $pdo->prepare('SELECT COUNT(*) FROM financial_state_cache WHERE tenant_id = :t');
$stmt->execute(['t' => $tenantId]);
$rowsCached = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare(
    'SELECT COUNT(DISTINCT CONCAT(scope_key,"|",scope_value))
       FROM financial_state_cache WHERE tenant_id = :t'
);
$stmt->execute(['t' => $tenantId]);
$scopesCached = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT COUNT(*) FROM financial_state_cache_dirty WHERE tenant_id = :t');
$stmt->execute(['t' => $tenantId]);
$dirtyCount = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare(
    'SELECT MIN(marked_at) FROM financial_state_cache_dirty WHERE tenant_id = :t'
);
$stmt->execute(['t' => $tenantId]);
$oldestDirty = $stmt->fetchColumn();
$oldestDirtyAge = $oldestDirty ? (int) (time() - strtotime((string) $oldestDirty)) : null;

$stmt = $pdo->prepare(
    'SELECT MAX(computed_at) FROM financial_state_cache WHERE tenant_id = :t'
);
$stmt->execute(['t' => $tenantId]);
$lastRebuildAt = $stmt->fetchColumn() ?: null;
$newestMetricAge = $lastRebuildAt ? (int) (time() - strtotime((string) $lastRebuildAt)) : null;

// ── Per-scope p50/p95 runtime ────────────────────────────────────────
$stmt = $pdo->prepare(
    'SELECT scope_key,
            COUNT(*)              AS metrics,
            AVG(computed_ms)      AS avg_ms,
            MAX(computed_ms)      AS max_ms,
            MAX(computed_at)      AS last_at
       FROM financial_state_cache
      WHERE tenant_id = :t AND computed_ms IS NOT NULL
      GROUP BY scope_key
      ORDER BY metrics DESC'
);
$stmt->execute(['t' => $tenantId]);
$perScope = [];
foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
    $perScope[] = [
        'scope_key' => $row['scope_key'],
        'metrics'   => (int) $row['metrics'],
        'avg_ms'    => $row['avg_ms']    !== null ? (int) round($row['avg_ms']) : null,
        'max_ms'    => $row['max_ms']    !== null ? (int) $row['max_ms'] : null,
        'last_at'   => $row['last_at'],
    ];
}

// ── Dirty-reason histogram (last 24h) ───────────────────────────────
$stmt = $pdo->prepare(
    'SELECT COALESCE(reason, "unspecified") AS reason, COUNT(*) AS n
       FROM financial_state_cache_dirty
      WHERE tenant_id = :t AND marked_at > NOW() - INTERVAL 1 DAY
      GROUP BY reason
      ORDER BY n DESC
      LIMIT 10'
);
$stmt->execute(['t' => $tenantId]);
$topDirtyReasons = [];
foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
    $topDirtyReasons[] = ['reason' => $row['reason'], 'count' => (int) $row['n']];
}

api_ok([
    'configured'          => true,
    'rows_cached'         => $rowsCached,
    'scopes_cached'       => $scopesCached,
    'dirty_count'         => $dirtyCount,
    'oldest_dirty_age_s'  => $oldestDirtyAge,
    'last_rebuild_at'     => $lastRebuildAt,
    'newest_metric_age_s' => $newestMetricAge,
    'per_scope'           => $perScope,
    'top_dirty_reasons'   => $topDirtyReasons,
    'snapshot_at'         => date('c'),
]);
