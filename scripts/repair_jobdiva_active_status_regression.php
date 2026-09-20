<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/db.php';

$tenantId = isset($argv[1]) ? (int) $argv[1] : 0;
$rawIds = trim((string) ($argv[2] ?? ''));
$expectedRestoreCount = isset($argv[3]) ? (int) $argv[3] : -1;
$expectedActiveTotal = isset($argv[4]) ? (int) $argv[4] : -1;

if ($tenantId <= 0 || $rawIds === '' || $expectedRestoreCount < 1 || $expectedActiveTotal < 1) {
    fwrite(
        STDERR,
        "Usage: php scripts/repair_jobdiva_active_status_regression.php "
        . "TENANT_ID PLACEMENT_IDS EXPECTED_RESTORE_COUNT EXPECTED_ACTIVE_TOTAL\n"
    );
    exit(2);
}

$placementIds = [];
foreach (explode(',', $rawIds) as $rawId) {
    $id = (int) trim($rawId);
    if ($id <= 0) {
        fwrite(STDERR, "Invalid placement ID: {$rawId}\n");
        exit(2);
    }
    $placementIds[$id] = $id;
}
$placementIds = array_values($placementIds);
sort($placementIds, SORT_NUMERIC);

$pdo = getDB();
if (!$pdo) {
    fwrite(STDERR, "Database connection unavailable.\n");
    exit(1);
}

$bindIds = static function (array $ids, string $prefix, array &$params): string {
    $placeholders = [];
    foreach (array_values($ids) as $index => $id) {
        $key = $prefix . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = (int) $id;
    }
    return implode(', ', $placeholders);
};

$today = date('Y-m-d');

try {
    $pdo->beginTransaction();

    $selectParams = ['tenant_id' => $tenantId];
    $selectIn = $bindIds($placementIds, 'placement_', $selectParams);
    $select = $pdo->prepare(
        "SELECT id, status, external_id, start_date, end_date, actual_end_date, deleted_at
           FROM placements
          WHERE tenant_id = :tenant_id
            AND id IN ({$selectIn})
       ORDER BY id ASC
            FOR UPDATE"
    );
    $select->execute($selectParams);
    $rows = $select->fetchAll(PDO::FETCH_ASSOC);

    if (count($rows) !== count($placementIds)) {
        $foundIds = array_map(static fn(array $row): int => (int) $row['id'], $rows);
        $missingIds = array_values(array_diff($placementIds, $foundIds));
        throw new RuntimeException(
            'Refusing repair because historical placement IDs are missing: ' . implode(',', $missingIds)
        );
    }

    $eligibleIds = [];
    foreach ($rows as $row) {
        $deletedAt = trim((string) ($row['deleted_at'] ?? ''));
        $startDate = trim((string) ($row['start_date'] ?? ''));
        $effectiveEndDate = trim((string) ($row['actual_end_date'] ?? ''));
        if ($effectiveEndDate === '') {
            $effectiveEndDate = trim((string) ($row['end_date'] ?? ''));
        }
        $eligible = (string) ($row['status'] ?? '') === 'pending_start'
            && str_starts_with((string) ($row['external_id'] ?? ''), 'jd:')
            && ($deletedAt === '' || $deletedAt === '0000-00-00 00:00:00')
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) === 1
            && $startDate <= $today
            && ($effectiveEndDate === '' || $effectiveEndDate >= $today);
        if ($eligible) {
            $eligibleIds[] = (int) $row['id'];
        }
    }

    if (count($eligibleIds) !== $expectedRestoreCount) {
        throw new RuntimeException(
            "Refusing repair: expected {$expectedRestoreCount} eligible placements, found "
            . count($eligibleIds)
            . ' (' . implode(',', $eligibleIds) . ')'
        );
    }

    $updateParams = [
        'tenant_id' => $tenantId,
        'start_today' => $today,
        'end_today' => $today,
    ];
    $updateIn = $bindIds($eligibleIds, 'restore_', $updateParams);
    $update = $pdo->prepare(
        "UPDATE placements
            SET status = 'active', updated_at = NOW()
          WHERE tenant_id = :tenant_id
            AND id IN ({$updateIn})
            AND status = 'pending_start'
            AND external_id LIKE 'jd:%'
            AND start_date <= :start_today
            AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
            AND (COALESCE(actual_end_date, end_date) IS NULL OR COALESCE(actual_end_date, end_date) >= :end_today)"
    );
    $update->execute($updateParams);
    $restored = $update->rowCount();
    if ($restored !== $expectedRestoreCount) {
        throw new RuntimeException(
            "Refusing repair: expected {$expectedRestoreCount} updates, wrote {$restored}."
        );
    }

    $activeTotalStmt = $pdo->prepare(
        "SELECT COUNT(*)
           FROM placements
          WHERE tenant_id = :tenant_id
            AND status = 'active'
            AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')"
    );
    $activeTotalStmt->execute(['tenant_id' => $tenantId]);
    $activeTotal = (int) $activeTotalStmt->fetchColumn();
    if ($activeTotal !== $expectedActiveTotal) {
        throw new RuntimeException(
            "Refusing repair: expected {$expectedActiveTotal} active placements after restore, found {$activeTotal}."
        );
    }

    $statusCountsStmt = $pdo->prepare(
        "SELECT status, COUNT(*) AS row_count
           FROM placements
          WHERE tenant_id = :tenant_id
            AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
       GROUP BY status"
    );
    $statusCountsStmt->execute(['tenant_id' => $tenantId]);
    $statusCounts = [];
    foreach ($statusCountsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $statusCounts[(string) $row['status']] = (int) $row['row_count'];
    }

    $detail = [
        'reason' => 'restore_active_placements_demoted_by_missing_jobdiva_actualstart',
        'historical_placement_ids_checked' => $placementIds,
        'placement_ids_restored' => $eligibleIds,
        'expected_restore_count' => $expectedRestoreCount,
        'active_total_after' => $activeTotal,
        'status_counts_after' => $statusCounts,
    ];
    $audit = $pdo->prepare(
        'INSERT INTO jobdiva_sync_audit
            (tenant_id, action, entity_type, direction, ok,
             items_processed, items_skipped, items_failed, detail, actor_user_id)
         VALUES (:tenant_id, :action, :entity_type, :direction, 1,
                 :items_processed, :items_skipped, 0, :detail, NULL)'
    );
    $audit->execute([
        'tenant_id' => $tenantId,
        'action' => 'repair_active_status_regression',
        'entity_type' => 'placement',
        'direction' => 'pull',
        'items_processed' => $restored,
        'items_skipped' => count($placementIds) - $restored,
        'detail' => json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    ]);

    $pdo->commit();
    echo json_encode([
        'ok' => true,
        'tenant_id' => $tenantId,
        'historical_ids_checked' => count($placementIds),
        'restored' => $restored,
        'restored_ids' => $eligibleIds,
        'active_total_after' => $activeTotal,
        'status_counts_after' => $statusCounts,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
