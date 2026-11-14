<?php
/** Exercise atomic entry approval and its weekly projection in disposable CI only. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'coreaccounting'
    || getenv('COREFLUX_STANDALONE_DATABASE') !== 'coreaccounting_ci'
    || !in_array('--confirm-disposable-fixture', $argv, true)) {
    fwrite(STDERR, "Disposable CoreAccounting CI database only.\n");
    exit(2);
}
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../modules/staffing/lib/timesheets.php';

$pdo = getDB();
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'coreaccounting_ci') {
    throw new RuntimeException('Connected database is not the disposable CI database.');
}
$tenantId = (int) $pdo->query('SELECT MIN(id) FROM tenants')->fetchColumn();
if ($tenantId <= 0) throw new RuntimeException('The CI tenant was not provisioned.');
$GLOBALS['__cf_request_tenant_id'] = $tenantId;
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message);
    $checks++;
    echo "PASS {$message}\n";
};
$refused = static function (callable $call, int $code) use ($assert): void {
    try { $call(); } catch (RuntimeException $e) {
        $assert($e->getCode() === $code, "operation refused with expected status {$code}");
        return;
    }
    throw new RuntimeException('Expected refusal did not occur.');
};
$financialState = static function () use ($pdo): array {
    $state = [];
    foreach (['accounting_journal_entries', 'accounting_journal_entry_lines', 'accounting_events'] as $table) {
        $state[$table] = $pdo->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }
    return $state;
};
$financialBefore = $financialState();
foreach (['0.00', '2.00', '2.25'] as $hours) {
    $payload = staffingTimesheetArtifactPayload([
        'id' => 1, 'person_id' => 1, 'period_start' => '2026-11-09', 'period_end' => '2026-11-15', 'total_hours' => $hours,
    ]);
    $assert(artifactComparableJson($payload) === artifactComparableJson(json_decode(json_encode($payload), true)),
        "{$hours} hours retain an identical artifact payload after storage");
}
$pdo->beginTransaction();
try {
    $users = [];
    foreach (['creator', 'alpha', 'beta'] as $name) {
        $pdo->prepare('INSERT INTO users (name, email, password_hash, role)
            VALUES (:name, :email, "!no-login-ci-fixture", "employee")')->execute([
                'name' => 'CI time ' . $name, 'email' => 'ci-time-projection-' . $name . '@example.invalid',
            ]);
        $users[$name] = ['id' => (int) $pdo->lastInsertId()];
    }
    $personId = scopedInsert('people', [
        'first_name' => 'CI Projection', 'last_name' => 'Worker',
        'email_primary' => 'ci-time-projection-worker@example.invalid', 'classification' => 'w2',
    ]);
    $entityId=(int)$pdo->query('SELECT id FROM accounting_entities WHERE tenant_id='.(int)$tenantId.' AND active=1 ORDER BY id LIMIT 1')->fetchColumn();
    if (!$entityId) throw new RuntimeException('The disposable fixture needs its provisioned accounting entity.');
    $companyId=scopedInsert('companies',['name'=>'CI time approval client']);
    $clientId=scopedInsert('staffing_clients',['name'=>'CI time approval client','company_id'=>$companyId]);
    $placementId = scopedInsert('placements', [
        'person_id' => $personId, 'status' => 'active', 'start_date' => '2026-11-01',
        'engagement_type' => 'w2', 'title' => 'CI synthetic approval projection',
        'client_id'=>$clientId,'accounting_entity_id'=>$entityId,'worksite_state'=>'NJ','workers_comp_class'=>'synthetic-class',
    ]);
    $rateId = scopedInsert('placement_rates', [
        'placement_id' => $placementId, 'effective_from' => '2026-11-01',
        'bill_rate' => 100, 'pay_rate' => 50, 'approved_at' => '2026-11-01 12:00:00',
    ]);
    $periodId = scopedInsert('time_periods', [
        'start_date' => '2026-11-09', 'end_date' => '2026-11-15', 'label' => 'CI projection week',
    ]);
    $week = staffingTimesheetUpsert($personId, '2026-11-09', '2026-11-15', [
        'tenant_id' => $tenantId, 'source' => 'manual_entry', 'created_by_user_id' => $users['creator']['id'],
    ]);
    $sheetId = (int) $week['id'];
    $artifactId = (string) $week['artifact_id'];
    $entryIds = [];
    foreach (['2026-11-09', '2026-11-10'] as $date) {
        $entryIds[] = scopedInsert('time_entries', [
            'placement_id' => $placementId, 'person_id' => $personId, 'period_id' => $periodId,
            'timesheet_id' => $sheetId, 'work_date' => $date, 'category' => 'regular_billable',
            'hour_type' => 'regular', 'hours' => 1, 'status' => 'pending_review',
            'created_by_user_id' => $users['creator']['id'], 'source' => 'manual_entry',
        ]);
    }
    $read = static fn(): array => [staffingTimesheetRow($tenantId, $sheetId), artifactGet($tenantId, $artifactId)];
    $assertState = static function (string $status, string $artifactStatus, ?int $reviewer) use ($read, $assert): void {
        [$header, $artifact] = $read();
        $assert($header['status'] === $status && $artifact['status'] === $artifactStatus
            && $artifact['payload']['status'] === $status, "entry week and artifact both project {$status}");
        $actualReviewer = $header['approved_by_user_id'] === null ? null : (int) $header['approved_by_user_id'];
        $assert($actualReviewer === $reviewer && $artifact['payload']['approved_by_user_id'] === $reviewer
            && $artifact['payload']['approved_at'] === $header['approved_at'],
            'header and artifact preserve the same approval provenance');
        $assert((float) $header['total_hours'] === 2.0 && (float) $artifact['payload']['total_hours'] === 2.0,
            'weekly hours equal the two source hours');
    };

    timeReconcileTimesheetHeader($sheetId, $users['creator']['id']);
    $assertState('submitted', 'review', null);
    $refused(static fn() => timeApproveEntry($entryIds[0], $users['creator']), 403);
    timeApproveEntry($entryIds[0], $users['alpha']);
    $assertState('submitted', 'review', null);
    $assert($read()[0]['approved_at'] === null, 'partial approval does not approve the weekly header');
    $approved = timeApproveEntry($entryIds[1], $users['alpha'], 'bulk_review');
    $assertState('approved', 'approved', $users['alpha']['id']);
    $assert($approved['approved_via'] === 'manual', 'bulk human review uses the supported manual approval method');
    $assert((int) $approved['rate_snapshot_id'] === $rateId && $read()[0]['approved_at'] === $approved['approved_at'],
        'approval locks the reviewed rate and projects the source approval date');
    $refused(static fn() => timeApproveEntry($entryIds[1], $users['beta']), 409);

    $before = $read();
    $eventCount = (int) $pdo->query('SELECT COUNT(*) FROM artifact_events')->fetchColumn();
    $auditCount = (int) $pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
    timeReconcileTimesheetHeader($sheetId, $users['beta']['id']);
    $assert($read() === $before
        && (int) $pdo->query('SELECT COUNT(*) FROM artifact_events')->fetchColumn() === $eventCount
        && (int) $pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn() === $auditCount,
        'repeated reconciliation changes no header, artifact version, event, or audit record');

    // Reproduce the observed pilot: approved rows with a stale header reviewer and review artifact.
    $entryBefore = array_map('timeEntryGet', $entryIds);
    scopedUpdate('staffing_timesheets', $sheetId, ['approved_by_user_id' => null]);
    $pdo->prepare('UPDATE artifact_objects SET status = "review" WHERE tenant_id = ? AND id = ?')
        ->execute([$tenantId, $artifactId]);
    timeReconcileTimesheetHeader($sheetId);
    $assertState('approved', 'approved', $users['alpha']['id']);
    $assert(array_map('timeEntryGet', $entryIds) === $entryBefore,
        'repairing historical projection preserves all approved source entry fields');

    // A second source reviewer is not misrepresented as a single weekly reviewer.
    scopedUpdate('time_entries', $entryIds[1], ['approved_by_user_id' => $users['beta']['id']]);
    timeReconcileTimesheetHeader($sheetId, $users['beta']['id']);
    $assertState('approved', 'approved', null);
    $stmt = $pdo->prepare('SELECT payload FROM artifact_events WHERE tenant_id = ? AND artifact_id = ?
        AND event_type = "timesheet.entries_reconciled" ORDER BY id DESC LIMIT 1');
    $stmt->execute([$tenantId, $artifactId]);
    $event = json_decode((string) $stmt->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
    $assert($event['approver_user_ids'] === [$users['alpha']['id'], $users['beta']['id']],
        'reconciliation records both original reviewers');

    scopedUpdate('time_entries', $entryIds[1], ['approved_by_user_id' => null, 'approved_at' => null]);
    scopedUpdate('time_entries', $entryIds[0], ['approved_by_user_id' => null, 'approved_at' => null]);
    timeReconcileTimesheetHeader($sheetId);
    $assertState('approved', 'approved', null);
    $assert($read()[0]['approved_at'] === null, 'legacy missing evidence never receives an invented approval date');

    scopedUpdate('staffing_timesheets', $sheetId, [
        'approved_by_user_id' => $users['alpha']['id'], 'approved_at' => '2026-11-10 12:00:00',
    ]);
    scopedUpdate('time_entries', $entryIds[1], ['status' => 'pending_review']);
    timeRejectEntry($entryIds[1], $users['beta'], 'CI test correction');
    $assertState('rejected', 'rejected', null);
    $assert($read()[0]['approved_at'] === null, 'rejection clears stale weekly approval evidence');
    scopedUpdate('time_entries', $entryIds[1], ['status' => 'draft']);
    timeReconcileTimesheetHeader($sheetId, $users['creator']['id']);
    $assertState('draft', 'draft', null);
    scopedUpdate('time_entries', $entryIds[1], ['status' => 'pending_review']);
    timeReconcileTimesheetHeader($sheetId, $users['creator']['id']);
    $assertState('submitted', 'review', null);

    // A closed artifact refuses synchronization. Both review mutations must roll back even
    // when an outer importer catches the error and keeps its transaction alive.
    artifactTransition($tenantId, $artifactId, 'archived', $users['creator']['id']);
    $before = $read();
    $pendingBefore = timeEntryGet($entryIds[1]);
    foreach (['approve', 'reject'] as $action) {
        $failed = false;
        try {
            if ($action === 'approve') timeApproveEntry($entryIds[1], $users['beta']);
            else timeRejectEntry($entryIds[1], $users['beta'], 'CI failure rollback');
        } catch (RuntimeException $e) {
            $failed = str_contains($e->getMessage(), 'Artifact lifecycle cannot move');
        }
        $assert($failed && $pdo->inTransaction() && $read() === $before
            && timeEntryGet($entryIds[1]) === $pendingBefore,
            "{$action} rolls back source and projection when artifact synchronization fails");
    }
    $pdo->prepare('UPDATE artifact_objects SET status = "review" WHERE tenant_id = ? AND id = ?')
        ->execute([$tenantId, $artifactId]);

    foreach (['payroll_ready', 'billing_ready', 'locked'] as $status) {
        scopedUpdate('staffing_timesheets', $sheetId, [
            'status' => $status, 'approved_by_user_id' => $users['alpha']['id'],
            'approved_at' => '2026-11-10 12:00:00',
        ]);
        timeReconcileTimesheetHeader($sheetId);
        $assertState($status, $status === 'locked' ? 'final' : 'approved', $users['alpha']['id']);
    }
    $before = $read();
    $pdo->exec('INSERT INTO tenants(name,tenant_type,is_active,is_simulation) VALUES("Synthetic other time owner","master",1,1)');
    $GLOBALS['__cf_request_tenant_id'] = (int)$pdo->lastInsertId();
    timeReconcileTimesheetHeader($sheetId, $users['beta']['id']);
    $refused(static fn() => timeApproveEntry($entryIds[1], $users['beta']), 404);
    $assert($read() === $before, 'a foreign company cannot reconcile or approve this timesheet');
    $GLOBALS['__cf_request_tenant_id'] = $tenantId;
    $assert($financialState() === $financialBefore, 'approval and reconciliation create no journals or accounting events');
    echo "{$checks} time approval projection checks passed.\n";
} finally {
    $GLOBALS['__cf_request_tenant_id'] = $tenantId;
    if ($pdo->inTransaction()) $pdo->rollBack();
}
