<?php
/**
 * Time timesheet <-> WorkflowEngine sync.
 *
 * The legacy weekly UI lives under Staffing, but the controlled business
 * subject is a Time timesheet. Workflow decisions sync back to the existing
 * staffing_timesheets header and its time_entries rows until the tables are
 * fully renamed/migrated into Time.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/time.php';

function timeSyncTimesheetFromWorkflow(
    int $tenantId,
    int $timesheetId,
    string $action,
    ?int $userId,
    string $instanceStatus,
    ?string $comment = null
): void {
    try {
        $pdo = getDB();
        if (!$pdo) return;
        $beforeHeader = timeTimesheetAuditRowForTenant($tenantId, $timesheetId);
        $beforeEntries = timeEntryAuditRowsForTimesheet($tenantId, $timesheetId);

        if ($action === 'reject' && $userId) {
            $reason = $comment ?: 'Rejected through workflow';
            $pdo->prepare(
                "UPDATE staffing_timesheets
                    SET status = 'rejected',
                        rejected_at = NOW(),
                        rejected_by_user_id = :u,
                        rejection_reason = :r
                  WHERE tenant_id = :t AND id = :id AND status = 'submitted'"
            )->execute(['u' => $userId, 'r' => $reason, 't' => $tenantId, 'id' => $timesheetId]);

            $pdo->prepare(
                "UPDATE time_entries
                    SET status = 'rejected', rejected_reason = :r
                  WHERE tenant_id = :t AND timesheet_id = :tid AND status = 'pending_review'"
            )->execute(['t' => $tenantId, 'tid' => $timesheetId, 'r' => $reason]);

            timeAudit('time.timesheet.rejected', [
                'timesheet_id' => $timesheetId,
                'rejected_by_user_id' => $userId,
                'reason' => $reason,
                'source' => 'workflow',
            ], $timesheetId, [
                'tenant_id' => $tenantId,
                'actor_user_id' => $userId,
                'before' => [
                    'timesheet' => $beforeHeader,
                    'entries' => $beforeEntries,
                ],
                'after' => [
                    'timesheet' => timeTimesheetAuditRowForTenant($tenantId, $timesheetId),
                    'entries' => timeEntryAuditRowsForTimesheet($tenantId, $timesheetId),
                ],
            ]);
            return;
        }

        if (!in_array($action, ['approve', 'skip'], true) || $instanceStatus !== 'approved' || !$userId) {
            return;
        }

        $pdo->prepare(
            "UPDATE staffing_timesheets
                SET status = 'approved',
                    approved_at = COALESCE(approved_at, NOW()),
                    approved_by_user_id = COALESCE(approved_by_user_id, :u),
                    approved_via = 'internal_app'
              WHERE tenant_id = :t AND id = :id AND status = 'submitted'"
        )->execute(['u' => $userId, 't' => $tenantId, 'id' => $timesheetId]);

        $pdo->prepare(
            "UPDATE time_entries
                SET status = 'approved',
                    approved_at = COALESCE(approved_at, NOW()),
                    approved_by_user_id = COALESCE(approved_by_user_id, :u),
                    approved_via = 'manual'
              WHERE tenant_id = :t AND timesheet_id = :tid AND status = 'pending_review'"
        )->execute(['t' => $tenantId, 'tid' => $timesheetId, 'u' => $userId]);

        timeSyncTimesheetApprovedAudit($pdo, $tenantId, $timesheetId, $userId, $beforeHeader, $beforeEntries);

        try {
            require_once __DIR__ . '/../../staffing/lib/timesheets.php';
            staffingEmitWorkerHoursApprovedEvent($tenantId, $timesheetId);
        } catch (\Throwable $e) {
            error_log('[time.workflow_sync] staffing accounting emit failed: ' . $e->getMessage());
        }
    } catch (\Throwable $e) {
        error_log('[time.workflow_sync] sync failed: ' . $e->getMessage());
    }
}

/** @internal */
function timeSyncTimesheetApprovedAudit(
    \PDO $pdo,
    int $tenantId,
    int $timesheetId,
    int $approverUserId,
    ?array $beforeHeader = null,
    array $beforeEntries = []
): void {
    $stmt = $pdo->prepare(
        "SELECT *
           FROM time_entries
          WHERE tenant_id = :t AND timesheet_id = :tid AND status = 'approved'"
    );
    $stmt->execute(['t' => $tenantId, 'tid' => $timesheetId]);
    $beforeById = [];
    foreach ($beforeEntries as $beforeEntry) {
        $beforeById[(int) ($beforeEntry['id'] ?? 0)] = $beforeEntry;
    }
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $entry) {
        timeEntryApprovedEmit((int) $entry['id'], $entry, 'manual', [
            'tenant_id' => $tenantId,
            'actor_user_id' => $approverUserId,
            'before' => $beforeById[(int) $entry['id']] ?? null,
            'approver_user_id' => $approverUserId,
            'timesheet_id' => $timesheetId,
            'source' => 'workflow',
        ]);
    }
    timeAudit('time.timesheet.approved', [
        'timesheet_id' => $timesheetId,
        'approved_by_user_id' => $approverUserId,
        'source' => 'workflow',
    ], $timesheetId, [
        'tenant_id' => $tenantId,
        'actor_user_id' => $approverUserId,
        'before' => [
            'timesheet' => $beforeHeader,
            'entries' => $beforeEntries,
        ],
        'after' => [
            'timesheet' => timeTimesheetAuditRowForTenant($tenantId, $timesheetId),
            'entries' => timeEntryAuditRowsForTimesheet($tenantId, $timesheetId),
        ],
    ]);
}
