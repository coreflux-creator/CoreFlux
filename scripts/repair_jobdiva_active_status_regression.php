<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/jobdiva/assignment_contract.php';

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
        "SELECT p.id, p.status, p.external_id, p.start_date, p.end_date,
                p.actual_end_date, p.deleted_at,
                (SELECT m.payload_snapshot
                   FROM external_entity_mappings m
                  WHERE m.tenant_id = p.tenant_id
                    AND m.internal_entity_type = 'placement'
                    AND m.internal_entity_id = p.id
                    AND m.source_system = 'jobdiva'
               ORDER BY m.id DESC
                  LIMIT 1) AS payload_snapshot
           FROM placements p
          WHERE p.tenant_id = :tenant_id
            AND p.id IN ({$selectIn})
       ORDER BY p.id ASC
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
    $pendingRestoreIds = [];
    $sourceActiveEndedRestoreIds = [];
    $restoreReasons = [];
    foreach ($rows as $row) {
        $deletedAt = trim((string) ($row['deleted_at'] ?? ''));
        $startDate = trim((string) ($row['start_date'] ?? ''));
        $actualEndDate = trim((string) ($row['actual_end_date'] ?? ''));
        $effectiveEndDate = trim((string) ($row['actual_end_date'] ?? ''));
        if ($effectiveEndDate === '') {
            $effectiveEndDate = trim((string) ($row['end_date'] ?? ''));
        }
        $baseEligible = str_starts_with((string) ($row['external_id'] ?? ''), 'jd:')
            && ($deletedAt === '' || $deletedAt === '0000-00-00 00:00:00')
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) === 1
            && $startDate <= $today
            && ($effectiveEndDate === '' || $effectiveEndDate >= $today);

        $placementId = (int) $row['id'];
        $status = (string) ($row['status'] ?? '');
        if ($baseEligible && $status === 'pending_start') {
            $eligibleIds[] = $placementId;
            $pendingRestoreIds[] = $placementId;
            $restoreReasons[(string) $placementId] = 'started_assignment_demoted_to_pending_start';
            continue;
        }

        $payload = json_decode((string) ($row['payload_snapshot'] ?? ''), true);
        $sourceStronglyActive = is_array($payload)
            && jobdivaAssignmentContractSnapshotIsStronglyActive($payload);
        if ($baseEligible
            && $status === 'ended'
            && $actualEndDate === ''
            && $sourceStronglyActive) {
            $eligibleIds[] = $placementId;
            $sourceActiveEndedRestoreIds[] = $placementId;
            $restoreReasons[(string) $placementId] = 'source_active_assignment_marked_ended';
        }
    }

    if (count($eligibleIds) !== $expectedRestoreCount) {
        throw new RuntimeException(
            "Refusing repair: expected {$expectedRestoreCount} eligible placements, found "
            . count($eligibleIds)
            . ' (' . implode(',', $eligibleIds) . ')'
        );
    }

    $restoreStatus = static function (
        array $ids,
        string $expectedStatus,
        string $prefix,
        bool $requireNoActualEnd = false
    ) use ($pdo, $bindIds, $tenantId, $today): int {
        if ($ids === []) return 0;
        $updateParams = [
            'tenant_id' => $tenantId,
            'expected_status' => $expectedStatus,
            'start_today' => $today,
            'end_today' => $today,
        ];
        $updateIn = $bindIds($ids, $prefix, $updateParams);
        $actualEndGuard = $requireNoActualEnd ? 'AND actual_end_date IS NULL' : '';
        $update = $pdo->prepare(
            "UPDATE placements
                SET status = 'active', updated_at = NOW()
              WHERE tenant_id = :tenant_id
                AND id IN ({$updateIn})
                AND status = :expected_status
                AND external_id LIKE 'jd:%'
                AND start_date <= :start_today
                AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
                AND (COALESCE(actual_end_date, end_date) IS NULL OR COALESCE(actual_end_date, end_date) >= :end_today)
                {$actualEndGuard}"
        );
        $update->execute($updateParams);
        return $update->rowCount();
    };

    $restored = $restoreStatus($pendingRestoreIds, 'pending_start', 'pending_restore_')
        + $restoreStatus($sourceActiveEndedRestoreIds, 'ended', 'ended_restore_', true);
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
        'reason' => 'restore_active_placements_from_authoritative_jobdiva_lifecycle',
        'historical_placement_ids_checked' => $placementIds,
        'placement_ids_restored' => $eligibleIds,
        'pending_start_ids_restored' => $pendingRestoreIds,
        'source_active_ended_ids_restored' => $sourceActiveEndedRestoreIds,
        'restore_reasons_by_placement_id' => $restoreReasons,
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
