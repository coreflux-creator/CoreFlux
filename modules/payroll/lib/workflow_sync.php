<?php
/**
 * Payroll run <-> WorkflowEngine sync.
 *
 * WorkflowGraph owns the approval decision. Payroll owns the resulting run,
 * line-item, and pay-period status updates.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/payroll.php';

function payrollSyncRunFromWorkflow(
    int $tenantId,
    int $runId,
    string $action,
    ?int $userId,
    string $instanceStatus,
    ?string $comment = null
): void {
    try {
        $pdo = getDB();
        if (!$pdo) return;
        $beforeRun = payrollSyncRunRow($tenantId, $runId) ?? ['id' => $runId];

        if ($action === 'reject' && $userId) {
            payrollAudit('payroll.run.approval_rejected', [
                'run_id' => $runId,
                'rejected_by_user_id' => $userId,
                'reason' => $comment ?: 'Rejected through workflow',
                'source' => 'workflow',
            ], $runId, [
                'before' => $beforeRun,
                'after' => payrollSyncRunRow($tenantId, $runId) ?? $beforeRun,
            ]);
            return;
        }

        if (!in_array($action, ['approve', 'skip'], true) || $instanceStatus !== 'approved' || !$userId) {
            return;
        }

        $run = payrollSyncRunRow($tenantId, $runId);
        if (!$run || !in_array((string) ($run['status'] ?? ''), ['computed', 'approved'], true)) {
            return;
        }

        $pdo->prepare(
            "UPDATE payroll_runs
                SET status = 'approved',
                    approved_at = COALESCE(approved_at, NOW()),
                    approved_by = COALESCE(approved_by, :u),
                    updated_at = NOW()
              WHERE tenant_id = :t AND id = :id AND status = 'computed'"
        )->execute(['u' => $userId, 't' => $tenantId, 'id' => $runId]);

        $pdo->prepare(
            "UPDATE payroll_line_items
                SET status = 'approved', updated_at = NOW()
              WHERE tenant_id = :t AND run_id = :rid AND status = 'computed'"
        )->execute(['t' => $tenantId, 'rid' => $runId]);

        $pdo->prepare(
            "UPDATE payroll_pay_periods
                SET status = 'approved', updated_at = NOW()
              WHERE tenant_id = :t AND id = :pid AND status <> 'paid'"
        )->execute(['t' => $tenantId, 'pid' => (int) ($run['pay_period_id'] ?? 0)]);

        $updated = payrollSyncRunRow($tenantId, $runId) ?? $run;
        payrollAudit('payroll.run.approved', [
            'run_id' => $runId,
            'approved_by_user_id' => $userId,
            'source' => 'workflow',
            'workflow_instance_status' => $instanceStatus,
        ], $runId, [
            'before' => $beforeRun,
            'after' => $updated,
        ]);
    } catch (\Throwable $e) {
        error_log('[payroll.workflow_sync] sync failed: ' . $e->getMessage());
    }
}

function payrollSyncRunRow(int $tenantId, int $runId): ?array
{
    $pdo = getDB();
    if (!$pdo) return null;
    $stmt = $pdo->prepare(
        'SELECT * FROM payroll_runs
          WHERE tenant_id = :t AND id = :id
          LIMIT 1'
    );
    $stmt->execute(['t' => $tenantId, 'id' => $runId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
