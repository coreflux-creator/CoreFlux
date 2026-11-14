<?php
/** Apply completed WorkflowGraph decisions to payroll-owned records. */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/payroll.php';
require_once __DIR__ . '/artifacts.php';
require_once __DIR__ . '/calculation_review.php';

function payrollSyncRunFromWorkflow(
    int $tenantId,
    int $runId,
    string $action,
    ?int $userId,
    string $instanceStatus,
    ?string $comment = null
): void {
        $pdo = getDB();
        if (!$pdo) throw new \RuntimeException('No database');
        $beforeRun=payrollReviewLockSubject($tenantId,$runId);
        $q=$pdo->prepare('SELECT * FROM workflow_instances WHERE tenant_id=? AND id=? FOR UPDATE');
        $q->execute([$tenantId,$beforeRun['workflow_instance_id']]);$instance=$q->fetch(PDO::FETCH_ASSOC);
        if (!$instance || $instance['status']!==$instanceStatus) throw new RuntimeException('The saved payroll review decision is unavailable.',409);
        payrollCalculationAssertWorkflow($beforeRun,$instance);
        payrollReviewAssertActor($tenantId,$beforeRun,$userId,$action,'app');
        $q=$pdo->prepare('SELECT id FROM workflow_step_actions WHERE tenant_id=? AND instance_id=? AND actor_user_id=? AND action=? ORDER BY id DESC LIMIT 1 FOR UPDATE');
        $q->execute([$tenantId,$instance['id'],$userId,$action]);
        if (!$q->fetchColumn()) throw new RuntimeException('The independent payroll decision receipt is missing.',409);

        if ($action === 'reject' && $userId) {
            payrollAudit('payroll.run.approval_rejected', [
                'run_id' => $runId,
                'rejected_by_user_id' => $userId,
                'reason' => $comment ?: 'Rejected through workflow',
                'source' => 'workflow',
            ], $runId, ['before' => $beforeRun, 'after' => payrollSyncRunRow($tenantId, $runId) ?? $beforeRun]);
            return;
        }
        if ($action !== 'approve' || $instanceStatus !== 'approved') return;

        $run = $beforeRun;
        if (!$run) throw new \RuntimeException("Payroll run {$runId} not found");
        if (!in_array((string) ($run['status'] ?? ''), ['computed', 'approved'], true)) {
            throw new \RuntimeException("Payroll run {$runId} cannot be approved from status {$run['status']}");
        }

        $pdo->prepare(
            "UPDATE payroll_runs SET status = 'approved', approved_at = COALESCE(approved_at, NOW()),
                    approved_by = COALESCE(approved_by, :u), approved_computation_revision=:revision,
                    approved_computation_sha256=:hash, updated_at = NOW()
              WHERE tenant_id = :t AND id = :id AND status = 'computed'"
        )->execute(['u' => $userId, 't' => $tenantId, 'id' => $runId,'revision'=>$run['computation_revision'],'hash'=>$run['computation_sha256']]);
        $pdo->prepare(
            "UPDATE payroll_line_items SET status = 'approved', updated_at = NOW()
              WHERE tenant_id = :t AND run_id = :rid AND status = 'computed'"
        )->execute(['t' => $tenantId, 'rid' => $runId]);
        $pdo->prepare(
            "UPDATE payroll_pay_periods SET status = 'approved', updated_at = NOW()
              WHERE tenant_id = :t AND id = :pid AND status <> 'paid'"
        )->execute(['t' => $tenantId, 'pid' => (int) ($run['pay_period_id'] ?? 0)]);

        payrollRunSyncArtifact($tenantId, $runId, $userId);

        $updated = payrollSyncRunRow($tenantId, $runId) ?? $run;
        payrollAudit('payroll.run.approved', [
            'run_id' => $runId,
            'approved_by_user_id' => $userId,
            'source' => 'workflow',
            'workflow_instance_status' => $instanceStatus,
        ], $runId, ['before' => $beforeRun, 'after' => $updated]);
}

function payrollSyncRunRow(int $tenantId, int $runId): ?array {
    $pdo = getDB();
    if (!$pdo) return null;
    $stmt = $pdo->prepare(
        'SELECT * FROM payroll_runs WHERE tenant_id = :t AND id = :id LIMIT 1'
    );
    $stmt->execute(['t' => $tenantId, 'id' => $runId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
