<?php
/** First-class review artifact projection for payroll runs. */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/../../../core/ai/artifacts.php';

function payrollRunSyncArtifact(int $tenantId, int $runId, ?int $actorUserId = null): array
{
    $stmt = getDB()->prepare(
        'SELECT r.*, p.period_start, p.period_end, p.pay_date
           FROM payroll_runs r
           JOIN payroll_pay_periods p
             ON p.tenant_id = r.tenant_id AND p.id = r.pay_period_id
          WHERE r.tenant_id = :tenant_id AND r.id = :id LIMIT 1'
    );
    $stmt->execute(['tenant_id' => $tenantId, 'id' => $runId]);
    $run = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$run) throw new RuntimeException("Payroll run {$runId} not found");

    $payload = [
        'run_id' => $runId,
        'pay_period_id' => (int) $run['pay_period_id'],
        'period_start' => $run['period_start'],
        'period_end' => $run['period_end'],
        'pay_date' => $run['pay_date'],
        'run_type' => $run['run_type'],
        'status' => $run['status'],
        'employee_count' => (int) $run['employee_count'],
        'gross_total_cents' => (int) $run['gross_total_cents'],
        'taxes_total_cents' => (int) $run['taxes_total_cents'],
        'deductions_total_cents' => (int) $run['deductions_total_cents'],
        'net_total_cents' => (int) $run['net_total_cents'],
        'employer_taxes_cents' => (int) $run['employer_taxes_cents'],
    ];
    $target = match ((string) $run['status']) {
        'computed' => 'review',
        'approved' => 'approved',
        'paid' => 'final',
        'voided' => 'archived',
        default => 'draft',
    };

    $artifact = artifactEnsureForSource(
        $tenantId,
        'payroll_review',
        'payroll',
        'payroll_run',
        $runId,
        [
            'title' => "Payroll run #{$runId} - {$run['period_start']} to {$run['period_end']}",
            'payload' => $payload,
            'created_by_user_id' => $run['created_by_user_id'] ?? $actorUserId,
            'initial_status' => 'draft',
        ]
    );
    getDB()->prepare(
        'UPDATE payroll_runs SET artifact_id = :artifact_id
          WHERE tenant_id = :tenant_id AND id = :id'
    )->execute([
        'artifact_id' => $artifact['id'],
        'tenant_id' => $tenantId,
        'id' => $runId,
    ]);
    artifactUpdate($tenantId, (string) $artifact['id'], ['payload' => $payload], $actorUserId);
    artifactLink(
        $tenantId,
        (string) $artifact['id'],
        'represents',
        null,
        'payroll_runs',
        $runId,
        [],
        $actorUserId
    );
    return artifactTransitionTo(
        $tenantId,
        (string) $artifact['id'],
        $target,
        $actorUserId,
        null,
        ['domain_status' => $run['status']]
    );
}
